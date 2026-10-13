<?php

namespace App\Tenancy;

use App\Models\Branch;
use LogicException;

final class CurrentBranch
{
    private ?Branch $branch = null;

    public function set(Branch $branch): void
    {
        $this->branch = $branch;
    }

    public function clear(): void
    {
        $this->branch = null;
    }

    public function isResolved(): bool
    {
        return $this->branch !== null;
    }

    public function get(): Branch
    {
        return $this->branch ?? throw new LogicException('No hay una sucursal activa en este request.');
    }

    public function id(): int
    {
        return (int) $this->get()->getKey();
    }
}
