<?php

namespace App\Console\Commands;

use App\Models\SatCatalogVersion;
use App\Services\Fiscal\SatCatalogImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckSatCatalogs extends Command
{
    protected $signature = 'sat:catalogs:check';

    protected $description = 'Consulta si phpCfdi publicó una versión nueva de catálogos SAT';

    public function handle(SatCatalogImporter $importer): int
    {
        $latest = $importer->latest();
        Cache::put('sat-catalogs-latest-release', $latest, now()->addDays(7));
        $active = SatCatalogVersion::query()->where('active_slot', 1)->value('version') ?: 'ninguna';
        $this->line("Versión activa: {$active}");
        $this->line("Versión remota: {$latest['version']}");
        $this->info($active === $latest['version'] ? 'Catálogos al día.' : "Actualización disponible. Ejecuta: php artisan sat:catalogs:sync --catalog-version={$latest['version']}");

        return self::SUCCESS;
    }
}
