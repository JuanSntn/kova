<?php

namespace App\Http\Controllers\Cash;

use App\Http\Controllers\Controller;
use App\Services\Branches\BranchAccessService;
use App\Services\Cash\CashService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CashController extends Controller
{
    public function __construct(private CurrentTenant $tenants, private CashService $cash) {}

    private function input(Request $request, array $rules): array
    {
        $unknown = array_diff(array_keys($request->all()), array_keys($rules));
        if ($unknown) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Este campo no está permitido.'));
        }

        return $request->validate($rules);
    }

    public function context(Request $r)
    {
        $tenant = $this->tenants->get();
        // POS users can read gating without being allowed to operate cash.
        $permission = $r->user()->can('cash.view') ? 'cash.view' : 'orders.create';
        $branch = $this->cash->branch($tenant, $r->user(), $r->header('X-Branch-ID'), $permission, true);

        return response()->json(['data' => $this->cash->context($tenant, $r->user(), $branch)]);
    }

    public function registers(Request $r)
    {
        $tenant = $this->tenants->get();
        $branch = $this->cash->branch($tenant, $r->user(), $r->header('X-Branch-ID'), 'cash.view', true);
        $items = $this->cash->registers($tenant, $branch);
        $page = max(1, (int) $r->query('page', 1));

        return response()->json(['data' => $items->forPage($page, 25)->values(), 'meta' => ['page' => $page, 'last_page' => max(1, (int) ceil($items->count() / 25)), 'total' => $items->count()]]);
    }

    public function saveRegister(Request $r, ?string $register = null)
    {
        $data = $this->input($r, ['name' => [$register ? 'sometimes' : 'required', 'string', 'max:100'], 'is_active' => ['sometimes', 'boolean']]);
        $tenant = $this->tenants->get();
        $branch = $this->cash->branch($tenant, $r->user(), $r->header('X-Branch-ID'), 'cash.manage');

        return response()->json(['data' => $this->cash->saveRegister($tenant, $r->user(), $branch, $data, $register)], $register ? 200 : 201);
    }

    public function index(Request $r)
    {
        $filters = $this->input($r, [
            'register' => ['sometimes', 'uuid'],
            'responsible' => ['sometimes', 'uuid'],
            'status' => ['sometimes', 'in:OPEN,CLOSED'],
            'business_date' => ['sometimes', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $tenant = $this->tenants->get();
        $branches = app(BranchAccessService::class)->historicalBranchIds($r->user(), $tenant, 'cash.view');
        $query = DB::table('cash_shifts')->where('tenant_id', $tenant->id)->whereIn('branch_id', $branches);
        if ($r->header('X-Branch-ID')) {
            $branch = $this->cash->branch($tenant, $r->user(), $r->header('X-Branch-ID'), 'cash.view', true);
            $query->where('branch_id', $branch->id);
        }
        if (! $r->user()->can('cash.manage')) {
            $query->where('responsible_user_id', $r->user()->id);
        }
        if (isset($filters['register'])) {
            $query->whereIn('cash_register_id', DB::table('cash_registers')->select('id')->where('tenant_id', $tenant->id)->where('public_id', $filters['register']));
        }
        if (isset($filters['responsible'])) {
            $query->whereIn('responsible_user_id', DB::table('memberships')->select('user_id')->where('tenant_id', $tenant->id)->where('public_id', $filters['responsible']));
        }
        foreach (['status', 'business_date'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        $page = $query->orderByDesc('id')->paginate(20);

        return response()->json(['data' => $page->getCollection()->map(fn ($s) => $this->cash->present($s, $r->user())), 'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function show(Request $r, string $shift)
    {
        return response()->json(['data' => $this->cash->present($this->cash->shift($this->tenants->get(), $r->user(), $shift), $r->user())]);
    }

    public function mutate(Request $r, ?string $shift = null)
    {
        $type = $r->route()->defaults['operation'];
        $shift = $r->route('shift');
        Validator::make(['key' => $r->header('Idempotency-Key')], ['key' => ['required', 'uuid']])->validate();
        $money = ['numeric', 'decimal:0,2', 'min:0', 'max:999999999.99'];
        $intervention = ['intervention_reason' => ['sometimes', 'nullable', 'string', 'max:1000']];
        $rules = match ($type) {
            'OPEN_SHIFT' => ['register_id' => ['required', 'uuid'], 'opening_cash' => ['required', ...$money]],
            'CASH_MOVEMENT' => [...$intervention, 'type' => ['required', 'in:IN,OUT'], 'amount' => ['required', ...$money, 'gt:0'], 'reason' => ['required', 'string', 'max:1000']],
            'COUNT_SHIFT' => [...$intervention, 'counted_cash' => ['required_without:denominations', 'prohibits:denominations', ...$money], 'denominations' => ['required_without:counted_cash', 'prohibits:counted_cash', 'array', 'min:1'], 'denominations.*' => ['integer', 'min:0', 'max:1000000']],
            'CLOSE_SHIFT' => [...$intervention, 'count_id' => ['required', 'uuid'], 'difference_reason' => ['sometimes', 'nullable', 'string', 'max:1000']],
        };
        $data = $this->input($r, $rules);
        $result = $this->cash->perform($this->tenants->get(), $r->user(), $r->header('Idempotency-Key'), $type, $shift, $r->header('X-Branch-ID'), $data);

        return response()->json(['data' => $result['data']], $result['created'] ? 201 : 200);
    }
}
