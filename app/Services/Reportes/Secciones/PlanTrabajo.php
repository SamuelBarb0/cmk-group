<?php

namespace App\Services\Reportes\Secciones;

use App\Models\WorkPlan;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;
use Carbon\CarbonImmutable;

/**
 * Plan de trabajo anual: lo programado para los meses del periodo contra lo
 * ejecutado. Misma regla que WorkPlan::recalcular(): un mes ejecutado solo
 * cuenta si estaba programado.
 */
class PlanTrabajo extends Seccion
{
    public const MESES = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public function clave(): string
    {
        return 'plan-trabajo';
    }

    public function titulo(): string
    {
        return 'Plan de trabajo anual';
    }

    public function modulo(): ?string
    {
        return 'plan-trabajo';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $programados = 0;
        $ejecutados = 0;
        $atrasadas = [];
        $sinPlan = [];

        foreach ($periodo->meses() as $anio => $meses) {
            $plan = WorkPlan::with('items.activity')->where('anio', $anio)->first();
            if (! $plan) {
                $sinPlan[] = $anio;

                continue;
            }
            foreach ($plan->items as $item) {
                if (! $plan->actividadAplica($item->work_plan_activity_id)) {
                    continue;
                }
                $prog = array_values(array_intersect($item->meses_programados ?? [], $meses));
                $hechos = array_intersect($prog, $item->meses_ejecutados ?? []);
                $programados += count($prog);
                $ejecutados += count($hechos);

                // Solo meses ya terminados: lo programado para este mes o
                // para después todavía no está atrasado.
                $vencidos = array_filter(array_diff($prog, $hechos),
                    fn ($m) => CarbonImmutable::create($anio, $m, 1)->endOfMonth()->isPast());
                if ($vencidos) {
                    $atrasadas[] = [
                        $item->activity?->codigo,
                        self::corto($item->activity?->nombre, 100),
                        implode(', ', array_map(fn ($m) => self::MESES[$m].' '.$anio, $vencidos)),
                        $item->responsable ?: $plan->responsable,
                    ];
                }
            }
        }

        if ($sinPlan) {
            $this->nota('Sin plan de trabajo registrado para '.implode(', ', $sinPlan).'.');
        }
        if ($programados === 0) {
            $this->nota('No hay actividades programadas en los meses del periodo.');

            return;
        }

        $cumplimiento = self::pct($ejecutados, $programados);
        $this->cifra('Actividades-mes programadas', $programados);
        $this->cifra('Ejecutadas', $ejecutados);
        $this->cifra('Cumplimiento del plan', self::porcentaje($cumplimiento),
            $cumplimiento < 90 ? 'Cumplimiento del plan de trabajo en '.self::porcentaje($cumplimiento).' (meta 90 %).' : null);

        $this->tabla('Actividades atrasadas', ['Código', 'Actividad', 'Meses sin ejecutar', 'Responsable'],
            $atrasadas, 'No hay actividades atrasadas en el periodo.');
    }
}
