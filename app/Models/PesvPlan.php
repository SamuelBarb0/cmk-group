<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan Estratégico de Seguridad Vial de una empresa cliente.
 *
 * Uno por tenant (unique en la migración). Agrupa el estado de los 24 pasos,
 * los datos del líder designado y el comité de seguridad vial.
 */
class PesvPlan extends Model
{
    use BelongsToTenant;

    /** Niveles de la Res. 40595. Lo fija el consultor. */
    public const NIVELES = ['basico', 'estandar', 'avanzado'];

    /**
     * Misionalidad para efectos del PESV (anexo, «Objeto, ámbito de aplicación»):
     * 1 = presta el servicio de transporte terrestre automotor, 2 = cualquier
     * otra actividad. Con el tamaño (flota o conductores) define el nivel.
     */
    public const MISIONALIDADES = [
        1 => 'Presta el servicio de transporte terrestre automotor',
        2 => 'Actividad diferente al transporte',
    ];

    /**
     * Umbrales de la Tabla 1 del anexo: [misionalidad => [nivel => mínimo]],
     * por flota y por conductores. Se toma el nivel MÁS ALTO de los dos.
     * Por debajo del básico (≤ 10 vehículos y ≤ 1 conductor) no hay
     * obligación de PESV.
     */
    private const UMBRALES = [
        1 => ['vehiculos' => ['avanzado' => 51, 'estandar' => 20, 'basico' => 11],
            'conductores' => ['avanzado' => 51, 'estandar' => 20, 'basico' => 2]],
        2 => ['vehiculos' => ['avanzado' => 101, 'estandar' => 50, 'basico' => 11],
            'conductores' => ['avanzado' => 101, 'estandar' => 50, 'basico' => 2]],
    ];

    protected $fillable = [
        'misionalidad',
        'nivel',
        'periodo_inicio',
        'periodo_fin',
        'lider_nombre',
        'lider_cargo',
        'lider_documento',
        'lider_designacion_fecha',
        'avance',
    ];

    protected function casts(): array
    {
        return [
            'lider_designacion_fecha' => 'date:Y-m-d',
            'periodo_inicio' => 'integer',
            'periodo_fin' => 'integer',
            'misionalidad' => 'integer',
            'avance' => 'decimal:2',
        ];
    }

    /**
     * Nivel que exige la Res. 40595 (Tabla 1) para esa misionalidad y tamaño.
     * null = por debajo del umbral: la empresa no está obligada a tener PESV.
     */
    public static function nivelPorNorma(int $misionalidad, int $vehiculos, int $conductores): ?string
    {
        $umbrales = self::UMBRALES[$misionalidad] ?? null;
        if (! $umbrales) {
            return null;
        }

        foreach (['avanzado', 'estandar', 'basico'] as $nivel) {
            if ($vehiculos >= $umbrales['vehiculos'][$nivel] || $conductores >= $umbrales['conductores'][$nivel]) {
                return $nivel;
            }
        }

        return null;
    }

    /** @return HasMany<PesvPlanStep, $this> */
    public function pasos(): HasMany
    {
        return $this->hasMany(PesvPlanStep::class, 'pesv_plan_id');
    }

    /** @return HasMany<PesvCommitteeMember, $this> */
    public function comite(): HasMany
    {
        return $this->hasMany(PesvCommitteeMember::class, 'pesv_plan_id');
    }

    /**
     * Recalcula el avance: porcentaje de pasos cumplidos sobre los que aplican.
     *
     * Los pasos marcados "no_aplica" salen del denominador —igual que en el
     * diagnóstico de la Res. 0312— para que no castiguen a una empresa por algo
     * que la norma no le exige.
     */
    public function recalcular(): void
    {
        $this->avance = $this->calcularAvance();
        $this->save();
    }

    /**
     * Cumplidos sobre los pasos que aplican.
     *
     * El denominador son los pasos del CATÁLOGO que la norma exige para el
     * nivel del plan, no las filas guardadas: antes se dividía por las filas,
     * y un plan recién creado con un solo paso en «cumple» marcaba 100 %.
     */
    public function calcularAvance(): float
    {
        $estados = $this->pasos()->pluck('estado', 'pesv_step_id');
        $aplican = PesvStep::all()
            ->filter(fn (PesvStep $s) => $s->aplicaA($this->nivel))
            ->reject(fn (PesvStep $s) => ($estados[$s->id] ?? null) === 'no_aplica');

        if ($aplican->isEmpty()) {
            return 0;
        }

        return round($aplican->filter(fn (PesvStep $s) => ($estados[$s->id] ?? null) === 'cumple')->count() * 100 / $aplican->count(), 2);
    }
}
