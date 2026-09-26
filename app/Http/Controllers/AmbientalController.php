<?php

namespace App\Http\Controllers;

use App\Models\ChemicalProduct;
use App\Models\ControlledDocument;
use App\Models\ResourceReading;
use App\Models\WasteRecord;
use App\Services\Ambiental\DocumentosAmbiental;
use App\Services\ControlDocumental\CicloDocumental;
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
 * M18 — Gestión ambiental (ISO 14001) de la empresa activa: residuos con
 * categoría RESPEL y certificados, consumos de agua y energía, e inventario
 * de productos químicos con su matriz de compatibilidad.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class AmbientalController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly DocumentosAmbiental $documentos,
    ) {}

    public function index(): Response
    {
        $catalogos = [
            'tiposResiduo' => WasteRecord::TIPOS,
            'disposiciones' => WasteRecord::DISPOSICIONES,
            'conCertificado' => WasteRecord::CON_CERTIFICADO,
            'categorias' => WasteRecord::ETIQUETA_CATEGORIA,
            'recursos' => ResourceReading::RECURSOS,
            'pictogramas' => ChemicalProduct::PICTOGRAMAS,
            'hdsAnios' => ChemicalProduct::HDS_ANIOS,
        ];
        if (! $this->context->has()) {
            return Inertia::render('ambiental/index', $catalogos + [
                'needsClient' => true, 'residuos' => [], 'lecturas' => [], 'quimicos' => [], 'matriz' => (object) [], 'respel' => null, 'documentos' => [],
            ]);
        }

        $residuos = WasteRecord::query()->orderByDesc('fecha')->orderByDesc('id')->get();
        $quimicos = ChemicalProduct::query()->orderByDesc('activo')->orderBy('nombre')->get();

        return Inertia::render('ambiental/index', $catalogos + [
            'needsClient' => false,
            'residuos' => $residuos->map(fn (WasteRecord $w) => $w->toArray() + ['sin_certificado' => $w->sinCertificado()])->values(),
            // Dos años: el actual y el anterior para comparar mes contra mes.
            'lecturas' => ResourceReading::query()->where('periodo', '>=', Carbon::today()->startOfMonth()->subMonths(23)->toDateString())
                ->orderByDesc('periodo')->get()->map(fn (ResourceReading $l) => $l->toArray() + ['mes' => $l->periodo->format('Y-m')])->values(),
            'quimicos' => $quimicos->values(),
            'matriz' => (object) ChemicalProduct::matriz($quimicos->where('activo', true)->values()),
            'respel' => WasteRecord::categoriaRespel(),
            'documentos' => collect(array_keys(DocumentosAmbiental::DOCUMENTOS))->map(fn (string $d) => $this->estadoDocumento($d))->filter()->values(),
        ]);
    }

    // ── Residuos ────────────────────────────────────────────────────────────

    public function storeResiduo(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }
        $this->guardarResiduo($request, new WasteRecord);

        return back()->with('success', 'Entrega de residuos registrada.');
    }

    public function updateResiduo(Request $request, WasteRecord $residuo): RedirectResponse
    {
        $this->guardarResiduo($request, $residuo);

        return back()->with('success', 'Registro actualizado.');
    }

    public function destroyResiduo(WasteRecord $residuo): RedirectResponse
    {
        $archivo = $residuo->archivo;
        $residuo->delete();
        if ($archivo) {
            Storage::disk('local')->delete($archivo);
        }

        return back()->with('success', 'Registro eliminado.');
    }

    public function certificado(WasteRecord $residuo): StreamedResponse
    {
        abort_unless($residuo->archivo && Storage::disk('local')->exists($residuo->archivo), 404);

        return Storage::disk('local')->download($residuo->archivo, $residuo->archivo_nombre);
    }

    private function guardarResiduo(Request $request, WasteRecord $residuo): void
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'tipo' => ['required', Rule::in(array_keys(WasteRecord::TIPOS))],
            'corriente' => ['nullable', 'required_if:tipo,peligroso', 'string', 'max:255'],
            'cantidad_kg' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'gestor' => ['nullable', 'string', 'max:255'],
            'licencia_gestor' => ['nullable', 'string', 'max:120'],
            'disposicion' => ['nullable', Rule::in(array_keys(WasteRecord::DISPOSICIONES))],
            'certificado' => ['nullable', 'string', 'max:60'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'archivo' => ['nullable', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg'],
        ], [
            'corriente.required_if' => 'Un residuo peligroso se identifica por su nombre (aceite usado, luminarias, envases contaminados…): lo pide el plan RESPEL.',
        ]);

        $archivo = $datos['archivo'] ?? null;
        unset($datos['archivo']);
        $residuo->fill($datos);
        if ($archivo) {
            $anterior = $residuo->archivo;
            $residuo->archivo = $archivo->storeAs('tenants/'.$this->context->id().'/residuos', uniqid().'-'.$archivo->getClientOriginalName(), 'local');
            $residuo->archivo_nombre = $archivo->getClientOriginalName();
            if ($anterior) {
                Storage::disk('local')->delete($anterior);
            }
        }
        $residuo->save();
    }

    // ── Consumos ────────────────────────────────────────────────────────────

    /** Una lectura por mes y recurso: registrar el mismo mes otra vez la corrige. */
    public function storeLectura(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }
        $datos = $request->validate([
            'mes' => ['required', 'date_format:Y-m', 'before_or_equal:'.Carbon::today()->format('Y-m')],
            'recurso' => ['required', Rule::in(array_keys(ResourceReading::RECURSOS))],
            'cantidad' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'costo' => ['nullable', 'numeric', 'min:0'],
            'trabajadores' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], ['mes.before_or_equal' => 'No se puede registrar el consumo de un mes futuro.']);

        $periodo = Carbon::createFromFormat('Y-m-d', $datos['mes'].'-01')->toDateString();
        $lectura = ResourceReading::query()->whereDate('periodo', $periodo)->where('recurso', $datos['recurso'])->first() ?? new ResourceReading;
        $nueva = ! $lectura->exists;
        $lectura->fill(['periodo' => $periodo] + collect($datos)->except('mes')->all())->save();

        return back()->with('success', $nueva ? 'Consumo registrado.' : 'Ese mes ya tenía lectura: quedó corregida.');
    }

    public function destroyLectura(ResourceReading $lectura): RedirectResponse
    {
        $lectura->delete();

        return back()->with('success', 'Lectura eliminada.');
    }

    // ── Productos químicos ──────────────────────────────────────────────────

    public function storeQuimico(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }
        $p = ChemicalProduct::create($this->validatedQuimico($request));

        return back()->with('success', "«{$p->nombre}» agregado al inventario.");
    }

    public function updateQuimico(Request $request, ChemicalProduct $quimico): RedirectResponse
    {
        $quimico->update($this->validatedQuimico($request));

        return back()->with('success', 'Producto actualizado.');
    }

    public function destroyQuimico(ChemicalProduct $quimico): RedirectResponse
    {
        $quimico->delete();

        return back()->with('success', 'Producto eliminado del inventario.');
    }

    /** @return array<string, mixed> */
    private function validatedQuimico(Request $request): array
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'proveedor' => ['nullable', 'string', 'max:255'],
            'uso' => ['nullable', 'string', 'max:255'],
            'cantidad' => ['nullable', 'string', 'max:60'],
            'ubicacion' => ['nullable', 'string', 'max:255'],
            'peligros' => ['present', 'array'],
            'peligros.*' => [Rule::in(array_keys(ChemicalProduct::PICTOGRAMAS))],
            'hds_fecha' => ['nullable', 'date', 'before_or_equal:today'],
            'epp' => ['nullable', 'string', 'max:255'],
            'activo' => ['boolean'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);
        $datos['peligros'] = array_values(array_unique($datos['peligros']));
        sort($datos['peligros']);

        return $datos;
    }

    // ── Documentos ──────────────────────────────────────────────────────────

    public function enviar(Request $request, CicloDocumental $ciclo): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }
        $documento = $request->validate(['documento' => ['required', Rule::in(array_keys(DocumentosAmbiental::DOCUMENTOS))]])['documento'];
        $entrada = $this->documentos->entrada($documento);
        if (! $entrada) {
            return back()->withErrors(['documento' => 'El catálogo del SIG no está cargado en esta instalación: falta correr SigCatalogSeeder.']);
        }

        $doc = $ciclo->recibirDeModulo($this->context->id(), $entrada, $this->documentos->contenido($documento),
            'Actualizado desde el módulo ambiental.', $request->user(), $this->documentos->claves($documento));

        return back()->with('success', "{$doc->codigo} quedó en borrador en el control documental. Revísalo y apruébalo allí.");
    }

    /** @return array<string, mixed>|null */
    private function estadoDocumento(string $clave): ?array
    {
        $entrada = $this->documentos->entrada($clave);
        if (! $entrada) {
            return null;
        }
        $doc = ControlledDocument::query()->where('document_catalog_id', $entrada->id)->first();

        return ['clave' => $clave, 'titulo' => $entrada->nombre, 'id' => $doc?->id, 'codigo' => $doc?->codigo, 'estado' => $doc?->estado];
    }
}
