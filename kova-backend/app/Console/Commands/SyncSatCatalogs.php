<?php

namespace App\Console\Commands;

use App\Services\Fiscal\SatCatalogImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncSatCatalogs extends Command
{
    protected $signature = 'sat:catalogs:sync {--catalog-version= : Versión explícita vE.D.YYYYMMDD}';

    protected $description = 'Importa y activa una versión explícita de los catálogos SAT';

    public function handle(SatCatalogImporter $importer): int
    {
        $version = trim((string) $this->option('catalog-version'));
        if ($version === '') {
            $this->error('Debes indicar --catalog-version. No se permite sincronizar latest.');

            return self::INVALID;
        }
        $lock = Cache::lock('sat-catalogs-sync', 1800);
        if (! $lock->get()) {
            $this->error('Ya existe una importación SAT en ejecución.');

            return self::FAILURE;
        }
        try {
            $catalog = $importer->sync($version);
            $this->info("Catálogo {$catalog->version} activo.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
