<?php

namespace App\Services\Reportes;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Escribe una exportación en .xlsx pensada para que el cliente la filtre:
 * encabezado fijo con filtros, fechas como FECHAS de Excel (se pueden ordenar
 * y agrupar) y sí/no en vez de 1/0.
 */
class ExcelExporter
{
    /**
     * @param  list<string>  $encabezados
     * @param  iterable<list<mixed>>  $filas
     */
    public function exportar(string $titulo, string $subtitulo, array $encabezados, iterable $filas, string $nombre): string
    {
        $libro = new Spreadsheet;
        $libro->getProperties()->setCreator(config('cmk.company.name', 'CMK GROUP'))->setTitle($titulo);
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle(Str::limit(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $titulo), 28, ''));

        // Dos filas de contexto: sin ellas, un Excel reenviado no dice de qué
        // empresa ni de qué periodo es.
        $hoja->setCellValue('A1', $titulo);
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('16243F');
        $hoja->setCellValue('A2', $subtitulo);
        $hoja->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('6E7277');

        $ultima = Coordinate::stringFromColumnIndex(max(1, count($encabezados)));
        $hoja->fromArray($encabezados, null, 'A4');
        $cab = $hoja->getStyle("A4:{$ultima}4");
        $cab->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $cab->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('16243F');

        $fila = 5;
        $fechas = [];
        foreach ($filas as $valores) {
            foreach (array_values($valores) as $i => $v) {
                $col = $i + 1;
                if ($v instanceof CarbonInterface) {
                    $v = Date::PHPToExcel($v->copy()->startOfDay());
                    $fechas[$col] = true;
                } elseif (is_bool($v)) {
                    $v = $v ? 'Sí' : 'No';
                } elseif (is_array($v)) {
                    $v = implode(', ', $v);
                }
                if ($v !== null && $v !== '') {
                    $hoja->setCellValue([$col, $fila], $v);
                }
            }
            $fila++;
        }
        $ultimaFila = max(4, $fila - 1);

        foreach (array_keys($fechas) as $col) {
            $letra = Coordinate::stringFromColumnIndex($col);
            $hoja->getStyle("{$letra}5:{$letra}{$ultimaFila}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
        }
        foreach (range(1, count($encabezados)) as $col) {
            $dim = $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($col));
            $dim->setAutoSize(true);
        }
        $hoja->calculateColumnWidths();
        // Los textos largos (descripciones) no deben hacer columnas de un metro.
        foreach (range(1, count($encabezados)) as $col) {
            $dim = $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($col));
            if ($dim->getWidth() > 60) {
                $dim->setAutoSize(false)->setWidth(60);
            }
        }
        $hoja->setAutoFilter("A4:{$ultima}{$ultimaFila}");
        $hoja->freezePane('A5');

        $dir = storage_path('app/temp');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $ruta = $dir.'/'.$nombre.'.xlsx';
        (new Xlsx($libro))->save($ruta);
        $libro->disconnectWorksheets();

        return $ruta;
    }
}
