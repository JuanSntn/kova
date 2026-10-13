<?php

namespace App\Console\Commands;

use App\Models\Addon;
use App\Models\AddonVersion;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Services\Subscriptions\CatalogService;
use Illuminate\Console\Command;

final class BillingCatalog extends Command
{
    protected $signature = 'billing:catalog {action : seed, draft, validate, publish or retire} {kind? : plan or addon} {id? : Identity code for draft; version UUID otherwise} {--file= : JSON definition for a draft}';

    protected $description = 'Gestiona versiones inmutables del catálogo comercial';

    public function handle(CatalogService $catalog): int
    {
        try {
            if ($this->argument('action') === 'seed') {
                $this->call('db:seed', ['--class' => 'PlanSeeder', '--force' => true]);

                return self::SUCCESS;
            }
            $kind = $this->argument('kind');
            if (! in_array($kind, ['plan', 'addon'])) {
                throw new \DomainException('Indica plan o addon.');
            }
            if ($this->argument('action') === 'draft') {
                $data = json_decode(file_get_contents($this->option('file')), true, 512, JSON_THROW_ON_ERROR);
                $class = $kind === 'plan' ? Plan::class : Addon::class;
                $identity = $class::firstOrCreate(['code' => $this->argument('id')], ['name' => $data['name']]);
                $version = $catalog->createVersion($identity, $data);
                $this->info($version->public_id);

                return self::SUCCESS;
            }
            $class = $kind === 'plan' ? PlanVersion::class : AddonVersion::class;
            $version = $class::where('public_id', $this->argument('id'))->firstOrFail();
            match ($this->argument('action')) {
                'validate' => $catalog->validate($version), 'publish' => $catalog->publish($version), 'retire' => $catalog->retire($version),
                default => throw new \DomainException('Acción desconocida.'),
            };
            $this->info('Catálogo actualizado.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
