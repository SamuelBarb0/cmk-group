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

    /** El paso 21 pide separar el análisis de los desplazamientos laborales de los no laborales. */
    public const DESPLAZAMIENTOS = ['laboral' => 'Desplazamiento laboral (en misión)', 'in_itinere' => 'Trayecto casa - trabajo', 'no_laboral' => 'No laboral'];

    /**
     * Matriz de nivel de pérdida de CMK (RE-SST-43), alineada con los niveles
     * de la TSV de la Res. 40595 (Tabla 10): fatalidades, heridos graves (más
     * de 30 días), heridos leves (hasta 30 días) y choques simples.
     */
    public const NIVELES_PERDIDA = [
        4 => ['nombre' => 'Crítico', 'personas' => 'Muerte', 'costos' => 'Más de $100.000.000'],
        3 => ['nombre' => 'Grave', 'personas' => 'Incapacidad de más de 30 días', 'costos' => 'Entre $10.000.000 y $100.000.000'],
        2 => ['nombre' => 'Medio', 'personas' => 'Incapacidad de hasta 30 días', 'costos' => 'Entre $1.000.000 y $10.000.000'],
        1 => ['nombre' => 'Leve', 'personas' => 'Primeros auxilios o solo daños (choque simple)', 'costos' => 'Menos de $1.000.000'],
    ];

    /** Nivel de pérdida que sugieren las consecuencias registradas. */
    public function nivelSugerido(): int
    {
        return match (true) {
            ($this->fallecidos ?? 0) > 0 || $this->gravedad === 'fatal' => 4,
            ($this->dias_incapacidad ?? 0) > 30 => 3,
            ($this->lesionados ?? 0) > 0 || ($this->dias_incapacidad ?? 0) > 0 || $this->gravedad === 'con_heridos' => 2,
            default => 1,
        };
    }

    public function nivel(): int
    {
        return $this->nivel_perdida ?? $this->nivelSugerido();
    }

    public function costoTotal(): float
    {
        return (float) $this->costo_directo + (float) $this->costo_indirecto;
    }

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
        'tipo_desplazamiento',
        'nivel_perdida',
        'costo_directo',
        'costo_indirecto',
        'fecha_investigacion',
        'equipo_investigador',
        'causas_inmediatas',
        'causas_basicas',
        'leccion_aprendida',
        'leccion_divulgada',
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
            'nivel_perdida' => 'integer',
            'costo_directo' => 'decimal:2',
            'costo_indirecto' => 'decimal:2',
            'fecha_investigacion' => 'date:Y-m-d',
            'leccion_divulgada' => 'boolean',
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
