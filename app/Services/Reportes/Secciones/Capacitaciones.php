<?php

namespace App\Services\Reportes\Secciones;

use App\Models\Training;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/** Capacitaciones del periodo: ejecución, cobertura y eficacia. */
class Capacitaciones extends Seccion
{
    public function clave(): string
    {
        return 'capacitaciones';
    }

    public function titulo(): string
    {
        return 'Capacitaciones';
    }

    public function modulo(): ?string
    {
        return 'capacitaciones';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $capacitaciones = $periodo->filtrar(Training::query(), 'fecha')
            ->with('attendees:id,training_id,asistio,eficaz')->orderBy('fecha')->get();
        $realizadas = $capacitaciones->where('estado', 'realizada');
        // Solo cuentan asistentes de las realizadas: una programada todavía no
        // tuvo a quién cubrir.
        $asistentes = $realizadas->flatMap->attendees;
        $asistieron = $asistentes->where('asistio', true);
        $evaluados = $asistieron->whereNotNull('eficaz');

        $ejecucion = self::pct($realizadas->count(), $capacitaciones->count());
        $cobertura = self::pct($asistieron->count(), $asistentes->count());
        $eficacia = self::pct($evaluados->where('eficaz', true)->count(), $evaluados->count());

        $this->cifra('Programadas en el periodo', $capacitaciones->count());
        $this->cifra('Realizadas', $realizadas->count());
        $this->cifra('Ejecución', self::porcentaje($ejecucion),
            $ejecucion !== null && $ejecucion < 90 ? 'Ejecución de capacitaciones en '.self::porcentaje($ejecucion).' (meta 90 %).' : null);
        $this->cifra('Cobertura (asistieron / convocados)', self::porcentaje($cobertura),
            $cobertura !== null && $cobertura < 90 ? 'Cobertura de capacitación en '.self::porcentaje($cobertura).' (meta 90 %).' : null);
        $this->cifra('Eficacia (aprobaron / evaluados)', self::porcentaje($eficacia),
            $eficacia !== null && $eficacia < 90 ? 'Eficacia de la capacitación en '.self::porcentaje($eficacia).' (meta 90 %).' : null);
        $this->cifra('Personas capacitadas', $asistieron->count());

        $this->tabla('Capacitaciones del periodo', ['Fecha', 'Tema', 'Estado', 'Convocados', 'Asistieron', 'Eficacia'],
            $capacitaciones->map(function ($c) {
                $ev = $c->attendees->where('asistio', true)->whereNotNull('eficaz');

                return [
                    self::fecha($c->fecha), self::corto($c->titulo, 70), self::etiqueta($c->estado),
                    $c->attendees->count(), $c->attendees->where('asistio', true)->count(),
                    $c->evalua_eficacia ? self::porcentaje(self::pct($ev->where('eficaz', true)->count(), $ev->count())) : 'No se evalúa',
                ];
            }),
            'Sin capacitaciones en el periodo.');
    }
}
