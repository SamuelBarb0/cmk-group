<?php

namespace App\Services\Reportes\Secciones;

use App\Models\AcpmAction;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/**
 * Acciones correctivas, preventivas y de mejora: las abiertas y cerradas en
 * el periodo, y cómo está HOY lo pendiente (lo vencido no espera al periodo).
 */
class Acpm extends Seccion
{
    public function clave(): string
    {
        return 'acpm';
    }

    public function titulo(): string
    {
        return 'Acciones correctivas, preventivas y de mejora';
    }

    public function modulo(): ?string
    {
        return 'acpm';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $abiertas = $periodo->filtrar(AcpmAction::query(), 'fecha_deteccion')->get();
        $cerradas = $periodo->filtrar(AcpmAction::query(), 'fecha_cierre')->where('estado', 'cerrada')->get();
        $pendientes = AcpmAction::pendientes()->orderBy('fecha_limite')->get();
        $vencidas = $pendientes->filter(fn ($a) => $a->vencida);
        $sinVerificar = AcpmAction::where('estado', 'cerrada')->whereNull('eficaz')->count();

        $this->cifra('Abiertas en el periodo', $abiertas->count());
        $this->cifra('Cerradas en el periodo', $cerradas->count());
        $this->cifra('Cerradas eficaces', $cerradas->where('eficaz', true)->count());
        $this->cifra('Pendientes hoy', $pendientes->count());
        $this->cifra('Vencidas hoy', $vencidas->count(),
            $vencidas->isNotEmpty() ? $vencidas->count().' acción(es) correctiva(s) o de mejora vencida(s).' : null);
        $this->cifra('Cerradas sin verificar eficacia', $sinVerificar);

        $this->tabla('Origen de las acciones abiertas en el periodo', ['Origen', 'Correctivas', 'Preventivas', 'Mejora'],
            collect(AcpmAction::ORIGENES)->map(fn ($o) => [
                self::etiqueta($o),
                $abiertas->where('origen_tipo', $o)->where('tipo', 'correctiva')->count(),
                $abiertas->where('origen_tipo', $o)->where('tipo', 'preventiva')->count(),
                $abiertas->where('origen_tipo', $o)->where('tipo', 'mejora')->count(),
            ])->filter(fn ($f) => $f[1] + $f[2] + $f[3] > 0),
            'No se abrieron acciones en el periodo.');

        $this->tabla('Acciones vencidas', ['Código', 'Hallazgo', 'Responsable', 'Fecha límite', 'Días de atraso'],
            $vencidas->map(fn ($a) => [$a->codigo, self::corto($a->hallazgo, 80), $a->responsable ?: '—', self::fecha($a->fecha_limite), abs((int) $a->dias_restantes)]),
            'No hay acciones vencidas.');
        $this->nota('Pendientes y vencidas son el estado a la fecha del informe.');
    }
}
