<?php

namespace App\Services\Fiscal;

use App\Contracts\Fiscal\PacProvider;
use App\Models\FiscalOutbox;
use App\Models\Invoice;
use App\Models\InvoiceAttempt;
use App\Services\Subscriptions\UsageService;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class FiscalProcessor
{
    public function __construct(
        private PacProvider $pac,
        private CsdVault $vault,
        private CfdiBuilder $builder,
        private InvoicePdfRenderer $pdf,
        private InvoiceDocumentStore $documents,
        private UsageService $usage,
        private StampCapacityService $capacity,
        private CurrentTenant $currentTenant,
    ) {}

    public function run(FiscalOutbox $operation): void
    {
        $tenant = $operation->tenant()->withoutGlobalScopes()->firstOrFail();
        $this->currentTenant->run($tenant, function () use ($operation) {
            $claimed = DB::transaction(function () use ($operation) {
                $row = FiscalOutbox::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
                if ($row->status === 'completed') {
                    return false;
                }
                if ($row->locked_at?->gt(now()->subMinutes(10))) {
                    return false;
                }
                $row->update(['status' => 'processing', 'locked_at' => now(), 'attempts' => $row->attempts + 1]);

                return true;
            });
            if (! $claimed) {
                return;
            }
            $operation->refresh();
            try {
                match ($operation->kind) {
                    'stamp' => $this->stamp($operation),
                    'cancel' => $this->cancel($operation),
                    default => throw new DomainException('Operación fiscal desconocida.'),
                };
                $operation->update(['status' => 'completed', 'locked_at' => null, 'last_error_code' => null, 'last_error_message' => null]);
            } catch (Throwable $exception) {
                $operation->update([
                    'status' => 'pending',
                    'locked_at' => null,
                    'last_error_code' => 'processing_failed',
                    'last_error_message' => 'La operación fiscal requiere un nuevo intento.',
                    'available_at' => now()->addSeconds(min(3600, 30 * (2 ** min($operation->attempts, 7)))),
                ]);
                throw $exception;
            }
        });
    }

    private function stamp(FiscalOutbox $operation): void
    {
        $this->assertCircuitClosed();
        $invoice = Invoice::query()->with(['reservation.policy.price', 'ticket', 'tenant'])->findOrFail($operation->invoice_id);
        if ($invoice->status === 'stamped') {
            return;
        }
        $reservation = $invoice->reservation;
        if (! $reservation) {
            $reservation = $this->capacity->reserve($invoice->tenant, $invoice);
        }
        $certificate = $invoice->tenant->fiscalProfile?->certificates()->where('status', 'active')->where('valid_until', '>', now())->latest('id')->first();
        if (! $certificate) {
            throw new DomainException('No existe un CSD activo para emitir el CFDI.');
        }
        $attempt = $this->beginAttempt($invoice, 'stamp');
        $started = hrtime(true);
        $pacContacted = false;
        try {
            $signedXml = $invoice->unsigned_xml_path
                ? $this->documents->get($invoice->unsigned_xml_path)
                : $this->builder->build($invoice, $this->vault->credential($certificate));
            if (! $invoice->unsigned_xml_path) {
                $invoice->update(['unsigned_xml_path' => $this->documents->put($invoice, 'unsigned', $signedXml), 'status' => 'stamping']);
            }
            if ($reservation->status === 'reconciliation_required') {
                $pacContacted = true;
                $result = $this->pac->recoverStamp($signedXml);
                if (! $result->successful && $result->code === 'not_found') {
                    $reservation->update(['status' => 'reserved']);
                    $result = $this->pac->stamp($signedXml);
                }
            } else {
                $pacContacted = true;
                $result = $this->pac->stamp($signedXml);
            }
            if (! $result->successful) {
                if ($result->code === 'provider_unavailable') {
                    $this->capacity->markUncertain($reservation);
                    $invoice->update(['status' => 'reconciliation_required']);
                    $this->recordCircuitFailure();
                    throw new DomainException('El PAC no confirmó si el CFDI fue timbrado.');
                }
                $reservation->update(['status' => 'released', 'released_at' => now()]);
                $invoice->update(['status' => 'failed']);
                $this->finishAttempt($attempt, 'failed', $result->code, $result->message, $started);

                return;
            }
            if (! $result->uuid || ! $result->xml) {
                $this->capacity->markUncertain($reservation);
                throw new DomainException('El PAC respondió sin un UUID verificable.');
            }
            $stampedPath = $this->documents->put($invoice, 'stamped', $result->xml);
            DB::transaction(function () use ($invoice, $reservation, $result, $stampedPath) {
                $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $issuedAt = CarbonImmutable::now('UTC');
                $locked->update(['fiscal_uuid' => strtolower($result->uuid), 'stamped_xml_path' => $stampedPath, 'status' => 'stamped', 'issued_at' => $issuedAt]);
                $this->usage->confirmIssuedStamp($locked, $reservation, $result->uuid, $issuedAt);
                $locked->ticket?->update(['used_at' => now()]);
            }, 3);
            try {
                $pdfPath = $this->documents->put($invoice->refresh(), 'invoice', $this->pdf->render($result->xml));
                $invoice->update(['pdf_path' => $pdfPath]);
            } catch (Throwable $pdfException) {
                // The fiscal result and usage are already committed. A derived
                // PDF failure must never retry or duplicate the PAC operation.
                report($pdfException);
            }
            Cache::forget('fiscal:pac:circuit_failures');
            Cache::forget('fiscal:pac:circuit_open');
            $this->finishAttempt($attempt, 'succeeded', 'stamped', null, $started);
        } catch (Throwable $exception) {
            // Once the signed XML reached the PAC, a local storage/database
            // failure cannot prove that no CFDI exists. Force recovery first
            // on the next attempt instead of sending another stamp blindly.
            if ($pacContacted && ! in_array($reservation->fresh()->status, ['confirmed', 'released'], true)) {
                $this->capacity->markUncertain($reservation);
                $invoice->update(['status' => 'reconciliation_required']);
            }
            if ($attempt->status === 'processing') {
                $this->finishAttempt($attempt, 'uncertain', 'processing_failed', 'La respuesta del PAC requiere reconciliación.', $started);
            }
            throw $exception;
        }
    }

    private function cancel(FiscalOutbox $operation): void
    {
        $this->assertCircuitClosed();
        $invoice = Invoice::query()->with(['tenant.fiscalProfile.certificates'])->findOrFail($operation->invoice_id);
        if ($invoice->status === 'cancelled') {
            return;
        }
        $certificate = $invoice->tenant->fiscalProfile?->certificates->where('status', 'active')->sortByDesc('id')->first();
        if (! $certificate || ! $invoice->fiscal_uuid) {
            throw new DomainException('No existe información suficiente para cancelar el CFDI.');
        }
        $attempt = $this->beginAttempt($invoice, 'cancel');
        $started = hrtime(true);
        $result = $this->pac->cancel($this->vault->credential($certificate), $invoice->issuer_snapshot['rfc'], $invoice->fiscal_uuid, $operation->payload['reason'], $operation->payload['replacement_uuid'] ?? null);
        if (! $result->successful) {
            $this->finishAttempt($attempt, 'failed', $result->code, $result->message, $started);
            throw new DomainException('El PAC no confirmó la cancelación.');
        }
        $path = $result->xml ? $this->documents->put($invoice, 'cancellation-receipt', $result->xml) : null;
        $invoice->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_receipt_path' => $path]);
        $this->finishAttempt($attempt, 'succeeded', $result->code, null, $started);
    }

    private function beginAttempt(Invoice $invoice, string $operation): InvoiceAttempt
    {
        $number = (int) $invoice->attempts()->where('operation', $operation)->max('attempt_number') + 1;

        return $invoice->attempts()->create(['operation' => $operation, 'attempt_number' => $number, 'status' => 'processing']);
    }

    private function finishAttempt(InvoiceAttempt $attempt, string $status, ?string $code, ?string $message, int $started): void
    {
        $attempt->update(['status' => $status, 'remote_code' => $code, 'safe_error' => $message ? mb_substr(strip_tags($message), 0, 500) : null, 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000)]);
    }

    private function assertCircuitClosed(): void
    {
        if (Cache::get('fiscal:pac:circuit_open')) {
            throw new DomainException('El PAC está temporalmente en recuperación.');
        }
    }

    private function recordCircuitFailure(): void
    {
        $failures = Cache::increment('fiscal:pac:circuit_failures');
        Cache::put('fiscal:pac:circuit_failures', $failures, now()->addMinutes(5));
        if ($failures >= 5) {
            Cache::put('fiscal:pac:circuit_open', true, now()->addMinute());
        }
    }
}
