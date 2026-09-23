<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Actividad del cronograma de un programa. Sin tenant propio: cuelga del
 * programa, y por eso NO tiene rutas propias; se guarda siempre a través de
 * su ProgramPlan, que sí pasa por el TenantScope.
 */
class ProgramPlanActivity extends Model
{
    protected $fillable = [
        'fase', 'nombre', 'responsable', 'presupuesto', 'meses_programados',
        'meses_ejecutados', 'observaciones', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'presupuesto' => 'decimal:2',
            'meses_programados' => 'array',
            'meses_ejecutados' => 'array',
        ];
    }

    /** @return BelongsTo<ProgramPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProgramPlan::class, 'program_plan_id');
    }
}
