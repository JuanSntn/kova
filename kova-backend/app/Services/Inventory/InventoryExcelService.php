<?php

namespace App\Services\Inventory;

use App\Http\Requests\Inventory\SaveSupplyRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pos\TenantWriteTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

class InventoryExcelService
{
    public const HEADERS = ['Insumo', 'Categoría', 'Unidad base', 'Mínimo', 'Ideal', 'Producido (si/no)', 'Presentación', 'Contenido', 'Unidad contenido', 'Proveedor', 'Código proveedor', 'Precio sin impuesto', 'Impuesto UUID'];

    public function export(Tenant $t, bool $template): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kova-inventory-');
        $writer = new Writer;
        try {
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues(self::HEADERS));
            if (! $template) {
                foreach (DB::table('inventory_supplies')->where('tenant_id', $t->id)->orderBy('name')->cursor() as $s) {
                    $data = app(SupplyService::class)->resource($s);
                    $presentations = $data['presentations'] ?: [null];
                    foreach ($presentations as $p) {
                        $values = [$s->name, $s->category, $s->base_unit, $s->minimum ?? '', $s->ideal ?? '', $s->produced ? 'si' : 'no',
                            $p['name'] ?? '', $p['quantity'] ?? '', $p['unit'] ?? '', $p['supplier'] ?? '', $p['supplierCode'] ?? '', $p['price'] ?? '', $p['tax'] ?? ''];
                        // Explicit strings prevent spreadsheet formula injection.
                        $writer->addRow(new Row(array_map(fn ($v) => new StringCell((string) $v, null), $values)));
                    }
                }
            }
            $writer->close();
        } catch (\Throwable $e) {
            $writer->close();
            @unlink($path);
            throw $e;
        }

        return $path;
    }

    public function preview(Tenant $t, User $u, string $path): array
    {
        // Bound inflated zip size before reading shared strings/cells.
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'El archivo no es un Excel válido.']);
        }
        $size = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $size += $zip->statIndex($i)['size'];
        }
        $zip->close();
        if ($size > 30 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'El contenido del archivo supera 30 MB.']);
        }
        $reader = new Reader;
        $groups = [];
        $count = 0;
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $count++;
                    if ($count > 2001) {
                        throw ValidationException::withMessages(['file' => 'Importa como máximo 2,000 filas por archivo.']);
                    }
                    foreach ($row->getCells() as $cell) {
                        if ($cell instanceof FormulaCell) {
                            throw ValidationException::withMessages(['file' => "Fila $count: usa valores, no fórmulas."]);
                        }
                    }
                    $values = array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $row->toArray());
                    if ($count === 1) {
                        if (array_slice($values, 0, 13) !== self::HEADERS) {
                            throw ValidationException::withMessages(['file' => 'Usa los encabezados de la plantilla sin modificarlos.']);
                        }

                        continue;
                    }
                    if (! array_filter($values, fn ($v) => $v !== '')) {
                        continue;
                    }
                    $v = array_pad($values, 13, '');
                    if (! in_array(mb_strtolower($v[5]), ['si', 'sí', 'no'], true)) {
                        throw ValidationException::withMessages(['file' => "Fila $count: Producido debe ser si o no."]);
                    }
                    $normal = mb_strtolower($v[0]);
                    $base = ['name' => $v[0], 'category' => $v[1], 'unit' => $v[2], 'minimum' => $v[3] === '' ? null : $v[3], 'ideal' => $v[4] === '' ? null : $v[4], 'produced' => mb_strtolower($v[5]) !== 'no'];
                    if (isset($groups[$normal]) && array_intersect_key($groups[$normal], $base) !== $base) {
                        throw ValidationException::withMessages(['file' => "Fila $count: los datos del insumo no coinciden entre presentaciones."]);
                    }
                    $groups[$normal] ??= $base + ['id' => (string) Str::uuid(), 'presentations' => []];
                    if ($v[6] !== '') {
                        $groups[$normal]['presentations'][] = ['id' => (string) Str::uuid(), 'name' => $v[6], 'quantity' => $v[7], 'unit' => $v[8], 'supplier' => $v[9], 'supplierCode' => $v[10], 'price' => $v[11] === '' ? null : $v[11], 'tax' => $v[12] === '' ? null : $v[12]];
                    }
                }
                break; // Template defines one sheet.
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo Excel. Usa la plantilla .xlsx.']);
        } finally {
            $reader->close();
        }
        if (! $groups) {
            throw ValidationException::withMessages(['file' => 'El archivo no contiene insumos.']);
        }
        $rules = new SaveSupplyRequest;
        $rules->setMethod('POST');
        foreach ($groups as $g) {
            Validator::make($g, $rules->rules(), $rules->messages())->validate();
            foreach ($g['presentations'] as $p) {
                app(InventoryCatalogService::class)->convert($p['quantity'], $p['unit'], $g['unit']);
            }
        }

        return app(TenantWriteTransaction::class)->run($t, function () use ($t, $u, $path, $groups) {
            app(SupplyService::class)->access($t, true);
            $hash = hash_file('sha256', $path);
            $existing = DB::table('inventory_imports')->where('tenant_id', $t->id)->where('file_hash', $hash)->first();
            if ($existing) {
                abort_unless((int) $existing->user_id === (int) $u->id, 409, 'Este archivo ya fue revisado por otro usuario.');

                return ['id' => $existing->public_id, 'count' => $existing->rows_count, 'committed' => $existing->committed_at !== null, 'items' => array_column(json_decode($existing->payload, true), 'name')];
            }
            $id = (string) Str::uuid();
            DB::table('inventory_imports')->insert(['public_id' => $id, 'tenant_id' => $t->id, 'user_id' => $u->id, 'file_hash' => $hash, 'payload' => json_encode(array_values($groups)), 'rows_count' => count($groups), 'created_at' => now()]);

            return ['id' => $id, 'count' => count($groups), 'committed' => false, 'items' => array_column($groups, 'name')];
        }, 'INVENTORY_RETRY');
    }

    public function commit(Tenant $t, User $u, string $id): array
    {
        return app(TenantWriteTransaction::class)->run($t, function () use ($t, $u, $id) {
            app(SupplyService::class)->access($t, true);
            $import = DB::table('inventory_imports')->where('tenant_id', $t->id)->where('user_id', $u->id)->where('public_id', $id)->first();
            abort_unless($import, 404);
            if (! $import->committed_at) {
                $existing = DB::table('inventory_supplies')->where('tenant_id', $t->id)->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
                foreach (json_decode($import->payload, true) as $supply) {
                    if (in_array(mb_strtolower(trim($supply['name'])), $existing, true)) {
                        throw ValidationException::withMessages(['file' => 'Ya existe el insumo '.$supply['name'].'. La importación sólo agrega insumos nuevos; no se guardó ninguna fila.']);
                    }
                    app(SupplyService::class)->save($t, $supply, null);
                }
                DB::table('inventory_imports')->where('id', $import->id)->update(['committed_at' => now()]);
            }

            return ['id' => $id, 'count' => $import->rows_count, 'committed' => true];
        }, 'INVENTORY_RETRY');
    }
}
