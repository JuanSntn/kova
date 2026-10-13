<?php

namespace App\Services\Fiscal;

use App\Models\FiscalCertificate;
use App\Models\TenantFiscalProfile;
use DateTimeImmutable;
use DomainException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpCfdi\Credentials\Credential;
use Throwable;

final class CsdVault
{
    public function encryptSecret(string $value, ?int $version = null): string
    {
        $resolvedVersion = $version ?? (int) config('fiscal.encryption_key_version');

        return 'v' . $resolvedVersion . ':' . $this->encrypter($resolvedVersion)->encryptString($value);
    }

    public function decryptSecret(string $value, ?int $version = null): string
    {
        if (preg_match('/^v([0-9]+):(.*)$/s', $value, $matches) === 1) {
            return $this->encrypter((int) $matches[1])->decryptString($matches[2]);
        }

        // Backwards compatibility for documents created before the envelope
        // included a key version. Those used the then-current configured key.
        return $this->encrypter($version ?? (int) config('fiscal.encryption_key_version'))->decryptString($value);
    }

    public function store(TenantFiscalProfile $profile, UploadedFile $certificateFile, UploadedFile $keyFile, string $password): FiscalCertificate {
        $certificate = $this->readUpload($certificateFile);
        $privateKey = $this->readUpload($keyFile);

        try {
            $credential = Credential::create(
                $certificate,
                $privateKey,
                $password
            );
        } catch (Throwable) {
            throw new DomainException(
                'El certificado, la llave o la contraseña no son válidos.'
            );
        }

        if (! $credential->isCsd()) {
            throw new DomainException(
                'El archivo debe ser un Certificado de Sello Digital, no una e.firma.'
            );
        }

        if (
            strtoupper($credential->rfc()) !==
            strtoupper((string) $profile->rfc)
        ) {
            throw new DomainException(
                'El RFC del certificado no coincide con el perfil fiscal.'
            );
        }

        if (
            ! $credential->certificate()->validOn(
                new DateTimeImmutable
            )
        ) {
            throw new DomainException(
                'El Certificado de Sello Digital no está vigente.'
            );
        }

        $fingerprint = hash(
            'sha256',
            $credential->certificate()->pem()
        );

        $existing = FiscalCertificate::query()
            ->where('tenant_id', $profile->tenant_id)
            ->where('sha256_fingerprint', $fingerprint)
            ->first();

        if ($existing) {
            return DB::transaction(function () use ($profile, $existing) {
                $profile->certificates()
                    ->where('status', 'active')
                    ->whereKeyNot($existing->getKey())
                    ->update([
                        'status' => 'replaced',
                    ]);

                $existing->update([
                    'status' => 'active',
                    'activated_at' => $existing->activated_at ?? now(),
                    'last_validated_at' => now(),
                ]);

                return $existing->refresh();
            });
        }

        $version = (int) config(
            'fiscal.encryption_key_version'
        );

        $directory = sprintf(
            'fiscal/%s/csd/%s',
            $profile->tenant->public_id,
            Str::uuid()
        );

        $certificatePath =
            $directory . '/certificate.enc';

        $privateKeyPath =
            $directory . '/private-key.enc';

        $disk = Storage::disk(
            config('fiscal.storage_disk')
        );

        $encrypter =
            $this->encrypter($version);

        $disk->put(
            $certificatePath,
            $encrypter->encryptString(
                $certificate
            )
        );

        $disk->put(
            $privateKeyPath,
            $encrypter->encryptString(
                $privateKey
            )
        );

        try {
            return DB::transaction(function () use (
                $profile,
                $credential,
                $fingerprint,
                $certificatePath,
                $privateKeyPath,
                $encrypter,
                $password,
                $version
            ) {
                $profile->certificates()
                    ->where('status', 'active')
                    ->update([
                        'status' => 'replaced',
                    ]);

                return $profile->certificates()->create([
                    'public_id' => (string) Str::uuid(),
                    'serial_number' => $credential
                        ->certificate()
                        ->serialNumber()
                        ->decimal(),
                    'rfc' => strtoupper(
                        $credential->rfc()
                    ),
                    'legal_name' => $credential->legalName(),
                    'valid_from' => $credential
                        ->certificate()
                        ->validFromDateTime(),
                    'valid_until' => $credential
                        ->certificate()
                        ->validToDateTime(),
                    'sha256_fingerprint' => $fingerprint,
                    'certificate_path' => $certificatePath,
                    'private_key_path' => $privateKeyPath,
                    'encrypted_password' => $encrypter->encryptString(
                        $password
                    ),
                    'encryption_key_version' => $version,
                    'status' => 'active',
                    'activated_at' => now(),
                    'last_validated_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            $disk->delete([
                $certificatePath,
                $privateKeyPath,
            ]);

            throw $exception;
        }
    }

    public function credential(FiscalCertificate $certificate): Credential
    {
        $encrypter = $this->encrypter((int) $certificate->encryption_key_version);
        $disk = Storage::disk(config('fiscal.storage_disk'));

        return Credential::create(
            $encrypter->decryptString($disk->get($certificate->certificate_path)),
            $encrypter->decryptString($disk->get($certificate->private_key_path)),
            $encrypter->decryptString($certificate->encrypted_password),
        );
    }

    private function readUpload(UploadedFile $file): string
    {
        $contents = file_get_contents($file->getRealPath());
        if (! is_string($contents) || $contents === '') {
            throw new DomainException('No se pudo leer el archivo del certificado.');
        }

        return $contents;
    }

    private function encrypter(int $version): Encrypter
    {
        $configured = config("fiscal.encryption_keys.$version");
        if ((! is_string($configured) || $configured === '') && app()->environment('testing')) {
            $configured = config('app.key');
        }
        if (! is_string($configured) || $configured === '') {
            throw new DomainException('La llave de cifrado fiscal no está configurada.');
        }
        $key = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : $configured;
        if (! is_string($key) || strlen($key) !== 32) {
            throw new DomainException('La llave de cifrado fiscal debe contener 32 bytes.');
        }

        return new Encrypter($key, 'AES-256-CBC');
    }
}
