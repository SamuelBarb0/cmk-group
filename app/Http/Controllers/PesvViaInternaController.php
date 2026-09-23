<?php

namespace App\Http\Controllers;

use App\Models\FormRecord;
use App\Models\PesvInternalRoad;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vías seguras administradas por la organización (paso 14): vías y zonas
 * internas con sus riesgos críticos, plan de acción y cronograma de
 * mantenimiento. Las inspecciones de vías se diligencian en Formatos
 * (FT-INS-VIAS) y aquí se cuentan.
 */
class PesvViaInternaController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('pesv/vias-internas', ['needsClient' => true]);
        }

        $anio = (int) now()->year;

        return Inertia::render('pesv/vias-internas', [
            'needsClient' => false,
            'anio' => $anio,
            'vias' => PesvInternalRoad::orderByDesc('activa')->orderBy('nombre')->get()->map(fn (PesvInternalRoad $v) => [
                ...$v->only(['id', 'nombre', 'descripcion', 'riesgos_criticos', 'tiempo_min', 'frecuente', 'veces_mes', 'plan_accion', 'activa', 'anio_cronograma']),
                'km' => $v->km !== null ? (float) $v->km : null,
                'cronograma' => $v->cronograma ?? [],
                'cumplimiento' => $v->cumplimiento($v->anio_cronograma === $anio ? (int) now()->month : null),
            ]),
            'actividades' => PesvInternalRoad::ACTIVIDADES,
            'inspecciones' => FormRecord::where('codigo', 'FT-INS-VIAS')->whereYear('fecha', $anio)->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->context->has(), 404);
        PesvInternalRoad::create($this->validar($request));

        return back()->with('success', 'Vía interna registrada.');
    }

    public function update(Request $request, PesvInternalRoad $via): RedirectResponse
    {
        $via->update($this->validar($request));

        return back()->with('success', 'Vía interna actualizada.');
    }

    public function destroy(PesvInternalRoad $via): RedirectResponse
    {
        $via->delete();

        return back()->with('success', 'Vía interna eliminada.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $request): array
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'riesgos_criticos' => ['nullable', 'string', 'max:2000'],
            'km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'tiempo_min' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'frecuente' => ['boolean'],
            'veces_mes' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'plan_accion' => ['nullable', 'string', 'max:3000'],
            'activa' => ['boolean'],
            'anio_cronograma' => ['nullable', 'integer', 'between:2000,2100'],
            'cronograma' => ['nullable', 'array', 'max:30'],
            'cronograma.*.actividad' => ['required', 'string', 'max:255'],
            'cronograma.*.costo' => ['nullable', 'numeric', 'min:0'],
            'cronograma.*.programados' => ['nullable', 'array'],
            'cronograma.*.programados.*' => ['integer', 'between:1,12'],
            'cronograma.*.ejecutados' => ['nullable', 'array'],
            'cronograma.*.ejecutados.*' => ['integer', 'between:1,12'],
        ]);
        $datos['cronograma'] = collect($datos['cronograma'] ?? [])->map(fn ($f) => [
            'actividad' => trim($f['actividad']),
            'costo' => isset($f['costo']) ? (float) $f['costo'] : null,
            'programados' => array_values(array_unique(array_map('intval', $f['programados'] ?? []))),
            'ejecutados' => array_values(array_unique(array_map('intval', $f['ejecutados'] ?? []))),
        ])->values()->all();

        return $datos;
    }
}
