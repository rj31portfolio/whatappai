<?php

namespace App\Policies;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

class CrmPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->member($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->member($user) && (int) $record->tenant_id === app(TenantContext::class)->id();
    }

    public function create(User $user): bool
    {
        return $this->member($user) && app(TenantContext::class)->role->canWriteCrm();
    }

    public function update(User $user, Model $record): bool
    {
        return $this->view($user, $record) && $this->create($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    private function member(User $user): bool
    {
        $context = app(TenantContext::class);

        return $context->tenant !== null && $context->role !== null
            && $user->tenants()->where('tenants.id', $context->id())->wherePivot('role', $context->role->value)->exists();
    }
}
