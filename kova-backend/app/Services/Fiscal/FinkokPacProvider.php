<?php

namespace App\Services\Fiscal;

use App\Contracts\Fiscal\PacProvider;
use App\Data\Fiscal\PacResult;
use DomainException;
use PhpCfdi\Credentials\Credential;
use PhpCfdi\Finkok\FinkokEnvironment;
use PhpCfdi\Finkok\FinkokSettings;
use PhpCfdi\Finkok\QuickFinkok;
use PhpCfdi\XmlCancelacion\Models\CancelDocument;
use Throwable;

final class FinkokPacProvider implements PacProvider
{
    private function client(): QuickFinkok
    {
        $username = config('fiscal.username');
        $password = config('fiscal.password');
        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            throw new DomainException('Las credenciales de Finkok no están configuradas.');
        }
        $environment = config('fiscal.environment') === 'production'
            ? FinkokEnvironment::makeProduction()
            : FinkokEnvironment::makeDevelopment();

        return new QuickFinkok(new FinkokSettings($username, $password, $environment));
    }

    public function healthCheck(): PacResult
    {
        try {
            $this->client()->customersObtain('XAXX010101000');

            return new PacResult(true, 'reachable');
        } catch (Throwable) {
            return new PacResult(false, 'provider_unavailable', 'No fue posible comunicarse con el PAC.');
        }
    }

    public function registerIssuer(string $rfc): PacResult
    {
        try {
            $result = $this->client()->customersAdd(strtoupper($rfc));

            return new PacResult($result->success(), $result->success() ? 'registered' : 'registration_failed', $this->safe($result->message()));
        } catch (Throwable) {
            return new PacResult(false, 'provider_unavailable', 'No fue posible registrar al emisor con el PAC.');
        }
    }

    public function issuerStatus(string $rfc): PacResult
    {
        try {
            $result = $this->client()->customersObtain(strtoupper($rfc));
            if ($result->customers()->count() === 0) {
                return new PacResult(false, 'not_registered', $this->safe($result->message()));
            }
            $customer = $result->customers()->first();

            return new PacResult($customer->status()->isActive(), $customer->status()->isActive() ? 'active' : 'suspended', null, data: ['credit' => $customer->credit()]);
        } catch (Throwable) {
            return new PacResult(false, 'provider_unavailable', 'No fue posible consultar al emisor con el PAC.');
        }
    }

    public function stamp(string $signedXml): PacResult
    {
        try {
            $result = $this->client()->stamp($signedXml);
            if ($result->hasAlerts() || $result->uuid() === '' || $result->xml() === '') {
                $alert = $result->alerts()->first();

                return new PacResult(false, $alert?->id() ?: 'stamp_rejected', $this->safe($alert?->message() ?: 'El PAC rechazó el CFDI.'));
            }

            return new PacResult(true, 'stamped', uuid: strtolower($result->uuid()), xml: $result->xml());
        } catch (Throwable) {
            return new PacResult(false, 'provider_unavailable', 'No se pudo confirmar el timbrado con el PAC.');
        }
    }

    public function recoverStamp(string $signedXml): PacResult
    {
        try {
            $result = $this->client()->stamped($signedXml);
            if ($result->hasAlerts() || $result->uuid() === '' || $result->xml() === '') {
                return new PacResult(false, 'not_found', 'El PAC todavía no confirma el CFDI.');
            }

            return new PacResult(true, 'recovered', uuid: strtolower($result->uuid()), xml: $result->xml());
        } catch (Throwable) {
            return new PacResult(false, 'provider_unavailable', 'No se pudo recuperar el CFDI con el PAC.');
        }
    }

    public function documentStatus(string $issuerRfc, string $receiverRfc, string $uuid, string $total): PacResult
    {
        try {
            $result = $this->client()->satStatus($issuerRfc, $receiverRfc, $uuid, $total);

            return new PacResult(true, $result->query(), data: ['cfdi' => $result->cfdi(), 'cancellable' => $result->cancellable(), 'cancellation' => $result->cancellation()]);
        } catch (Throwable) {
            return new PacResult(false, 'provider_unavailable', 'No se pudo consultar el CFDI.');
        }
    }

    public function cancel(Credential $credential, string $rfc, string $uuid, string $reason, ?string $replacementUuid = null): PacResult
    {
        $document = match ($reason) {
            '01' => $replacementUuid ? CancelDocument::newWithErrorsRelated($uuid, $replacementUuid) : throw new DomainException('La cancelación 01 requiere el UUID sustituto.'),
            '02' => CancelDocument::newWithErrorsUnrelated($uuid),
            '03' => CancelDocument::newNotExecuted($uuid),
            '04' => CancelDocument::newNormativeToGlobal($uuid),
            default => throw new DomainException('El motivo de cancelación no es válido.'),
        };
        try {
            $result = $this->client()->cancel($credential, $document);

            return new PacResult($result->voucher() !== '', $result->statusCode(), xml: $result->voucher());
        } catch (Throwable) {
            return new PacResult(false, 'provider_unavailable', 'No se pudo confirmar la cancelación con el PAC.');
        }
    }

    public function downloadXml(string $rfc, string $uuid): PacResult
    {
        try {
            $result = $this->client()->cfdiDownload($uuid, $rfc);

            return new PacResult($result->xml() !== '', $result->xml() !== '' ? 'downloaded' : 'not_found', $this->safe($result->error()), $uuid, $result->xml());
        } catch (Throwable) {
            return new PacResult(false, 'provider_unavailable', 'No se pudo descargar el CFDI.');
        }
    }

    private function safe(string $message): ?string
    {
        $message = trim(strip_tags($message));

        return $message === '' ? null : mb_substr($message, 0, 500);
    }
}
