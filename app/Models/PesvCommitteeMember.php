<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Integrante del Comité de Seguridad Vial (Paso 2).
 *
 * Cuelga del plan, que ya está segregado por tenant. Puede enlazar a un
 * empleado registrado o guardarse a mano si el integrante es externo.
 */
class PesvCommitteeMember extends Model
{
    public const ROLES = ['presidente', 'secretario', 'integrante'];

    protected $fillable = [
        'pesv_plan_id',
        'employee_id',
        'nombre',
        'documento',
        'cargo',
        'rol_comite',
        'es_representante_direccion',
    ];

    protected function casts(): array
    {
        return ['es_representante_direccion' => 'boolean'];
    }

    /** @return BelongsTo<PesvPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PesvPlan::class, 'pesv_plan_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
