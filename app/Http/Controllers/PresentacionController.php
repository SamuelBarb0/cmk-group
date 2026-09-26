<?php

namespace App\Http\Controllers;

use App\Jobs\GenerarPresentacionJob;
use App\Models\DocumentCatalogEntry;
use App\Models\Presentation;
use App\Models\TenantDocument;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Presentaciones (.pptx) que la IA arma con el contexto completo del cliente,
 * de cualquier módulo del mapa documental (M01–M20) o del sistema completo.
 *
 * Permisos: ver y descargar -> documents.view | generar y borrar -> documents.manage
 */
class PresentacionController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        $tenant = $this->context->has() ? $this->context->get() : null;
        // Módulos del mapa que la empresa tiene: los que tienen algún documento contratado.
        $contratados = $tenant?->documentos_sig === null ? null
            : DocumentCatalogEntry::query()->whereIn('id', $tenant->documentos_sig)->distinct()->pluck('modulo')->all();

        return Inertia::render('presentaciones/index', [
            'needsClient' => ! $tenant,
            'presentaciones' => $tenant ? Presentation::query()->with('user:id,name')->latest()->limit(50)->get() : [],
            'modulos' => collect(DocumentCatalogEntry::MODULOS)
                ->map(fn (string $nombre, string $m) => ['codigo' => $m, 'nombre' => $nombre, 'contratado' => $contratados === null || in_array($m, $contratados, true)])
                ->values(),
            'propositos' => collect(Presentation::PROPOSITOS)->map(fn (array $p) => $p[0]),
            'moduloInicial' => array_key_exists((string) $request->query('modulo'), DocumentCatalogEntry::MODULOS) ? $request->query('modulo') : null,
            'periodo' => ['desde' => Carbon::today()->subYear()->addDay()->toDateString(), 'hasta' => Carbon::today()->toDateString()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        $datos = $request->validate([
            'modulo' => ['nullable', Rule::in(array_keys(DocumentCatalogEntry::MODULOS))],
            'proposito' => ['required', Rule::in(array_keys(Presentation::PROPOSITOS))],
            'instrucciones' => ['nullable', 'required_if:proposito,otro', 'string', 'max:2000'],
            'diapositivas' => ['required', 'integer', 'between:4,20'],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde', 'before_or_equal:today'],
        ], [
            'instrucciones.required_if' => 'Cuenta qué presentación necesitas.',
        ]);

        $p = new Presentation($datos);
        $p->forceFill(['user_id' => $request->user()->id, 'estado' => 'generando']);
        $p->save();
        GenerarPresentacionJob::dispatch($p->id);

        return back()->with('success', 'Generando la presentación con los datos del cliente. Tarda uno o dos minutos.');
    }

    public function descargar(Presentation $presentacion): StreamedResponse
    {
        abort_unless($presentacion->estado === 'lista' && $presentacion->archivo && Storage::disk('local')->exists($presentacion->archivo), 404);

        return Storage::disk('local')->download($presentacion->archivo, basename($presentacion->archivo));
    }

    public function destroy(Presentation $presentacion): RedirectResponse
    {
        if ($presentacion->archivo) {
            TenantDocument::query()->where('path', $presentacion->archivo)->delete();
            Storage::disk('local')->delete($presentacion->archivo);
        }
        $presentacion->delete();

        return back()->with('success', 'Presentación eliminada.');
    }
}
