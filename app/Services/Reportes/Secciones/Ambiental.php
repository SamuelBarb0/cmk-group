<?php

namespace App\Services\Reportes\Secciones;

use App\Models\ChemicalProduct;
use App\Models\ResourceReading;
use App\Models\WasteRecord;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/**
 * Desempeño ambiental en el periodo: residuos generados y aprovechados,
 * categoría RESPEL, certificados pendientes, consumos contra el mismo periodo
 * del año anterior y productos químicos sin hoja de seguridad vigente.
 */
class Ambiental extends Seccion
{
    public function clave(): string
    {
        return 'ambiental';
    }

    public function titulo(): string
    {
        return 'Desempeño ambiental';
    }

    public function modulo(): ?string
    {
        return 'ambiental';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $rango = [$periodo->desde->toDateString(), $periodo->hasta->toDateString()];
        $residuos = WasteRecord::query()->whereBetween('fecha', $rango)->get();
        $total = (float) $residuos->sum('cantidad_kg');
        $aprovechado = (float) $residuos->whereIn('disposicion', WasteRecord::APROVECHADOS)->sum('cantidad_kg');
        $sinCertificado = $residuos->filter(fn (WasteRecord $w) => $w->sinCertificado());
        $respel = WasteRecord::categoriaRespel($periodo->hasta);
        $quimicos = ChemicalProduct::query()->where('activo', true)->get();
        $sinHds = $quimicos->where('estado_hds', '!=', 'vigente');

        $this->cifra('Residuos generados (kg)', number_format($total, 1, ',', '.'));
        $this->cifra('Aprovechados', $total > 0 ? round(100 * $aprovechado / $total).' %' : '—');
        $this->cifra('Residuos peligrosos (kg)', number_format((float) $residuos->where('tipo', 'peligroso')->sum('cantidad_kg'), 1, ',', '.'));
        $this->cifra('Categoría RESPEL al cierre', WasteRecord::ETIQUETA_CATEGORIA[$respel['categoria']]);
        $this->cifra('Entregas sin certificado de disposición', $sinCertificado->count(),
            $sinCertificado->count() ? 'Hay residuos peligrosos o RAEE entregados sin certificado de disposición del gestor.' : null);
        $this->cifra('Productos químicos sin HDS vigente', $sinHds->count(),
            $sinHds->count() ? 'Hay productos químicos sin hoja de datos de seguridad o con una de más de '.ChemicalProduct::HDS_ANIOS.' años.' : null);

        $antes = [$periodo->desde->subYear()->toDateString(), $periodo->hasta->subYear()->toDateString()];
        $lecturas = ResourceReading::query()->whereBetween('periodo', [$periodo->desde->startOfMonth()->toDateString(), $rango[1]])->get();
        $previas = ResourceReading::query()->whereBetween('periodo', [$periodo->desde->subYear()->startOfMonth()->toDateString(), $antes[1]])->get();

        $this->tabla('Consumos del periodo', ['Recurso', 'Consumo', 'Mismo periodo del año anterior', 'Variación'],
            collect(ResourceReading::RECURSOS)->map(function ($r, string $recurso) use ($lecturas, $previas) {
                $ahora = (float) $lecturas->where('recurso', $recurso)->sum('cantidad');
                $antes = (float) $previas->where('recurso', $recurso)->sum('cantidad');
                if ($ahora == 0 && $antes == 0) {
                    return null;
                }

                return [
                    "{$r[0]} ({$r[1]})",
                    number_format($ahora, 1, ',', '.'),
                    $antes > 0 ? number_format($antes, 1, ',', '.') : '—',
                    $antes > 0 ? round(100 * ($ahora - $antes) / $antes, 1).' %' : '—',
                ];
            })->filter()->values(),
            'Sin lecturas de consumo en el periodo.');

        $this->tabla('Residuos por tipo (kg)', ['Tipo', 'kg'],
            collect(WasteRecord::TIPOS)->map(fn ($l, $t) => [$l, number_format((float) $residuos->where('tipo', $t)->sum('cantidad_kg'), 1, ',', '.')])
                ->filter(fn ($f) => $f[1] !== '0,0')->values(),
            'Sin residuos registrados en el periodo.');
    }
}
