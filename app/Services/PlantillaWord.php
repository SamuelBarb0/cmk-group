<?php

namespace App\Services;

use PhpOffice\PhpWord\TemplateProcessor;

/**
 * TemplateProcessor que además sabe dónde van el logo y las firmas en los
 * documentos modelo de CMK, que NO traen tokens de imagen: traen texto.
 *
 * - El logo es una celda del encabezado con el literal «LOGO» o «LOGO EMPRESA»,
 *   siempre en un solo run (revisado en los 24 modelos).
 * - La firma del representante legal va bajo el rótulo «Firma del
 *   representante legal,», que Word sí parte en varios runs; por eso se busca
 *   por el texto del párrafo entero y no en el XML crudo.
 *
 * Los marca como `${LOGO}` / `${FIRMA_REP}` EN MEMORIA, sobre la copia que
 * abre TemplateProcessor: los .docx originales de CMK no se tocan, y una
 * plantilla que ya traiga esos tokens escritos funciona igual.
 */
class PlantillaWord extends TemplateProcessor
{
    /** Convierte el literal «LOGO» del encabezado en `${LOGO}`. Devuelve cuántos marcó. */
    public function marcarLogo(): int
    {
        return $this->enCadaParte(fn (string $xml, int &$n) => preg_replace(
            '/(<w:t(?:\s[^>]*)?>)\s*LOGO(?:\s+(?:DE\s+LA\s+)?EMPRESA)?\s*(<\/w:t>)/u',
            '$1${LOGO}$2',
            $xml,
            -1,
            $n,
        ));
    }

    /**
     * Añade un párrafo `${FIRMA_REP}` justo después de cada rótulo «Firma del
     * representante legal», entre el rótulo y la línea para firmar.
     */
    public function marcarFirmaRepresentante(): int
    {
        return $this->enCadaParte(function (string $xml, int &$n) {
            // Un párrafo sin cruzar al siguiente (`<w:pPr>` no cuenta: \b).
            return preg_replace_callback('/<w:p\b(?:\s[^>]*)?>(?:(?!<w:p\b).)*?<\/w:p>/s', function (array $m) use (&$n) {
                preg_match_all('/<w:t(?:\s[^>]*)?>([^<]*)<\/w:t>/', $m[0], $textos);
                $texto = trim(html_entity_decode(implode('', $textos[1])), " \t\n\r\0\x0B\u{A0}");

                if (! preg_match('/^firma del representante legal,?$/iu', $texto)) {
                    return $m[0];
                }
                $n++;

                return $m[0].'<w:p><w:r><w:t>${FIRMA_REP}</w:t></w:r></w:p>';
            }, $xml);
        });
    }

    /**
     * Aplica un reemplazo al cuerpo, encabezados y pies.
     *
     * @param  callable(string, int&): ?string  $reemplazo
     */
    private function enCadaParte(callable $reemplazo): int
    {
        $total = 0;
        $aplicar = function (string $xml) use ($reemplazo, &$total): string {
            $n = 0;
            $nuevo = $reemplazo($xml, $n);
            $total += $n;

            // preg_* devuelve null si el XML lo desborda: mejor sin marcar que vacío.
            return $nuevo ?? $xml;
        };

        $this->tempDocumentMainPart = $aplicar($this->tempDocumentMainPart);
        $this->tempDocumentHeaders = array_map($aplicar, $this->tempDocumentHeaders);
        $this->tempDocumentFooters = array_map($aplicar, $this->tempDocumentFooters);

        return $total;
    }
}
