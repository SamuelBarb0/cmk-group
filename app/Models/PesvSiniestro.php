<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Siniestro vial registrado por la organización.
 *
 * Insumo del Paso 13 (investigación interna) y del Paso 21 (análisis
 * estadístico de siniestralidad).
 */
class PesvSiniestro extends Model
{
    use BelongsToTenant;

    protected $table = 'pesv_siniestros';

    public const TIPOS = ['choque', 'atropello', 'volcamiento', 'caida_ocupante', 'incendio', 'otro'];

    public const GRAVEDADES = ['solo_danos', 'con_heridos', 'fatal'];

    protected $fillable = [
        'fecha',
        'hora',
        'lugar',
        'tipo',
        'gravedad',
        'pesv_vehicle_id',
        'employee_id',
        'descripcion',
        'causa_probable',
        'lesionados',
        'fallecidos',
        'dias_incapacidad',
        'costo',
        'investigado',
        'acciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'lesionados' => 'integer',
            'fallecidos' => 'integer',
            'dias_incapacidad' => 'integer',
            'costo' => 'decimal:2',
            'investigado' => 'boolean',
        ];
    }

    /** @return BelongsTo<PesvVehicle, $this> */
    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(PesvVehicle::class, 'pesv_vehicle_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function conductor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
