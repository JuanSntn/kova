<?php

namespace App\Services\Branches;

use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Fiscal\SatCatalogService;
use App\Services\Fiscal\SatPostalTimezoneResolver;
use App\Services\Subscriptions\CapacityService;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class BranchService
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly SatCatalogService $catalogs,
        private readonly SatPostalTimezoneResolver $timezones,
        private readonly CapacityService $capacity,
    ) {}

    public function create(array $data): Branch
    {
        return DB::transaction(function () use ($data): Branch {
            $tenant = $this->currentTenant->get();
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->capacity->assertAdditional(
                $tenant,
                'included_branches',
                $tenant->branches()->where('billing_enabled', true)->count(),
                message: 'Tu plan actual no permite agregar más sucursales. Sube de plan o contrata una sucursal adicional para continuar.',
            );
            $this->assertUniqueCode(
                $data['code'],
            );

            $postal = $this->resolvePostalCode(
                $data['postal_code'],
            );

            return Branch::query()->create([
                'code' => strtoupper(
                    trim($data['code']),
                ),

                'name' => trim($data['name']),

                'postal_code' => $postal['code'],

                'state' => $postal['state'],

                'municipality' => $postal['municipality'],

                'locality' => $postal['locality'],

                'city' => $this->nullableString(
                    $data['city'] ?? null,
                ),

                'neighborhood' => $this->nullableString(
                    $data['neighborhood'] ?? null,
                ),

                'street' => $this->nullableString(
                    $data['street'] ?? null,
                ),

                'exterior_number' => $this->nullableString(
                    $data['exterior_number'] ?? null,
                ),

                'interior_number' => $this->nullableString(
                    $data['interior_number'] ?? null,
                ),

                'country_code' => 'MX',

                'latitude' => null,

                'longitude' => null,

                'timezone' => $this->timezones
                    ->resolve($postal),

                'phone' => $this->nullableString(
                    $data['phone'] ?? null,
                ),

                'is_active' => $data['is_active'] ?? true,

                'billing_enabled' => true,
            ]);
        });
    }

    public function createInitialBranch(Tenant $tenant, string $name = 'Principal', string $code = 'PRIN'): Branch
    {
        return Branch::query()->create([
            'code' => strtoupper(trim($code)),
            'name' => trim($name),
            'timezone' => $tenant->timezone,
            'billing_enabled' => true,
        ]);
    }

    public function enableForPlan(Branch $branch): Branch
    {
        $this->assertTenant($branch);
        if ($branch->billing_enabled) {
            return $branch;
        }

        return DB::transaction(function () use ($branch): Branch {
            $tenant = $this->currentTenant->get();
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $locked = $tenant->branches()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            if ($locked->billing_enabled) {
                return $locked;
            }
            $this->capacity->assertAdditional(
                $tenant,
                'included_branches',
                $tenant->branches()->where('billing_enabled', true)->count(),
                message: 'Tu plan actual no permite habilitar más sucursales. Sube de plan o contrata una sucursal adicional para continuar.',
            );
            $locked->update(['billing_enabled' => true]);

            return $locked->refresh();
        });
    }

    public function update(
        Branch $branch,
        array $data,
    ): Branch {
        $this->assertTenant(
            $branch,
        );

        return DB::transaction(function () use (
            $branch,
            $data,
        ): Branch {
            $code = array_key_exists(
                'code',
                $data,
            )
                ? strtoupper(
                    trim($data['code']),
                )
                : $branch->code;

            $this->assertUniqueCode(
                $code,
                $branch,
            );

            $attributes = [
                'code' => $code,

                'name' => array_key_exists(
                    'name',
                    $data,
                )
                    ? trim($data['name'])
                    : $branch->name,

                'city' => array_key_exists(
                    'city',
                    $data,
                )
                    ? $this->nullableString(
                        $data['city'],
                    )
                    : $branch->city,

                'neighborhood' => array_key_exists(
                    'neighborhood',
                    $data,
                )
                    ? $this->nullableString(
                        $data['neighborhood'],
                    )
                    : $branch->neighborhood,

                'street' => array_key_exists(
                    'street',
                    $data,
                )
                    ? $this->nullableString(
                        $data['street'],
                    )
                    : $branch->street,

                'exterior_number' => array_key_exists(
                    'exterior_number',
                    $data,
                )
                    ? $this->nullableString(
                        $data['exterior_number'],
                    )
                    : $branch->exterior_number,

                'interior_number' => array_key_exists(
                    'interior_number',
                    $data,
                )
                    ? $this->nullableString(
                        $data['interior_number'],
                    )
                    : $branch->interior_number,

                'phone' => array_key_exists(
                    'phone',
                    $data,
                )
                    ? $this->nullableString(
                        $data['phone'],
                    )
                    : $branch->phone,

                'is_active' => $data['is_active']
                    ?? $branch->is_active,
            ];

            $postalCode =
                $branch->postal_code;

            if (
                array_key_exists(
                    'postal_code',
                    $data,
                )
            ) {
                $postal =
                    $this->resolvePostalCode(
                        $data['postal_code'],
                    );

                $postalCode =
                    $postal['code'];

                $attributes['postal_code'] =
                    $postal['code'];

                $attributes['state'] =
                    $postal['state'];

                $attributes['municipality'] =
                    $postal['municipality'];

                $attributes['locality'] =
                    $postal['locality'];

                $attributes['country_code'] =
                    'MX';

                $attributes['timezone'] =
                    $this->timezones
                        ->resolve($postal);
            } elseif (
                $branch->postal_code
                && ! $branch->timezone
            ) {
                $postal =
                    $this->resolvePostalCode(
                        $branch->postal_code,
                    );

                $attributes['state'] =
                    $postal['state'];

                $attributes['municipality'] =
                    $postal['municipality'];

                $attributes['locality'] =
                    $postal['locality'];

                $attributes['country_code'] =
                    'MX';

                $attributes['timezone'] =
                    $this->timezones
                        ->resolve($postal);
            }

            if (
                $this->locationChanged(
                    $branch,
                    $data,
                    $postalCode,
                )
            ) {
                $attributes['latitude'] =
                    null;

                $attributes['longitude'] =
                    null;
            }

            $branch->update(
                $attributes,
            );

            return $branch->refresh();
        });
    }

    private function resolvePostalCode(
        string $postalCode,
    ): array {
        $postal =
            $this->catalogs
                ->resolvePostalCode(
                    trim($postalCode),
                );

        if (! $postal) {
            throw ValidationException::withMessages([
                'postal_code' => [
                    'El código postal no corresponde a un código postal vigente del SAT.',
                ],
            ]);
        }

        return $postal;
    }

    private function assertUniqueCode(
        string $code,
        ?Branch $ignore = null,
    ): void {
        $query =
            Branch::query()
                ->where(
                    'code',
                    strtoupper(
                        trim($code),
                    ),
                );

        if ($ignore) {
            $query->whereKeyNot(
                $ignore->getKey(),
            );
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'code' => [
                    'Ya existe una sucursal con este código.',
                ],
            ]);
        }
    }

    private function assertTenant(
        Branch $branch,
    ): void {
        $tenant =
            $this->currentTenant
                ->get();

        if (
            (int) $branch->tenant_id
            !== (int) $tenant->id
        ) {
            throw new LogicException(
                'La sucursal no pertenece al negocio actual.',
            );
        }
    }

    private function locationChanged(
        Branch $branch,
        array $data,
        ?string $postalCode,
    ): bool {
        if (
            (string) $branch->postal_code
            !== (string) $postalCode
        ) {
            return true;
        }

        foreach (
            [
                'city',
                'neighborhood',
                'street',
                'exterior_number',
                'interior_number',
            ] as $field
        ) {
            if (
                array_key_exists(
                    $field,
                    $data,
                )
                && $this->nullableString(
                    $data[$field],
                ) !== $this->nullableString(
                    $branch->{$field},
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function nullableString(
        mixed $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value =
            trim(
                (string) $value,
            );

        return $value === ''
            ? null
            : $value;
    }
}
