<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainConflictException;
use App\Services\Branches\BranchAccessService;
use App\Tenancy\CurrentBranch;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ResolveBranch
{
    public function __construct(private CurrentTenant $tenants, private CurrentBranch $branches, private BranchAccessService $access) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $this->branches->clear();
        $tenant = $this->tenants->get();
        $requested = $request->header('X-Branch-ID');
        $payloadBranch = $request->input('branch_id');

        if ($requested !== null && ! Str::isUuid($requested)) {
            abort(404);
        }
        if ($requested) {
            $branch = $tenant->branches()->where('public_id', $requested)->firstOrFail();
            $this->access->assertOperate($request->user(), $tenant, $branch, $permission);
        } else {
            $available = $this->access->operableFor($request->user(), $tenant, $permission);
            if ($available->count() !== 1) {
                throw new DomainConflictException('Selecciona una sucursal para continuar.', 'POS_BRANCH_NOT_RESOLVED');
            }
            $branch = $available->sole();
        }

        if ($payloadBranch !== null && $payloadBranch !== $branch->public_id) {
            throw new DomainConflictException('La sucursal de la operación no coincide con el contexto seleccionado.', 'BRANCH_CONTEXT_MISMATCH');
        }

        $this->branches->set($branch);
        try {
            return $next($request);
        } finally {
            $this->branches->clear();
        }
    }
}
