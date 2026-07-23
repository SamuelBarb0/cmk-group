<?php

namespace App\Services;

use App\Models\FormRecord;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Exporta un registro del motor de formatos a .docx con membrete de CMK,
 * reconstruyendo secciones y campos desde el SNAPSHOT del esquema del registro.
 */
class FormRecordExporter
{
    private const NAVY = '16243F';

    private const ESTADO = [
        'cumple' => 'Cumple',
        'no_cumple' => 'No cumple',
        'no_aplica' => 'N/A',
    ];

    public function export(FormRecord $record): string
    {
        $company = config('cmk.company');
        $data = $record->data ?? [];

        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);

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

        // Título + metadatos del registro.
        $section->addText($record->titulo, ['bold' => true, 'size' => 16, 'color' => self::NAVY], ['spaceAfter' => 40]);
        $meta = 'Código: '.$record->codigo
            .'   |   Empresa: '.($record->tenant->name ?? '—')
            .'   |   Fecha: '.($record->fecha?->format('d/m/Y') ?? '—');
        if ($record->responsable) {
            $meta .= '   |   Responsable: '.$record->responsable;
        }
        $section->addText($meta, ['size' => 9, 'color' => '666666'], ['spaceAfter' => 200]);

        foreach (($record->schema['secciones'] ?? []) as $seccion) {
            $section->addText(
                strtoupper($seccion['titulo'] ?? ''),
                ['bold' => true, 'size' => 11, 'color' => self::NAVY],
                ['spaceBefore' => 160, 'spaceAfter' => 80],
            );

            foreach (($seccion['campos'] ?? []) as $campo) {
                $this->renderCampo($section, $campo, $data[$campo['key']] ?? null);
            }
        }

        $path = storage_path('app/temp');
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }
        $file = $path.'/'.Str::slug($record->codigo.'-'.$record->titulo).'-'.$record->id.'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($file);

        return $file;
    }

    private function renderCampo($section, array $campo, $valor): void
    {
        $label = $campo['label'] ?? $campo['key'];

        if (($campo['tipo'] ?? 'text') === 'checklist') {
            $section->addText($label, ['bold' => true, 'size' => 10], ['spaceBefore' => 60]);
            $table = $section->addTable([
                'borderSize' => 6, 'borderColor' => 'CCCCCC', 'cellMargin' => 60, 'width' => 100 * 50, 'unit' => 'pct',
            ]);
            $table->addRow();
            $table->addCell(6000, ['bgColor' => 'F2F4F7'])->addText('Ítem', ['bold' => true, 'size' => 9]);
            $table->addCell(1600, ['bgColor' => 'F2F4F7'])->addText('Estado', ['bold' => true, 'size' => 9]);
            $table->addCell(3400, ['bgColor' => 'F2F4F7'])->addText('Observación', ['bold' => true, 'size' => 9]);

            foreach ((array) $valor as $fila) {
                $table->addRow();
                $table->addCell(6000)->addText((string) ($fila['item'] ?? ''), ['size' => 9]);
                $table->addCell(1600)->addText(self::ESTADO[$fila['estado'] ?? ''] ?? '—', ['size' => 9]);
                $table->addCell(3400)->addText((string) ($fila['obs'] ?? ''), ['size' => 9]);
            }

            return;
        }

        if (($campo['tipo'] ?? '') === 'firma') {
            $firma = (array) $valor;
            $texto = ($firma['nombre'] ?? '') !== ''
                ? ($firma['nombre'] ?? '').(($firma['cc'] ?? '') !== '' ? ' — C.C. '.$firma['cc'] : '')
                : '________________________';
            $section->addText($label.': '.$texto, ['size' => 10], ['spaceBefore' => 120]);

            return;
        }

        $texto = is_scalar($valor) ? (string) $valor : '';
        $run = $section->addTextRun(['spaceBefore' => 40, 'spaceAfter' => 40]);
        $run->addText($label.': ', ['bold' => true, 'size' => 10]);
        $run->addText($texto !== '' ? $texto : '—', ['size' => 10]);
    }
}
