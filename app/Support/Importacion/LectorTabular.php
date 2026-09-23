<?php

namespace App\Support\Importacion;

use DOMDocument;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Ods;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;
use ZipArchive;

/**
 * Lee un .xlsx, .xls, .ods o .csv a hojas de filas.
 *
 * El .xlsx (lo más común) va con un lector propio: es un zip de XML, igual
 * que el .docx que ya lee PlantillaImporter, y así no depende de nada. El
 * .xls (Excel 97-2003) y el .ods son binario / otro XML y van con
 * PhpSpreadsheet. El .xlsb no lo lee ninguna librería de PHP: se pide
 * guardarlo como .xlsx con un mensaje claro.
 *
 * Lo que el lector de .xlsx hace a mano, porque Excel no lo dice en la celda:
 * - Los textos van en sharedStrings.xml y la celda solo trae el índice.
 * - Una FECHA es un número de serie (45366 = 15/03/2024). Se sabe que es
 *   fecha por el formato de su estilo en styles.xml, y se devuelve como
 *   Y-m-d para que la validación la entienda.
 * - Las celdas vacías no existen en el XML: la columna sale de la referencia
 *   («C7»), no de la posición.
 *
 * Límites: 5.000 filas y 80 columnas por hoja. Una nómina o una matriz de
 * peligros cabe de sobra; un archivo más grande casi seguro no es lo que se
 * quiere importar.
 */
final class LectorTabular
{
    public const MAX_FILAS = 5000;

    public const MAX_COLUMNAS = 80;

    /** Formatos de número incorporados de Excel que son fecha. */
    private const FORMATOS_FECHA = [14, 15, 16, 17, 22, 27, 30, 36, 45, 46, 47, 50, 57];

    /**
     * @return array<string, list<list<string|null>>> nombre de hoja => filas
     */
    public static function leer(string $ruta, string $extension): array
    {
        return match (strtolower($extension)) {
            'xlsx' => self::xlsx($ruta),
            'xls', 'ods' => self::conPhpSpreadsheet($ruta, strtolower($extension)),
            'csv', 'txt' => ['CSV' => self::csv($ruta)],
            'xlsb' => throw new RuntimeException('Los .xlsb (Excel binario) no se pueden leer: ábrelo en Excel y guárdalo como .xlsx.'),
            default => throw new RuntimeException('Formato no soportado: sube un .xlsx, .xls, .ods o .csv.'),
        };
    }

    /**
     * .xls (Excel 97-2003) y .ods (LibreOffice) con PhpSpreadsheet. El .xlsx
     * sigue con el lector propio: más liviano y ya probado con los libros de
     * CMK. Se cargan los estilos (no «solo datos») porque es la única forma de
     * saber qué números son fechas.
     *
     * @return array<string, list<list<string|null>>>
     */
    private static function conPhpSpreadsheet(string $ruta, string $ext): array
    {
        try {
            $lector = $ext === 'xls' ? new Xls : new Ods;
            $libro = $lector->load($ruta);
        } catch (\Throwable) {
            throw new RuntimeException("El archivo no es un .{$ext} válido o está dañado.");
        }

        $hojas = [];
        foreach ($libro->getWorksheetIterator() as $hoja) {
            $filas = [];
            $maxFila = min($hoja->getHighestDataRow(), self::MAX_FILAS);
            $maxCol = min(Coordinate::columnIndexFromString($hoja->getHighestDataColumn()), self::MAX_COLUMNAS);

            for ($r = 1; $r <= $maxFila; $r++) {
                $fila = [];
                for ($c = 1; $c <= $maxCol; $c++) {
                    $celda = $hoja->getCell([$c, $r]);
                    $v = $celda->getValue();
                    if ($v === null || $v === '') {
                        $fila[] = null;

                        continue;
                    }
                    if ($celda->isFormula()) {
                        // El valor que Excel guardó al calcular; recalcular aquí
                        // podría fallar con funciones o referencias externas.
                        $v = $celda->getOldCalculatedValue() ?? $celda->getCalculatedValue();
                    }
                    if (is_numeric($v) && Date::isDateTime($celda)) {
                        $v = Date::excelToDateTimeObject((float) $v)->format('Y-m-d');
                    } elseif (is_bool($v)) {
                        $v = $v ? 'TRUE' : 'FALSE';
                    } elseif (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
                        $v = (string) (int) $v;   // 1098765432.0 -> «1098765432»
                    }
                    $v = trim(preg_replace('/\s+/u', ' ', (string) $v));
                    // Un error de fórmula (#N/A, #REF!…) no es un dato.
                    $esError = in_array($v, ['#N/A', '#VALUE!', '#REF!', '#DIV/0!', '#NAME?', '#NUM!', '#NULL!'], true);
                    $fila[] = ($v === '' || $esError) ? null : $v;
                }
                while ($fila !== [] && end($fila) === null) {
                    array_pop($fila);
                }
                $filas[] = $fila;
            }
            while ($filas !== [] && end($filas) === []) {
                array_pop($filas);
            }
            if ($filas !== []) {
                $hojas[$hoja->getTitle()] = $filas;
            }
        }
        $libro->disconnectWorksheets();

        if ($hojas === []) {
            throw new RuntimeException('El archivo no tiene hojas con datos.');
        }

        return $hojas;
    }

    /** @return array<string, list<list<string|null>>> */
    private static function xlsx(string $ruta): array
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta) !== true || $zip->locateName('xl/workbook.xml') === false) {
            throw new RuntimeException('El archivo no es un Excel .xlsx válido. Si es .xls o .xlsb, ábrelo en Excel y guárdalo como .xlsx.');
        }

        $compartidos = self::textosCompartidos($zip);
        $estilosFecha = self::estilosFecha($zip);

        // Nombre de cada hoja -> archivo XML, vía las relaciones del libro.
        $rels = [];
        $x = self::xpath($zip->getFromName('xl/_rels/workbook.xml.rels'), 'r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($x->query('//r:Relationship') as $rel) {
            $rels[$rel->getAttribute('Id')] = ltrim(str_replace('/xl/', '', $rel->getAttribute('Target')), '/');
        }

        $hojas = [];
        $w = self::xpath($zip->getFromName('xl/workbook.xml'), 'm', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($w->query('//m:sheets/m:sheet') as $hoja) {
            $rid = $hoja->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $archivo = $rels[$rid] ?? null;
            $xml = $archivo ? $zip->getFromName('xl/'.$archivo) : false;
            if ($xml === false) {
                continue;
            }
            $filas = self::filasDeHoja($xml, $compartidos, $estilosFecha);
            if ($filas !== []) {
                $hojas[$hoja->getAttribute('name')] = $filas;
            }
        }
        $zip->close();

        if ($hojas === []) {
            throw new RuntimeException('El Excel no tiene hojas con datos.');
        }

        return $hojas;
    }

    /** @return list<list<string|null>> */
    private static function filasDeHoja(string $xml, array $compartidos, array $estilosFecha): array
    {
        $x = self::xpath($xml, 'm', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $filas = [];

        foreach ($x->query('//m:sheetData/m:row') as $row) {
            // Excel NO escribe las filas vacías: la posición real sale de r="N".
            // Sin esto, las filas se corrían y el número que ve el consultor
            // («la fila 8 no tiene cédula») no era el del Excel.
            $r = (int) $row->getAttribute('r');
            while ($r > 0 && count($filas) < $r - 1 && count($filas) < self::MAX_FILAS) {
                $filas[] = [];
            }
            if (count($filas) >= self::MAX_FILAS) {
                break;
            }
            $fila = [];
            foreach ($x->query('m:c', $row) as $c) {
                $col = self::indiceColumna($c->getAttribute('r'));
                if ($col === null || $col >= self::MAX_COLUMNAS) {
                    continue;
                }
                $tipo = $c->getAttribute('t');
                $v = $x->query('m:v', $c)->item(0)?->textContent;

                $valor = match ($tipo) {
                    's' => $compartidos[(int) $v] ?? null,
                    'inlineStr' => $x->query('m:is', $c)->item(0)?->textContent,
                    'b' => $v === '1' ? 'TRUE' : ($v === '0' ? 'FALSE' : null),
                    'e' => null,   // #N/A, #VALUE!…: no es un dato
                    default => $v,
                };

                // Número con estilo de fecha -> Y-m-d (serial de 1900; 25569 = 1970-01-01).
                // Excel omite t en los números; otras herramientas escriben t="n".
                if ($valor !== null && in_array($tipo, ['', 'n'], true) && is_numeric($valor) && in_array((int) $c->getAttribute('s'), $estilosFecha, true)) {
                    $valor = gmdate('Y-m-d', (int) round(((float) $valor - 25569) * 86400));
                }

                $valor = $valor === null ? null : trim(preg_replace('/\s+/u', ' ', $valor));
                $fila[$col] = $valor === '' ? null : $valor;
            }
            if (array_filter($fila, fn ($v) => $v !== null) === []) {
                $filas[] = [];   // se conserva la fila vacía: los índices de fila importan

                continue;
            }
            $max = max(array_keys($fila));
            $filas[] = array_map(fn ($i) => $fila[$i] ?? null, range(0, $max));
        }

        // Quitar filas vacías del final.
        while ($filas !== [] && end($filas) === []) {
            array_pop($filas);
        }

        return $filas;
    }

    /** @return list<string> */
    private static function textosCompartidos(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $x = self::xpath($xml, 'm', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $out = [];
        foreach ($x->query('//m:si') as $si) {
            // Un texto con formato mixto viene partido en varios <r><t>: se concatenan.
            $partes = [];
            foreach ($x->query('.//m:t', $si) as $t) {
                $partes[] = $t->textContent;
            }
            $out[] = implode('', $partes);
        }

        return $out;
    }

    /** Índices de estilo (cellXfs) cuyo formato de número es una fecha. @return list<int> */
    private static function estilosFecha(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) {
            return [];
        }
        $x = self::xpath($xml, 'm', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $fechasPropias = [];
        foreach ($x->query('//m:numFmts/m:numFmt') as $nf) {
            // Formato propio de fecha: quitando lo entre comillas y corchetes, tiene
            // d o y, o tiene m sin h ni s (si no, «mm» son minutos de una hora).
            $c = preg_replace('/"[^"]*"|\[[^\]]*\]/', '', strtolower($nf->getAttribute('formatCode')));
            $esFecha = str_contains($c, 'd') || str_contains($c, 'y')
                || (str_contains($c, 'm') && ! str_contains($c, 'h') && ! str_contains($c, 's'));
            if ($esFecha) {
                $fechasPropias[] = (int) $nf->getAttribute('numFmtId');
            }
        }

        $estilos = [];
        foreach ($x->query('//m:cellXfs/m:xf') as $i => $xf) {
            $id = (int) $xf->getAttribute('numFmtId');
            if (in_array($id, self::FORMATOS_FECHA, true) || in_array($id, $fechasPropias, true)) {
                $estilos[] = $i;
            }
        }

        return $estilos;
    }

    /** @return list<list<string|null>> */
    private static function csv(string $ruta): array
    {
        $contenido = file_get_contents($ruta);
        if ($contenido === false) {
            throw new RuntimeException('No se pudo leer el archivo.');
        }
        // Excel en español guarda CSV en Windows-1252 y con «;».
        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        // El separador se decide sobre las primeras 20 líneas, no solo la primera:
        // los Excel de consultoría abren con un título sin separadores, y con esa
        // sola línea un «;» de Excel en español se tomaba por coma.
        $inicio = implode("\n", array_slice(explode("\n", $contenido), 0, 20));
        $sep = substr_count($inicio, ';') > substr_count($inicio, ',') ? ';' : ',';

        $filas = [];
        $h = fopen('php://memory', 'r+');
        fwrite($h, $contenido);
        rewind($h);
        while (($f = fgetcsv($h, 0, $sep, '"', '\\')) !== false && count($filas) < self::MAX_FILAS) {
            $f = array_slice($f, 0, self::MAX_COLUMNAS);
            $f = array_map(fn ($v) => ($v = trim((string) $v)) === '' ? null : $v, $f);
            $filas[] = array_filter($f, fn ($v) => $v !== null) === [] ? [] : $f;
        }
        fclose($h);

        return $filas;
    }

    /** «C7» -> 2; «AB12» -> 27. */
    private static function indiceColumna(string $ref): ?int
    {
        if (! preg_match('/^([A-Z]+)\d+$/', $ref, $m)) {
            return null;
        }
        $n = 0;
        foreach (str_split($m[1]) as $l) {
            $n = $n * 26 + (ord($l) - 64);
        }

        return $n - 1;
    }

    /** Letra de columna para mostrar: 0 -> A, 27 -> AB. */
    public static function letra(int $indice): string
    {
        $s = '';
        for ($n = $indice + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }

    private static function xpath(string|false $xml, string $prefijo, string $ns): DOMXPath
    {
        if ($xml === false) {
            throw new RuntimeException('El Excel está incompleto o dañado.');
        }
        $dom = new DOMDocument;
        // LIBXML_NONET: un xlsx es un archivo del cliente, no se le deja pedir nada a la red.
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        $x = new DOMXPath($dom);
        $x->registerNamespace($prefijo, $ns);

        return $x;
    }
}
