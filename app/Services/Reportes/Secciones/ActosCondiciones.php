<?php

namespace App\Services\Reportes\Secciones;

use App\Models\SafetyReport;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/** Reportes de actos y condiciones inseguras levantados en el periodo. */
class ActosCondiciones extends Seccion
{
    public function clave(): string
    {
        return 'reportes-ac';
    }

    public function titulo(): string
    {
        return 'Actos y condiciones inseguras';
    }

    public function modulo(): ?string
    {
        return 'reportes-ac';
    }

    public function permiso(): string
    {
        return 'incidents.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $reportes = $periodo->filtrar(SafetyReport::query(), 'fecha')->orderBy('fecha')->get();
        // Misma regla que el scope intervenidos(): cerrado también cuenta.
        $intervenidos = $reportes->whereIn('estado', ['intervenido', 'cerrado'])->count();
        $criticos = $reportes->where('severidad', 'critico')->where('estado', 'reportado')->count();
        $porcentaje = self::pct($intervenidos, $reportes->count());

        $this->cifra('Reportes', $reportes->count());
        $this->cifra('Actos inseguros', $reportes->where('tipo', 'acto')->count());
        $this->cifra('Condiciones inseguras', $reportes->where('tipo', 'condicion')->count());
        $this->cifra('Intervenidos', self::porcentaje($porcentaje),
            $porcentaje !== null && $porcentaje < 90 ? 'Solo el '.self::porcentaje($porcentaje).' de los actos y condiciones reportados fue intervenido (meta 90 %).' : null);
        $this->cifra('Críticos sin intervenir', $criticos, $criticos ? "{$criticos} reporte(s) de severidad crítica sin intervenir." : null);

        $this->tabla('Por severidad', ['Severidad', 'Reportes', 'Intervenidos'],
            collect(SafetyReport::SEVERIDADES)->reverse()->map(fn ($s) => [
                self::etiqueta($s),
                $reportes->where('severidad', $s)->count(),
                $reportes->where('severidad', $s)->whereIn('estado', ['intervenido', 'cerrado'])->count(),
            ])->filter(fn ($f) => $f[1] > 0),
            'Sin reportes en el periodo.');

        $this->tabla('Pendientes de intervenir', ['Fecha', 'Tipo', 'Área', 'Descripción', 'Severidad'],
            $reportes->where('estado', 'reportado')->sortByDesc(fn ($r) => array_search($r->severidad, SafetyReport::SEVERIDADES, true))
                ->map(fn ($r) => [self::fecha($r->fecha), self::etiqueta($r->tipo), $r->area ?: '—', self::corto($r->descripcion, 80), self::etiqueta($r->severidad)]),
            'Todos los reportes del periodo fueron intervenidos.');
    }
}
