<?php

namespace App\Services\Cash;

use App\Exceptions\DomainConflictException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Branches\BranchAccessService;
use App\Services\Pos\TenantWriteTransaction;
use App\Services\Subscriptions\EntitlementService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CashService
{
    public function __construct(private EntitlementService $entitlements, private BranchAccessService $access, private TenantWriteTransaction $writes) {}

    private function fail(string $code, string $message): never
    {
        throw new DomainConflictException($message, $code);
    }

    private function money(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(2);
    }

    private function timestamp(?string $value): ?string
    {
        return $value === null ? null : \Carbon\CarbonImmutable::parse($value, config('app.timezone'))->utc()->toIso8601String();
    }

    public function branch(Tenant $tenant, User $user, ?string $publicId, string $permission, bool $history = false): Branch
    {
        if ($publicId !== null) {
            $branch = $tenant->branches()->where('public_id', $publicId)->firstOrFail();
            $history ? $this->access->assertHistory($user, $tenant, $branch, $permission) : $this->access->assertOperate($user, $tenant, $branch, $permission);

            return $branch;
        }
        $branches = $this->access->operableFor($user, $tenant, $permission);
        if ($branches->count() !== 1) {
            $this->fail('CASH_BRANCH_REQUIRED', 'Selecciona una sucursal para continuar.');
        }

        return $branches->sole();
    }

    private function writable(Tenant $tenant, User $user, Branch $branch, string $permission): void
    {
        $this->access->assertOperate($user, $tenant, $branch, $permission);
        $this->entitlements->assertWritable($tenant);
        if (! $this->entitlements->allowsCash($tenant)) {
            $this->fail('CASH_FEATURE_UNAVAILABLE', 'Tu contrato no permite nuevas operaciones de caja.');
        }
    }

    public function shift(Tenant $tenant, User $user, string $publicId, string $permission = 'cash.view'): object
    {
        $shift = DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('public_id', $publicId)->first();
        abort_unless($shift, 404);
        $branch = $tenant->branches()->whereKey($shift->branch_id)->firstOrFail();
        $this->access->assertHistory($user, $tenant, $branch, $permission);
        abort_unless((int) $shift->responsible_user_id === (int) $user->id || $user->can('cash.manage'), 403);
        abort_unless(DB::table('cash_registers')->where('tenant_id', $tenant->id)->where('branch_id', $branch->id)->where('id', $shift->cash_register_id)->exists(), 404);

        return $shift;
    }

    private function assertOpen(object $shift): void
    {
        if ($shift->status !== 'OPEN') {
            $this->fail('CASH_SHIFT_CLOSED', 'El turno ya está cerrado.');
        }
    }

    private function intervention(object $shift, User $user, array $data): void
    {
        if ((int) $shift->responsible_user_id !== (int) $user->id) {
            abort_unless($user->can('cash.manage'), 403);
            if (blank($data['intervention_reason'] ?? null)) {
                throw ValidationException::withMessages(['intervention_reason' => 'Indica el motivo de la intervención.']);
            }
        }
    }

    /** Caller owns the tenant write transaction when confirming a sale. */
    public function saleContext(Tenant $tenant, Branch $branch, User $user): array
    {
        $required = $this->entitlements->allowsCash($tenant);
        if (! $required) {
            if (DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('branch_id', $branch->id)->where('status', 'OPEN')->exists()) {
                $this->fail('CASH_SHIFT_CLOSE_REQUIRED', 'Cierra los turnos pendientes antes de continuar sin caja.');
            }

            return ['required' => false, 'shift' => null];
        }
        $this->access->assertOperate($user, $tenant, $branch, 'cash.operate');
        $shift = DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('branch_id', $branch->id)
            ->where('responsible_user_id', $user->id)->where('status', 'OPEN')->first();
        if (! $shift) {
            $this->fail('CASH_SHIFT_REQUIRED', 'Necesitas abrir un turno antes de cobrar.');
        }

        return ['required' => true, 'shift' => $shift->public_id];
    }

    public function attachSale(Tenant $tenant, Branch $branch, User $user, Order $order): void
    {
        $context = $this->saleContext($tenant, $branch, $user);
        if (! $context['required']) {
            return;
        }
        $shift = $this->shift($tenant, $user, $context['shift'], 'cash.operate');
        $this->assertOpen($shift);
        $order->update(['cash_shift_id' => $shift->id]);
        DB::table('cash_shifts')->where('id', $shift->id)->increment('activity_version');
    }

    public function summary(object $shift): array
    {
        $totals = array_fill_keys(['cash_sales_total', 'cash_refunds_total', 'cash_in_total', 'cash_out_total', 'card_sales_total', 'transfer_sales_total'], '0.00');
        $sales = DB::table('orders')->where('tenant_id', $shift->tenant_id)->where('branch_id', $shift->branch_id)->where('cash_shift_id', $shift->id)->where('status', 'paid')->get(['payment_method', 'total']);
        foreach ($sales as $sale) {
            $field = match ($sale->payment_method) {
                'cash' => 'cash_sales_total', 'card' => 'card_sales_total', 'transfer' => 'transfer_sales_total'
            };
            $totals[$field] = $this->money(BigDecimal::of($totals[$field])->plus((string) $sale->total));
        }
        foreach (DB::table('cash_movements')->where('tenant_id', $shift->tenant_id)->where('cash_shift_id', $shift->id)->get() as $movement) {
            $field = $movement->type === 'IN' ? 'cash_in_total' : 'cash_out_total';
            $totals[$field] = $this->money(BigDecimal::of($totals[$field])->plus((string) $movement->amount));
        }

        return [...$totals, 'sales_count' => $sales->count(), 'expected_cash' => $this->money(BigDecimal::of((string) $shift->opening_cash)->plus($totals['cash_sales_total'])->plus($totals['cash_in_total'])->minus($totals['cash_out_total']))];
    }

    public function present(object $shift, User $user): array
    {
        $register = DB::table('cash_registers')->where('tenant_id', $shift->tenant_id)->where('id', $shift->cash_register_id)->first();
        $branch = DB::table('branches')->where('tenant_id', $shift->tenant_id)->where('id', $shift->branch_id)->first();
        $responsible = DB::table('users')->where('id', $shift->responsible_user_id)->value('name');
        $data = [
            'id' => $shift->public_id, 'register' => ['id' => $register->public_id, 'name' => $register->name],
            'branch' => ['id' => $branch->public_id, 'name' => $branch->name],
            'responsible' => $responsible, 'is_own' => (int) $shift->responsible_user_id === (int) $user->id,
            'status' => $shift->status, 'business_date' => $shift->business_date, 'opened_timezone' => $shift->opened_timezone,
            'opening_cash' => $this->money($shift->opening_cash), 'opened_at' => $this->timestamp($shift->opened_at), 'closed_at' => $this->timestamp($shift->closed_at),
        ];
        if ($shift->status === 'CLOSED') {
            foreach (['cash_sales_total', 'cash_refunds_total', 'cash_in_total', 'cash_out_total', 'card_sales_total', 'transfer_sales_total', 'expected_cash', 'counted_cash', 'difference'] as $key) {
                $data[$key] = $this->money($shift->$key);
            }
            $data['sales_count'] = $shift->sales_count;
            $data['difference_reason'] = $shift->difference_reason;
            $data['intervention_reason'] = $shift->intervention_reason;
            $data['denominations'] = $shift->denominations ? json_decode($shift->denominations, true) : null;
            $data['closed_by'] = DB::table('users')->where('id', $shift->closed_by_user_id)->value('name');
        } elseif ($user->can('cash.manage')) {
            $data = [...$data, ...$this->summary($shift)];
        } else {
            // Intentionally omit expected cash and difference before the count.
            $data['sales_count'] = DB::table('orders')->where('tenant_id', $shift->tenant_id)->where('cash_shift_id', $shift->id)->count();
        }
        $data['movements'] = DB::table('cash_movements')->where('tenant_id', $shift->tenant_id)->where('cash_shift_id', $shift->id)->orderBy('id')->get()->map(fn ($m) => [
            'id' => $m->public_id, 'type' => $m->type, 'amount' => $this->money($m->amount), 'reason' => $m->reason,
            'intervention_reason' => $m->intervention_reason, 'created_at' => $this->timestamp($m->created_at),
            'author' => DB::table('users')->where('id', $m->created_by_user_id)->value('name'),
        ])->all();

        return $data;
    }

    public function context(Tenant $tenant, User $user, Branch $branch): array
    {
        $this->access->assertHistory($user, $tenant, $branch);
        $enabled = $this->entitlements->allowsCash($tenant);
        $writable = true;
        try {
            $this->entitlements->assertWritable($tenant);
        } catch (\DomainException) {
            $writable = false;
        }
        $shift = DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('branch_id', $branch->id)
            ->where('open_user_id', $user->id)->where('status', 'OPEN')->first();
        $ownHere = $shift !== null;
        $pending = DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('branch_id', $branch->id)->where('status', 'OPEN')->exists();
        $operable = $branch->is_active && $branch->billing_enabled && $writable;

        return [
            'feature_enabled' => $enabled, 'read_only' => ! $writable, 'shift_required' => $enabled, 'closure_required' => ! $enabled && $pending,
            'current_shift' => $shift && $user->can('cash.view') ? $this->present($this->shift($tenant, $user, $shift->public_id), $user) : null,
            'can_checkout' => $operable && $user->can('orders.create') && ($enabled ? $ownHere && $user->can('cash.operate') : ! $pending),
            'available_actions' => [
                'open' => $enabled && $operable && ! $shift && $user->can('cash.operate'),
                'supervise_move' => $enabled && $operable && $user->can('cash.manage') && $user->can('cash.operate'),
                'move' => $enabled && $operable && $ownHere && $user->can('cash.operate'),
                'close' => (bool) $shift && $user->can('cash.operate'),
                'manage_registers' => $enabled && $operable && $user->hasRole('owner') && $user->can('cash.manage'),
            ],
            'permissions' => ['view' => $user->can('cash.view'), 'operate' => $user->can('cash.operate'), 'manage' => $user->can('cash.manage')],
        ];
    }

    public function saveRegister(Tenant $tenant, User $user, Branch $branch, array $data, ?string $id): array
    {
        return $this->writes->run($tenant, function () use ($tenant, $user, $branch, $data, $id) {
            $this->writable($tenant, $user, $branch->refresh(), 'cash.manage');
            abort_unless($user->hasRole('owner'), 403);
            $register = $id ? DB::table('cash_registers')->where('tenant_id', $tenant->id)->where('branch_id', $branch->id)->where('public_id', $id)->first() : null;
            abort_if($id && ! $register, 404);
            if ($register && isset($data['is_active']) && ! $data['is_active'] && DB::table('cash_shifts')->where('open_register_id', $register->id)->exists()) {
                $this->fail('CASH_REGISTER_BUSY', 'Cierra el turno antes de desactivar la caja.');
            }
            $values = [...$data, 'updated_at' => now()];
            if ($register) {
                DB::table('cash_registers')->where('id', $register->id)->update($values);
            } else {
                $id = (string) Str::uuid();
                DB::table('cash_registers')->insert([...$values, 'public_id' => $id, 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'created_at' => now()]);
            }

            return $this->registers($tenant, $branch)->firstWhere('id', $id);
        }, 'CASH_RETRY');
    }

    public function registers(Tenant $tenant, Branch $branch): Collection
    {
        return DB::table('cash_registers')->where('tenant_id', $tenant->id)->where('branch_id', $branch->id)->orderBy('name')->get()->map(fn ($r) => [
            'id' => $r->public_id, 'name' => $r->name, 'is_active' => (bool) $r->is_active,
            'busy' => DB::table('cash_shifts')->where('open_register_id', $r->id)->exists(),
        ]);
    }

    public function perform(Tenant $tenant, User $user, string $key, string $type, ?string $target, ?string $branchPublicId, array $data): array
    {
        $fingerprint = hash('sha256', json_encode([$type, $target, $type === 'OPEN_SHIFT' ? $branchPublicId : null, $this->canonical($data)], JSON_THROW_ON_ERROR));

        return $this->writes->run($tenant, function () use ($tenant, $user, $key, $type, $target, $branchPublicId, $data, $fingerprint) {
            $existing = DB::table('cash_operations')->where('tenant_id', $tenant->id)->where('idempotency_key', strtolower($key))->first();
            if ($existing) {
                if ((int) $existing->user_id !== (int) $user->id || ! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    $this->fail('CASH_IDEMPOTENCY_CONFLICT', 'La clave corresponde a otra operación.');
                }
                $branch = $tenant->branches()->whereKey($existing->branch_id)->firstOrFail();
                $this->access->assertHistory($user, $tenant, $branch, 'cash.operate');

                return ['data' => json_decode($existing->response_snapshot, true), 'created' => false];
            }
            $operationId = (string) Str::uuid();
            if ($type === 'OPEN_SHIFT') {
                $branch = $this->branch($tenant, $user, $branchPublicId, 'cash.operate');
                $this->writable($tenant, $user, $branch, 'cash.operate');
                $register = DB::table('cash_registers')->where('tenant_id', $tenant->id)->where('branch_id', $branch->id)->where('public_id', $data['register_id'])->where('is_active', true)->first();
                abort_unless($register, 404);
                if (DB::table('cash_shifts')->where('open_register_id', $register->id)->exists()) {
                    $this->fail('CASH_REGISTER_BUSY', 'La caja ya tiene un turno abierto.');
                }
                if (DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('branch_id', $branch->id)
                    ->where('open_user_id', $user->id)->where('status', 'OPEN')->exists()) {
                    $this->fail('CASH_USER_ALREADY_HAS_SHIFT', 'Ya tienes un turno abierto en esta sucursal.');
                }
                $timezone = $branch->timezone ?: ($tenant->timezone ?: config('app.timezone'));
                $shiftId = DB::table('cash_shifts')->insertGetId([
                    'public_id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
                    'cash_register_id' => $register->id, 'responsible_user_id' => $user->id, 'status' => 'OPEN',
                    'open_register_id' => $register->id, 'open_user_id' => $user->id,
                    'opening_cash' => $this->money($data['opening_cash']), 'business_date' => now()->timezone($timezone)->toDateString(),
                    'opened_timezone' => $timezone, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $shift = DB::table('cash_shifts')->where('id', $shiftId)->first();
                $result = $this->present($shift, $user);
            } else {
                $shift = $this->shift($tenant, $user, $target, 'cash.operate');
                $branch = $tenant->branches()->whereKey($shift->branch_id)->firstOrFail();
                $this->assertOpen($shift);
                $this->intervention($shift, $user, $data);
                $summary = $this->summary($shift);
                if ($type === 'CASH_MOVEMENT') {
                    $this->writable($tenant, $user, $branch, 'cash.operate');
                    abort_unless(DB::table('memberships')->where('tenant_id', $tenant->id)->where('user_id', $shift->responsible_user_id)->where('status', 'active')->exists(), 403, 'El responsable está suspendido. Sólo se permite cerrar su turno.');
                    if ($data['type'] === 'OUT' && BigDecimal::of($data['amount'])->isGreaterThan($summary['expected_cash'])) {
                        $this->fail('CASH_INSUFFICIENT_FUNDS', 'La caja no tiene saldo suficiente para realizar esta salida.');
                    }
                    $movementId = (string) Str::uuid();
                    DB::table('cash_movements')->insert([
                        'public_id' => $movementId, 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'cash_shift_id' => $shift->id,
                        'type' => $data['type'], 'amount' => $this->money($data['amount']), 'reason' => $data['reason'],
                        'intervention_reason' => $data['intervention_reason'] ?? null, 'created_by_user_id' => $user->id, 'created_at' => now(),
                    ]);
                    DB::table('cash_shifts')->where('id', $shift->id)->increment('activity_version');
                    $result = ['id' => $movementId, 'type' => $data['type'], 'amount' => $this->money($data['amount']), 'reason' => $data['reason']];
                } elseif ($type === 'COUNT_SHIFT') {
                    $counted = $this->counted($data);
                    $result = [...$summary, 'id' => $operationId, 'shift_id' => $shift->public_id,
                        'activity_version' => $shift->activity_version, 'counted_cash' => $counted,
                        'difference' => $this->money(BigDecimal::of($counted)->minus($summary['expected_cash'])),
                        'denominations' => $data['denominations'] ?? null, 'intervention_reason' => $data['intervention_reason'] ?? null];
                } elseif ($type === 'CLOSE_SHIFT') {
                    $count = DB::table('cash_operations')->where('tenant_id', $tenant->id)->where('branch_id', $branch->id)->where('resource_id', $shift->id)
                        ->where('resource_type', 'shift')->where('user_id', $user->id)->where('operation_type', 'COUNT_SHIFT')->where('public_id', $data['count_id'])->first();
                    abort_unless($count, 404);
                    $snapshot = json_decode($count->response_snapshot, true);
                    if ((int) $snapshot['activity_version'] !== (int) $shift->activity_version) {
                        $this->fail('CASH_COUNT_STALE', 'Hubo actividad después del conteo. Cuenta nuevamente antes de cerrar.');
                    }
                    if (! BigDecimal::of($snapshot['difference'])->isZero() && blank($data['difference_reason'] ?? null)) {
                        $this->fail('CASH_DIFFERENCE_REASON_REQUIRED', 'Explica la diferencia antes de cerrar el turno.');
                    }
                    $closing = array_intersect_key($snapshot, array_flip([...array_keys($summary), 'counted_cash', 'difference']));
                    DB::table('cash_shifts')->where('id', $shift->id)->update([
                        ...$closing, 'status' => 'CLOSED', 'open_register_id' => null, 'open_user_id' => null,
                        'difference_reason' => $data['difference_reason'] ?? null, 'intervention_reason' => $data['intervention_reason'] ?? null,
                        'denominations' => $snapshot['denominations'] === null ? null : json_encode($snapshot['denominations'], JSON_THROW_ON_ERROR),
                        'closed_at' => now(), 'closed_by_user_id' => $user->id, 'updated_at' => now(),
                    ]);
                    $result = $this->present(DB::table('cash_shifts')->where('id', $shift->id)->first(), $user);
                } else {
                    throw new \LogicException('Unsupported cash operation');
                }
            }
            DB::table('cash_operations')->insert([
                'public_id' => $operationId, 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'user_id' => $user->id,
                'operation_type' => $type, 'idempotency_key' => strtolower($key), 'request_fingerprint' => $fingerprint,
                'resource_type' => 'shift', 'resource_id' => $shift->id, 'response_snapshot' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);

            return ['data' => $result, 'created' => true];
        }, 'CASH_RETRY');
    }

    private function canonical(array $data): array
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $data;
    }

    private function counted(array $data): string
    {
        if (isset($data['counted_cash'])) {
            return $this->money($data['counted_cash']);
        }
        $cents = BigDecimal::zero();
        foreach ($data['denominations'] as $denomination => $quantity) {
            if (! in_array((string) $denomination, ['10', '20', '50', '100', '200', '500', '1000', '2000', '5000', '10000', '20000', '50000', '100000'], true)) {
                throw ValidationException::withMessages(['denominations' => 'La denominación no es válida.']);
            }
            $cents = $cents->plus(BigDecimal::of((string) $denomination)->multipliedBy($quantity));
        }
        if ($cents->isGreaterThan('99999999999')) {
            throw ValidationException::withMessages(['denominations' => 'El conteo excede el importe permitido.']);
        }

        return (string) $cents->dividedBy(100, 2);
    }
}
