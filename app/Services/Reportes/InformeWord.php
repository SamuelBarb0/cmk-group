<?php

namespace App\Services\Reportes;

use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;

/**
 * Pinta el informe de gestión en Word, editable: el consultor lo revisa y
 * le agrega su análisis antes de mandarlo. Mismo membrete que los demás
 * exportadores de la plataforma, con el logo de CMK.
 */
class InformeWord
{
    private const NAVY = '16243F';

    private const GRIS = '6E7277';

    private const ROJO = 'B42318';

    /** @param  array<string, mixed>  $informe  salida de InformeGestion::generar() */
    public function exportar(array $informe, ?string $observaciones): string
    {
        $company = config('cmk.company');
        $w = new PhpWord;
        $w->setDefaultFontName('Calibri');
        $w->setDefaultFontSize(10);
        $w->addTitleStyle(1, ['bold' => true, 'size' => 14, 'color' => self::NAVY], ['spaceBefore' => 240, 'spaceAfter' => 80, 'keepNext' => true]);
        $w->addTitleStyle(2, ['bold' => true, 'size' => 11, 'color' => self::NAVY], ['spaceBefore' => 160, 'spaceAfter' => 60, 'keepNext' => true]);

        $sec = $w->addSection(['marginTop' => 1300, 'marginBottom' => 1000, 'marginLeft' => 1100, 'marginRight' => 1100]);

        $header = $sec->addHeader()->addTable(['width' => 100 * 50, 'unit' => 'pct']);
        $fila = $header->addRow();
        $fila->addCell(1800)->addImage(resource_path('reportes/logo-cmk.png'), ['width' => Converter::cmToPoint(2.6)]);
        $celda = $fila->addCell(7400);
        $celda->addText(Str::upper($company['legal_name'] ?? 'CMK GROUP S.A.S.').'  ·  NIT '.($company['nit'] ?? ''),
            ['bold' => true, 'size' => 8, 'color' => self::NAVY], ['alignment' => 'right', 'spaceAfter' => 0]);
        $meta = self::meta($informe);
        $celda->addText($meta['titulo'].' · '.$informe['empresa']['nombre'],
            ['size' => 8, 'color' => self::GRIS], ['alignment' => 'right', 'spaceAfter' => 0]);
        $sec->addFooter()->addPreserveText('Página {PAGE} de {NUMPAGES}  ·  '.($company['name'] ?? 'CMK GROUP').'  ·  '.($company['domain'] ?? ''),
            ['size' => 8, 'color' => '888888'], ['alignment' => 'center']);

        // Portada breve
        $sec->addText(Str::upper($meta['titulo']), ['bold' => true, 'size' => 18, 'color' => self::NAVY], ['spaceAfter' => 60]);
        $sec->addText($informe['empresa']['razon_social'], ['bold' => true, 'size' => 12], ['spaceAfter' => 0]);
        $sec->addText('NIT '.($informe['empresa']['nit'] ?: '—').($informe['empresa']['ciudad'] ? '  ·  '.$informe['empresa']['ciudad'] : ''), ['color' => self::GRIS], ['spaceAfter' => 120]);
        $this->pares($sec, [
            ['Periodo', $informe['periodo']['etiqueta']],
            ['Fecha del informe', $informe['generado']['fecha']],
            ['Elaborado por', $informe['generado']['por'].' — '.($company['name'] ?? 'CMK GROUP')],
        ]);

        $sec->addTitle($meta['atencion_titulo'], 1);
        if ($informe['atencion'] === []) {
            $sec->addText($meta['atencion_vacia']);
        }
        foreach ($informe['atencion'] as $a) {
            $run = $sec->addListItemRun(0, null, ['spaceAfter' => 40]);
            $run->addText($a['seccion'].': ', ['bold' => true, 'color' => self::ROJO]);
            $run->addText($a['texto']);
        }

        if (filled($observaciones)) {
            $sec->addTitle($meta['observaciones_titulo'], 1);
            foreach (preg_split('/\R{2,}/', trim($observaciones)) as $parrafo) {
                $sec->addText(str_replace(["\r\n", "\n"], ' ', $parrafo), [], ['spaceAfter' => 100]);
            }
        }

        foreach ($informe['secciones'] as $s) {
            $sec->addTitle($s['titulo'], 1);
            if ($s['cifras']) {
                $this->pares($sec, array_map(fn ($c) => [$c['etiqueta'], $c['valor'], (bool) $c['alerta']], $s['cifras']));
            }
            foreach ($s['tablas'] as $t) {
                if ($t['filas'] === [] && $t['vacio'] === '') {
                    continue;
                }
                $sec->addTitle($t['titulo'], 2);
                if ($t['filas'] === []) {
                    $sec->addText($t['vacio'], ['italic' => true, 'color' => self::GRIS]);

                    continue;
                }
                $this->tabla($sec, $t['columnas'], $t['filas']);
                if ($t['omitidas'] > 0) {
                    $sec->addText("… y {$t['omitidas']} más. El detalle completo está en la exportación a Excel del módulo.", ['italic' => true, 'size' => 8, 'color' => self::GRIS]);
                }
            }
            foreach ($s['notas'] as $n) {
                $sec->addText($n, ['italic' => true, 'size' => 8, 'color' => self::GRIS], ['spaceBefore' => 60]);
            }
        }

        // Firmas
        $sec->addTextBreak(2);
        $firmas = $sec->addTable(['width' => 100 * 50, 'unit' => 'pct']);
        $f = $firmas->addRow();
        foreach ($meta['firmas'] as [$rol, $nombre, $cargo]) {
            $c = $f->addCell(4600);
            $c->addText('_______________________________', ['color' => '999999'], ['spaceBefore' => 600, 'spaceAfter' => 0]);
            $c->addText($rol.($nombre ? ': '.$nombre : ''), ['bold' => true], ['spaceAfter' => 0]);
            $c->addText($cargo, ['size' => 8, 'color' => self::GRIS]);
        }

        $dir = storage_path('app/temp');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $ruta = $dir.'/'.self::nombre($informe).'.docx';
        IOFactory::createWriter($w, 'Word2007')->save($ruta);

        return $ruta;
    }

    /**
     * Textos del documento. El informe de gestión usa los de siempre; otros
     * informes con la misma estructura (p. ej. el reporte de autogestión del
     * PESV) los cambian con las llaves opcionales del arreglo.
     *
     * @param  array<string, mixed>  $informe
     * @return array{titulo: string, atencion_titulo: string, atencion_vacia: string, observaciones_titulo: string, firmas: list<array{0: string, 1: string, 2: string}>}
     */
    public static function meta(array $informe): array
    {
        $company = config('cmk.company');

        return [
            'titulo' => $informe['titulo'] ?? 'Informe de gestión del SG-SST',
            'atencion_titulo' => $informe['atencion_titulo'] ?? 'Puntos de atención',
            'atencion_vacia' => $informe['atencion_vacia'] ?? 'No se encontraron situaciones que requieran acción inmediata en los módulos revisados.',
            'observaciones_titulo' => $informe['observaciones_titulo'] ?? 'Análisis y recomendaciones del consultor',
            'firmas' => $informe['firmas'] ?? [
                ['Elaboró', $informe['generado']['por'], $company['name'] ?? 'CMK GROUP'],
                ['Recibió', '', 'Representante legal · '.$informe['empresa']['nombre']],
            ],
        ];
    }

    public static function nombre(array $informe): string
    {
        if (isset($informe['archivo'])) {
            return $informe['archivo'];
        }

        return 'informe-gestion-'.Str::slug($informe['empresa']['nombre']).'-'.$informe['periodo']['desde'].'-a-'.$informe['periodo']['hasta'];
    }

    /** @param  list<array{0: string, 1: string, 2?: bool}>  $pares */
    private function pares($sec, array $pares): void
    {
        $t = $sec->addTable(['borderSize' => 6, 'borderColor' => 'DDDDDD', 'cellMargin' => 60, 'width' => 100 * 50, 'unit' => 'pct']);
        foreach ($pares as $p) {
            $r = $t->addRow();
            $r->addCell(4200, ['bgColor' => 'F2F4F7'])->addText($p[0], ['bold' => true], ['spaceAfter' => 0]);
            $r->addCell(5000)->addText($p[1], ($p[2] ?? false) ? ['bold' => true, 'color' => self::ROJO] : [], ['spaceAfter' => 0]);
        }
    }

    /**
     * @param  list<string>  $columnas
     * @param  list<list<string>>  $filas
     */
    private function tabla($sec, array $columnas, array $filas): void
    {
        $t = $sec->addTable(['borderSize' => 6, 'borderColor' => 'DDDDDD', 'cellMargin' => 50, 'width' => 100 * 50, 'unit' => 'pct']);
        $r = $t->addRow(null, ['tblHeader' => true]);
        foreach ($columnas as $c) {
            $r->addCell(null, ['bgColor' => self::NAVY])->addText($c, ['bold' => true, 'size' => 8, 'color' => 'FFFFFF'], ['spaceAfter' => 0]);
        }
        foreach ($filas as $fila) {
            $r = $t->addRow(null, ['cantSplit' => true]);
            foreach ($fila as $v) {
                $r->addCell()->addText($v, ['size' => 8], ['spaceAfter' => 0]);
            }
        }
    }
}
