<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Services\Pos\ModifierService;
use App\Services\Pos\TenantWriteTransaction;
use App\Services\Subscriptions\CatalogAccessService;
use App\Services\Subscriptions\EntitlementService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ModifierController extends Controller
{
    public function index(Request $r)
    {
        abort_unless($r->user()->can('products.view'), 403);
        $t = app(CurrentTenant::class)->get();

        return response()->json(['data' => DB::table('menu_modifier_groups')->where('tenant_id', $t->id)->orderBy('name')->get()->map(fn ($g) => $this->resource($g)), 'can_manage' => $r->user()->can('products.update')]);
    }

    private function resource(object $g): array
    {
        return ['id' => $g->public_id, 'name' => $g->name, 'kind' => $g->kind, 'version' => $g->version, 'is_active' => (bool) $g->is_active, 'options' => json_decode($g->options, true)];
    }

    public function save(Request $r, ?string $group = null)
    {
        abort_unless($r->user()->can('products.update'), 403);
        $t = app(CurrentTenant::class)->get();
        $data = $r->validate([
            'name' => ['required', 'string', 'max:100'], 'kind' => ['required', Rule::in(['size', 'extras'])],
            'is_active' => ['required', 'boolean'], 'version' => [$group ? 'required' : 'nullable', 'integer', 'min:1'],
            'options' => ['required', 'array', 'min:1', 'max:30'], 'options.*' => ['array:id,name'],
            'options.*.id' => ['nullable', 'uuid', 'distinct'], 'options.*.name' => ['required', 'string', 'max:100', 'distinct'],
            'tenant_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'user_id' => ['prohibited'],
        ]);

        return app(TenantWriteTransaction::class)->run($t, function () use ($t, $data, $group) {
            app(EntitlementService::class)->assertWritable($t);
            $old = $group ? DB::table('menu_modifier_groups')->where('tenant_id', $t->id)->where('public_id', $group)->first() : null;
            abort_if($group && ! $old, 404);
            abort_if($old && $old->version !== $data['version'], 409, 'El grupo cambió. Actualiza antes de guardar.');
            if ($old && $old->kind !== $data['kind']) {
                throw ValidationException::withMessages(['kind' => 'El tipo del grupo no puede cambiar.']);
            }
            $oldIds = $old ? array_column(json_decode($old->options, true), 'id') : [];
            $options = [];
            foreach ($data['options'] as $o) {
                if (! empty($o['id']) && ! in_array($o['id'], $oldIds, true)) {
                    abort(422, 'La opción no pertenece al grupo.');
                }
                $options[] = ['id' => $o['id'] ?? (string) Str::uuid(), 'name' => $o['name']];
            }
            // Referenced choices cannot disappear silently from required product groups.
            if ($old && array_diff($oldIds, array_column($options, 'id')) && DB::table('menu_product_modifiers')->where('tenant_id', $t->id)->where('group_id', $old->id)->exists()) {
                throw ValidationException::withMessages(['options' => 'Desvincula el grupo de sus productos antes de quitar opciones.']);
            }
            $id = $group ?? (string) Str::uuid();
            DB::table('menu_modifier_groups')->updateOrInsert(['tenant_id' => $t->id, 'public_id' => $id], ['name' => $data['name'], 'kind' => $data['kind'], 'is_active' => $data['is_active'], 'version' => ($old->version ?? 0) + 1, 'options' => json_encode($options), 'created_at' => $old->created_at ?? now(), 'updated_at' => now()]);

            return response()->json(['data' => $this->resource(DB::table('menu_modifier_groups')->where('tenant_id', $t->id)->where('public_id', $id)->first())]);
        });
    }

    public function product(Request $r, string $product)
    {
        abort_unless($r->user()->can($r->isMethod('GET') ? 'products.view' : 'products.update'), 403);
        $t = app(CurrentTenant::class)->get();
        $p = $t->products()->where('public_id', $product)->firstOrFail();
        $service = app(ModifierService::class);
        if ($r->isMethod('GET')) {
            return response()->json(['data' => $service->groups($t, $p)]);
        }
        $data = $r->validate([
            'groups' => ['present', 'array', 'max:20'], 'groups.*' => ['array:id,min,max,options'],
            'groups.*.id' => ['required', 'uuid', 'distinct'], 'groups.*.min' => ['required', 'integer', 'min:0', 'max:30'], 'groups.*.max' => ['required', 'integer', 'min:1', 'max:30'],
            'groups.*.options' => ['required', 'array', 'min:1', 'max:30'], 'groups.*.options.*' => ['array:id,price,effect,target_type,target_id,quantity,source_type,source_id,source_quantity'],
            'groups.*.options.*.id' => ['required', 'uuid'], 'groups.*.options.*.price' => ['required', 'numeric', 'min:0', 'max:999999', 'decimal:0,2'],
            'groups.*.options.*.effect' => ['required', Rule::in(['none', 'add', 'remove', 'replace', 'swap'])],
            'groups.*.options.*.target_type' => ['nullable', Rule::in(['supply', 'recipe'])], 'groups.*.options.*.target_id' => ['nullable', 'uuid'],
            'groups.*.options.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999', 'decimal:0,6'],
            'groups.*.options.*.source_type' => ['nullable', Rule::in(['supply', 'recipe'])], 'groups.*.options.*.source_id' => ['nullable', 'uuid'],
            'groups.*.options.*.source_quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999', 'decimal:0,6'],
            'tenant_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'user_id' => ['prohibited'], 'product_id' => ['prohibited'],
        ]);

        return app(TenantWriteTransaction::class)->run($t, function () use ($t, $p, $data, $service) {
            app(EntitlementService::class)->assertWritable($t);
            $p = $t->products()->whereKey($p->id)->firstOrFail();
            app(CatalogAccessService::class)->assertProductMutable($p);
            foreach ($service->groups($t, $p) as $existing) {
                foreach ($existing['options'] as $option) {
                    if (($option['effect'] ?? 'none') !== 'none') {
                        abort_unless(auth()->user()->can('inventory.manage'), 403);
                    }
                }
            }
            $sizeCount = 0;
            foreach ($data['groups'] as $a) {
                $g = DB::table('menu_modifier_groups')->where('tenant_id', $t->id)->where('public_id', $a['id'])->where('is_active', true)->first();
                abort_unless($g, 422, 'El grupo no pertenece a este negocio o está inactivo.');
                $ids = array_column($a['options'], 'id');
                abort_if(count($ids) !== count(array_unique($ids)) || array_diff($ids, array_column(json_decode($g->options, true), 'id')), 422, 'Opciones inválidas para este grupo.');
                abort_if($a['min'] > $a['max'] || $a['max'] > count($ids), 422, 'Revisa el mínimo y máximo de opciones.');
                if ($g->kind === 'size') {
                    $sizeCount++;
                    abort_if($sizeCount > 1 || $a['min'] !== 1 || $a['max'] !== 1, 422, 'Asigna un solo grupo de tamaño con una selección obligatoria.');
                }
                foreach ($a['options'] as $o) {
                    if ($o['effect'] !== 'none') {
                        abort_unless(! empty($o['target_type']) && ! empty($o['target_id']) && isset($o['quantity']), 422, 'Completa el consumo de la opción.');
                    }
                    if ($o['effect'] === 'swap') {
                        abort_unless(! empty($o['source_type']) && ! empty($o['source_id']) && isset($o['source_quantity']), 422, 'Completa el ingrediente que se retira.');
                    }
                    $service->validateEffect($t, $o, $g->kind);
                }
            }
            DB::table('menu_product_modifiers')->where('tenant_id', $t->id)->where('product_id', $p->id)->delete();
            foreach ($data['groups'] as $a) {
                $gid = DB::table('menu_modifier_groups')->where('tenant_id', $t->id)->where('public_id', $a['id'])->value('id');
                DB::table('menu_product_modifiers')->insert(['tenant_id' => $t->id, 'product_id' => $p->id, 'group_id' => $gid, 'min_selections' => $a['min'], 'max_selections' => $a['max'], 'options' => json_encode($a['options']), 'created_at' => now(), 'updated_at' => now()]);
            }

            return response()->json(['data' => $service->groups($t,$p)]);
        });
    }
}
