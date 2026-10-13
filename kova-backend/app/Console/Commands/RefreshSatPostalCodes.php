<?php

namespace App\Console\Commands;

use App\Services\Fiscal\SatCatalogImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RefreshSatPostalCodes extends Command
{
    protected $signature =
    'sat:catalogs:refresh-postal';

    protected $description =
    'Reimporta códigos postales y metadatos de huso horario de la versión SAT activa';

    public function handle(
        SatCatalogImporter $importer,
    ): int {
        $lock = Cache::lock(
            'sat-catalogs-sync',
            1800,
        );

        if (! $lock->get()) {
            $this->error(
                'Ya existe una importación SAT en ejecución.',
            );

            return self::FAILURE;
        }

        try {
            $catalog =
                $importer
                ->refreshActivePostalCodes();

            $this->info(
                "Códigos postales de {$catalog->version} actualizados.",
            );

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error(
                $e->getMessage(),
            );

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
