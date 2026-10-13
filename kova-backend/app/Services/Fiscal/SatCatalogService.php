<?php

namespace App\Services\Fiscal;

use App\Models\SatCatalogVersion;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SatCatalogService
{
    private ?SatCatalogVersion $cachedVersion = null;

    public function __construct(
        private RfcClassifier $rfcs,
    ) {}

    public function activeVersion(): SatCatalogVersion
    {
        return $this->cachedVersion ??=
            SatCatalogVersion::query()
            ->where('active_slot', 1)
            ->where('status', 'active')
            ->first()
            ?? throw new DomainException(
                'Los catálogos SAT todavía no están disponibles.'
            );
    }

    public function versionName(): string
    {
        return $this->activeVersion()->version;
    }

    public function regimes(
        string $rfc,
        CarbonInterface|string|null $at = null,
    ): array {
        $type = $this->rfcs->classify($rfc);

        $query =
            $this->active(
                'sat_tax_regimes',
                $at,
            );

        if ($type === 'physical') {
            $query->where(
                'applies_to_physical',
                true,
            );
        } elseif ($type === 'legal') {
            $query->where(
                'applies_to_legal',
                true,
            );
        } else {
            return [];
        }

        return $this->entries(
            $query
                ->orderBy('code')
                ->get(),
        );
    }

    public function cfdiUses(
        string $rfc,
        string $regime,
        CarbonInterface|string|null $at = null,
    ): array {
        $type = $this->rfcs->classify($rfc);

        $query =
            $this->active(
                'sat_cfdi_uses',
                $at,
            );

        if (
            in_array(
                $type,
                [
                    'physical',
                    'generic_national',
                ],
                true,
            )
        ) {
            $query->where(
                'applies_to_physical',
                true,
            );
        } elseif (
            in_array(
                $type,
                [
                    'legal',
                    'generic_foreign',
                ],
                true,
            )
        ) {
            $query->where(
                'applies_to_legal',
                true,
            );
        } else {
            return [];
        }

        return $this->entries(
            $query
                ->get()
                ->filter(
                    function ($row) use ($regime): bool {
                        $regimes =
                            json_decode(
                                $row->receiver_regime_codes ?: '[]',
                                true,
                            ) ?: [];

                        return in_array(
                            $regime,
                            $regimes,
                            true,
                        );
                    }
                )
                ->sortBy('code')
                ->values(),
        );
    }

    public function list(
        string $table,
        CarbonInterface|string|null $at = null,
    ): array {
        return $this->entries(
            $this->active(
                $table,
                $at,
            )
                ->orderBy('code')
                ->get(),
        );
    }

    public function search(
        string $table,
        string $search,
        int $limit = 20,
        CarbonInterface|string|null $at = null,
    ): array {
        $term =
            $this->normalize(
                $search,
            );

        if (mb_strlen($term) < 2) {
            return [];
        }

        $limit =
            min(
                25,
                max(
                    1,
                    $limit,
                ),
            );

        $words =
            array_values(
                array_filter(
                    explode(
                        ' ',
                        $term,
                    ),
                ),
            );

        $rows =
            $this->active(
                $table,
                $at,
            )
            ->where(
                function (Builder $query) use (
                    $term,
                    $words,
                ): void {
                    $query
                        ->where(
                            'code',
                            'like',
                            $term . '%',
                        )
                        ->orWhere(
                            function (Builder $description) use (
                                $words,
                            ): void {
                                foreach ($words as $word) {
                                    $description->where(
                                        'normalized_description',
                                        'like',
                                        '%' . $word . '%',
                                    );
                                }
                            }
                        );
                }
            )
            ->orderByRaw(
                'case
                        when lower(code) = ? then 0
                        when lower(code) like ? then 1
                        else 2
                    end',
                [
                    $term,
                    $term . '%',
                ],
            )
            ->orderBy('description')
            ->limit($limit)
            ->get();

        return $this->entries(
            $rows,
        );
    }

    public function resolve(
        string $table,
        string $code,
        CarbonInterface|string|null $at = null,
    ): ?array {
        $row =
            $this->active(
                $table,
                $at,
            )
            ->where(
                'code',
                strtoupper(
                    trim(
                        $code,
                    ),
                ),
            )
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'code' =>
            $row->code,

            'description' =>
            $row->description,
        ];
    }

    public function resolvePostalCode(
        string $postalCode,
        CarbonInterface|string|null $at = null,
    ): ?array {
        $row = $this->active(
            'sat_postal_codes',
            $at,
        )
            ->where(
                'code',
                trim($postalCode),
            )
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'code' =>
            $row->code,

            'description' =>
            $row->description,

            'state' =>
            $row->state,

            'municipality' =>
            $row->municipality,

            'locality' =>
            $row->locality,

            'border_stimulus' =>
            $row->border_stimulus === null
                ? null
                : (int) $row->border_stimulus,

            'timezone_description' =>
            $row->timezone_description,

            'summer_start_month' =>
            $row->summer_start_month,

            'summer_start_day' =>
            $row->summer_start_day,

            'summer_start_hour' =>
            $row->summer_start_hour,

            'summer_difference' =>
            $row->summer_difference,

            'winter_start_month' =>
            $row->winter_start_month,

            'winter_start_day' =>
            $row->winter_start_day,

            'winter_start_hour' =>
            $row->winter_start_hour,

            'winter_difference' =>
            $row->winter_difference,
        ];
    }

    public function resolveProductService(
        string $code,
        CarbonInterface|string|null $at = null,
    ): ?array {
        $row = $this->active(
            'sat_product_services',
            $at,
        )
            ->where(
                'code',
                strtoupper(
                    trim(
                        $code,
                    ),
                ),
            )
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'code' =>
            $row->code,

            'description' =>
            $row->description,

            'vat_transfer' =>
            $row->vat_transfer === null
                ? null
                : (bool) $row->vat_transfer,

            'ieps_transfer' =>
            $row->ieps_transfer === null
                ? null
                : (bool) $row->ieps_transfer,

            'border_stimulus' =>
            $row->border_stimulus === null
                ? null
                : (int) $row->border_stimulus,
        ];
    }

    public function borderStimulusForPostalCode(
        string $postalCode,
        CarbonInterface|string|null $at = null,
    ): ?int {
        $postalCodeEntry =
            $this->resolvePostalCode(
                $postalCode,
                $at,
            );

        return $postalCodeEntry['border_stimulus']
            ?? null;
    }

    public function borderStimulusForProductService(
        string $code,
        CarbonInterface|string|null $at = null,
    ): ?int {
        $productService =
            $this->resolveProductService(
                $code,
                $at,
            );

        return $productService['border_stimulus']
            ?? null;
    }

    public function assertProfile(
        array $data,
        CarbonInterface|string|null $at = null,
    ): void {
        $type =
            $this->rfcs->classify(
                $data['rfc'],
            );

        $errors = [];

        if (
            ! in_array(
                $type,
                [
                    'physical',
                    'legal',
                ],
                true,
            )
        ) {
            $errors['rfc'] = [
                'El RFC del emisor debe pertenecer a una persona física o moral.',
            ];
        }

        if (
            ! collect(
                $this->regimes(
                    $data['rfc'],
                    $at,
                ),
            )->contains(
                'code',
                $data['tax_regime_code'],
            )
        ) {
            $errors['tax_regime_code'] = [
                'El régimen fiscal no está vigente o no es compatible con el RFC.',
            ];
        }

        if (
            ! $this->resolvePostalCode(
                $data['postal_code'],
                $at,
            )
        ) {
            $errors['postal_code'] = [
                'El código postal no corresponde a un código postal fiscal vigente del SAT.',
            ];
        }

        foreach (
            [
                'default_product_service_code' =>
                'sat_product_services',

                'default_unit_code' =>
                'sat_units',

                'default_tax_object_code' =>
                'sat_tax_objects',
            ] as $field => $table
        ) {
            if (
                ! $this->resolve(
                    $table,
                    $data[$field],
                    $at,
                )
            ) {
                $errors[$field] = [
                    'La clave seleccionada no existe o no está vigente en el catálogo SAT.',
                ];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages(
                $errors,
            );
        }
    }

    public function assertProduct(
        array $data,
        CarbonInterface|string|null $at = null,
    ): void {
        $errors = [];

        foreach (
            [
                'sat_product_service_code' =>
                'sat_product_services',

                'sat_unit_code' =>
                'sat_units',

                'sat_tax_object_code' =>
                'sat_tax_objects',
            ] as $field => $table
        ) {
            if (
                array_key_exists(
                    $field,
                    $data,
                )
                && $data[$field] !== null
                && $data[$field] !== ''
                && ! $this->resolve(
                    $table,
                    $data[$field],
                    $at,
                )
            ) {
                $errors[$field] = [
                    'La clave seleccionada no existe o no está vigente en el catálogo SAT.',
                ];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages(
                $errors,
            );
        }
    }

    public function assertReceiver(
        array $data,
        CarbonInterface|string|null $at = null,
    ): void {
        $errors = [];

        if (
            $this->rfcs->classify(
                $data['rfc'],
            ) === 'invalid'
        ) {
            $errors['rfc'] = [
                'El RFC no es válido.',
            ];
        }

        if (
            ! collect(
                $this->regimesForReceiver(
                    $data['rfc'],
                    $at,
                ),
            )->contains(
                'code',
                $data['tax_regime_code'],
            )
        ) {
            $errors['tax_regime_code'] = [
                'El régimen fiscal no es compatible con el RFC.',
            ];
        }

        if (
            ! $this->resolvePostalCode(
                $data['postal_code'],
                $at,
            )
        ) {
            $errors['postal_code'] = [
                'El código postal fiscal no está vigente.',
            ];
        }

        if (
            ! collect(
                $this->cfdiUses(
                    $data['rfc'],
                    $data['tax_regime_code'],
                    $at,
                ),
            )->contains(
                'code',
                $data['cfdi_use_code'],
            )
        ) {
            $errors['cfdi_use_code'] = [
                'El uso de CFDI no es compatible con el receptor.',
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages(
                $errors,
            );
        }
    }

    public function regimesForReceiver(
        string $rfc,
        CarbonInterface|string|null $at = null,
    ): array {
        $type =
            $this->rfcs->classify(
                $rfc,
            );

        if (
            $type === 'generic_national'
            || $type === 'generic_foreign'
        ) {
            $entry =
                $this->resolve(
                    'sat_tax_regimes',
                    '616',
                    $at,
                );

            return $entry
                ? [$entry]
                : [];
        }

        return $this->regimes(
            $rfc,
            $at,
        );
    }

    private function active(
        string $table,
        CarbonInterface|string|null $at,
    ): Builder {
        $date =
            $at instanceof CarbonInterface
            ? $at->toDateString()
            : (
                $at
                ?: now()->toDateString()
            );

        return DB::table($table)
            ->where(
                'catalog_version_id',
                $this->activeVersion()->id,
            )
            ->where(
                fn(Builder $query) =>
                $query
                    ->whereNull('valid_from')
                    ->orWhere(
                        'valid_from',
                        '<=',
                        $date,
                    ),
            )
            ->where(
                fn(Builder $query) =>
                $query
                    ->whereNull('valid_until')
                    ->orWhere(
                        'valid_until',
                        '>=',
                        $date,
                    ),
            );
    }

    private function entries(
        iterable $rows,
    ): array {
        return collect($rows)
            ->map(
                fn($row) => [
                    'code' =>
                    $row->code,

                    'description' =>
                    $row->description,
                ],
            )
            ->values()
            ->all();
    }

    public function normalize(
        string $value,
    ): string {
        return preg_replace(
            '/\s+/',
            ' ',
            strtolower(
                Str::ascii(
                    trim(
                        $value,
                    ),
                ),
            ),
        ) ?: '';
    }
}
