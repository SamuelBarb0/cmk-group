<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prueba de idoneidad de un conductor (PASO 11 · procedimiento de pruebas de
 * idoneidad): teórica, práctica según el vehículo y psicosensométrica en un CRC.
 */
class PesvDriverTest extends Model
{
    use BelongsToTenant;

    public const TIPOS = [
        'teorica' => 'Prueba teórica (conocimiento)',
        'practica' => 'Prueba teórico-práctica de conducción',
        'psicosensometrica' => 'Prueba psicosensométrica (CRC)',
    ];

    /** RE-SST-48: «puntaje igual o menor a 60 %: aspirante no apto». */
    public const PUNTAJE_MINIMO = 60;

    protected $fillable = ['employee_id', 'tipo', 'fecha', 'puntaje', 'resultado', 'vigente_hasta', 'evaluador', 'observaciones'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d', 'vigente_hasta' => 'date:Y-m-d', 'puntaje' => 'decimal:1'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
