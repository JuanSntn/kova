<?php

namespace App\Services\Fiscal;

use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;

final class InvoiceDocumentStore
{
    public function __construct(private CsdVault $vault) {}

    public function put(Invoice $invoice, string $kind, string $contents): string
    {
        $path = sprintf('fiscal/%s/invoices/%s/%s.enc', $invoice->tenant->public_id, $invoice->public_id, $kind);
        Storage::disk(config('fiscal.storage_disk'))->put($path, $this->vault->encryptSecret($contents));

        return $path;
    }

    public function get(string $path): string
    {
        return $this->vault->decryptSecret(Storage::disk(config('fiscal.storage_disk'))->get($path));
    }
}
