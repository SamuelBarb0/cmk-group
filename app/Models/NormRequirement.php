<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Requisito de una norma (catálogo GLOBAL).
 *
 * `clave_comun` agrupa el mismo requisito en las distintas normas: la política
 * integrada es SIG-08 en el SG-SST (2.2.4.6.5), en el PESV (paso 3) y en las
 * tres ISO (5.2). Cada fila guarda la referencia exacta de su norma.
 */
class NormRequirement extends Model
{
    protected $fillable = ['norm_id', 'clave_comun', 'etapa', 'referencia', 'titulo', 'evidencia', 'modulo', 'nota', 'orden'];

    public const ETAPAS = [
        '0' => 'Diagnóstico inicial',
        '4' => 'Contexto de la organización',
        '5' => 'Liderazgo y participación',
        '6' => 'Planificación',
        '7' => 'Apoyo',
        '8' => 'Operación',
        '9' => 'Evaluación del desempeño',
        '10' => 'Mejora',
    ];

    /** @return BelongsTo<Norm, $this> */
    public function norm(): BelongsTo
    {
        return $this->belongsTo(Norm::class);
    }

    /** @return BelongsToMany<ControlledDocument, $this> */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(ControlledDocument::class, 'controlled_document_requirement')->withTimestamps();
    }
}
