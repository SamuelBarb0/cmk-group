<?php

namespace App\Services\Reportes\Secciones;

use App\Models\FormRecord;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/** Inspecciones, listas de chequeo y actas diligenciadas en el periodo. */
class Inspecciones extends Seccion
{
    private const GRUPOS = ['inspeccion' => 'Inspección', 'lista' => 'Lista de chequeo', 'acta' => 'Acta', 'general' => 'Formato'];

    public function clave(): string
    {
        return 'inspecciones';
    }

    public function titulo(): string
    {
        return 'Inspecciones y formatos';
    }

    public function modulo(): ?string
    {
        return 'inspecciones';
    }

    public function permiso(): string
    {
        return 'inspections.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $registros = $periodo->filtrar(FormRecord::query(), 'fecha')->get(['id', 'codigo', 'titulo', 'grupo', 'estado', 'data']);
        $borradores = $registros->where('estado', 'borrador')->count();

        // Ítems de listas de chequeo marcados «no cumple»: el hallazgo que
        // interesa a la gerencia, no el formato en sí.
        $noCumple = $registros->where('estado', 'completado')->sum(fn ($r) => collect($r->data ?? [])
            ->filter(fn ($v) => is_array($v))
            ->sum(fn ($lista) => collect($lista)->filter(fn ($i) => is_array($i) && ($i['estado'] ?? null) === 'no_cumple')->count()));

        $this->cifra('Registros', $registros->count());
        $this->cifra('Completados', $registros->where('estado', 'completado')->count());
        $this->cifra('En borrador', $borradores, $borradores ? "{$borradores} formato(s) del periodo quedaron en borrador." : null);
        $this->cifra('Ítems «no cumple» encontrados', $noCumple);

        $this->tabla('Por formato', ['Código', 'Formato', 'Tipo', 'Registros', 'Completados'],
            $registros->groupBy('codigo')->map(fn ($rs, $codigo) => [
                $codigo, self::corto($rs->first()->titulo, 70), self::GRUPOS[$rs->first()->grupo] ?? 'Formato',
                $rs->count(), $rs->where('estado', 'completado')->count(),
            ])->sortByDesc(fn ($f) => $f[3]),
            'Sin formatos diligenciados en el periodo.');
    }
}
