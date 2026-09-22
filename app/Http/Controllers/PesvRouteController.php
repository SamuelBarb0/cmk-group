<?php

namespace App\Http\Controllers;

use App\Models\PesvRoute;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rutas y desplazamientos habituales del cliente activo (PESV, Paso 5).
 *
 * Alimenta los pasos 14 (vías seguras) y 15 (planificación de desplazamientos).
 *
 * Permisos: ver -> pesv.view | gestionar -> pesv.manage
 */
class PesvRouteController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('pesv/rutas', [
                'needsClient' => true,
                'rutas' => [],
                'stats' => ['total' => 0, 'criticas' => 0],
                'nivelesRiesgo' => PesvRoute::NIVELES_RIESGO,
                'tiposVia' => PesvRoute::TIPOS_VIA,
                'frecuencias' => PesvRoute::FRECUENCIAS,
            ]);
        }

        // Las rutas más peligrosas primero: es el orden en que hay que
        // atenderlas y el que espera ver un auditor.
        //
        // Se ordena con un CASE y no con FIELD() —como hace IpercController—
        // porque FIELD() solo existe en MySQL: en SQLite (los tests) y en
        // PostgreSQL la consulta reventaría.
        $rutas = PesvRoute::orderByRaw(
            "CASE nivel_riesgo WHEN 'critico' THEN 1 WHEN 'alto' THEN 2 WHEN 'medio' THEN 3 WHEN 'bajo' THEN 4 ELSE 5 END"
        )
            ->orderBy('nombre')
            ->get();

        return Inertia::render('pesv/rutas', [
            'needsClient' => false,
            'rutas' => $rutas,
            'stats' => [
                'total' => $rutas->count(),
                'criticas' => $rutas->whereIn('nivel_riesgo', ['alto', 'critico'])->count(),
                'km' => round((float) $rutas->sum('distancia_km'), 2),
            ],
            'nivelesRiesgo' => PesvRoute::NIVELES_RIESGO,
            'tiposVia' => PesvRoute::TIPOS_VIA,
            'frecuencias' => PesvRoute::FRECUENCIAS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de registrar rutas.']);
        }

        PesvRoute::create($this->validated($request));

        return back()->with('success', 'Ruta registrada.');
    }

    public function update(Request $request, PesvRoute $ruta): RedirectResponse
    {
        $ruta->update($this->validated($request));

        return back()->with('success', 'Ruta actualizada.');
    }

    public function destroy(PesvRoute $ruta): RedirectResponse
    {
        $ruta->delete();

        return back()->with('success', 'Ruta eliminada.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'origen' => ['nullable', 'string', 'max:255'],
            'destino' => ['nullable', 'string', 'max:255'],
            'tipo_via' => ['nullable', Rule::in(PesvRoute::TIPOS_VIA)],
            'distancia_km' => ['nullable', 'numeric', 'min:0'],
            'duracion_min' => ['nullable', 'integer', 'min:0'],
            'frecuencia' => ['nullable', Rule::in(PesvRoute::FRECUENCIAS)],
            'horario' => ['nullable', 'string', 'max:120'],
            'peligros' => ['nullable', 'string'],
            'controles' => ['nullable', 'string'],
            'nivel_riesgo' => ['nullable', Rule::in(PesvRoute::NIVELES_RIESGO)],
            'is_active' => ['boolean'],
        ]);
    }
}
