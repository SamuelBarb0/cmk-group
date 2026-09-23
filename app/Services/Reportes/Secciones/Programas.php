<?php

namespace App\Services\Reportes\Secciones;

use App\Models\ProgramPlan;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/** Programas de gestión (PVE, riesgo psicosocial, vial…): cumplimiento en los meses del periodo. */
class Programas extends Seccion
{
    public function clave(): string
    {
        return 'programas';
    }

    public function titulo(): string
    {
        return 'Programas de gestión';
    }

    public function modulo(): ?string
    {
        return 'programas';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $filas = [];
        $programadas = 0;
        $ejecutadas = 0;
        foreach ($periodo->meses() as $anio => $meses) {
            foreach (ProgramPlan::with('activities')->where('anio', $anio)->orderBy('codigo')->get() as $plan) {
                $c = $plan->conteo($meses);
                if ($c['programadas'] === 0) {
                    continue;
                }
                $programadas += $c['programadas'];
                $ejecutadas += $c['ejecutadas'];
                $filas[] = [$plan->codigo, self::corto($plan->nombre, 60), $anio, $c['programadas'], $c['ejecutadas'], self::porcentaje($plan->cumplimiento($meses))];
            }
        }

        $total = self::pct($ejecutadas, $programadas);
        $this->cifra('Programas con actividades en el periodo', count($filas));
        $this->cifra('Cumplimiento conjunto', self::porcentaje($total),
            $total !== null && $total < 90 ? 'Cumplimiento de los programas de gestión en '.self::porcentaje($total).' (meta 90 %).' : null);

        $this->tabla('Cumplimiento por programa', ['Código', 'Programa', 'Año', 'Actividades programadas', 'Ejecutadas', 'Cumplimiento'],
            $filas, 'Sin actividades de programas en el periodo.');
    }
}
