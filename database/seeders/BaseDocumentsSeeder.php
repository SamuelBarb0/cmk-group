<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Carga el CONTENIDO BASE (documento modelo real de CMK) en las plantillas
 * que lo tienen, desde storage/app/plantillas-base/{CODIGO}.txt (+ .docx).
 *
 * La IA rellenará estos modelos con los datos del cliente en vez de inventar.
 */
class BaseDocumentsSeeder extends Seeder
{
    public function run(): void
    {
        // Plantillas que tienen documento modelo base extraído.
        $codigos = ['POL-SGI', 'MAN-SGSST', 'PR-INV-ACC'];

        foreach ($codigos as $codigo) {
            $txt = "plantillas-base/{$codigo}.txt";
            $docx = "plantillas-base/{$codigo}.docx";

            if (! Storage::disk('local')->exists($txt)) {
                $this->command?->warn("Base no encontrada: {$txt}");
                continue;
            }

            $template = DocumentTemplate::where('codigo', $codigo)->first();
            if (! $template) {
                $this->command?->warn("Plantilla no existe: {$codigo}");
                continue;
            }

            $template->update([
                'contenido_base' => Storage::disk('local')->get($txt),
                'archivo' => Storage::disk('local')->exists($docx) ? $docx : null,
            ]);

            $this->command?->info("Base cargada en {$codigo} (".strlen($template->contenido_base).' chars).');
        }
    }
}
