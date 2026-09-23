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
        'encuesta_token',
        'encuesta_activa',
        'diagnostico_analisis',
    ];

    protected function casts(): array
    {
        return [
            'lider_designacion_fecha' => 'date:Y-m-d',
            'periodo_inicio' => 'integer',
            'periodo_fin' => 'integer',
            'misionalidad' => 'integer',
            'avance' => 'decimal:2',
            'encuesta_activa' => 'boolean',
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
     * Preguntas de la lista de verificación (Tabla 16) en «cumple» sobre las
     * que exige el nivel del plan, sin las «no aplica».
     *
     * Se mide por PREGUNTA y no por paso: un paso con 5 preguntas y 4
     * cumplidas no está en cero. El denominador son las preguntas del
     * catálogo que aplican al nivel, no las respondidas: si no, un plan con
     * una sola respuesta daría 100 %.
     */
    public function calcularAvance(): float
    {
        $estados = $this->criterios()->pluck('estado', 'pesv_criterion_id');
        $aplican = PesvCriterion::all()
            ->filter(fn (PesvCriterion $c) => $c->aplicaA($this->nivel))
            ->reject(fn (PesvCriterion $c) => ($estados[$c->id] ?? null) === 'no_aplica');

        if ($aplican->isEmpty()) {
            return 0;
        }

        return round($aplican->filter(fn (PesvCriterion $c) => ($estados[$c->id] ?? null) === 'cumple')->count() * 100 / $aplican->count(), 2);
    }

    /**
     * Estado de un paso a partir de sus preguntas que aplican al nivel:
     * ninguna verificada → pendiente; todas «no aplica» → no aplica; alguna
     * «no cumple» → no cumple; todas cumplidas → cumple; si no, en proceso.
     *
     * @param  iterable<string>  $estados  estados de las preguntas que aplican
     */
    public static function estadoDelPaso(iterable $estados): string
    {
        $e = collect($estados);
        $verificadas = $e->reject(fn ($x) => $x === 'no_verificado');

        return match (true) {
            $verificadas->isEmpty() => 'pendiente',
            $e->every(fn ($x) => $x === 'no_aplica') => 'no_aplica',
            $e->contains('no_cumple') => 'no_cumple',
            $e->every(fn ($x) => in_array($x, ['cumple', 'no_aplica'], true)) => 'cumple',
            default => 'en_proceso',
        };
    }

    /** Recalcula y guarda el estado de un paso tras responder una de sus preguntas. */
    public function sincronizarPaso(PesvStep $paso): string
    {
        $respuestas = $this->criterios()->pluck('estado', 'pesv_criterion_id');
        $estado = self::estadoDelPaso(
            PesvCriterion::where('pesv_step_id', $paso->id)->get()
                ->filter(fn (PesvCriterion $c) => $c->aplicaA($this->nivel))
                ->map(fn (PesvCriterion $c) => $respuestas[$c->id] ?? 'no_verificado'),
        );

        $this->pasos()->updateOrCreate(['pesv_step_id' => $paso->id], ['estado' => $estado]);

        return $estado;
    }

    /** @return HasMany<PesvPlanCriterion, $this> */
    public function criterios(): HasMany
    {
        return $this->hasMany(PesvPlanCriterion::class, 'pesv_plan_id');
    }
}
