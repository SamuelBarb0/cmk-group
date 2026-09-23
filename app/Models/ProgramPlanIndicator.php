<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Indicador de un programa de gestión.
 *
 * El automático (cumplimiento) se calcula del cronograma del programa; los
 * demás, de las lecturas por periodo: valor = numerador / denominador × constante.
 */
class ProgramPlanIndicator extends Model
{
    protected $fillable = [
        'clave', 'nombre', 'numerador_label', 'denominador_label', 'constante',
        'meta', 'meta_texto', 'sentido', 'frecuencia', 'automatico', 'lecturas', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'constante' => 'float',
            'meta' => 'float',
            'automatico' => 'boolean',
            'lecturas' => 'array',
        ];
    }

    /** @return BelongsTo<ProgramPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProgramPlan::class, 'program_plan_id');
    }

    public function periodos(): int
    {
        return ProgramPlan::FRECUENCIAS[$this->frecuencia] ?? 1;
    }

    /**
     * Numerador y denominador de cada periodo, y el acumulado del año.
     * El acumulado suma numeradores y denominadores (no promedia porcentajes):
     * un semestre con 2 de 2 y otro con 1 de 10 no es un 55 %, es un 25 %.
     *
     * @return array{periodos: list<array{periodo: int, numerador: ?float, denominador: ?float, valor: ?float}>, anual: ?float}
     */
    public function valores(ProgramPlan $plan): array
    {
        $filas = [];
        $sumN = 0.0;
        $sumD = 0.0;

        for ($p = 1; $p <= $this->periodos(); $p++) {
            if ($this->automatico) {
                $c = $plan->conteo(ProgramPlan::mesesDelPeriodo($this->frecuencia, $p));
                $n = (float) $c['ejecutadas'];
                $d = (float) $c['programadas'];
            } else {
                $l = $this->lecturas[(string) $p] ?? null;
                $n = isset($l['numerador']) ? (float) $l['numerador'] : null;
                $d = isset($l['denominador']) ? (float) $l['denominador'] : null;
            }

            $valor = ($n !== null && $d !== null && $d > 0) ? round($n / $d * $this->constante, 1) : null;
            if ($valor !== null) {
                $sumN += $n;
                $sumD += $d;
            }

            $filas[] = ['periodo' => $p, 'numerador' => $n, 'denominador' => $d, 'valor' => $valor];
        }

        return [
            'periodos' => $filas,
            'anual' => $sumD > 0 ? round($sumN / $sumD * $this->constante, 1) : null,
        ];
    }

    /** null si no hay meta o no hay valor: sin dato no se pinta ni verde ni rojo. */
    public function cumpleMeta(?float $valor): ?bool
    {
        if ($valor === null || $this->meta === null) {
            return null;
        }

        return $this->sentido === 'desc' ? $valor <= $this->meta : $valor >= $this->meta;
    }
}
