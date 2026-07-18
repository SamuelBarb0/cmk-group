<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aplica a cualquier modelo cuya información pertenece a un cliente (tenant).
 *
 * - Registra el global TenantScope (filtra por tenant_id).
 * - Rellena automáticamente tenant_id al crear, usando el tenant activo.
 * - Expone la relación tenant().
 *
 * Uso: `use BelongsToTenant;` en el modelo + columna tenant_id en su tabla.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if (empty($model->tenant_id)) {
                $context = app(TenantContext::class);
                if ($context->has()) {
                    $model->tenant_id = $context->id();
                }
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Consulta ignorando el filtro por tenant (uso administrativo/consolidado). */
    public static function withoutTenantScope(): \Illuminate\Database\Eloquent\Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }
}
