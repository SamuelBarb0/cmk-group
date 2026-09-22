<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Simulacro de emergencia.
 *
 * Es la fuente de los tres indicadores del «Programa de emergencias» de CMK:
 * CUMP-SIM (realizados sobre programados), REC-SIM (recomendaciones
 * implementadas) y PART-EMERG (participantes sobre convocados).
 */
class EmergencyDrill extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'fecha', 'tipo', 'escenario', 'estado', 'sede', 'entidades_apoyo',
        'hora_inicio', 'hora_fin', 'tiempo_evacuacion_segundos', 'evacuados',
        'convocados', 'participantes', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'tiempo_evacuacion_segundos' => 'integer',
            'evacuados' => 'integer',
            'convocados' => 'integer',
            'participantes' => 'integer',
        ];
    }

    public const TIPOS = ['interno', 'externo'];

    public const ESTADOS = ['programado', 'realizado'];

    /**
     * La meta del programa de CMK: mínimo dos simulacros al año, y al menos
     * uno de ellos con partes interesadas externas.
     */
    public const META_ANUAL = 2;

    public const META_EXTERNOS = 1;

    /**
     * MySQL devuelve un TIME como «14:30:00», y un <input type="time"> sin
     * `step` no pinta los segundos: se recorta a HH:MM para que el formulario
     * no aparezca vacío al editar (el mismo tropiezo que las fechas del 17-sep).
     */
    protected function horaInicio(): Attribute
    {
        return Attribute::get(fn (?string $v) => $v === null ? null : substr($v, 0, 5));
    }

    protected function horaFin(): Attribute
    {
        return Attribute::get(fn (?string $v) => $v === null ? null : substr($v, 0, 5));
    }

    /** @return HasMany<EmergencyDrillRecommendation, $this> */
    public function recommendations(): HasMany
    {
        return $this->hasMany(EmergencyDrillRecommendation::class)->orderBy('orden');
    }
}
