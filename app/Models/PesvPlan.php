<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan Estratégico de Seguridad Vial de una empresa cliente.
 *
 * Uno por tenant (unique en la migración). Agrupa el estado de los 24 pasos,
 * los datos del líder designado y el comité de seguridad vial.
 */
class PesvPlan extends Model
{
    use BelongsToTenant;

    /** Niveles de la Res. 40595. Lo fija el consultor. */
    public const NIVELES = ['basico', 'estandar', 'avanzado'];

    protected $fillable = [
        'nivel',
        'periodo_inicio',
        'periodo_fin',
        'lider_nombre',
        'lider_cargo',
        'lider_documento',
        'lider_designacion_fecha',
        'avance',
    ];

    protected function casts(): array
    {
        return [
            'lider_designacion_fecha' => 'date:Y-m-d',
            'periodo_inicio' => 'integer',
            'periodo_fin' => 'integer',
            'avance' => 'decimal:2',
        ];
    }

    /** @return HasMany<PesvPlanStep, $this> */
    public function pasos(): HasMany
    {
        return $this->hasMany(PesvPlanStep::class, 'pesv_plan_id');
    }

    /** @return HasMany<PesvCommitteeMember, $this> */
    public function comite(): HasMany
    {
        return $this->hasMany(PesvCommitteeMember::class, 'pesv_plan_id');
    }

    /**
     * Recalcula el avance: porcentaje de pasos cumplidos sobre los que aplican.
     *
     * Los pasos marcados "no_aplica" salen del denominador —igual que en el
     * diagnóstico de la Res. 0312— para que no castiguen a una empresa por algo
     * que la norma no le exige.
     */
    public function recalcular(): void
    {
        $pasos = $this->pasos()->get();
        $aplican = $pasos->where('estado', '!=', 'no_aplica');

        $this->avance = $aplican->isEmpty()
            ? 0
            : round($aplican->where('estado', 'cumple')->count() * 100 / $aplican->count(), 2);

        $this->save();
    }
}
