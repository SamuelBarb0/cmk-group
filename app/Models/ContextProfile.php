<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Ficha de contexto de una empresa (una por empresa): alcance del sistema
 * integrado (ISO 4.3), exclusiones justificadas y la decisión sobre el cambio
 * climático (ISO 45001:2018/Amd 1:2024 y 9001/14001:2026, cláusula 4.1: la
 * organización DETERMINA si es una cuestión pertinente; decir que no también
 * es una decisión, pero tiene que quedar justificada).
 */
class ContextProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'alcance', 'sedes', 'productos_servicios', 'exclusiones',
        'cambio_climatico', 'cambio_climatico_justificacion', 'revisado_at', 'revisado_por',
    ];

    protected function casts(): array
    {
        return [
            'exclusiones' => 'array',
            'cambio_climatico' => 'boolean',
            'revisado_at' => 'date:Y-m-d',
        ];
    }

    /** El análisis de contexto se revisa al menos una vez al año. */
    public const REVISION_MESES = 12;

    public function revisionVencida(): bool
    {
        return $this->revisado_at === null || $this->revisado_at->copy()->addMonths(self::REVISION_MESES)->isPast();
    }
}
