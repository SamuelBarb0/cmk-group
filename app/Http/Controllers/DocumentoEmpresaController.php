<?php

namespace App\Http\Controllers;

use App\Models\TenantDocument;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Repositorio documental de la empresa cliente activa: los documentos QUEDAN
 * guardados por empresa — exports .docx de Documentos IA (archivados
 * automáticamente) y archivos subidos a mano (versiones firmadas, evidencias).
 *
 * Permisos: ver/descargar -> documents.view | subir/eliminar -> documents.manage
 */
class DocumentoEmpresaController extends Controller
{
    /** Categorías sugeridas del repositorio (alineadas con el SGI). */
    private const CATEGORIAS = [
        'Políticas', 'Procedimientos', 'Formatos', 'Planes', 'Manuales',
        'Evidencias', 'Actas', 'Contratos', 'Otros',
    ];

    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('documentos/index', [
                'needsClient' => true,
                'documents' => [],
                'categorias' => [],
            ]);
        }

        return Inertia::render('documentos/index', [
            'needsClient' => false,
            'documents' => TenantDocument::latest()->get()->map(fn (TenantDocument $d) => [
                'id' => $d->id,
                'nombre' => $d->nombre,
                'categoria' => $d->categoria,
                'origen' => $d->origen,
                'size' => $d->size,
                'mime' => $d->mime,
                'subido_por' => $d->subido_por,
                'updated_at' => $d->updated_at?->format('d/m/Y H:i'),
            ]),
            'categorias' => self::CATEGORIAS,
        ]);
    }

    /** Sube un archivo al repositorio de la empresa activa. */
    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de subir documentos.']);
        }

        $data = $request->validate([
            'archivo' => [
                'required', 'file', 'max:10240',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,txt,csv',
            ],
            'nombre' => ['nullable', 'string', 'max:255'],
            'categoria' => ['nullable', 'string', 'max:100'],
        ]);

        $archivo = $data['archivo'];
        $tenantId = $this->context->id();

        $path = $archivo->storeAs(
            "tenants/{$tenantId}/documentos",
            uniqid().'-'.$archivo->getClientOriginalName(),
            'local',
        );

        TenantDocument::create([
            'nombre' => $data['nombre'] ?: $archivo->getClientOriginalName(),
            'categoria' => $data['categoria'] ?? null,
            'origen' => 'upload',
            'path' => $path,
            'size' => $archivo->getSize(),
            'mime' => $archivo->getClientMimeType(),
            'subido_por' => $request->user()?->name,
        ]);

        return back()->with('success', 'Documento guardado en el repositorio de la empresa.');
    }

    /** Descarga un documento del repositorio (el binding ya viene filtrado por tenant). */
    public function download(TenantDocument $documento): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($documento->path), 404, 'El archivo ya no existe en el servidor.');

        $extension = pathinfo($documento->path, PATHINFO_EXTENSION);
        $nombre = \Illuminate\Support\Str::slug($documento->nombre).($extension ? ".{$extension}" : '');

        return Storage::disk('local')->download($documento->path, $nombre);
    }

    public function destroy(TenantDocument $documento): RedirectResponse
    {
        Storage::disk('local')->delete($documento->path);
        $documento->delete();

        return back()->with('success', 'Documento eliminado del repositorio.');
    }
}
