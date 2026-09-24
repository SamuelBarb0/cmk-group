<?php

namespace App\Services\Reportes\Secciones;

use App\Models\Audit;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/** Auditorías programadas en el periodo y sus hallazgos. */
class Auditoria extends Seccion
{
    private const NC = ['no_conformidad_mayor', 'no_conformidad_menor'];

    public function clave(): string
    {
        return 'auditoria';
    }

    public function titulo(): string
    {
        return 'Auditoría';
    }

    public function modulo(): ?string
    {
        return 'auditoria';
    }

    public function permiso(): string
    {
        return 'audit.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $auditorias = $periodo->filtrar(Audit::query(), 'fecha_programada')->with('findings')->orderBy('fecha_programada')->get();
        $hallazgos = $auditorias->flatMap->findings;
        $ncSinAccion = $hallazgos->whereIn('tipo', self::NC)->whereNull('acpm_action_id')->count();

        $this->cifra('Auditorías programadas', $auditorias->count());
        $this->cifra('Cerradas', $auditorias->where('estado', 'cerrada')->count());
        $this->cifra('No conformidades mayores', $hallazgos->where('tipo', 'no_conformidad_mayor')->count());
        $this->cifra('No conformidades menores', $hallazgos->where('tipo', 'no_conformidad_menor')->count());
        $this->cifra('Fortalezas', $hallazgos->where('tipo', 'fortaleza')->count());
        $this->cifra('No conformidades sin acción', $ncSinAccion,
            $ncSinAccion ? "{$ncSinAccion} no conformidad(es) de auditoría sin acción correctiva asociada." : null);

        $this->tabla('Auditorías del periodo', ['Código', 'Tipo', 'Fecha', 'Estado', 'No conformidades', 'Observaciones'],
            $auditorias->map(fn ($a) => [
                $a->codigo, self::etiqueta($a->tipo), self::fecha($a->fecha_programada), self::etiqueta($a->estado),
                $a->findings->whereIn('tipo', self::NC)->count(), $a->findings->where('tipo', 'observacion')->count(),
            ]),
            'Sin auditorías programadas en el periodo.');

        // Solo las auditorías que se hicieron contra la tabla de requisitos
        // tienen cumplimiento por norma; las anteriores no traen normas.
        $conAlcance = $auditorias->filter(fn ($a) => ! empty($a->sistemas));
        if ($conAlcance->isNotEmpty()) {
            $this->tabla('Cumplimiento por norma', ['Auditoría', 'Norma', 'Cumplimiento', 'Conformes', 'No conformes', 'Pendientes'],
                $conAlcance->flatMap(fn ($a) => collect($a->cumplimientoPorNorma())->map(fn ($n) => [
                    $a->codigo, $n['nombre'], $n['cumplimiento'] === null ? 'Sin evaluar' : $n['cumplimiento'].' %',
                    $n['conformes'], $n['no_conformes'], $n['pendientes'],
                ])),
                'Sin auditorías con normas definidas.');
        }
    }
}
