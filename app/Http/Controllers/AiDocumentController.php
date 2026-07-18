<?php

namespace App\Http\Controllers;

use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Services\AiService;
use App\Services\DocumentFiller;
use App\Services\DocxExporter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Generación de documentos SGI con IA (Claude) para el cliente activo.
 *
 * Flujo human-in-the-loop: la IA genera un borrador a partir de la plantilla
 * + el contexto de la organización; el consultor lo edita y aprueba.
 *
 * Permisos: ver -> documents.view | generar/editar -> documents.manage
 */
class AiDocumentController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AiService $ai,
        private readonly DocumentFiller $filler,
    ) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('documentos-ia/index', [
                'needsClient' => true,
                'templates' => [],
                'documents' => [],
            ]);
        }

        return Inertia::render('documentos-ia/index', [
            'needsClient' => false,
            'templates' => DocumentTemplate::orderBy('orden')->get()->map(fn (DocumentTemplate $t) => [
                'id' => $t->id,
                'codigo' => $t->codigo,
                'nombre' => $t->nombre,
                'tipo' => $t->tipo,
                'categoria' => $t->categoria,
                'normas' => $t->normas,
                'descripcion' => $t->descripcion,
                'tiene_base' => $t->tieneBase(),
            ]),
            'documents' => GeneratedDocument::latest()->get(['id', 'document_template_id', 'titulo', 'estado', 'version', 'generado_por', 'updated_at', 'contenido']),
        ]);
    }

    /** Genera un documento con IA a partir de una plantilla y el contexto del cliente. */
    public function generate(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de generar documentos.']);
        }

        $data = $request->validate([
            'document_template_id' => ['required', 'integer', 'exists:document_templates,id'],
        ]);

        $template = DocumentTemplate::findOrFail($data['document_template_id']);
        $tenant = $this->context->get();

        if ($template->tieneBase()) {
            // DOCUMENTO MODELO real de CMK: se rellena de forma DETERMINISTA con
            // los datos del cliente (instantáneo, exacto, sin alterar el texto de
            // cumplimiento). No pasa por la IA.
            $contenido = $this->filler->fill($template->contenido_base, $tenant);
            $via = 'con los datos del cliente';
        } else {
            // Sin modelo base: la IA redacta desde cero a partir del prompt.
            // Puede tardar; damos margen sobre el max_execution_time por defecto.
            // (En producción esto debería moverse a una cola/Job.)
            set_time_limit($this->ai->timeout() + 30);

            $system = 'Eres un consultor senior experto en SST, PESV y HSEQ en Colombia. '
                .'Redactas documentos formales del Sistema de Gestión conforme a la normativa colombiana '
                .'(Decreto 1072, Resolución 0312, Resolución 1401, Resolución 40595, ISO 9001/14001/45001). '
                .'Escribe en español formal, en formato Markdown con encabezados, listo para revisión. '
                .'No inventes datos que no se te proporcionen: usa [PENDIENTE] cuando falte información.';

            $prompt = $template->prompt."\n\n".$this->contextoCliente($tenant)
                ."\n\nEntrega el documento completo en Markdown.";

            try {
                $contenido = $this->ai->complete($prompt, $system);
            } catch (Throwable $e) {
                return back()->withErrors(['ai' => 'No se pudo generar el documento: '.$e->getMessage()]);
            }
            $via = 'con IA';
        }

        GeneratedDocument::create([
            'document_template_id' => $template->id,
            'titulo' => $template->nombre,
            'contenido' => $contenido,
            'estado' => 'borrador',
            'version' => 1,
            'generado_por' => $request->user()?->name,
        ]);

        return back()->with('success', "Documento «{$template->nombre}» generado {$via}.");
    }

    /** Bloque de contexto del cliente para los prompts de redacción con IA. */
    private function contextoCliente(\App\Models\Tenant $tenant): string
    {
        return "Datos de la empresa cliente:\n"
            ."- Nombre: {$tenant->name}\n"
            .'- NIT: '.($tenant->nit ?: '[PENDIENTE]')."\n"
            .'- Actividad económica: '.($tenant->actividad_economica ?: '[PENDIENTE]')."\n"
            .'- Sector: '.($tenant->sector ?: '[PENDIENTE]')."\n"
            .'- Tamaño: '.($tenant->tamano_empresa ?: '[PENDIENTE]')."\n"
            .'- N.° de trabajadores: '.($tenant->num_trabajadores ?: '[PENDIENTE]')."\n"
            .'- Nivel de riesgo ARL: '.($tenant->nivel_riesgo ?: '[PENDIENTE]')."\n"
            .'- ARL: '.($tenant->arl ?: '[PENDIENTE]')."\n"
            .'- Representante legal: '.($tenant->representante_legal ?: '[PENDIENTE]')."\n"
            .'- Responsable SG-SST: '.($tenant->responsable_sgsst ?: '[PENDIENTE]').' (Licencia: '.($tenant->licencia_sgsst ?: '[PENDIENTE]').")\n"
            .'- Ciudad: '.($tenant->city ?: '[PENDIENTE]');
    }

    /** Guarda ediciones del consultor y/o cambia el estado (revisión/aprobación). */
    public function update(Request $request, GeneratedDocument $documento): RedirectResponse
    {
        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'contenido' => ['required', 'string'],
            'estado' => ['required', Rule::in(['borrador', 'en_revision', 'aprobado'])],
        ]);

        // Si cambió el contenido respecto al guardado, sube la versión.
        if ($data['contenido'] !== $documento->contenido) {
            $data['version'] = $documento->version + 1;
        }

        $documento->update($data);

        return back()->with('success', 'Documento actualizado.');
    }

    public function destroy(GeneratedDocument $documento): RedirectResponse
    {
        $documento->delete();

        return back()->with('success', 'Documento eliminado.');
    }

    /** Descarga el documento como .docx con membrete de CMK. */
    public function export(GeneratedDocument $documento, DocxExporter $exporter): BinaryFileResponse
    {
        $path = $exporter->export($documento);

        return response()
            ->download($path, \Illuminate\Support\Str::slug($documento->titulo).'.docx')
            ->deleteFileAfterSend();
    }
}
