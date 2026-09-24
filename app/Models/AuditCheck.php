<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Respuesta de la lista de verificación de una auditoría para un requisito
 * común (SIG-01…SIG-62). Se alcanza siempre a través de su Audit.
 */
class AuditCheck extends Model
{
    protected $fillable = ['clave_comun', 'resultado', 'evidencia'];

    /** `observacion` cumple, con un comentario; `no_aplica` sale del cálculo. */
    public const RESULTADOS = ['conforme', 'no_conforme', 'observacion', 'no_aplica'];

    /** @return BelongsTo<Audit, $this> */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }
}
