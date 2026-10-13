<?php

namespace App\Console\Commands;

use App\Models\Addon;
use App\Models\AddonVersion;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Services\Subscriptions\CatalogService;
use DomainException;
use Illuminate\Console\Command;

final class SyncDefinedBillingCatalog extends Command
{
    protected $signature = 'billing:sync-defined-catalog
        {--dry-run : Muestra las versiones que se publicarían sin modificar el catálogo}';

    protected $description = 'Publica versiones inmutables cuando config/billing_catalog.php cambió';

    public function handle(CatalogService $catalog): int
    {
        try {
            $definition = config('billing_catalog');

            if (! is_array($definition)) {
                throw new DomainException('La definición config/billing_catalog.php es inválida.');
            }

            $features = Feature::query()->get()->keyBy('code');
            if ($features->isEmpty()) {
                throw new DomainException('Primero inicializa el catálogo con billing:catalog seed.');
            }

            $changes = [];

            foreach ($definition['addons'] ?? [] as $code => $data) {
                $addon = $this->addonIdentity($code, $data);
                if (! $addon) {
                    $changes[] = ['addon', $code, null];

                    continue;
                }

                $current = $addon->versions()
                    ->where('status', 'published')
                    ->latest('version')
                    ->first();

                if ($current && $this->matchesAddon($current, $data, $features->all())) {
                    continue;
                }

                $changes[] = ['addon', $code, $current?->version];

                if ($this->option('dry-run')) {
                    continue;
                }

                $version = $catalog->createVersion($addon, [
                    'name' => $data['name'],
                    'mode' => $data['mode'],
                    'entitlements' => $data['entitlements'] ?? [],
                    'prices' => $data['prices'] ?? [],
                ]);
                $catalog->publish($version);
            }

            foreach ($definition['plans'] ?? [] as $code => $data) {
                $plan = $this->planIdentity($code, $data);
                if (! $plan) {
                    $changes[] = ['plan', $code, null];

                    continue;
                }

                $current = $plan->versions()
                    ->where('status', 'published')
                    ->latest('version')
                    ->first();

                $addons = $this->addonDefinition($data['addons'] ?? []);

                if ($current && $this->matchesPlan($current, $data, $addons, $features->all())) {
                    continue;
                }

                $changes[] = ['plan', $code, $current?->version];

                if ($this->option('dry-run')) {
                    continue;
                }

                $version = $catalog->createVersion($plan, [
                    'name' => $data['name'],
                    'entitlements' => $data['entitlements'] ?? [],
                    'prices' => $data['prices'] ?? [],
                    'addons' => $addons,
                ]);
                $catalog->publish($version);
            }

            if ($changes === []) {
                $this->info('El catálogo definido ya está publicado.');

                return self::SUCCESS;
            }

            foreach ($changes as [$kind, $code, $previous]) {
                $action = $this->option('dry-run') ? 'Se publicaría' : 'Publicada';
                $this->line(sprintf('%s versión nueva de %s %s (anterior: %s).', $action, $kind, $code, $previous ? 'v'.$previous : 'ninguna'));
            }

            if ($this->option('dry-run')) {
                $this->comment('No se modificó el catálogo ni Stripe.');
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function addonIdentity(string $code, array $data): ?Addon
    {
        if ($this->option('dry-run')) {
            return Addon::query()->where('code', $code)->first();
        }

        return Addon::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $data['name'],
                'is_active' => (bool) ($data['is_active'] ?? true),
            ],
        );
    }

    private function planIdentity(string $code, array $data): ?Plan
    {
        if ($this->option('dry-run')) {
            return Plan::query()->where('code', $code)->first();
        }

        return Plan::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $data['name'],
                'tier' => (int) $data['tier'],
                'is_active' => (bool) ($data['is_active'] ?? true),
            ],
        );
    }

    /** @param array<string, Feature> $features */
    private function matchesAddon(AddonVersion $version, array $expected, array $features): bool
    {
        return $version->name === $expected['name']
            && $version->mode === $expected['mode']
            && $this->matchesPrices($version, $expected['prices'] ?? [])
            && $this->matchesEntitlements($version, $expected['entitlements'] ?? [], $features, false);
    }

    /** @param array<int, array{version: string, automatic: bool}> $addons
     *  @param array<string, Feature> $features
     */
    private function matchesPlan(PlanVersion $version, array $expected, array $addons, array $features): bool
    {
        return $version->name === $expected['name']
            && $this->matchesPrices($version, $expected['prices'] ?? [])
            && $this->matchesEntitlements($version, $expected['entitlements'] ?? [], $features, true)
            && $this->matchesAddons($version, $addons);
    }

    private function matchesPrices(PlanVersion|AddonVersion $version, array $expected): bool
    {
        $actual = $version->prices()
            ->get()
            ->mapWithKeys(fn ($price) => [$price->interval => (int) $price->amount])
            ->all();

        ksort($actual);
        ksort($expected);

        return $actual === $expected;
    }

    /** @param array<string, Feature> $features */
    private function matchesEntitlements(PlanVersion|AddonVersion $version, array $expected, array $features, bool $includeDefaults): bool
    {
        $actual = $version->entitlements()
            ->with('feature')
            ->get()
            ->mapWithKeys(function ($entitlement): array {
                $value = $entitlement->is_unlimited
                    ? null
                    : ($entitlement->feature->type === 'boolean' ? (bool) $entitlement->value : (int) $entitlement->value);

                return [$entitlement->feature->code => $value];
            })
            ->all();

        if ($includeDefaults) {
            foreach ($features as $code => $feature) {
                if (! array_key_exists($code, $expected)) {
                    $expected[$code] = $feature->type === 'boolean' ? false : 0;
                }
            }
        }

        ksort($actual);
        ksort($expected);

        return $actual === $expected;
    }

    /** @param array<string, array{automatic?: bool}> $addons
     *  @return array<int, array{version: string, automatic: bool}>
     */
    private function addonDefinition(array $addons): array
    {
        return collect($addons)
            ->map(function (array $data, string $code): array {
                $version = AddonVersion::query()
                    ->where('status', 'published')
                    ->whereHas('addon', fn ($query) => $query->where('code', $code))
                    ->latest('version')
                    ->firstOrFail();

                return [
                    'version' => $version->public_id,
                    'automatic' => (bool) ($data['automatic'] ?? false),
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<int, array{version: string, automatic: bool}> $expected */
    private function matchesAddons(PlanVersion $version, array $expected): bool
    {
        $actual = $version->availableAddons()
            ->with('addon')
            ->get()
            ->map(fn (AddonVersion $addon) => [
                'code' => $addon->addon->code,
                'version' => $addon->public_id,
                'automatic' => (bool) $addon->pivot->automatic,
            ])
            ->sortBy('code')
            ->values()
            ->all();

        $wanted = collect($expected)
            ->map(function (array $addon): array {
                $version = AddonVersion::query()->where('public_id', $addon['version'])->with('addon')->firstOrFail();

                return [
                    'code' => $version->addon->code,
                    'version' => $addon['version'],
                    'automatic' => $addon['automatic'],
                ];
            })
            ->sortBy('code')
            ->values()
            ->all();

        return $actual === $wanted;
    }
}
