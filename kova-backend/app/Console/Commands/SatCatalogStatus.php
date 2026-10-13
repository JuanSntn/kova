<?php

namespace App\Console\Commands;

use App\Models\SatCatalogVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SatCatalogStatus extends Command
{
    protected $signature = 'sat:catalogs:status';

    protected $description = 'Muestra el estado local de los catálogos SAT';

    public function handle(): int
    {
        $active = SatCatalogVersion::query()->where('active_slot', 1)->first();
        $latest = Cache::get('sat-catalogs-latest-release');
        $this->table(['Dato', 'Valor'], [['Activa', $active?->version ?? 'ninguna'], ['Estado', $active?->status ?? 'sin importar'], ['Última detectada', $latest['version'] ?? 'sin consultar'], ['Conteos', json_encode($active?->counts ?? [], JSON_UNESCAPED_UNICODE)]]);

        return self::SUCCESS;
    }
}
