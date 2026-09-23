<?php

namespace App\Services\Reportes\Secciones;

use App\Models\ChangeRequest;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/** Solicitudes de cambio del periodo. El estado se deduce (ChangeRequest::estado). */
class GestionCambio extends Seccion
{
    public function clave(): string
    {
        return 'gestion-cambio';
    }

    public function titulo(): string
    {
        return 'Gestión del cambio';
    }

    public function modulo(): ?string
    {
        return 'gestion-cambio';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $cambios = $periodo->filtrar(ChangeRequest::query(), 'fecha_solicitud')->orderBy('fecha_solicitud')->get();
        $cerrados = $cambios->where('estado', 'cerrado');
        $vencidos = ChangeRequest::all()->filter(fn ($c) => $c->vencido)->count();

        $this->cifra('Solicitudes en el periodo', $cambios->count());
        $this->cifra('Pendientes de aprobación', $cambios->where('estado', 'pendiente_aprobacion')->count());
        $this->cifra('Aprobadas en curso', $cambios->where('estado', 'aprobado')->count());
        $this->cifra('Cerradas', $cerrados->count());
        $this->cifra('Cerradas eficaces', self::porcentaje(self::pct($cerrados->where('cierre_eficaz', true)->count(), $cerrados->count())));
        $this->cifra('Vencidas hoy', $vencidos, $vencidos ? "{$vencidos} cambio(s) aprobado(s) sin cerrar pasada su fecha límite." : null);

        $this->tabla('Solicitudes del periodo', ['Fecha', 'Tipo', 'Descripción', 'Estado'],
            $cambios->map(fn ($c) => [self::fecha($c->fecha_solicitud), self::etiqueta($c->tipo), self::corto($c->descripcion, 80), self::etiqueta($c->estado)]),
            'Sin solicitudes de cambio en el periodo.');
    }
}
