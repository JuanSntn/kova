<?php

namespace App\Console\Commands;

use App\Models\FiscalOutbox;
use App\Services\Fiscal\FiscalProcessor;
use Illuminate\Console\Command;
use Throwable;

class ProcessFiscal extends Command
{
    protected $signature = 'fiscal:process {--limit=25}';

    protected $description = 'Procesa la cola durable de operaciones fiscales';

    public function handle(FiscalProcessor $processor): int
    {
        $operations = FiscalOutbox::withoutGlobalScopes()->whereIn('status', ['pending', 'processing'])->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))->oldest()->limit(max(1, min(100, (int) $this->option('limit'))))->get();
        foreach ($operations as $operation) {
            try {
                $processor->run($operation);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return self::SUCCESS;
    }
}
