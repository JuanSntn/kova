<?php

namespace App\Tenancy;

use App\Exceptions\MissingTenantContext;
use App\Models\Tenant;
use Closure;

class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function isResolved(): bool
    {
        return $this->tenant !== null;
    }

    public function get(): Tenant
    {
        return $this->tenant ?? throw MissingTenantContext::make();
    }

    public function id(): int
    {
        return (int) $this->get()->getKey();
    }

    /**
     * Execute work in a tenant context and restore the previous context afterwards.
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
