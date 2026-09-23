<?php

namespace App\Services\Reportes\Secciones;

use App\Models\PpeDelivery;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/** Entregas de elementos de protección personal en el periodo. */
class Epp extends Seccion
{
    public function clave(): string
    {
        return 'epp';
    }

    public function titulo(): string
    {
        return 'Elementos de protección personal';
    }

    public function modulo(): ?string
    {
        return 'epp';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $entregas = $periodo->filtrar(PpeDelivery::query(), 'fecha_entrega')->with('item:id,nombre,categoria')->get();
        $sinFirma = $entregas->reject(fn ($e) => $e->firmada)->count();

        $this->cifra('Entregas', $entregas->count());
        $this->cifra('Unidades entregadas', (int) $entregas->sum('cantidad'));
        $this->cifra('Trabajadores dotados', $entregas->pluck('employee_id')->unique()->count());
        $this->cifra('Reposiciones', $entregas->where('motivo', 'reposicion')->count());
        $this->cifra('Entregas sin firma del trabajador', $sinFirma,
            $sinFirma ? "{$sinFirma} entrega(s) de EPP sin firma de recibido: no sirven como soporte ante una inspección." : null);

        $this->tabla('Por elemento', ['Elemento', 'Categoría', 'Entregas', 'Unidades'],
            $entregas->groupBy('ppe_item_id')->map(fn ($es) => [
                $es->first()->item?->nombre ?? '—', self::etiqueta($es->first()->item?->categoria), $es->count(), (int) $es->sum('cantidad'),
            ])->sortByDesc(fn ($f) => $f[3]),
            'Sin entregas de EPP en el periodo.');
    }
}
