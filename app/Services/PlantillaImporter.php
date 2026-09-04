<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

/**
 * Convierte el archivo que sube el consultor en el `contenido_base` de una
 * plantilla: Markdown que la IA lee y que DocumentFiller recorre buscando
 * marcadores.
 *
 * LEE EL XML DEL .docx A MANO en vez de usar el lector de PhpWord, por dos
 * cosas que salieron al probar con los documentos reales de CMK:
 *
 *  1. PhpWord solo reconoce como titulo los estilos INGLESES (`Heading1`). El
 *     Word en espanol los guarda como `Ttulo1`/`Ttulo2`, asi que los nueve
 *     documentos de CMK llegaban sin un solo encabezado y la IA perdia la
 *     estructura del documento.
 *  2. PhpWord aborta la lectura entera con «Invalid image» cuando el .docx
 *     lleva una imagen EMF —dos de los nueve la llevan—. Aqui las imagenes
 *     sencillamente no se miran: de una plantilla interesa el texto.
 *
 * Los marcadores del original (`${TOKEN}` y «NOMBRE DE LA EMPRESA») viajan
 * intactos: son justo lo que se sustituye despues con los datos del cliente.
 */
class PlantillaImporter
{
    /** Extensiones aceptadas al subir una plantilla. */
    public const EXTENSIONES = ['docx', 'txt', 'md'];

    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** Estilos de parrafo que son un encabezado, en ingles y en espanol. */
    private const ENCABEZADO = '/^(?:heading|t.?tulo)\s*(\d)$/iu';

    public function extraer(UploadedFile $archivo): string
    {
        return $this->extraerDe(
            $archivo->getRealPath(),
            strtolower($archivo->getClientOriginalExtension()),
        );
    }

    /**
     * Igual que extraer(), pero desde una ruta del disco.
     *
     * Lo usa `plantillas:reimportar` para volver a leer los .docx modelo de CMK
     * sin pasar por una subida HTTP.
     */
    public function extraerDe(string $ruta, string $extension): string
    {
        $texto = match ($extension) {
            'docx' => $this->desdeDocx($ruta),
            'txt', 'md' => (string) file_get_contents($ruta),
            default => throw new RuntimeException("No se leer archivos «.{$extension}». Sube un .docx, .txt o .md."),
        };

        $texto = $this->limpiar($texto);

        if ($texto === '') {
            throw new RuntimeException(
                'El archivo no tiene texto legible. Si es un .docx escaneado (paginas en imagen), primero hay que pasarlo a texto.',
            );
        }

        return $texto;
    }

    /** Lee el cuerpo del .docx y lo devuelve como Markdown. */
    private function desdeDocx(string $ruta): string
    {
        $zip = new ZipArchive;

        if ($zip->open($ruta) !== true) {
            throw new RuntimeException('El archivo no es un .docx valido (no se pudo abrir).');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException('El .docx no tiene cuerpo de documento. Es posible que sea un .doc antiguo renombrado.');
        }

        $dom = new DOMDocument;
        $previo = libxml_use_internal_errors(true);
        $cargado = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        if (! $cargado) {
            throw new RuntimeException('El contenido del .docx esta danado y no se pudo leer.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS);

        $cuerpo = $xpath->query('//w:body')->item(0);

        if (! $cuerpo instanceof DOMElement) {
            return '';
        }

        $lineas = [];

        foreach ($cuerpo->childNodes as $nodo) {
            if (! $nodo instanceof DOMElement) {
                continue;
            }

            match ($nodo->localName) {
                'p' => $this->volcarParrafo($nodo, $xpath, $lineas),
                'tbl' => $this->volcarTabla($nodo, $xpath, $lineas),
                default => null,
            };
        }

        return implode("\n", $lineas);
    }

    /**
     * Un `w:p` es un parrafo, un encabezado o una vineta segun su estilo.
     *
     * @param  array<int,string>  $lineas
     */
    private function volcarParrafo(DOMElement $p, DOMXPath $xpath, array &$lineas): void
    {
        $texto = $this->textoDe($p, $xpath);

        if ($texto === '') {
            return;
        }

        $estilo = (string) $xpath->evaluate('string(w:pPr/w:pStyle/@w:val)', $p);

        if (preg_match(self::ENCABEZADO, $estilo, $m) === 1) {
            $nivel = max(1, min(6, (int) $m[1]));
            $lineas[] = '';
            $lineas[] = str_repeat('#', $nivel).' '.$texto;
            $lineas[] = '';

            return;
        }

        // `w:numPr` es lo que hace que un parrafo sea de lista; el estilo
        // «Parrafo de lista» por si solo no basta, Word se lo aplica tambien a
        // parrafos sangrados que no son vinetas.
        if ($xpath->query('w:pPr/w:numPr', $p)->length > 0) {
            $nivel = (int) $xpath->evaluate('string(w:pPr/w:numPr/w:ilvl/@w:val)', $p);
            $lineas[] = str_repeat('  ', max(0, $nivel)).'- '.$texto;

            return;
        }

        $lineas[] = $texto;
    }

    /**
     * Vuelca `w:tbl` como tabla Markdown, con la primera fila de encabezado.
     * Estos documentos meten en tablas datos que importan —responsables,
     * periodicidades, matrices—, y aplanarlas a prosa perderia que valor va
     * con que columna.
     *
     * @param  array<int,string>  $lineas
     */
    private function volcarTabla(DOMElement $tbl, DOMXPath $xpath, array &$lineas): void
    {
        $filas = [];

        foreach ($xpath->query('w:tr', $tbl) as $tr) {
            $celdas = [];

            foreach ($xpath->query('w:tc', $tr) as $tc) {
                // El pipe separa columnas en Markdown: dentro de una celda hay
                // que escaparlo o parte la tabla en dos.
                $celdas[] = str_replace(
                    ['|', "\n"],
                    ['\\|', ' '],
                    $this->textoDe($tc, $xpath),
                );
            }

            if (array_filter($celdas, fn (string $c) => trim($c) !== '') !== []) {
                $filas[] = $celdas;
            }
        }

        if ($filas === []) {
            return;
        }

        $columnas = max(array_map('count', $filas));
        $lineas[] = '';

        foreach ($filas as $i => $celdas) {
            $lineas[] = '| '.implode(' | ', array_pad($celdas, $columnas, '')).' |';

            if ($i === 0) {
                $lineas[] = '|'.str_repeat(' --- |', $columnas);
            }
        }

        $lineas[] = '';
    }

    /**
     * Texto de un nodo: todos sus `w:t`, mas tabuladores y saltos de linea.
     *
     * OJO con recortar cada trozo por separado: Word parte una frase en varios
     * `w:r` cada vez que cambia el formato, y el espacio que los separa vive al
     * final de uno de ellos. Haciendole trim a cada uno salia «materia
     * deseguridad» pegado. Se recorta solo el resultado final.
     *
     * `w:p` entra en la consulta para separar PARRAFOS ANIDADOS: una celda de
     * tabla suele llevar varios, y sin este corte se pegaban entre si —«Norte:
     * XXXXSur: XXXXXEste:»—, que es el mismo defecto de arriba una escala mas
     * arriba. Un `w:p` va antes que su propio texto en el documento, asi que
     * marcar su apertura con un salto basta para separarlos; sobre un `w:p`
     * suelto no cambia nada, porque `.//` solo mira descendientes.
     */
    private function textoDe(DOMNode $nodo, DOMXPath $xpath): string
    {
        $partes = [];

        foreach ($xpath->query('.//w:t | .//w:tab | .//w:br | .//w:cr | .//w:p', $nodo) as $hijo) {
            $partes[] = match ($hijo->localName) {
                't' => $hijo->textContent,
                'tab' => ' ',
                default => "\n",
            };
        }

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', implode('', $partes)));
    }

    /** Normaliza saltos y colapsa los huecos que deja el volcado del .docx. */
    private function limpiar(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = (string) preg_replace('/[ \t]+$/m', '', $texto);
        $texto = (string) preg_replace("/\n{3,}/", "\n\n", $texto);

        return trim($texto);
    }
}
