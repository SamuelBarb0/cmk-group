<?php

namespace App\Http\Controllers;

use App\Models\ControlledDocument;
use App\Models\ControlledDocumentVersion;
use App\Models\GeneratedDocument;
use App\Services\ControlDocumental\CicloDocumental;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Versiones de un documento controlado: edición del borrador, transiciones del
 * ciclo de vida y descarga del archivo.
 *
 * Las rutas usan scopeBindings: `{version}` se busca dentro de `{documento}`,
 * que ya pasó por el filtro de la empresa. Una versión de otro documento (o de
 * otra empresa) da 404.
 */
class ControlDocumentalVersionController extends Controller
{
    public function __construct(private readonly CicloDocumental $ciclo) {}

    public function store(Request $request, ControlledDocument $documento): RedirectResponse
    {
        $this->ciclo->nuevaVersion($documento, auth()->user(), (string) $request->input('descripcion_cambio'));

        return back()->with('success', 'Versión nueva abierta en borrador.');
    }

    /** Guarda el borrador. Llega por POST porque puede traer un archivo. */
    public function update(Request $request, ControlledDocument $documento, ControlledDocumentVersion $version): RedirectResponse
    {
        if (! $version->esEditable()) {
            throw ValidationException::withMessages(['estado' => 'Solo se edita un borrador. Esta versión está '.str_replace('_', ' ', $version->estado).'.']);
        }

        $datos = $request->validate([
            'contenido' => ['nullable', 'string'],
            'descripcion_cambio' => ['nullable', 'string', 'max:2000'],
            'archivo' => ['nullable', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,png,jpg,jpeg'],
            'documento_ia_id' => ['nullable', 'integer'],
        ]);

        $version->descripcion_cambio = $datos['descripcion_cambio'] ?? null;
        // El navegador manda el textarea con saltos CRLF, y el render de
        // Markdown corta las líneas en LF: el retorno de carro sobrante dejaba
        // los encabezados y las listas como texto literal.
        $version->contenido = isset($datos['contenido']) ? str_replace("\r\n", "\n", $datos['contenido']) : null;

        if (! empty($datos['documento_ia_id'])) {
            // Pasa por el TenantScope: un id de otra empresa da 404.
            $ia = GeneratedDocument::query()->findOrFail($datos['documento_ia_id']);
            $version->contenido = $ia->contenido;
        }

        if ($request->hasFile('archivo')) {
            $archivo = $request->file('archivo');
            // Nombre propio por versión: no se sobrescribe el archivo que la
            // versión anterior pueda estar compartiendo.
            $version->archivo = $archivo->storeAs(
                "tenants/{$documento->tenant_id}/control-documental/{$documento->id}",
                'v'.$version->version.'-'.Str::random(8).'.'.$archivo->getClientOriginalExtension(),
                'local',
            );
            $version->archivo_nombre = $archivo->getClientOriginalName();
        }

        $version->save();

        return back()->with('success', 'Borrador guardado.');
    }

    public function transicion(Request $request, ControlledDocument $documento, ControlledDocumentVersion $version): RedirectResponse
    {
        $datos = $request->validate([
            'accion' => ['required', Rule::in(['enviar', 'revisar', 'devolver', 'aprobar'])],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = auth()->user();

        match ($datos['accion']) {
            'enviar' => $this->ciclo->enviarARevision($version, $user),
            'revisar' => $this->ciclo->aprobarRevision($version, $user),
            'devolver' => $this->ciclo->devolver($version, (string) ($datos['observaciones'] ?? '')),
            'aprobar' => $this->ciclo->aprobar($version, $user),
        };

        return back()->with('success', [
            'enviar' => 'Enviado a revisión.',
            'revisar' => 'Revisión aprobada; pasa a aprobación.',
            'devolver' => 'Devuelto al autor con observaciones.',
            'aprobar' => "Versión {$version->version} aprobada y publicada.",
        ][$datos['accion']]);
    }

    public function destroy(ControlledDocument $documento, ControlledDocumentVersion $version): RedirectResponse
    {
        $this->ciclo->descartar($version);

        return back()->with('success', 'Borrador descartado.');
    }

    public function archivo(ControlledDocument $documento, ControlledDocumentVersion $version): StreamedResponse
    {
        abort_unless($version->archivo && Storage::disk('local')->exists($version->archivo), 404);

        return Storage::disk('local')->download($version->archivo, $version->archivo_nombre);
    }
}
