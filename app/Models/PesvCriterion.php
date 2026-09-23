<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una pregunta de la lista de verificación oficial del PESV (Tabla 16 de la
 * Res. 40595). Catálogo GLOBAL; la respuesta de cada empresa vive en
 * PesvPlanCriterion.
 */
class PesvCriterion extends Model
{
    protected $table = 'pesv_criteria';

    protected $fillable = ['pesv_step_id', 'codigo', 'pregunta', 'niveles', 'orden'];

    protected function casts(): array
    {
        return ['niveles' => 'array', 'orden' => 'integer'];
    }

    public function aplicaA(?string $nivel): bool
    {
        return in_array($nivel, $this->niveles ?? [], true);
    }

    /** @return BelongsTo<PesvStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(PesvStep::class, 'pesv_step_id');
    }
}
