<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hallazgo de una auditoría. Si exige acción, se enlaza con su ACPM. */
class AuditFinding extends Model
{
    protected $fillable = [
        'audit_id', 'tipo', 'proceso', 'requisito',
        'descripcion', 'evidencia', 'acpm_action_id',
    ];

    /**
     * Una fortaleza también es un hallazgo: las actas de cierre de CMK abren
     * por ahí, y un informe que solo lista fallos hace que el auditado deje de
     * colaborar en la siguiente.
     */
    public const TIPOS = [
        'no_conformidad_mayor',
        'no_conformidad_menor',
        'observacion',
        'oportunidad',
        'fortaleza',
    ];

    /** Las que sí son incumplimiento y exigen acción correctiva. */
    public const NO_CONFORMIDADES = ['no_conformidad_mayor', 'no_conformidad_menor'];

    /** @return BelongsTo<Audit, $this> */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    /** @return BelongsTo<AcpmAction, $this> */
    public function accion(): BelongsTo
    {
        return $this->belongsTo(AcpmAction::class, 'acpm_action_id');
    }
}
