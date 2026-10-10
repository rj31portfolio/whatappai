<?php

namespace App\Models\Concerns;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $query->where($query->getModel()->qualifyColumn('tenant_id'), app(TenantContext::class)->id());
        });
        static::creating(function ($model): void {
            $tenantId = app(TenantContext::class)->id();
            if ($model->tenant_id !== null && (int) $model->tenant_id !== $tenantId) {
                throw new LogicException('Cross-tenant creation rejected.');
            }
            $model->tenant_id = $tenantId;
        });
        static::updating(function ($model): void {
            if ($model->isDirty('tenant_id') || (int) $model->tenant_id !== app(TenantContext::class)->id()) {
                throw new LogicException('Cross-tenant update rejected.');
            }
        });
        static::deleting(function ($model): void {
            if ((int) $model->tenant_id !== app(TenantContext::class)->id()) {
                throw new LogicException('Cross-tenant deletion rejected.');
            }
        });
    }
}
