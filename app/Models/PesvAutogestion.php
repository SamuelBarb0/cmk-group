<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Datos del reporte de autogestión anual del PESV (paso 20) que no salen de
 * otros módulos, y la constancia de radicación ante la entidad verificadora.
 */
class PesvAutogestion extends Model
{
    use BelongsToTenant;

    protected $table = 'pesv_autogestiones';

    /** Entidades verificadoras de la Ley 2050 de 2020, art. 1. */
    public const ENTIDADES = [
        'supertransporte' => 'Superintendencia de Transporte',
        'mintrabajo' => 'Ministerio del Trabajo',
        'transito' => 'Organismo de tránsito',
    ];

    protected $fillable = [
        'anio',
        'lider_email',
        'auditores',
        'objetivos_siguiente',
        'programas_siguiente',
        'analisis',
        'entidad_verificadora',
        'reportado_at',
        'radicado',
    ];

    protected function casts(): array
    {
        return ['anio' => 'integer', 'auditores' => 'array', 'reportado_at' => 'date:Y-m-d'];
    }
}
