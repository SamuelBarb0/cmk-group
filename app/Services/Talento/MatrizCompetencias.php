<?php

namespace App\Services\Talento;

use App\Models\CompetencyAssessment;
use App\Models\Employee;
use App\Models\JobPosition;
use App\Models\JobPositionRequirement;
use App\Models\TrainingAttendee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cruza los trabajadores de cada cargo con lo que el cargo exige y dice, celda
 * por celda, si cumplen (ISO 7.2 c: «asegurarse de que las personas sean
 * competentes» y conservar la evidencia).
 *
 * Una celda sale, en este orden:
 *   1. de una capacitación realizada, eficaz y vigente del tema del requisito
 *      (si el requisito tiene tema): `cumple`, fuente capacitación;
 *   2. de la evaluación a mano, si la hay: `cumple` o `no_cumple`;
 *   3. si el requisito tiene tema: `vencido` si la tuvo y ya venció, `falta`
 *      si nunca la tomó (o la reprobó);
 *   4. si no: `sin_evaluar`.
 *
 * Una capacitación reprobada (`eficaz` = false) no cuenta; una sin nota
 * (no evalúa, o todavía no la califican) sí, porque la asistencia es la
 * evidencia que queda.
 */
class MatrizCompetencias
{
    public const ESTADOS = [
        'cumple' => 'Cumple',
        'vencido' => 'Vencido',
        'falta' => 'Falta',
        'no_cumple' => 'No cumple',
        'sin_evaluar' => 'Sin evaluar',
    ];

    /** Estados que son una brecha: el trabajador no demuestra el requisito. */
    public const BRECHA = ['falta', 'vencido', 'no_cumple'];

    /**
     * La matriz de un cargo.
     *
     * @return array{empleados: Collection<int, Employee>, requisitos: Collection<int, JobPositionRequirement>, celdas: array<string, array{estado: string, detalle: ?string, evidencia: ?string, fuente: ?string}>}
     */
    public function deCargo(JobPosition $cargo): array
    {
        $empleados = $cargo->empleados();
        $requisitos = $cargo->requirements()->with('topic:id,codigo,titulo')->get();

        return [
            'empleados' => $empleados,
            'requisitos' => $requisitos,
            'celdas' => $this->celdas($empleados, $requisitos),
        ];
    }

    /**
     * Resumen por cargo y brechas de formación de toda la empresa.
     *
     * @return array{cargos: list<array<string, mixed>>, brechas: list<array<string, mixed>>, sin_cargo: int}
     */
    public function resumen(): array
    {
        $cargos = JobPosition::query()->with(['process:id,sigla', 'requirements.topic:id,titulo'])
            ->orderBy('orden')->orderBy('nombre')->get();
        $empleados = Employee::query()->where('is_active', true)->orderBy('apellidos')->orderBy('nombres')->get();
        $porCargo = $empleados->groupBy(fn (Employee $e) => JobPosition::normalizar($e->cargo));

        $filas = [];
        $brechas = [];
        foreach ($cargos as $cargo) {
            $suyos = $porCargo->get(JobPosition::normalizar($cargo->nombre), collect())->values();
            $celdas = $this->celdas($suyos, $cargo->requirements);
            $cumple = collect($celdas)->where('estado', 'cumple')->count();

            foreach ($cargo->requirements->where('tipo', 'formacion') as $r) {
                $sinCumplir = $suyos->filter(fn (Employee $e) => in_array($celdas["{$e->id}-{$r->id}"]['estado'], self::BRECHA, true));
                if ($sinCumplir->isNotEmpty()) {
                    $brechas[] = [
                        'cargo' => $cargo->nombre,
                        'job_position_id' => $cargo->id,
                        'requisito_id' => $r->id,
                        'requisito' => $r->descripcion,
                        'tema' => $r->topic?->titulo,
                        'training_topic_id' => $r->training_topic_id,
                        'personas' => $sinCumplir->map(fn (Employee $e) => [
                            'id' => $e->id,
                            'nombre' => trim("{$e->nombres} {$e->apellidos}"),
                            'estado' => $celdas["{$e->id}-{$r->id}"]['estado'],
                        ])->values()->all(),
                    ];
                }
            }

            $filas[] = [
                'id' => $cargo->id,
                'nombre' => $cargo->nombre,
                'proceso' => $cargo->process?->sigla,
                'perfil_completo' => $cargo->perfilCompleto(),
                'trabajadores' => $suyos->count(),
                'requisitos' => $cargo->requirements->count(),
                'celdas' => count($celdas),
                'cumple' => $cumple,
                'porcentaje' => count($celdas) ? (int) round(100 * $cumple / count($celdas)) : null,
            ];
        }

        $nombres = $cargos->map(fn (JobPosition $c) => JobPosition::normalizar($c->nombre))->all();

        return [
            'cargos' => $filas,
            'brechas' => $brechas,
            // Trabajadores cuyo cargo no tiene perfil: nadie ha dicho qué se les exige.
            'sin_cargo' => $empleados->filter(fn (Employee $e) => ! in_array(JobPosition::normalizar($e->cargo), $nombres, true))->count(),
        ];
    }

    /**
     * @param  Collection<int, Employee>  $empleados
     * @param  Collection<int, JobPositionRequirement>  $requisitos
     * @return array<string, array{estado: string, detalle: ?string, evidencia: ?string, fuente: ?string}>
     */
    public function celdas(Collection $empleados, Collection $requisitos): array
    {
        if ($empleados->isEmpty() || $requisitos->isEmpty()) {
            return [];
        }

        $evaluaciones = CompetencyAssessment::query()
            ->whereIn('employee_id', $empleados->pluck('id'))
            ->whereIn('job_position_requirement_id', $requisitos->pluck('id'))->get()
            ->keyBy(fn (CompetencyAssessment $a) => "{$a->employee_id}-{$a->job_position_requirement_id}");
        $capacitaciones = $this->capacitaciones($empleados->pluck('id')->all(),
            $requisitos->pluck('training_topic_id')->filter()->unique()->values()->all());
        $hoy = Carbon::today();

        $celdas = [];
        foreach ($empleados as $e) {
            foreach ($requisitos as $r) {
                $celdas["{$e->id}-{$r->id}"] = $this->celda($r, $evaluaciones->get("{$e->id}-{$r->id}"),
                    $r->training_topic_id ? $capacitaciones->get("{$e->id}-{$r->training_topic_id}") : null, $hoy);
            }
        }

        return $celdas;
    }

    /**
     * @param  array{titulo: string, fecha: ?Carbon, vence: ?Carbon}|null  $cap
     * @return array{estado: string, detalle: ?string, evidencia: ?string, fuente: ?string}
     */
    private function celda(JobPositionRequirement $r, ?CompetencyAssessment $eval, ?array $cap, Carbon $hoy): array
    {
        if ($cap && ($cap['vence'] === null || $cap['vence']->gte($hoy))) {
            return [
                'estado' => 'cumple', 'fuente' => 'capacitacion', 'evidencia' => null,
                'detalle' => "Capacitación «{$cap['titulo']}»"
                    .($cap['fecha'] ? ' del '.$cap['fecha']->toDateString() : '')
                    .($cap['vence'] ? ', vence el '.$cap['vence']->toDateString() : ''),
            ];
        }
        if ($eval) {
            return [
                'estado' => $eval->cumple ? 'cumple' : 'no_cumple', 'fuente' => 'evaluacion', 'evidencia' => $eval->evidencia,
                'detalle' => 'Evaluado el '.$eval->evaluado_at->toDateString().($eval->evaluado_por ? " por {$eval->evaluado_por}" : ''),
            ];
        }
        if ($r->training_topic_id) {
            return $cap
                ? ['estado' => 'vencido', 'fuente' => 'capacitacion', 'evidencia' => null, 'detalle' => "Capacitación «{$cap['titulo']}» vencida el ".$cap['vence']->toDateString()]
                : ['estado' => 'falta', 'fuente' => null, 'evidencia' => null, 'detalle' => 'Sin capacitación de este tema'];
        }

        return ['estado' => 'sin_evaluar', 'fuente' => null, 'evidencia' => null, 'detalle' => null];
    }

    /**
     * La capacitación que cuenta, por trabajador y tema: la que vence más
     * tarde (sin vencimiento gana) y, a igualdad, la más reciente.
     *
     * @param  list<int>  $empleados
     * @param  list<int>  $temas
     * @return Collection<string, array{titulo: string, fecha: ?Carbon, vence: ?Carbon}>
     */
    private function capacitaciones(array $empleados, array $temas): Collection
    {
        if ($temas === []) {
            return collect();
        }

        return TrainingAttendee::query()
            ->whereIn('employee_id', $empleados)
            ->where('asistio', true)
            ->where(fn ($q) => $q->whereNull('eficaz')->orWhere('eficaz', true))
            ->whereHas('training', fn ($q) => $q->where('estado', 'realizada')->whereIn('training_topic_id', $temas))
            ->with('training:id,training_topic_id,titulo,fecha,vigencia_meses')
            ->get()
            ->map(function (TrainingAttendee $a) {
                $t = $a->training;
                $fecha = $t->fecha ? Carbon::parse($t->fecha) : null;

                return [
                    'clave' => "{$a->employee_id}-{$t->training_topic_id}",
                    'titulo' => $t->titulo,
                    'fecha' => $fecha,
                    'vence' => $fecha && $t->vigencia_meses ? $fecha->copy()->addMonths($t->vigencia_meses) : null,
                ];
            })
            // keyBy se queda con el último: se ordena de peor a mejor.
            ->sortBy(fn (array $c) => sprintf('%020d-%020d', $c['vence'] ? $c['vence']->timestamp : PHP_INT_MAX, $c['fecha']?->timestamp ?? 0))
            ->keyBy('clave');
    }
}
