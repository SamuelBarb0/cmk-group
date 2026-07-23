<?php

namespace App\Jobs;

use App\Models\GeneratedDocument;
use App\Services\AiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Redacta en segundo plano un documento SGI con IA (plantillas SIN modelo base).
 *
 * El controlador crea primero el documento en estado «generando» y encola este
 * job; aquí se llama a la IA y se pasa a «borrador» (o «error» si falla).
 * Así la petición web responde al instante y la redacción larga (~1-2 min)
 * no depende del max_execution_time de PHP.
 */
class GenerateAiDocumentJob implements ShouldQueue
{
    use Queueable;

    /** La redacción de documentos largos puede tardar varios minutos. */
    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public int $documentId,
        public string $prompt,
        public string $system,
    ) {}

    public function handle(AiService $ai): void
    {
        // En cola no hay TenantContext: se busca sin el scope por tenant.
        $documento = GeneratedDocument::withoutTenantScope()->find($this->documentId);

        if ($documento === null || $documento->estado !== 'generando') {
            return; // eliminado o ya resuelto por otra vía
        }

        $documento->update([
            'contenido' => $ai->complete($this->prompt, $this->system),
            'estado' => 'borrador',
        ]);
    }

    public function failed(?Throwable $e): void
    {
        GeneratedDocument::withoutTenantScope()
            ->where('id', $this->documentId)
            ->where('estado', 'generando')
            ->update([
                'estado' => 'error',
                'contenido' => 'No se pudo generar el documento: '.($e?->getMessage() ?? 'error desconocido')
                    ."\n\nElimina este registro y vuelve a intentarlo.",
            ]);
    }
}
