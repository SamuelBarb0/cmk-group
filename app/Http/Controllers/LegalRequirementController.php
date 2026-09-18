<?php

namespace App\Http\Controllers;

use App\Models\LegalRequirement;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Matriz de requisitos legales del cliente activo.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class LegalRequirementController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('requisitos-legales/index', [
                'needsClient' => true,
                'requisitos' => [],
                'stats' => ['total' => 0, 'aplicables' => 0, 'cumplidos' => 0, 'porcentaje' => 0],
                'catalogos' => ['cumplimientos' => LegalRequirement::CUMPLIMIENTOS],
            ]);
        }

        $requisitos = LegalRequirement::query()
            ->orderBy('norma')
            ->orderBy('articulo')
            ->get();

        $aplicables = $requisitos->where('aplica', true);
        $cumplidos = $aplicables->where('cumplimiento', 'cumple');

        return Inertia::render('requisitos-legales/index', [
            'needsClient' => false,
            'requisitos' => $requisitos,
            'stats' => [
                'total' => $requisitos->count(),
                'aplicables' => $aplicables->count(),
                'cumplidos' => $cumplidos->count(),
                // Es el indicador CUMP-LEG, calculado igual que en los scopes
                // del modelo. Se muestra aquí para que el consultor vea la
                // cifra sin tener que ir a Indicadores.
                'porcentaje' => $aplicables->count() > 0
                    ? (int) round($cumplidos->count() / $aplicables->count() * 100)
                    : 0,
            ],
            'catalogos' => ['cumplimientos' => LegalRequirement::CUMPLIMIENTOS],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de registrar requisitos.']);
        }

        LegalRequirement::create($this->validated($request));

        return back()->with('success', 'Requisito legal agregado.');
    }

    public function update(Request $request, LegalRequirement $requisito): RedirectResponse
    {
        $requisito->update($this->validated($request));

        return back()->with('success', 'Requisito actualizado.');
    }

    public function destroy(LegalRequirement $requisito): RedirectResponse
    {
        $requisito->delete();

        return back()->with('success', 'Requisito eliminado.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'norma' => ['required', 'string', 'max:255'],
            'anio' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'articulo' => ['nullable', 'string', 'max:120'],
            'tema' => ['nullable', 'string', 'max:160'],
            'entidad' => ['nullable', 'string', 'max:120'],
            'requisito' => ['required', 'string', 'max:2000'],
            'aplica' => ['boolean'],
            // Si no aplica hay que decir por qué: en una auditoría no basta con
            // omitir el requisito, hay que sustentar la exclusión.
            'justificacion_no_aplica' => ['nullable', 'string', 'max:1000', 'required_if:aplica,false'],
            'cumplimiento' => ['required', Rule::in(LegalRequirement::CUMPLIMIENTOS)],
            'forma_cumplimiento' => ['nullable', 'string', 'max:2000'],
            'evidencia' => ['nullable', 'string', 'max:2000'],
            'responsable' => ['nullable', 'string', 'max:255'],
            'fecha_verificacion' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
