<?php

namespace App\Http\Controllers\Preparation;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Services\Subscriptions\CapacityService;
use App\Services\Subscriptions\EntitlementService;
use App\Tenancy\CurrentBranch;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PreparationController extends Controller
{
    public function index(
        Request $request,
        CurrentTenant $tenants,
        CurrentBranch $branches,
        CapacityService $capacity,
        EntitlementService $entitlements,
    ): JsonResponse {
        abort_unless($request->user()->can(PermissionName::PreparationView->value), 403);

        $tenant = $tenants->get();
        $branch = $branches->get();
        $capacity->assertFeature($tenant, 'allows_preparation');
        $limits = $entitlements->resolve($entitlements->current($tenant));
        $independent = array_key_exists('max_preparation_stations', $limits)
            && $limits['max_preparation_stations'] === null;
        $lockedStation = $independent ? $this->roleStationFor($request) : null;
        $station = $independent ? ($lockedStation ?? $this->requestedStation($request)) : null;

        $orders = $tenant->orders()
            ->where('branch_id', $branch->id)
            ->where('status', 'paid')
            ->whereHas('items', function ($query) use ($station): void {
                $query->whereIn('preparation_status', ['pending', 'preparing'])
                    ->whereNotNull('preparation_station_snapshot');
                if ($station) {
                    $query->where('preparation_station_snapshot', $station);
                }
            })
            ->with(['creator', 'items' => function ($query) use ($station): void {
                $query->whereIn('preparation_status', ['pending', 'preparing'])
                    ->whereNotNull('preparation_station_snapshot');
                if ($station) {
                    $query->where('preparation_station_snapshot', $station);
                }
            }])
            ->oldest()
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $orders->map(fn ($order) => [
                'id' => $order->public_id,
                'folio' => $order->folio,
                'created_at' => $order->created_at->toISOString(),
                'created_by' => $order->creator?->name,
                'items' => $order->items->map(fn (OrderItem $item) => $this->item($item))->values(),
            ])->values(),
            'meta' => [
                'mode' => $independent ? 'independent' : 'shared',
                'station' => $station,
                'station_locked' => $lockedStation !== null,
            ],
        ]);
    }

    public function update(
        Request $request,
        string $item,
        CurrentTenant $tenants,
        CurrentBranch $branches,
        CapacityService $capacity,
        EntitlementService $entitlements,
    ): JsonResponse {
        abort_unless($request->user()->can(PermissionName::PreparationManage->value), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(['preparing', 'ready'])],
        ]);

        $tenant = $tenants->get();
        $branch = $branches->get();
        $capacity->assertFeature($tenant, 'allows_preparation');
        $limits = $entitlements->resolve($entitlements->current($tenant));
        $independent = array_key_exists('max_preparation_stations', $limits)
            && $limits['max_preparation_stations'] === null;
        $station = $independent
            ? ($this->roleStationFor($request) ?? $this->requestedStation($request))
            : null;

        $updated = DB::transaction(function () use ($tenant, $branch, $item, $data, $station): OrderItem {
            $query = OrderItem::query()
                ->where('public_id', $item)
                ->whereHas('order', fn ($orders) => $orders
                    ->where('tenant_id', $tenant->id)
                    ->where('branch_id', $branch->id)
                    ->where('status', 'paid'));
            if ($station) {
                $query->where('preparation_station_snapshot', $station);
            }

            $model = $query->lockForUpdate()->firstOrFail();
            $allowed = match ($model->preparation_status) {
                'pending' => ['preparing', 'ready'],
                'preparing' => ['ready'],
                default => [],
            };
            if (! in_array($data['status'], $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => 'La comanda ya fue terminada o cambió de estado.',
                ]);
            }

            $model->update([
                'preparation_status' => $data['status'],
                'preparation_started_at' => $data['status'] === 'preparing'
                    ? ($model->preparation_started_at ?? now())
                    : $model->preparation_started_at,
                'preparation_ready_at' => $data['status'] === 'ready' ? now() : null,
            ]);

            return $model->refresh();
        });

        return response()->json(['data' => $this->item($updated)]);
    }

    private function roleStationFor(Request $request): ?string
    {
        $user = $request->user();
        if ($user->hasRole(RoleName::Kitchen->value)) {
            return 'kitchen';
        }
        if ($user->hasRole(RoleName::Bar->value)) {
            return 'bar';
        }

        return null;
    }

    private function requestedStation(Request $request): ?string
    {
        $requested = $request->query('station');
        return in_array($requested, ['kitchen', 'bar'], true) ? $requested : null;
    }

    private function item(OrderItem $item): array
    {
        return [
            'id' => $item->public_id,
            'name' => $item->product_name_snapshot,
            'quantity' => $item->quantity,
            'modifiers' => $item->modifiers_snapshot ?? [],
            'station' => $item->preparation_station_snapshot,
            'status' => $item->preparation_status,
            'started_at' => $item->preparation_started_at?->toISOString(),
        ];
    }
}
