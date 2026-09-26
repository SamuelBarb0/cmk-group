<?php

namespace App\Services\Ambiental;

use App\Models\ChemicalProduct;
use App\Models\DocumentCatalogEntry;
use App\Models\ResourceReading;
use App\Models\WasteRecord;
use App\Services\ControlDocumental\CatalogoModulo as C;
use Illuminate\Support\Carbon;

/**
 * Arma el texto de los documentos del M18 que salen de los registros
 * ambientales, para mandarlos al control documental como borrador:
 *
 *   residuos        → FT  «Registro de generación de residuos»                   (SIG-51)
 *   certificados    → FT  «Registro de certificados de disposición final»         (SIG-51)
 *   consumos        → FT  «Registro de consumos de agua y energía»                (SIG-51)
 *   respel          → PLA «Plan de gestión integral de residuos peligrosos»       (SIG-51)
 *   compatibilidad  → MTZ «Matriz de compatibilidad química (SGA)»                (SIG-26)
 *   hds             → FT  «Hoja de datos de seguridad de los productos»           (SIG-26)
 *
 * Los registros van con los últimos 12 meses.
 */
class DocumentosAmbiental
{
    /** documento => [tipo, fragmento del nombre en el catálogo, fragmentos del título del requisito] */
    public const DOCUMENTOS = [
        'residuos' => ['FT', 'generación de residuos', ['Gestión integral de residuos']],
        'certificados' => ['FT', 'certificados de disposición', ['Gestión integral de residuos']],
        'consumos' => ['FT', 'consumos de agua', ['Gestión integral de residuos']],
        'respel' => ['PLA', 'residuos peligrosos', ['Gestión integral de residuos']],
        'compatibilidad' => ['MTZ', 'compatibilidad química', ['Programas ambientales']],
        'hds' => ['FT', 'datos de seguridad', ['Programas ambientales']],
    ];

    public function entrada(string $documento): ?DocumentCatalogEntry
    {
        [$tipo, $fragmento] = self::DOCUMENTOS[$documento];

        return C::entrada('M18', $tipo, $fragmento);
    }

    /** @return list<string> */
    public function claves(string $documento): array
    {
        return C::claves('M18', self::DOCUMENTOS[$documento][2]);
    }

    public function contenido(string $documento): string
    {
        return match ($documento) {
            'residuos' => $this->residuos(),
            'certificados' => $this->certificados(),
            'consumos' => $this->consumos(),
            'respel' => $this->respel(),
            'compatibilidad' => $this->compatibilidad(),
            default => $this->hds(),
        };
    }

    private function desde(): string
    {
        return Carbon::today()->startOfMonth()->subMonths(11)->toDateString();
    }

    private static function kg(float $v): string
    {
        return number_format($v, 2, ',', '.');
    }

    private function residuos(): string
    {
        $filas = WasteRecord::query()->where('fecha', '>=', $this->desde())->orderBy('fecha')->get();
        $md = "## Generación de residuos por mes y tipo (kg, últimos 12 meses)\n\n";
        if ($filas->isEmpty()) {
            return $md."Sin registros.\n";
        }

        $meses = $filas->groupBy(fn (WasteRecord $w) => $w->fecha->format('Y-m'));
        $tipos = array_keys(WasteRecord::TIPOS);
        $md .= '| Mes | '.implode(' | ', array_map(fn ($t) => explode(' (', WasteRecord::TIPOS[$t])[0], $tipos))." | Total |\n|---|".str_repeat('---|', count($tipos) + 1)."\n";
        foreach ($meses as $mes => $grupo) {
            $md .= "| {$mes}";
            foreach ($tipos as $t) {
                $md .= ' | '.self::kg((float) $grupo->where('tipo', $t)->sum('cantidad_kg'));
            }
            $md .= ' | '.self::kg((float) $grupo->sum('cantidad_kg'))." |\n";
        }

        $total = (float) $filas->sum('cantidad_kg');
        $aprovechado = (float) $filas->whereIn('disposicion', WasteRecord::APROVECHADOS)->sum('cantidad_kg');

        return $md."\nTotal: ".self::kg($total).' kg. Aprovechado (reciclaje y posconsumo): '.self::kg($aprovechado).' kg ('
            .($total > 0 ? round(100 * $aprovechado / $total) : 0)." %).\n";
    }

    private function certificados(): string
    {
        $filas = WasteRecord::query()->where('fecha', '>=', $this->desde())
            ->where(fn ($q) => $q->whereIn('tipo', WasteRecord::CON_CERTIFICADO)->orWhereNotNull('certificado'))
            ->orderBy('fecha')->get();
        $md = "## Entregas a gestores y certificados de disposición (últimos 12 meses)\n\n";
        if ($filas->isEmpty()) {
            return $md."Sin entregas que requieran certificado.\n";
        }

        $md .= "| Fecha | Residuo | kg | Gestor | Licencia del gestor | Disposición | Certificado |\n|---|---|---|---|---|---|---|\n";
        foreach ($filas as $w) {
            $md .= '| '.$w->fecha->toDateString().' | '.C::celda(WasteRecord::TIPOS[$w->tipo].($w->corriente ? ": {$w->corriente}" : ''))
                .' | '.self::kg($w->cantidad_kg).' | '.C::celda($w->gestor).' | '.C::celda($w->licencia_gestor)
                .' | '.($w->disposicion ? WasteRecord::DISPOSICIONES[$w->disposicion] : '—')
                .' | '.($w->sinCertificado() ? '**Pendiente**' : C::celda($w->certificado ?: 'Adjunto'))." |\n";
        }

        return $md;
    }

    private function consumos(): string
    {
        $desde = Carbon::parse($this->desde());
        $lecturas = ResourceReading::query()->where('periodo', '>=', $desde->copy()->subYear()->toDateString())->orderBy('periodo')->get();
        $md = "## Consumos mensuales (últimos 12 meses, comparados con el mismo mes del año anterior)\n\n";
        if ($lecturas->isEmpty()) {
            return $md."Sin lecturas registradas.\n";
        }

        foreach (ResourceReading::RECURSOS as $recurso => [$etiqueta, $unidad]) {
            $suyas = $lecturas->where('recurso', $recurso)->keyBy(fn ($l) => $l->periodo->format('Y-m'));
            $actuales = $suyas->filter(fn ($l) => $l->periodo->gte($desde));
            if ($actuales->isEmpty()) {
                continue;
            }
            $md .= "### {$etiqueta} ({$unidad})\n\n| Mes | Consumo | Por persona | Mismo mes año anterior | Variación | Costo |\n|---|---|---|---|---|---|\n";
            foreach ($actuales as $mes => $l) {
                $antes = $suyas->get($l->periodo->copy()->subYear()->format('Y-m'));
                $var = $antes && $antes->cantidad > 0 ? round(100 * ($l->cantidad - $antes->cantidad) / $antes->cantidad, 1).' %' : '—';
                $md .= "| {$mes} | ".self::kg($l->cantidad).' | '.($l->por_persona !== null ? self::kg($l->por_persona) : '—')
                    .' | '.($antes ? self::kg($antes->cantidad) : '—')." | {$var} | ".($l->costo !== null ? '$ '.number_format($l->costo, 0, ',', '.') : '—')." |\n";
            }
            $md .= "\n";
        }

        return $md;
    }

    private function respel(): string
    {
        $cat = WasteRecord::categoriaRespel();
        $peligrosos = WasteRecord::query()->where('tipo', 'peligroso')->where('fecha', '>=', $this->desde())->get();
        $corrientes = $peligrosos->groupBy(fn (WasteRecord $w) => $w->corriente ?: 'Sin especificar');

        $md = "## Objetivo\n\nPrevenir la generación de residuos peligrosos, reducir su cantidad y peligrosidad, y asegurar su manejo, almacenamiento, transporte y disposición final de forma ambientalmente segura (Decreto 1076 de 2015, título 6, capítulo 1; ISO 14001 8.1).\n\n";
        $md .= "## Categoría del generador\n\n";
        $md .= 'Media móvil de los últimos seis meses ('.$cat['desde'].' a '.$cat['hasta'].'): **'.self::kg($cat['media_kg']).' kg/mes**. Categoría: **'.WasteRecord::ETIQUETA_CATEGORIA[$cat['categoria']].'**.';
        $md .= $cat['registro_obligatorio']
            ? " La organización debe estar inscrita en el Registro de Generadores de Residuos Peligrosos del IDEAM ante su autoridad ambiental y actualizar la información cada año (Res. 1362 de 2007).\n\n"
            : " Aun sin obligación de inscripción, se mantiene el manejo seguro y la entrega a gestores autorizados.\n\n";

        $md .= "## Identificación de los residuos peligrosos (últimos 12 meses)\n\n";
        if ($corrientes->isEmpty()) {
            $md .= "Sin residuos peligrosos registrados.\n\n";
        } else {
            $md .= "| Residuo | kg generados | Gestor | Disposición |\n|---|---|---|---|\n";
            foreach ($corrientes as $nombre => $grupo) {
                $md .= '| '.C::celda($nombre).' | '.self::kg((float) $grupo->sum('cantidad_kg'))
                    .' | '.C::celda($grupo->pluck('gestor')->filter()->unique()->implode(', '))
                    .' | '.C::celda($grupo->pluck('disposicion')->filter()->unique()->map(fn ($d) => WasteRecord::DISPOSICIONES[$d])->implode(', '))." |\n";
            }
            $md .= "\n";
        }

        $md .= "## Manejo interno\n\n"
            ."1. **Prevención y minimización:** compra de productos menos peligrosos, uso de la cantidad necesaria y programas posconsumo con los proveedores.\n"
            ."2. **Separación en la fuente:** recipientes identificados por tipo de residuo, sin mezclar residuos peligrosos con otros.\n"
            ."3. **Almacenamiento temporal:** área cubierta, señalizada, con piso impermeable, contención de derrames, ventilación y separación de sustancias incompatibles (ver la matriz de compatibilidad química). El generador no almacena RESPEL más de doce meses.\n"
            ."4. **Etiquetado:** cada recipiente lleva el nombre del residuo, sus peligros (pictogramas SGA) y la fecha de inicio de almacenamiento.\n"
            ."5. **Contingencias:** kit para derrames y procedimiento de respuesta integrado al plan de emergencias.\n\n";
        $md .= "## Manejo externo\n\nLos residuos peligrosos se entregan solo a gestores con licencia ambiental vigente, en vehículos que cumplen el Decreto 1079 de 2015 para el transporte de mercancías peligrosas. Se conservan las actas de entrega y los certificados de tratamiento o disposición final durante al menos cinco años.\n\n";
        $md .= "## Seguimiento\n\nSe registra cada entrega, se calcula mensualmente la media móvil para confirmar la categoría y se verifican los certificados de disposición. Los resultados se presentan en la revisión por la dirección.\n";

        return $md;
    }

    private function compatibilidad(): string
    {
        $productos = ChemicalProduct::query()->where('activo', true)->orderBy('nombre')->get();
        $md = "## Uso\n\nMatriz orientativa de almacenamiento conjunto según los pictogramas SGA de cada producto. **Incompatible:** no almacenar juntos. **Separar:** almacenar con distancia, barrera física o contención separada. La decisión final se toma con las secciones 7 (manipulación y almacenamiento) y 10 (estabilidad y reactividad) de cada hoja de datos de seguridad: por ejemplo, un ácido y una base comparten el pictograma de corrosivo y no se almacenan juntos.\n\n";
        if ($productos->count() < 2) {
            return $md."Se necesitan al menos dos productos activos en el inventario.\n";
        }

        $matriz = ChemicalProduct::matriz($productos);
        $abrev = ['compatible' => 'C', 'separar' => 'S', 'incompatible' => '**X**'];
        $md .= '| Producto | '.$productos->map(fn ($p, $i) => (string) ($i + 1))->implode(' | ')." |\n|---|".str_repeat('---|', $productos->count())."\n";
        foreach ($productos as $i => $a) {
            $md .= '| '.($i + 1).'. '.C::celda($a->nombre);
            foreach ($productos as $b) {
                $md .= ' | '.($a->id === $b->id ? '—' : $abrev[$matriz[min($a->id, $b->id).'-'.max($a->id, $b->id)]]);
            }
            $md .= " |\n";
        }
        $md .= "\nC: compatible · S: separar · X: incompatible.\n\n## Productos y peligros\n\n| N.º | Producto | Pictogramas SGA | Ubicación |\n|---|---|---|---|\n";
        foreach ($productos as $i => $p) {
            $md .= '| '.($i + 1).' | '.C::celda($p->nombre).' | '
                .C::celda(collect($p->peligros)->map(fn ($g) => "{$g} ".ChemicalProduct::PICTOGRAMAS[$g])->implode(', ') ?: 'Sin pictogramas')
                .' | '.C::celda($p->ubicacion)." |\n";
        }

        return $md;
    }

    private function hds(): string
    {
        $productos = ChemicalProduct::query()->where('activo', true)->orderBy('nombre')->get();
        $md = "## Control de hojas de datos de seguridad\n\nCada producto químico tiene disponible, en el sitio de uso y en español, su hoja de datos de seguridad de 16 secciones conforme al SGA (Resolución 773 de 2021). Las hojas con más de ".ChemicalProduct::HDS_ANIOS." años se solicitan de nuevo al proveedor.\n\n";
        if ($productos->isEmpty()) {
            return $md."Sin productos en el inventario.\n";
        }

        $estados = ['vigente' => 'Vigente', 'desactualizada' => '**Solicitar actualización**', 'sin_hds' => '**No se tiene**'];
        $md .= "| Producto | Proveedor | Uso | Cantidad | Ubicación | Pictogramas | EPP | Fecha de la HDS | Estado |\n|---|---|---|---|---|---|---|---|---|\n";
        foreach ($productos as $p) {
            $md .= '| '.C::celda($p->nombre).' | '.C::celda($p->proveedor).' | '.C::celda($p->uso).' | '.C::celda($p->cantidad)
                .' | '.C::celda($p->ubicacion).' | '.C::celda(implode(', ', $p->peligros ?? [])).' | '.C::celda($p->epp)
                .' | '.($p->hds_fecha?->toDateString() ?? '—').' | '.$estados[$p->estado_hds]." |\n";
        }

        return $md;
    }
}
