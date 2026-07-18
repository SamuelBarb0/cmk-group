<?php

namespace App\Services;

use App\Models\GeneratedDocument;
use App\Services\DocumentFiller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Exporta un documento generado a .docx.
 *
 * - Si la plantilla tiene un .docx MODELO (tokenizado con ${...}): rellena los
 *   tokens con los datos del cliente sobre el Word original → LAYOUT EXACTO
 *   preservado, solo se reemplazan los datos (no se recrea nada).
 * - Si no hay modelo (redacción IA): construye el .docx desde el Markdown con
 *   membrete de CMK.
 */
class DocxExporter
{
    private const NAVY = '16243F';

    public function __construct(
        private readonly DocumentFiller $filler,
    ) {}

    public function export(GeneratedDocument $doc): string
    {
        $template = $doc->template;

        if ($template && $template->archivo && Storage::disk('local')->exists($template->archivo)) {
            return $this->desdeModelo($doc, $template->archivo);
        }

        return $this->desdeMarkdown($doc);
    }

    /**
     * Rellena el .docx modelo (tokenizado) con los datos del cliente,
     * preservando exactamente el formato/membrete original de CMK.
     */
    private function desdeModelo(GeneratedDocument $doc, string $archivo): string
    {
        $tp = new TemplateProcessor(Storage::disk('local')->path($archivo));

        // Mismo mapa de datos que el relleno en pantalla (fuente única de verdad).
        $valores = $this->filler->tokens($doc->tenant);

        // Reemplaza cada token presente; los sin dato quedan como [PENDIENTE].
        foreach ($tp->getVariables() as $var) {
            $val = $valores[$var] ?? null;
            $tp->setValue($var, filled($val) ? $val : '[PENDIENTE]');
        }

        $path = $this->rutaSalida($doc);
        $tp->saveAs($path);

        return $path;
    }

    /** Construye el .docx desde el Markdown con membrete CMK (documentos sin modelo). */
    private function desdeMarkdown(GeneratedDocument $doc): string
    {
        $company = config('cmk.company');

        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);
        $phpWord->addTitleStyle(1, ['size' => 15, 'bold' => true, 'color' => self::NAVY], ['spaceAfter' => 160]);
        $phpWord->addTitleStyle(2, ['size' => 13, 'bold' => true, 'color' => self::NAVY], ['spaceBefore' => 160, 'spaceAfter' => 100]);
        $phpWord->addTitleStyle(3, ['size' => 11, 'bold' => true, 'color' => self::NAVY]);

        $section = $phpWord->addSection(['marginTop' => 1200, 'marginBottom' => 1000]);

        $header = $section->addHeader();
        $header->addText(
            strtoupper($company['legal_name'] ?? 'CMK GROUP S.A.S.').'  ·  NIT '.($company['nit'] ?? ''),
            ['bold' => true, 'size' => 9, 'color' => self::NAVY],
        );
        $header->addText('SST · HSEQ · PESV', ['size' => 8, 'color' => '9AA4B8']);

        $footer = $section->addFooter();
        $footer->addPreserveText(
            'Página {PAGE} de {NUMPAGES}  ·  '.($company['name'] ?? 'CMK GROUP'),
            ['size' => 8, 'color' => '888888'],
            ['alignment' => 'center'],
        );

        $section->addText($doc->titulo, ['bold' => true, 'size' => 16, 'color' => self::NAVY], ['spaceAfter' => 60]);
        $section->addText(
            'Empresa: '.($doc->tenant->name ?? '—')
                .'   |   Estado: '.ucfirst($doc->estado)
                .'   |   Versión: '.$doc->version,
            ['size' => 9, 'color' => '666666'],
            ['spaceAfter' => 240],
        );

        Html::addHtml($section, Str::markdown($doc->contenido), false, false);

        $path = $this->rutaSalida($doc);
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    private function rutaSalida(GeneratedDocument $doc): string
    {
        $dir = storage_path('app/temp');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir.'/'.Str::slug($doc->titulo).'-v'.$doc->version.'.docx';
    }
}
