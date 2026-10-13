<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\AddonVersion;
use App\Models\Feature;
use App\Models\Plan;
use App\Services\Subscriptions\CatalogService;
use DomainException;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $catalog =
            app(
                CatalogService::class
            );

        $definition =
            config(
                'billing_catalog'
            );

        if (
            ! is_array($definition)
            || ! is_array(
                $definition['features']
                    ?? null
            )
            || ! is_array(
                $definition['addons']
                    ?? null
            )
            || ! is_array(
                $definition['plans']
                    ?? null
            )
        ) {
            throw new DomainException(
                'La definición config/billing_catalog.php es inválida.'
            );
        }

        $this->seedFeatures(
            $definition['features']
        );

        $this->seedAddons(
            $catalog,
            $definition['addons']
        );

        $this->seedPlans(
            $catalog,
            $definition['plans']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    */

    private function seedFeatures(
        array $features
    ): void {
        foreach (
            $features
            as $code => $data
        ) {
            /*
             * Las features son parte de la estructura
             * del catálogo.
             *
             * Si ya existen no cambiamos silenciosamente
             * type/unit/reset/additive.
             */
            $feature =
                Feature::query()
                ->firstOrCreate(
                    [
                        'code' =>
                        $code,
                    ],
                    [
                        'name' =>
                        $data['name'],

                        'type' =>
                        $data['type'],

                        'unit' =>
                        $data['unit']
                            ?? null,

                        'reset_policy' =>
                        $data['reset_policy'],

                        'additive' =>
                        (bool) $data['additive'],
                    ]
                );

            $expected = [
                'type' =>
                $data['type'],

                'unit' =>
                $data['unit']
                    ?? null,

                'reset_policy' =>
                $data['reset_policy'],

                'additive' =>
                (bool) $data['additive'],
            ];

            $actual = [
                'type' =>
                $feature->type,

                'unit' =>
                $feature->unit,

                'reset_policy' =>
                $feature
                    ->reset_policy,

                'additive' =>
                (bool) $feature
                    ->additive,
            ];

            if (
                $actual
                !== $expected
            ) {
                throw new DomainException(
                    'La feature '
                        . $code
                        . ' ya existe con una definición distinta. '
                        . 'No se modifica automáticamente desde el seeder.'
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Addons
    |--------------------------------------------------------------------------
    */

    private function seedAddons(
        CatalogService $catalog,
        array $addons
    ): void {
        foreach (
            $addons
            as $code => $data
        ) {
            /*
             * La identidad del addon sí puede actualizar
             * nombre/estado.
             */
            $addon =
                Addon::query()
                ->updateOrCreate(
                    [
                        'code' =>
                        $code,
                    ],
                    [
                        'name' =>
                        $data['name'],

                        'is_active' =>
                        (bool) (
                            $data['is_active']
                            ?? true
                        ),
                    ]
                );

            /*
             * Seeder = bootstrap.
             *
             * Si ya existe una versión, jamás la
             * modificamos o reemplazamos desde aquí.
             *
             * v2/v3 deben nacer explícitamente mediante
             * CatalogService.
             */
            if (
                $addon
                ->versions()
                ->exists()
            ) {
                continue;
            }

            $version =
                $catalog
                ->createVersion(
                    $addon,
                    [
                        'name' =>
                        $data['name'],

                        'mode' =>
                        $data['mode'],

                        'entitlements' =>
                        $data['entitlements']
                            ?? [],

                        'prices' =>
                        $data['prices']
                            ?? [],
                    ]
                );

            $catalog->publish(
                $version
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    */

    private function seedPlans(
        CatalogService $catalog,
        array $plans
    ): void {
        foreach (
            $plans
            as $code => $data
        ) {
            /*
             * Plan = identidad comercial mutable.
             *
             * tier/name/active sí pueden actualizarse.
             */
            $plan =
                Plan::query()
                ->updateOrCreate(
                    [
                        'code' =>
                        $code,
                    ],
                    [
                        'name' =>
                        $data['name'],

                        'tier' =>
                        (int) $data['tier'],

                        'is_active' =>
                        (bool) (
                            $data['is_active']
                            ?? true
                        ),
                    ]
                );

            /*
             * PlanVersion = contrato inmutable.
             *
             * Nunca se edita una versión existente
             * desde el seeder.
             */
            if (
                $plan
                ->versions()
                ->exists()
            ) {
                continue;
            }

            $availableAddons = [];

            foreach (
                $data['addons']
                    ?? []
                as $addonCode =>
                $addonConfig
            ) {
                /*
                 * El addon ya fue creado/publicado
                 * en seedAddons().
                 */
                $addonVersion =
                    AddonVersion::query()
                    ->where(
                        'status',
                        'published'
                    )
                    ->whereHas(
                        'addon',
                        fn($query) =>
                        $query
                            ->where(
                                'code',
                                $addonCode
                            )
                    )
                    ->latest(
                        'version'
                    )
                    ->firstOrFail();

                $availableAddons[] = [
                    'version' =>
                    $addonVersion
                        ->public_id,

                    'automatic' =>
                    (bool) (
                        $addonConfig['automatic']
                        ?? false
                    ),
                ];
            }

            $version =
                $catalog
                ->createVersion(
                    $plan,
                    [
                        'name' =>
                        $data['name'],

                        'entitlements' =>
                        $data['entitlements']
                            ?? [],

                        /*
                             * Ya vienen expresados en minor units.
                             *
                             * No hacemos:
                             *
                             * × 100
                             * × 1000
                             */
                        'prices' =>
                        $data['prices']
                            ?? [],

                        'addons' =>
                        $availableAddons,
                    ]
                );

            $catalog->publish(
                $version
            );
        }
    }
}
