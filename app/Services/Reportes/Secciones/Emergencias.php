<?php

namespace App\Services\Reportes\Secciones;

use App\Models\BrigadeMember;
use App\Models\EmergencyDrill;
use App\Models\EmergencyEquipment;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/** Simulacros del periodo; brigada y equipos, a la fecha. */
class Emergencias extends Seccion
{
    public function clave(): string
    {
        return 'emergencias';
    }

    public function titulo(): string
    {
        return 'Preparación ante emergencias';
    }

    public function modulo(): ?string
    {
        return 'emergencias';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $simulacros = $periodo->filtrar(EmergencyDrill::query(), 'fecha')->with('recommendations')->orderBy('fecha')->get();
        $realizados = $simulacros->where('estado', 'realizado');
        $participacion = self::pct($realizados->sum('participantes'), $realizados->sum('convocados'));
        $recomendaciones = $simulacros->flatMap->recommendations;
        $pendientes = $recomendaciones->where('implementada', false)->count();

        $brigada = BrigadeMember::where('activo', true)->get(['id', 'curso_primer_respondiente']);
        $equipos = EmergencyEquipment::all();
        $vencidos = $equipos->filter(fn ($e) => $e->vencido)->count();

        $this->cifra('Simulacros programados', $simulacros->count());
        $this->cifra('Simulacros realizados', $realizados->count());
        $this->cifra('Participación', self::porcentaje($participacion),
            $participacion !== null && $participacion < 80 ? 'Participación en simulacros del '.self::porcentaje($participacion).' (meta 80 %).' : null);
        $this->cifra('Recomendaciones sin implementar', $pendientes,
            $pendientes ? "{$pendientes} recomendación(es) de simulacros sin implementar." : null);
        $this->cifra('Brigadistas activos', $brigada->count(), $brigada->isEmpty() ? 'La empresa no tiene brigada de emergencias activa.' : null);
        $this->cifra('Brigadistas sin curso de primer respondiente', $brigada->where('curso_primer_respondiente', false)->count());
        $this->cifra('Equipos de emergencia vencidos', $vencidos,
            $vencidos ? "{$vencidos} equipo(s) de emergencia vencido(s) (extintores, botiquines…)." : null);

        $this->tabla('Simulacros del periodo', ['Fecha', 'Tipo', 'Escenario', 'Estado', 'Participación', 'Evacuación'],
            $simulacros->map(fn ($s) => [
                self::fecha($s->fecha), self::etiqueta($s->tipo), self::corto($s->escenario, 50), self::etiqueta($s->estado),
                self::porcentaje(self::pct($s->participantes, $s->convocados)),
                $s->tiempo_evacuacion_segundos ? gmdate('i:s', $s->tiempo_evacuacion_segundos).' min' : '—',
            ]),
            'Sin simulacros en el periodo.');
        $this->nota('La norma pide al menos '.EmergencyDrill::META_ANUAL.' simulacros al año. Brigada y equipos son el estado a la fecha del informe.');
    }
}
