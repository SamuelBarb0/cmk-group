<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Importa procedimientos base adicionales desde el manifiesto generado
 * (storage/app/private/plantillas-base/_manifest.json), con su .docx modelo
 * tokenizado y su texto extraído. Cada uno queda listo para export con formato.
 */
class ProcedimientosBaseSeeder extends Seeder
{
    public function run(): void
    {
        $manifestPath = 'plantillas-base/_manifest.json';

        if (! Storage::disk('local')->exists($manifestPath)) {
            $this->command?->warn('No hay _manifest.json; ejecuta primero el importador.');

            return;
        }

        $items = json_decode(Storage::disk('local')->get($manifestPath), true) ?? [];
        $orden = 10;

        foreach ($items as $it) {
            $codigo = $it['codigo'];
            $txt = "plantillas-base/{$codigo}.txt";
            $docx = "plantillas-base/{$codigo}.docx";

            DocumentTemplate::updateOrCreate(
                ['codigo' => $codigo],
                [
                    'nombre' => $it['nombre'],
                    'tipo' => $it['tipo'],
                    'categoria' => $it['categoria'],
                    'normas' => $it['normas'],
                    'descripcion' => $it['descripcion'],
                    'contenido_base' => Storage::disk('local')->exists($txt) ? Storage::disk('local')->get($txt) : null,
                    'archivo' => Storage::disk('local')->exists($docx) ? $docx : null,
                    'prompt' => 'Completa el procedimiento modelo con los datos de la empresa.',
                    'orden' => $orden++,
                ],
            );

            $this->command?->info("Importado: {$codigo} — {$it['nombre']}");
        }
    }
}
