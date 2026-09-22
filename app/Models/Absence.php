<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ausencia de un trabajador (incapacidad, licencia o permiso).
 *
 * Es la fuente de tres indicadores legales, y de ahí las constantes de abajo:
 *  · AUS-CM  -> días de las ausencias por CAUSA MÉDICA
 *  · IS-AT   -> días perdidos por ACCIDENTE DE TRABAJO
 *  · PREV-EL -> casos de enfermedad laboral
 */
class Absence extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_id', 'fecha_inicio', 'fecha_fin', 'dias', 'tipo',
        'diagnostico', 'cie10', 'entidad', 'incapacidad_numero', 'prorroga',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date:Y-m-d',
            'fecha_fin' => 'date:Y-m-d',
            'dias' => 'integer',
            'prorroga' => 'boolean',
        ];
    }

    public const TIPOS = [
        'enfermedad_general',
        'accidente_trabajo',
        'enfermedad_laboral',
        'accidente_comun',
        'licencia_maternidad',
        'licencia_luto',
        'permiso',
        'otro',
    ];

    /**
     * Las que cuentan como «causa médica» para AUS-CM.
     *
     * Un permiso o una licencia de luto son ausencias reales, pero no son causa
     * médica: meterlas en el indicador inflaría el ausentismo médico con algo
     * que el SG-SST no puede intervenir.
     */
    public const CAUSA_MEDICA = [
        'enfermedad_general',
        'accidente_trabajo',
        'enfermedad_laboral',
        'accidente_comun',
    ];

    protected static function booted(): void
    {
        static::saving(function (Absence $ausencia): void {
            // Si no vino el número de días, se propone con las fechas (ambos
            // extremos incluidos: una incapacidad de un solo día es 1, no 0).
            // El consultor puede corregirlo, porque hay empresas que cuentan
            // solo días hábiles.
            if (! $ausencia->dias && $ausencia->fecha_inicio && $ausencia->fecha_fin) {
                $ausencia->dias = $ausencia->fecha_inicio->diffInDays($ausencia->fecha_fin) + 1;
            }
        });
    }

    public function scopeCausaMedica(Builder $query): Builder
    {
        return $query->whereIn('tipo', self::CAUSA_MEDICA);
    }

    public function scopeEnPeriodo(Builder $query, string $desde, string $hasta): Builder
    {
        // Se filtra por la fecha de INICIO y no por solapamiento de rango: es
        // como CMK cuenta el ausentismo del mes en sus hojas, y cambiarlo daría
        // cifras distintas a las que el cliente ya conoce.
        return $query->whereBetween('fecha_inicio', [$desde, $hasta]);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
