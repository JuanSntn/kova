<?php

namespace App\Services\Pos;

use App\Exceptions\DomainConflictException;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Branches\BranchAccessService;
use App\Tenancy\CurrentBranch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CheckoutService
{
    public function __construct(private OrderService $orders, private BranchAccessService $access, private CurrentBranch $currentBranch) {}

    public function recover(Tenant $tenant, User $user, string $key): ?Order
    {
        $operation = DB::table('pos_checkout_operations')->where('tenant_id', $tenant->id)
            ->where('idempotency_key', strtolower($key))->first();
        if (! $operation) {
            return null;
        }
        abort_unless((int) $operation->user_id === (int) $user->id, 404);
        $branch = $tenant->branches()->whereKey($operation->branch_id)->firstOrFail();
        $this->access->assertHistory($user, $tenant, $branch, 'orders.create');

        return $tenant->orders()->whereKey($operation->order_id)->with(['branch', 'items.product'])->firstOrFail();
    }

    /** The first statement in each transaction must obtain SQLite's write lock. */
    public function confirm(Tenant $tenant, User $user, string $key, ?string $branchId, array $data): array
    {
        $key = strtolower($key);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return app(TenantWriteTransaction::class)->run($tenant, function () use ($tenant, $user, $key, $branchId, $data, $hash) {
            $operation = DB::table('pos_checkout_operations')->where('tenant_id', $tenant->id)
                ->where('idempotency_key', $key)->first();
            if ($operation) {
                $order = $this->recover($tenant, $user, $key);
                if (($branchId !== null && $order->branch->public_id !== $branchId)
                    || ! hash_equals($operation->payload_hash, $hash)) {
                    throw new DomainConflictException('Esta clave corresponde a otra confirmación. Verifica la venta original.', 'POS_IDEMPOTENCY_CONFLICT');
                }

                return ['order' => $order, 'created' => false];
            }
            if ($branchId !== null) {
                abort_unless(Str::isUuid($branchId), 404);
                $branch = $tenant->branches()->where('public_id', $branchId)->firstOrFail();
                $this->access->assertOperate($user, $tenant, $branch, 'orders.create');
            } else {
                $available = $this->access->operableFor($user, $tenant, 'orders.create');
                if ($available->count() !== 1) {
                    throw new DomainConflictException('Selecciona una sucursal para continuar.', 'POS_BRANCH_NOT_RESOLVED');
                }
                $branch = $available->sole();
            }
            if (isset($data['branch_id']) && $data['branch_id'] !== $branch->public_id) {
                throw new DomainConflictException('La sucursal no coincide con el contexto.', 'BRANCH_CONTEXT_MISMATCH');
            }
            $this->currentBranch->set($branch);
            try {
                $order = $this->orders->checkout($tenant, $user, $data);
            } finally {
                $this->currentBranch->clear();
            }
            DB::table('pos_checkout_operations')->insert([
                'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'user_id' => $user->id,
                'idempotency_key' => $key, 'payload_hash' => $hash, 'order_id' => $order->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['order' => $order, 'created' => true];
        });
    }
}
