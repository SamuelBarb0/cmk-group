<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plantilla de documento SGI (transversal a varias normas).
 *
 * Dos procedencias, que conviven en la misma tabla:
 *  - `tenant_id` NULL: catálogo de CMK, visible para todas las empresas.
 *  - `tenant_id` lleno: formato propio de esa empresa, solo visible para ella.
 *
 * NO usa BelongsToTenant a propósito: ese trait filtra por tenant_id y dejaría
 * fuera justamente el catálogo global, que es lo que ve todo el mundo. El
 * filtro correcto («las mías + las de CMK») es el scope visibles().
 */
class DocumentTemplate extends Model
{
    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'tipo',
        'categoria',
        'normas',
        'descripcion',
        'contenido_base',
        'archivo',
        'prompt',
        'orden',
        'subido_por',
        'subida_at',
    ];

    /** ¿Tiene documento modelo base para rellenar (vs. generar desde cero)? */
    public function tieneBase(): bool
    {
        return filled($this->contenido_base);
    }

    /** ¿Es del catálogo de CMK (y no de una empresa concreta)? */
    public function esGlobal(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * Las plantillas que puede usar una empresa: el catálogo de CMK más las
     * suyas propias. Sin empresa activa (consultor de CMK trabajando fuera de
     * un cliente) solo se ve el catálogo global.
     *
     * @param  Builder<DocumentTemplate>  $query
     */
    public function scopeVisibles(Builder $query, ?int $tenantId): void
    {
        $query->where(function (Builder $q) use ($tenantId) {
            $q->whereNull('tenant_id');

            if ($tenantId !== null) {
                $q->orWhere('tenant_id', $tenantId);
            }
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected function casts(): array
    {
        return [
            'normas' => 'array',
            'orden' => 'integer',
            'subida_at' => 'datetime',
        ];
    }
}
