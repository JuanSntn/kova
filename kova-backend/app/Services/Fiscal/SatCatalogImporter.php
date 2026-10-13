<?php

namespace App\Services\Fiscal;

use App\Models\SatCatalogVersion;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use RuntimeException;
use Throwable;

final class SatCatalogImporter
{
    private const TABLES = [
        'sat_tax_regimes' => [
            'source' => 'cfdi_40_regimenes_fiscales',
            'minimum' => 10,
            'columns' => [
                'id',
                'texto',
                'aplica_fisica',
                'aplica_moral',
                'vigencia_desde',
                'vigencia_hasta',
            ],
        ],

        'sat_postal_codes' => [
            'source' => 'cfdi_40_codigos_postales',
            'minimum' => 90000,
            'columns' => [
                'id',
                'estado',
                'municipio',
                'localidad',
                'estimulo_frontera',
                'vigencia_desde',
                'vigencia_hasta',
                'huso_descripcion',
                'huso_verano_mes_inicio',
                'huso_verano_dia_inicio',
                'huso_verano_hora_inicio',
                'huso_verano_diferencia',
                'huso_invierno_mes_inicio',
                'huso_invierno_dia_inicio',
                'huso_invierno_hora_inicio',
                'huso_invierno_diferencia',
            ],
        ],

        'sat_product_services' => [
            'source' => 'cfdi_40_productos_servicios',
            'minimum' => 50000,
            'columns' => [
                'id',
                'texto',
                'iva_trasladado',
                'ieps_trasladado',
                'estimulo_frontera',
                'vigencia_desde',
                'vigencia_hasta',
            ],
        ],

        'sat_units' => [
            'source' => 'cfdi_40_claves_unidades',
            'minimum' => 2000,
            'columns' => [
                'id',
                'texto',
                'descripcion',
                'vigencia_desde',
                'vigencia_hasta',
                'simbolo',
            ],
        ],

        'sat_tax_objects' => [
            'source' => 'cfdi_40_objetos_impuestos',
            'minimum' => 4,
            'columns' => [
                'id',
                'texto',
                'vigencia_desde',
                'vigencia_hasta',
            ],
        ],

        'sat_cfdi_uses' => [
            'source' => 'cfdi_40_usos_cfdi',
            'minimum' => 20,
            'columns' => [
                'id',
                'texto',
                'aplica_fisica',
                'aplica_moral',
                'vigencia_desde',
                'vigencia_hasta',
                'regimenes_fiscales_receptores',
            ],
        ],

        'sat_payment_forms' => [
            'source' => 'cfdi_40_formas_pago',
            'minimum' => 20,
            'columns' => [
                'id',
                'texto',
                'es_bancarizado',
                'requiere_numero_operacion',
                'vigencia_desde',
                'vigencia_hasta',
            ],
        ],

        'sat_payment_methods' => [
            'source' => 'cfdi_40_metodos_pago',
            'minimum' => 2,
            'columns' => [
                'id',
                'texto',
                'vigencia_desde',
                'vigencia_hasta',
            ],
        ],

        'sat_taxes' => [
            'source' => 'cfdi_40_impuestos',
            'minimum' => 3,
            'columns' => [
                'id',
                'texto',
                'retencion',
                'traslado',
                'ambito',
                'vigencia_desde',
                'vigencia_hasta',
            ],
        ],

        'sat_factor_types' => [
            'source' => 'cfdi_40_tipos_factores',
            'minimum' => 3,
            'columns' => [
                'id',
                'vigencia_desde',
                'vigencia_hasta',
            ],
        ],

        'sat_rate_rules' => [
            'source' => 'cfdi_40_reglas_tasa_cuota',
            'minimum' => 10,
            'columns' => [
                'tipo',
                'minimo',
                'valor',
                'impuesto',
                'factor',
                'traslado',
                'retencion',
                'vigencia_desde',
                'vigencia_hasta',
            ],
        ],
    ];

    public function release(string $version): array
    {
        if (! preg_match(
            '/^v\d+\.\d+\.\d{8}$/',
            $version,
        )) {
            throw new RuntimeException(
                'La versión SAT no tiene un formato válido.',
            );
        }

        $release = Http::acceptJson()
            ->timeout(20)
            ->get(
                "https://api.github.com/repos/phpcfdi/resources-sat-catalogs/releases/tags/{$version}",
            )
            ->throw()
            ->json();

        $asset = collect(
            $release['assets'] ?? [],
        )->firstWhere(
            'name',
            'catalogs.db.bz2',
        );

        if (
            ! $asset
            || ! str_starts_with(
                (string) ($asset['digest'] ?? ''),
                'sha256:',
            )
        ) {
            throw new RuntimeException(
                'La release no publica el asset o su digest SHA-256.',
            );
        }

        return [
            'version' => $version,
            'published_at' => $release['published_at'] ?? null,
            'url' => $asset['browser_download_url'],
            'sha256' => substr($asset['digest'], 7),
        ];
    }

    public function latest(): array
    {
        $release = Http::acceptJson()
            ->timeout(20)
            ->get(
                'https://api.github.com/repos/phpcfdi/resources-sat-catalogs/releases/latest',
            )
            ->throw()
            ->json();

        return [
            'version' => $release['tag_name'],
            'published_at' => $release['published_at'] ?? null,
        ];
    }

    public function sync(
        string $version,
    ): SatCatalogVersion {
        $meta = $this->release(
            $version,
        );

        $record = SatCatalogVersion::query()
            ->firstOrNew([
                'version' => $version,
            ]);

        if ($record->status === 'active') {
            return $record;
        }

        $record
            ->fill([
                'status' => 'downloading',
                'active_slot' => null,
                'published_at' => $meta['published_at'],
                'asset_url' => $meta['url'],
                'asset_sha256' => $meta['sha256'],
                'started_at' => now(),
                'error_message' => null,
            ])
            ->save();

        [$compressed, $sqlite] =
            $this->temporaryFiles();

        try {
            $source = $this->downloadSource(
                $meta,
                $compressed,
                $sqlite,
            );

            $counts = $this->validateSource(
                $source,
            );

            $record->update([
                'status' => 'importing',
                'counts' => $counts,
            ]);

            $this->clearVersion(
                $record->id,
            );

            foreach (
                self::TABLES as $target => $definition
            ) {
                $this->importTable(
                    $source,
                    $record->id,
                    $target,
                    $definition['source'],
                );
            }

            $record->update([
                'status' => 'validated',
                'imported_at' => now(),
                'validated_at' => now(),
            ]);

            DB::transaction(
                function () use ($record): void {
                    SatCatalogVersion::query()
                        ->where(
                            'active_slot',
                            1,
                        )
                        ->lockForUpdate()
                        ->update([
                            'status' => 'retired',
                            'active_slot' => null,
                        ]);

                    $record
                        ->refresh()
                        ->update([
                            'status' => 'active',
                            'active_slot' => 1,
                            'activated_at' => now(),
                        ]);
                },
                3,
            );

            return $record->refresh();
        } catch (Throwable $e) {
            $record->update([
                'status' => 'failed',
                'active_slot' => null,
                'error_message' => mb_substr(
                    $e->getMessage(),
                    0,
                    500,
                ),
            ]);

            throw $e;
        } finally {
            @unlink($compressed);
            @unlink($sqlite);
        }
    }

    public function refreshActivePostalCodes(): SatCatalogVersion
    {
        $record = SatCatalogVersion::query()
            ->where('active_slot', 1)
            ->where('status', 'active')
            ->first();

        if (! $record) {
            throw new RuntimeException(
                'No existe una versión SAT activa.',
            );
        }

        $meta = $this->release(
            $record->version,
        );

        [$compressed, $sqlite] =
            $this->temporaryFiles();

        try {
            $source = $this->downloadSource(
                $meta,
                $compressed,
                $sqlite,
            );

            $definition =
                self::TABLES['sat_postal_codes'];

            $this->validateTable(
                $source,
                'sat_postal_codes',
                $definition,
            );

            DB::transaction(
                function () use (
                    $source,
                    $record,
                    $definition,
                ): void {
                    DB::table(
                        'sat_postal_codes',
                    )
                        ->where(
                            'catalog_version_id',
                            $record->id,
                        )
                        ->delete();

                    $this->importTable(
                        $source,
                        $record->id,
                        'sat_postal_codes',
                        $definition['source'],
                    );
                },
                3,
            );

            return $record->refresh();
        } finally {
            @unlink($compressed);
            @unlink($sqlite);
        }
    }

    public function refreshActiveBorderStimulusCatalogs(): SatCatalogVersion
    {
        $record = SatCatalogVersion::query()
            ->where('active_slot', 1)
            ->where('status', 'active')
            ->first();

        if (! $record) {
            throw new RuntimeException(
                'No existe una versión SAT activa.',
            );
        }

        $meta = $this->release(
            $record->version,
        );

        [$compressed, $sqlite] =
            $this->temporaryFiles();

        try {
            $source = $this->downloadSource(
                $meta,
                $compressed,
                $sqlite,
            );

            $targets = [
                'sat_postal_codes',
                'sat_product_services',
            ];

            foreach ($targets as $target) {
                $this->validateTable(
                    $source,
                    $target,
                    self::TABLES[$target],
                );
            }

            DB::transaction(
                function () use (
                    $source,
                    $record,
                    $targets,
                ): void {
                    foreach ($targets as $target) {
                        $definition =
                            self::TABLES[$target];

                        DB::table(
                            $target,
                        )
                            ->where(
                                'catalog_version_id',
                                $record->id,
                            )
                            ->delete();

                        $this->importTable(
                            $source,
                            $record->id,
                            $target,
                            $definition['source'],
                        );
                    }
                },
                3,
            );

            return $record->refresh();
        } finally {
            @unlink($compressed);
            @unlink($sqlite);
        }
    }

    private function validateSource(
        PDO $source,
    ): array {
        $counts = [];

        foreach (
            self::TABLES as $target => $definition
        ) {
            $counts[$target] =
                $this->validateTable(
                    $source,
                    $target,
                    $definition,
                );
        }

        return $counts;
    }

    private function validateTable(
        PDO $source,
        string $target,
        array $definition,
    ): int {
        $columns = $source
            ->query(
                'PRAGMA table_info('
                    . $definition['source']
                    . ')',
            )
            ->fetchAll(
                PDO::FETCH_ASSOC,
            );

        if (! $columns) {
            throw new RuntimeException(
                "Falta la tabla {$definition['source']}.",
            );
        }

        $available = array_column(
            $columns,
            'name',
        );

        $missing = array_values(
            array_diff(
                $definition['columns'],
                $available,
            ),
        );

        if ($missing) {
            throw new RuntimeException(
                sprintf(
                    '%s no contiene las columnas requeridas: %s.',
                    $definition['source'],
                    implode(', ', $missing),
                ),
            );
        }

        $count = (int) $source
            ->query(
                'SELECT COUNT(*) FROM '
                    . $definition['source'],
            )
            ->fetchColumn();

        if (
            $count
            < $definition['minimum']
        ) {
            throw new RuntimeException(
                "{$definition['source']} no cumple el conteo mínimo.",
            );
        }

        return $count;
    }

    private function importTable(
        PDO $source,
        int $versionId,
        string $target,
        string $sourceTable,
    ): void {
        $statement = $source->query(
            'SELECT * FROM ' . $sourceTable,
        );

        $batch = [];

        while (
            $row = $statement->fetch(
                PDO::FETCH_ASSOC,
            )
        ) {
            $batch[] = $this->map(
                $target,
                $versionId,
                $row,
            );

            if (count($batch) >= 1000) {
                DB::table($target)
                    ->insert($batch);

                $batch = [];
            }
        }

        if ($batch) {
            DB::table($target)
                ->insert($batch);
        }
    }

    private function map(
        string $target,
        int $versionId,
        array $r,
    ): array {
        $validity = [
            'valid_from' => $this->date(
                $r['vigencia_desde'] ?? null,
            ),

            'valid_until' => $this->date(
                $r['vigencia_hasta'] ?? null,
            ),
        ];

        if ($target === 'sat_rate_rules') {
            return [
                'catalog_version_id' => $versionId,
                'rule_type' => $r['tipo'],
                'minimum' => $r['minimo'] === ''
                    ? null
                    : $r['minimo'],
                'value' => $r['valor'],
                'tax' => $r['impuesto'],
                'factor' => $r['factor'],
                'allows_transfer' => (bool) $r['traslado'],
                'allows_withholding' => (bool) $r['retencion'],
                ...$validity,
            ];
        }

        $base = [
            'catalog_version_id' => $versionId,
            'code' => $r['id'],
            'description' => $r['texto'] ?? $r['id'],

            'normalized_description' =>
            app(SatCatalogService::class)
                ->normalize(
                    $r['texto'] ?? $r['id'],
                ),

            ...$validity,
        ];

        return match ($target) {
            'sat_tax_regimes' => [
                ...$base,
                'applies_to_physical' =>
                (bool) $r['aplica_fisica'],
                'applies_to_legal' =>
                (bool) $r['aplica_moral'],
            ],

            'sat_postal_codes' => [
                ...$base,
                'state' =>
                $this->nullable(
                    $r['estado'],
                ),
                'municipality' =>
                $this->nullable(
                    $r['municipio'],
                ),
                'locality' =>
                $this->nullable(
                    $r['localidad'],
                ),
                'border_stimulus' =>
                (int) $r['estimulo_frontera'],
                'timezone_description' =>
                $this->nullable(
                    $r['huso_descripcion'],
                ),
                'summer_start_month' =>
                $this->nullable(
                    $r['huso_verano_mes_inicio'],
                ),
                'summer_start_day' =>
                $this->nullable(
                    $r['huso_verano_dia_inicio'],
                ),
                'summer_start_hour' =>
                $this->nullable(
                    $r['huso_verano_hora_inicio'],
                ),
                'summer_difference' =>
                $this->nullable(
                    $r['huso_verano_diferencia'],
                ),
                'winter_start_month' =>
                $this->nullable(
                    $r['huso_invierno_mes_inicio'],
                ),
                'winter_start_day' =>
                $this->nullable(
                    $r['huso_invierno_dia_inicio'],
                ),
                'winter_start_hour' =>
                $this->nullable(
                    $r['huso_invierno_hora_inicio'],
                ),
                'winter_difference' =>
                $this->nullable(
                    $r['huso_invierno_diferencia'],
                ),
            ],

            'sat_product_services' => [
                ...$base,
                'vat_transfer' =>
                $r['iva_trasladado'] === ''
                    ? null
                    : (bool) $r['iva_trasladado'],
                'ieps_transfer' =>
                $r['ieps_trasladado'] === ''
                    ? null
                    : (bool) $r['ieps_trasladado'],
                'border_stimulus' =>
                (int) $r['estimulo_frontera'],
            ],

            'sat_units' => [
                ...$base,
                'details' =>
                $this->nullable(
                    $r['descripcion'],
                ),
                'symbol' =>
                $this->nullable(
                    $r['simbolo'],
                ),
            ],

            'sat_cfdi_uses' => [
                ...$base,
                'applies_to_physical' =>
                (bool) $r['aplica_fisica'],
                'applies_to_legal' =>
                (bool) $r['aplica_moral'],

                'receiver_regime_codes' =>
                json_encode(
                    array_values(
                        array_filter(
                            array_map(
                                'trim',
                                explode(
                                    ',',
                                    $r['regimenes_fiscales_receptores'],
                                ),
                            ),
                        ),
                    ),
                ),
            ],

            'sat_payment_forms' => [
                ...$base,
                'banked' =>
                (bool) $r['es_bancarizado'],
                'requires_operation_number' =>
                (bool) $r['requiere_numero_operacion'],
            ],

            'sat_taxes' => [
                ...$base,
                'allows_withholding' =>
                (bool) $r['retencion'],
                'allows_transfer' =>
                (bool) $r['traslado'],
                'scope' =>
                $this->nullable(
                    $r['ambito'],
                ),
            ],

            default => $base,
        };
    }

    private function downloadSource(
        array $meta,
        string $compressed,
        string $sqlite,
    ): PDO {
        $response = Http::timeout(180)
            ->withOptions([
                'sink' => $compressed,
            ])
            ->get(
                $meta['url'],
            );

        if (! $response->successful()) {
            throw new RuntimeException(
                'No se pudo descargar el catálogo SAT.',
            );
        }

        if (
            ! hash_equals(
                $meta['sha256'],
                hash_file(
                    'sha256',
                    $compressed,
                ),
            )
        ) {
            throw new RuntimeException(
                'El digest SHA-256 del catálogo no coincide.',
            );
        }

        $this->decompress(
            $compressed,
            $sqlite,
        );

        $source = new PDO(
            'sqlite:' . $sqlite,
            null,
            null,
            [
                PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,
            ],
        );

        if (
            $source
            ->query(
                'PRAGMA integrity_check',
            )
            ->fetchColumn()
            !== 'ok'
        ) {
            throw new RuntimeException(
                'SQLite no superó la comprobación de integridad.',
            );
        }

        return $source;
    }

    private function temporaryFiles(): array
    {
        $compressed = tempnam(
            sys_get_temp_dir(),
            'sat-catalog-',
        );

        if ($compressed === false) {
            throw new RuntimeException(
                'No se pudo crear el archivo temporal del catálogo.',
            );
        }

        return [
            $compressed,
            $compressed . '.sqlite',
        ];
    }

    private function decompress(
        string $source,
        string $target,
    ): void {
        if (! function_exists('bzopen')) {
            throw new RuntimeException(
                'La extensión PHP bz2 es obligatoria para importar catálogos SAT.',
            );
        }

        $in = bzopen(
            $source,
            'r',
        );

        $out = fopen(
            $target,
            'wb',
        );

        if (! $in || ! $out) {
            throw new RuntimeException(
                'No se pudo preparar el archivo del catálogo.',
            );
        }

        while (! feof($in)) {
            fwrite(
                $out,
                bzread(
                    $in,
                    1024 * 1024,
                ),
            );
        }

        bzclose($in);
        fclose($out);
    }

    private function date(
        ?string $value,
    ): ?string {
        if (! $value) {
            return null;
        }

        return (
            new DateTimeImmutable(
                $value,
            )
        )->format('Y-m-d');
    }

    private function nullable(
        mixed $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim(
            (string) $value,
        );

        return $value === ''
            ? null
            : $value;
    }

    private function clearVersion(
        int $versionId,
    ): void {
        foreach (
            array_keys(
                self::TABLES,
            ) as $table
        ) {
            DB::table($table)
                ->where(
                    'catalog_version_id',
                    $versionId,
                )
                ->delete();
        }
    }
}
