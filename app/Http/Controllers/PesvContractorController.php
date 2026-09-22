<?php

namespace App\Http\Controllers;

use App\Models\PesvContractor;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contratistas y terceros del cliente activo (PESV, Paso 5).
 *
 * Alimenta los pasos 11 (evaluación de terceros) y 18 (gestión del cambio).
 *
 * Permisos: ver -> pesv.view | gestionar -> pesv.manage
 */
class PesvContractorController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('pesv/contratistas', [
                'needsClient' => true,
                'contratistas' => [],
                'stats' => ['total' => 0, 'sin_evaluar' => 0],
                'tipos' => PesvContractor::TIPOS,
            ]);
        }

        $contratistas = PesvContractor::orderBy('nombre')->get();

        return Inertia::render('pesv/contratistas', [
            'needsClient' => false,
            'contratistas' => $contratistas,
            'stats' => [
                'total' => $contratistas->count(),
                'sin_evaluar' => $contratistas->whereNull('evaluado_at')->count(),
                'con_pesv' => $contratistas->where('tiene_pesv', true)->count(),
                'conductores' => (int) $contratistas->sum('num_conductores'),
                'vehiculos' => (int) $contratistas->sum('num_vehiculos'),
            ],
            'tipos' => PesvContractor::TIPOS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de registrar contratistas.']);
        }

        PesvContractor::create($this->validated($request));

        return back()->with('success', 'Contratista registrado.');
    }

    public function update(Request $request, PesvContractor $contratista): RedirectResponse
    {
        $contratista->update($this->validated($request));

        return back()->with('success', 'Contratista actualizado.');
    }

    public function destroy(PesvContractor $contratista): RedirectResponse
    {
        $contratista->delete();

        return back()->with('success', 'Contratista eliminado.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'nit' => ['nullable', 'string', 'max:30'],
            'tipo' => ['required', Rule::in(PesvContractor::TIPOS)],
            'actividad' => ['nullable', 'string', 'max:255'],
            'contacto_nombre' => ['nullable', 'string', 'max:255'],
            'contacto_telefono' => ['nullable', 'string', 'max:50'],
            'contacto_email' => ['nullable', 'email', 'max:255'],
            'num_conductores' => ['nullable', 'integer', 'min:0'],
            'num_vehiculos' => ['nullable', 'integer', 'min:0'],
            'tiene_pesv' => ['boolean'],
            'evaluado_at' => ['nullable', 'date'],
            'calificacion' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
        ]);
    }
}
