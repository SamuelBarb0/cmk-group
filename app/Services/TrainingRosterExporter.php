<?php

namespace App\Services;

use App\Models\Training;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Exporta el REGISTRO DE ASISTENCIA de una capacitación a .docx con membrete de
 * CMK: encabezado con los datos de la sesión + tabla de asistentes con columna
 * de firma (para firmar físicamente o dejar constancia).
 */
class TrainingRosterExporter
{
    private const NAVY = '16243F';

    private const MODALIDAD = ['presencial' => 'Presencial', 'virtual' => 'Virtual'];

    public function export(Training $training): string
    {
        $company = config('cmk.company');
        $training->loadMissing('attendees');

        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);

        $section = $phpWord->addSection(['marginTop' => 1100, 'marginBottom' => 1000]);

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

        $section->addText('REGISTRO DE ASISTENCIA A CAPACITACIÓN', ['bold' => true, 'size' => 15, 'color' => self::NAVY], ['spaceAfter' => 40]);
        $section->addText($training->titulo, ['bold' => true, 'size' => 12], ['spaceAfter' => 160]);

        // Ficha de la sesión (tabla 2 columnas).
        $ficha = $section->addTable(['borderSize' => 6, 'borderColor' => 'DDDDDD', 'cellMargin' => 70, 'width' => 100 * 50, 'unit' => 'pct']);
        $filas = [
            ['Empresa', $training->tenant->name ?? '—'],
            ['Fecha', $training->fecha?->format('d/m/Y') ?? '—'],
            ['Instructor / facilitador', $training->instructor ?: '—'],
            ['Modalidad', self::MODALIDAD[$training->modalidad] ?? ucfirst((string) $training->modalidad)],
            ['Duración', $training->duracion_minutos ? $training->duracion_minutos.' minutos' : '—'],
            ['Lugar', $training->lugar ?: '—'],
        ];
        foreach ($filas as [$k, $v]) {
            $ficha->addRow();
            $ficha->addCell(3200, ['bgColor' => 'F2F4F7'])->addText($k, ['bold' => true, 'size' => 9]);
            $ficha->addCell(7800)->addText((string) $v, ['size' => 9]);
        }
        if (filled($training->objetivo)) {
            $ficha->addRow();
            $ficha->addCell(3200, ['bgColor' => 'F2F4F7'])->addText('Objetivo', ['bold' => true, 'size' => 9]);
            $ficha->addCell(7800)->addText($training->objetivo, ['size' => 9]);
        }

        $section->addTextBreak(1);
        $section->addText('Asistentes', ['bold' => true, 'size' => 11, 'color' => self::NAVY], ['spaceAfter' => 60]);

        // Tabla de asistentes con columna de firma.
        $tabla = $section->addTable(['borderSize' => 6, 'borderColor' => 'CCCCCC', 'cellMargin' => 60, 'width' => 100 * 50, 'unit' => 'pct']);
        $tabla->addRow();
        foreach ([['N°', 700], ['Nombre', 4000], ['Documento', 2200], ['Cargo', 2600], ['Firma', 3000]] as [$h, $w]) {
            $tabla->addCell($w, ['bgColor' => 'F2F4F7'])->addText($h, ['bold' => true, 'size' => 9]);
        }

        $i = 1;
        foreach ($training->attendees as $a) {
            $tabla->addRow();
            $tabla->addCell(700)->addText((string) $i++, ['size' => 9]);
            $tabla->addCell(4000)->addText((string) $a->nombres, ['size' => 9]);
            $tabla->addCell(2200)->addText((string) ($a->numero_documento ?: ''), ['size' => 9]);
            $tabla->addCell(2600)->addText((string) ($a->cargo ?: ''), ['size' => 9]);
            $tabla->addCell(3000)->addText('', ['size' => 9]);
        }

        // Si no hay asistentes, deja filas en blanco para firmar a mano.
        if ($training->attendees->isEmpty()) {
            for ($r = 0; $r < 10; $r++) {
                $tabla->addRow();
                $tabla->addCell(700)->addText((string) ($r + 1), ['size' => 9]);
                foreach ([4000, 2200, 2600, 3000] as $w) {
                    $tabla->addCell($w)->addText('', ['size' => 9]);
                }
            }
        }

        $section->addTextBreak(2);
        $section->addText('_______________________________', ['size' => 10]);
        $section->addText('Firma del instructor / facilitador', ['size' => 9, 'color' => '666666']);

        $dir = storage_path('app/temp');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir.'/asistencia-'.Str::slug($training->titulo).'-'.$training->id.'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($file);

        return $file;
    }
}
