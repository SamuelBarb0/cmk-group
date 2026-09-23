<?php

namespace App\Services\Reportes\Secciones;

use App\Models\MaintenanceAsset;
use App\Models\MaintenanceRecord;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/** Mantenimientos del periodo y estado del plan de cada activo a la fecha. */
class Mantenimiento extends Seccion
{
    public function clave(): string
    {
        return 'mantenimiento';
    }

    public function titulo(): string
    {
        return 'Mantenimiento de activos';
    }

    public function modulo(): ?string
    {
        return 'mantenimiento';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $registros = $periodo->filtrar(MaintenanceRecord::query(), 'fecha')->get(['id', 'tipo', 'valor', 'estado']);
        $activos = MaintenanceAsset::where('activo', true)->with(['planItems', 'records'])->get();

        $vencidos = [];
        foreach ($activos as $a) {
            foreach ($a->estadoDelPlan() as $e) {
                if ($e['estado'] === 'vencido') {
                    $vencidos[] = [trim(($a->codigo ? "{$a->codigo} · " : '').$a->nombre), self::corto($e['item']->actividad, 60),
                        $e['ultimo'] ? self::fecha($e['ultimo']->fecha) : 'Nunca'];
                }
            }
        }

        $this->cifra('Mantenimientos preventivos', $registros->where('tipo', 'preventivo')->count());
        $this->cifra('Mantenimientos correctivos', $registros->where('tipo', 'correctivo')->count());
        $this->cifra('Costo del periodo', '$ '.self::numero((float) $registros->sum('valor')));
        $this->cifra('Órdenes abiertas', $registros->where('estado', 'abierta')->count());
        $this->cifra('Activos en seguimiento', $activos->count());
        $this->cifra('Mantenimientos vencidos', count($vencidos),
            $vencidos ? count($vencidos).' mantenimiento(s) preventivo(s) vencido(s) según el plan de cada activo.' : null);

        $this->tabla('Mantenimientos vencidos', ['Activo', 'Actividad', 'Último registro'], $vencidos, 'No hay mantenimientos vencidos.');
        $this->nota('Los vencidos son el estado a la fecha del informe.');
    }
}
