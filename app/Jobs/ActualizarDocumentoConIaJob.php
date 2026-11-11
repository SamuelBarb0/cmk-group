<?php

namespace App\Jobs;

use App\Models\ControlledDocument;
use App\Models\ControlledDocumentVersion;
use App\Models\User;
use App\Services\Ai\ContextoCliente;
use App\Services\AiService;
use App\Services\DocxExporter;
use App\Services\PlantillaImporter;
use App\Services\Reportes\Periodo;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Actualiza con IA el borrador de un documento del control documental: lee
 * el documento que la empresa ya tiene (su Word o PDF adjunto, o el texto del
 * borrador) y lo reescribe con los datos reales del cliente en la plataforma.
 *
 * El resultado queda como TEXTO del mismo borrador; el archivo original sigue
 * adjunto para comparar. No se salta el ciclo: alguien lo revisa, lo envía a
 * revisión y lo aprueba como cualquier otro borrador.
 */
class ActualizarDocumentoConIaJob implements ShouldQueue
{
    use Queueable;

    /** Por debajo del retry_after de la cola (ver ColaDeTrabajosTest). */
    public int $timeout = 300;

    public int $tries = 1;

    /** Salida y espera HTTP para documentos largos (un manual completo). */
    private const MAX_TOKENS = 16000;

    private const TIMEOUT_HTTP = 280;

    public function __construct(
        public int $versionId,
        public int $userId,
        public ?string $instrucciones = null,
    ) {}

    public function handle(AiService $ai, ContextoCliente $contexto, PlantillaImporter $importer, TenantContext $tenantContext): void
    {
        $version = ControlledDocumentVersion::find($this->versionId);
        if ($version === null || $version->estado !== 'borrador' || $version->ia_estado !== 'generando') {
            return; // descartado, enviado a revisión o ya resuelto
        }

        // En cola no hay TenantContext: se pone el de la empresa del documento.
        $documento = ControlledDocument::withoutTenantScope()->with('catalogEntry')->findOrFail($version->controlled_document_id);
        $tenant = $documento->tenant;
        $tenantContext->set($tenant);

        $user = User::findOrFail($this->userId);
        // Las cifras del módulo al que pertenece el documento; sin catálogo, el sistema completo.
        $piezas = $documento->catalogEntry?->modulo ? [$documento->catalogEntry->modulo] : [];
        $datos = $contexto->texto($tenant, $user, $piezas, Periodo::desde(null, null));

        $instruccion = "Actualiza el documento «{$documento->titulo}» ({$documento->codigo}) de la empresa con sus datos reales, que van abajo.\n\n"
            ."- Conserva su estructura, sus secciones y el texto técnico y legal; cambia solo lo que los datos de la empresa contradicen o completan (nombres, NIT, cargos, responsables, fechas, cifras, alcance).\n"
            ."- Si un dato no está, escribe [PENDIENTE]; no lo inventes.\n"
            ."- Responde solo con el documento completo en Markdown, sin comentarios antes ni después.\n"
            .($this->instrucciones ? "\nIndicaciones de quien lo pidió:\n{$this->instrucciones}\n" : '')
            ."\n# Datos de la empresa en la plataforma\n\n{$datos}";

        $system = 'Eres un consultor senior en SST, PESV y HSEQ en Colombia que actualiza documentos del sistema de gestión '
            .'de una empresa cliente, en español formal. '.DocxExporter::AVISO_FIRMAS;

        $texto = $ai->complete($this->contenidoFuente($version, $importer, $instruccion), $system, self::MAX_TOKENS, self::TIMEOUT_HTTP);

        $version->update([
            'contenido' => $texto,
            'ia_estado' => null,
            'ia_error' => null,
            'descripcion_cambio' => $version->descripcion_cambio ?: 'Actualizado con IA con los datos de la empresa.',
        ]);
    }

    /**
     * Lo que la IA lee: el PDF tal cual (Claude lo lee entero, también si es
     * escaneado), el texto de un Word, o el texto del borrador si no hay archivo.
     *
     * @return string|list<array<string,mixed>>
     */
    private function contenidoFuente(ControlledDocumentVersion $version, PlantillaImporter $importer, string $instruccion): string|array
    {
        if ($version->archivo && Storage::disk('local')->exists($version->archivo)) {
            $ruta = Storage::disk('local')->path($version->archivo);
            $extension = strtolower(pathinfo($version->archivo, PATHINFO_EXTENSION));

            if ($extension === 'pdf') {
                return [AiService::bloquePdf($ruta), ['type' => 'text', 'text' => $instruccion]];
            }
            if (in_array($extension, PlantillaImporter::EXTENSIONES, true)) {
                return "# Documento actual de la empresa\n\n".$importer->extraerDe($ruta, $extension)."\n\n---\n\n".$instruccion;
            }
        }

        if (filled($version->contenido)) {
            return "# Documento actual de la empresa\n\n{$version->contenido}\n\n---\n\n".$instruccion;
        }

        throw new RuntimeException('El borrador no tiene un archivo Word o PDF ni texto que actualizar.');
    }

    public function failed(?Throwable $e): void
    {
        ControlledDocumentVersion::query()
            ->whereKey($this->versionId)
            ->where('ia_estado', 'generando')
            ->update(['ia_estado' => 'error', 'ia_error' => mb_substr($e?->getMessage() ?? 'Error desconocido', 0, 1000)]);
    }
}
