<?php

namespace App\Services\Reportes\Secciones;

use App\Models\RiskOpportunity;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/**
 * Riesgos y oportunidades de los procesos: foto actual, con lo que un
 * auditor pregunta primero (riesgos altos sin tratar y tratamientos cerrados
 * sin evaluar su eficacia).
 */
class RiesgosOportunidades extends Seccion
{
    public function clave(): string
    {
        return 'riesgos-oportunidades';
    }

    public function titulo(): string
    {
        return 'Riesgos y oportunidades';
    }

    public function modulo(): ?string
    {
        return 'riesgos-oportunidades';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $filas = RiskOpportunity::query()->with('process:id,sigla')->get();
        $riesgos = $filas->where('tipo', 'riesgo');
        $altos = $riesgos->whereIn('nivel', ['alto', 'critico']);
        $sinTratar = $filas->filter(fn (RiskOpportunity $r) => $r->sinTratar());
        $sinEficacia = $filas->where('estado', 'cerrado')->filter(fn ($r) => blank($r->eficacia));

        $this->cifra('Riesgos', $riesgos->count());
        $this->cifra('Oportunidades', $filas->where('tipo', 'oportunidad')->count());
        $this->cifra('Riesgos altos o críticos', $altos->count());
        $this->cifra('Altos o críticos sin tratar', $sinTratar->count(),
            $sinTratar->count() ? 'Hay riesgos altos o críticos sin acción ni responsable.' : null);
        $this->cifra('Tratamientos cerrados sin evaluar eficacia', $sinEficacia->count(),
            $sinEficacia->count() ? 'ISO 9001 6.1.2 pide evaluar la eficacia de las acciones.' : null);

        $this->tabla('Riesgos altos y críticos', ['Proceso', 'Riesgo', 'P × I', 'Tratamiento', 'Estado'],
            $altos->sortByDesc('valor')->map(fn (RiskOpportunity $r) => [
                $r->process?->sigla ?? 'General', self::corto($r->descripcion, 80), "{$r->probabilidad} × {$r->impacto}",
                ucfirst($r->tratamiento), str_replace('_', ' ', $r->estado),
            ]),
            'Sin riesgos altos o críticos.');
    }
}
