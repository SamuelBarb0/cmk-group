<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuestión interna o externa del contexto (ISO 4.1). Segregada por tenant.
 *
 * DOFA: lo interno es fortaleza o debilidad; lo externo, oportunidad o
 * amenaza. Lo externo además se clasifica en PESTEL para no dejar por fuera
 * un frente entero (lo legal y lo ambiental suelen ser los olvidados).
 */
class ContextIssue extends Model
{
    use BelongsToTenant;

    protected $fillable = ['origen', 'dofa', 'pestel', 'descripcion', 'impacto', 'cambio_climatico', 'tratamiento', 'sistemas', 'orden'];

    protected function casts(): array
    {
        return ['sistemas' => 'array', 'cambio_climatico' => 'boolean'];
    }

    public const ORIGENES = ['interno', 'externo'];

    /** Qué cuadrantes DOFA corresponden a cada origen. */
    public const DOFA = [
        'interno' => ['fortaleza', 'debilidad'],
        'externo' => ['oportunidad', 'amenaza'],
    ];

    public const PESTEL = ['politico', 'economico', 'social', 'tecnologico', 'ambiental', 'legal'];

    public const IMPACTOS = ['alto', 'medio', 'bajo'];
}
