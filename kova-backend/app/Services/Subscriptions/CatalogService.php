<?php

namespace App\Services\Subscriptions;

use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\AddonVersion;
use App\Models\BillingPriceMapping;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanVersion;
use DomainException;
use Illuminate\Support\Facades\DB;

final class CatalogService
{
    public function createVersion(
        Plan|Addon $identity,
        array $data
    ): PlanVersion|AddonVersion {
        return DB::transaction(
            function () use (
                $identity,
                $data
            ) {
                $identity
                    ->newQuery()
                    ->whereKey(
                        $identity->id
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $version =
                    $identity
                        ->versions()
                        ->create([
                            'version' =>
                                (
                                    $identity
                                        ->versions()
                                        ->max(
                                            'version'
                                        )
                                    ?? 0
                                ) + 1,

                            'name' =>
                                $data['name']
                                ?? $identity->name,

                            'mode' =>
                                $data['mode']
                                ?? 'recurring',
                        ]);

                foreach (
                    $data['entitlements']
                    ?? []
                    as $code => $value
                ) {
                    $feature =
                        Feature::query()
                            ->where(
                                'code',
                                $code
                            )
                            ->firstOrFail();

                    if (
                        $value !== null
                        && ! is_int($value)
                        && ! is_bool($value)
                    ) {
                        throw new DomainException(
                            'El beneficio debe ser un entero no negativo, booleano o null para ilimitado.'
                        );
                    }

                    if (
                        is_int($value)
                        && $value < 0
                    ) {
                        throw new DomainException(
                            'El beneficio no puede ser negativo.'
                        );
                    }

                    if (
                        $feature->type === 'boolean'
                        && ! is_bool($value)
                    ) {
                        throw new DomainException(
                            'La capacidad '
                            .$code
                            .' requiere un booleano.'
                        );
                    }

                    $version
                        ->entitlements()
                        ->create([
                            'feature_id' =>
                                $feature->id,

                            'value' =>
                                $value,

                            'is_unlimited' =>
                                $value === null,
                        ]);
                }

                foreach (
                    $data['prices']
                    ?? []
                    as $interval => $amount
                ) {
                    if (
                        ! in_array(
                            $interval,
                            ['month', 'year'],
                            true
                        )
                        || ! is_int($amount)
                        || $amount < 0
                    ) {
                        throw new DomainException(
                            'Precio o periodicidad inválidos.'
                        );
                    }

                    if (
                        $identity instanceof Addon
                        && $interval !== 'month'
                    ) {
                        throw new DomainException(
                            'Los extras se cobran mensualmente.'
                        );
                    }

                    $version
                        ->prices()
                        ->create([
                            'interval' =>
                                $interval,

                            'amount' =>
                                $amount,

                            'currency' =>
                                'mxn',
                        ]);
                }

                if (
                    $version
                    instanceof PlanVersion
                ) {
                    foreach (
                        $data['addons']
                        ?? []
                        as $addon
                    ) {
                        $addonVersion =
                            AddonVersion::query()
                                ->where(
                                    'public_id',
                                    $addon['version']
                                )
                                ->where(
                                    'status',
                                    'published'
                                )
                                ->firstOrFail();

                        $version
                            ->availableAddons()
                            ->attach(
                                $addonVersion->id,
                                [
                                    'automatic' =>
                                        $addon[
                                            'automatic'
                                        ]
                                        ?? false,
                                ]
                            );
                    }
                }

                return $version;
            }
        );
    }

    public function publish(
        PlanVersion|AddonVersion $version
    ): void {
        DB::transaction(
            function () use ($version) {
                $version =
                    $version
                        ->newQuery()
                        ->whereKey(
                            $version->id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $version->status
                    !== 'draft'
                ) {
                    throw new DomainException(
                        'Solo se publican borradores.'
                    );
                }

                $this->validate(
                    $version
                );

                $version->update([
                    'status' =>
                        'published',

                    'published_at' =>
                        now(),
                ]);
            }
        );
    }

    public function validate(
        PlanVersion|AddonVersion $version
    ): void {
        if (
            ! $version
                ->prices()
                ->exists()
        ) {
            throw new DomainException(
                'Faltan precios.'
            );
        }

        if (
            ! in_array(
                $version->mode,
                [
                    'recurring',
                    'metered',
                ],
                true
            )
        ) {
            throw new DomainException(
                'Modalidad inválida.'
            );
        }

        if (
            $version
            instanceof PlanVersion
        ) {
            $required =
                Feature::query()
                    ->pluck('id')
                    ->all();

            if (
                $version
                    ->prices()
                    ->where(
                        'amount',
                        '>',
                        0
                    )
                    ->exists()
                && ! $version
                    ->availableAddons()
                    ->wherePivot(
                        'automatic',
                        true
                    )
                    ->where(
                        'mode',
                        'metered'
                    )
                    ->exists()
            ) {
                throw new DomainException(
                    'Un plan pagado requiere un complemento mensual de consumo automático.'
                );
            }

            if (
                array_diff(
                    $required,
                    $version
                        ->entitlements()
                        ->pluck(
                            'feature_id'
                        )
                        ->all()
                )
            ) {
                throw new DomainException(
                    'Define todas las capacidades, incluso las no incluidas.'
                );
            }
        }
    }

    public function retire(
        PlanVersion|AddonVersion $version
    ): void {
        if (
            $version->status
            !== 'published'
        ) {
            throw new DomainException(
                'Solo se retiran versiones publicadas.'
            );
        }

        $version->update([
            'status' =>
                'retired',

            'retired_at' =>
                now(),
        ]);
    }

    public function price(
        string $code,
        string $interval = 'month',
        ?string $offer = null,
    ): PlanPrice {
        $query =
            PlanPrice::query()
                ->with(
                    'version.plan'
                )
                ->where(
                    'interval',
                    $interval
                )
                ->whereHas(
                    'version',
                    fn ($query) =>
                    $query
                        ->where(
                            'status',
                            'published'
                        )
                        ->whereHas(
                            'plan',
                            fn ($plan) =>
                            $plan
                                ->where(
                                    'code',
                                    $code
                                )
                                ->where(
                                    'is_active',
                                    true
                                )
                        )
                );

        if ($offer) {
            $query->where(
                'public_id',
                $offer
            );
        }

        $price =
            $query
                ->orderByDesc('id')
                ->first();

        if (! $price) {
            throw new DomainException(
                'Oferta no disponible.'
            );
        }

        return $price;
    }

    public function mapping(
        PlanPrice|AddonPrice $price
    ): BillingPriceMapping {
        $column =
            $price instanceof PlanPrice
            ? 'plan_price_id'
            : 'addon_price_id';

        $mapping =
            BillingPriceMapping::query()
                ->where(
                    $column,
                    $price->id
                )
                ->where(
                    'livemode',
                    config(
                        'billing.livemode',
                        false
                    )
                )
                ->first();

        if (
            ! $mapping
            || blank(
                $mapping
                    ->stripe_price_id
            )
        ) {
            throw new DomainException(
                'La oferta todavía no tiene el cobro configurado.'
            );
        }

        return $mapping;
    }

    public function assertPurchasable(
        PlanPrice $price
    ): void {
        if (
            $price->interval === 'year'
            && ! config(
                'billing.annual_enabled',
                false
            )
        ) {
            throw new DomainException(
                'Los planes anuales todavía no están disponibles.'
            );
        }

        if (
            $price->amount === 0
        ) {
            return;
        }

        $this->mapping($price);

        $automaticAddons =
            $price
                ->version
                ->availableAddons()
                ->wherePivot(
                    'automatic',
                    true
                )
                ->with('prices')
                ->get();

        foreach (
            $automaticAddons
            as $addon
        ) {
            foreach (
                $addon->prices
                as $extraPrice
            ) {
                $this->mapping(
                    $extraPrice
                );
            }
        }
    }

    public function offers(): array
    {
        return Plan::query()
            ->where(
                'is_active',
                true
            )
            ->orderBy('tier')
            ->get()
            ->map(
                function (Plan $plan) {
                    $version =
                        $plan
                            ->versions()
                            ->where(
                                'status',
                                'published'
                            )
                            ->latest(
                                'version'
                            )
                            ->first();

                    if (! $version) {
                        return null;
                    }

                    $benefits =
                        app(
                            EntitlementService::class
                        )->forVersion(
                            $version
                        );

                    if (
                        isset(
                            $benefits[
                                'monthly_sales_limit'
                            ]
                        )
                    ) {
                        $benefits[
                            'monthly_sales_limit'
                        ] =
                            $this->decimal(
                                $benefits[
                                    'monthly_sales_limit'
                                ]
                            );
                    }

                    $benefits[
                        'additional_stamp_price'
                    ] = null;

                    $benefits[
                        'additional_branch_price'
                    ] = null;

                    foreach (
                        $version
                            ->availableAddons()
                            ->with([
                                'addon',
                                'prices',
                            ])
                            ->get()
                        as $addonVersion
                    ) {
                        $field =
                            match (
                                $addonVersion
                                    ->addon
                                    ->code
                            ) {
                                'extra_stamp' =>
                                    'additional_stamp_price',

                                'extra_branch' =>
                                    'additional_branch_price',

                                default =>
                                    null,
                            };

                        if (! $field) {
                            continue;
                        }

                        $monthPrice =
                            $addonVersion
                                ->prices
                                ->firstWhere(
                                    'interval',
                                    'month'
                                );

                        if ($monthPrice) {
                            $benefits[
                                $field
                            ] =
                                $this->decimal(
                                    $monthPrice
                                        ->amount
                                );
                        }
                    }

                    return array_merge(
                        [
                            'code' =>
                                $plan->code,

                            'name' =>
                                $version->name,

                            'tier' =>
                                $plan->tier,
                        ],
                        $benefits,
                        [
                            'monthly_price' =>
                                $this->decimal(
                                    $version
                                        ->prices()
                                        ->where(
                                            'interval',
                                            'month'
                                        )
                                        ->value(
                                            'amount'
                                        )
                                    ?? 0
                                ),

                            'version' =>
                                $version
                                    ->public_id,

                            'prices' =>
                                $version
                                    ->prices
                                    ->map(
                                        function (
                                            PlanPrice $price
                                        ) {
                                            try {
                                                $this
                                                    ->assertPurchasable(
                                                        $price
                                                    );

                                                $available =
                                                    true;
                                            } catch (
                                                DomainException
                                            ) {
                                                $available =
                                                    false;
                                            }

                                            return [
                                                'offer_id' =>
                                                    $price
                                                        ->public_id,

                                                'interval' =>
                                                    $price
                                                        ->interval,

                                                'currency' =>
                                                    $price
                                                        ->currency,

                                                'amount' =>
                                                    $price
                                                        ->amount,

                                                'available' =>
                                                    $available,
                                            ];
                                        }
                                    )
                                    ->values()
                                    ->all(),

                            'addons' =>
                                $version
                                    ->availableAddons
                                    ->map(
                                        function (
                                            AddonVersion $addon
                                        ) {
                                            return [
                                                'version' =>
                                                    $addon
                                                        ->public_id,

                                                'code' =>
                                                    $addon
                                                        ->addon
                                                        ->code,

                                                'name' =>
                                                    $addon
                                                        ->name,

                                                'mode' =>
                                                    $addon
                                                        ->mode,

                                                'automatic' =>
                                                    (bool) $addon
                                                        ->pivot
                                                        ->automatic,

                                                'prices' =>
                                                    $addon
                                                        ->prices
                                                        ->map(
                                                            function (
                                                                AddonPrice $price
                                                            ) {
                                                                try {
                                                                    $this
                                                                        ->mapping(
                                                                            $price
                                                                        );

                                                                    $available =
                                                                        true;
                                                                } catch (
                                                                    DomainException
                                                                ) {
                                                                    $available =
                                                                        false;
                                                                }

                                                                return [
                                                                    'offer_id' =>
                                                                        $price
                                                                            ->public_id,

                                                                    'amount' =>
                                                                        $price
                                                                            ->amount,

                                                                    'currency' =>
                                                                        $price
                                                                            ->currency,

                                                                    'interval' =>
                                                                        $price
                                                                            ->interval,

                                                                    'available' =>
                                                                        $available,
                                                                ];
                                                            }
                                                        )
                                                        ->values()
                                                        ->all(),
                                            ];
                                        }
                                    )
                                    ->values()
                                    ->all(),
                        ]
                    );
                }
            )
            ->filter()
            ->values()
            ->all();
    }

    public function decimal(
        int $amount
    ): string {
        return intdiv(
            $amount,
            100
        )
        .'.'
        .str_pad(
            (string) (
                $amount % 100
            ),
            2,
            '0',
            STR_PAD_LEFT
        );
    }
}
