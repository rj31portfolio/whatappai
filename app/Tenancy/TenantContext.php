<?php

namespace App\Tenancy;

use App\Enums\Role;
use App\Models\Tenant;
use Closure;
use LogicException;

class TenantContext
{
    public ?Tenant $tenant = null;
    public ?Role $role = null;

    public function id(): int
    {
        return $this->tenant?->id ?? throw new LogicException('Tenant context is required.');
    }

    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $previousRole = $this->role;
        $this->tenant = $tenant;
        $this->role = null;
        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
            $this->role = $previousRole;
        }
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->role = null;
    }
}
