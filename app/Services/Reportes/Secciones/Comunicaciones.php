<?php

namespace App\Services\Reportes\Secciones;

use App\Models\CommunicationLog;
use App\Models\CommunicationPlanItem;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/**
 * Comunicaciones: lo registrado en el periodo y lo que HOY sigue esperando
 * respuesta (una respuesta vencida a una autoridad no espera al periodo).
 */
class Comunicaciones extends Seccion
{
    public function clave(): string
    {
        return 'comunicaciones';
    }

    public function titulo(): string
    {
        return 'Comunicaciones';
    }

    public function modulo(): ?string
    {
        return 'comunicaciones';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $registro = $periodo->filtrar(CommunicationLog::query(), 'fecha')->get();
        $pendientes = CommunicationLog::pendientes()->orderBy('fecha_limite_respuesta')->get();
        $vencidas = $pendientes->where('respuesta_vencida', true);

        $this->cifra('Comunicaciones en la matriz', CommunicationPlanItem::query()->count());
        $this->cifra('Registradas en el periodo', $registro->count());
        $this->cifra('Externas', $registro->where('tipo', 'externa')->count());
        $this->cifra('Recibidas', $registro->where('direccion', 'entrante')->count());
        $this->cifra('Esperando respuesta hoy', $pendientes->count());
        $this->cifra('Respuestas vencidas', $vencidas->count(),
            $vencidas->count() ? "{$vencidas->count()} comunicación(es) con la respuesta vencida." : null);

        $this->tabla('Pendientes de respuesta', ['Fecha', 'Parte interesada', 'Asunto', 'Plazo'],
            $pendientes->map(fn ($c) => [$c->fecha, $c->parte_interesada, self::corto($c->asunto, 70), $c->fecha_limite_respuesta]),
            'Nada pendiente de respuesta.');
    }
}
