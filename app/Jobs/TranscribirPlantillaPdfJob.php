<?php

namespace App\Jobs;

use App\Models\DocumentTemplate;
use App\Services\AiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Pasa a texto el PDF de una plantilla propia de una empresa, UNA vez, al
 * subirla. Después la plantilla genera documentos igual que una subida en
 * Word: el sistema no tiene un lector de PDF, pero Claude lee el PDF entero
 * (también si es escaneado).
 *
 * Mientras corre, la plantilla está «procesando» y no se puede usar.
 */
class TranscribirPlantillaPdfJob implements ShouldQueue
{
    use Queueable;

    /** Por debajo del retry_after de la cola (ver ColaDeTrabajosTest). */
    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public int $plantillaId,
        public string $rutaPdf,
    ) {}

    public function handle(AiService $ai): void
    {
        $plantilla = DocumentTemplate::find($this->plantillaId);
        if ($plantilla === null || $plantilla->estado_proceso !== 'procesando') {
            return;
        }

        if (! Storage::disk('local')->exists($this->rutaPdf)) {
            throw new RuntimeException('El PDF subido ya no está en el servidor.');
        }

        $texto = $ai->complete([
            AiService::bloquePdf(Storage::disk('local')->path($this->rutaPdf)),
            ['type' => 'text', 'text' => 'Transcribe este documento a Markdown, completo y fiel: mismo texto, mismo orden, '
                .'encabezados como #, listas como listas y tablas como tablas Markdown. No resumas, no corrijas ni agregues nada, '
                .'y conserva tal cual marcadores como «NOMBRE DE LA EMPRESA». Responde solo con el documento.'],
        ], null, 16000, 280);

        $plantilla->update([
            'contenido_base' => trim($texto),
            'estado_proceso' => null,
            'error_proceso' => null,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        DocumentTemplate::query()
            ->whereKey($this->plantillaId)
            ->where('estado_proceso', 'procesando')
            ->update(['estado_proceso' => 'error', 'error_proceso' => mb_substr($e?->getMessage() ?? 'Error desconocido', 0, 1000)]);
    }
}
