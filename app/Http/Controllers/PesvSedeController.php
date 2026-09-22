<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PesvSede;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sedes o centros de trabajo del cliente activo (PESV, Paso 5).
 *
 * Permisos: ver -> pesv.view | gestionar -> pesv.manage
 */
class PesvSedeController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('pesv/sedes', [
                'needsClient' => true,
                'sedes' => [],
                'sugerencias' => [],
                'empresa' => null,
            ]);
        }

        $tenant = $this->context->get();

        return Inertia::render('pesv/sedes', [
            'needsClient' => false,
            'sedes' => PesvSede::orderByDesc('es_principal')->orderBy('nombre')->get(),
            // Las sedes que ya se escribieron en la ficha de los empleados:
            // es información que la empresa YA cargó y que aquí se ofrece para
            // no volver a teclearla.
            'sugerencias' => Employee::query()
                ->whereNotNull('sede')
                ->where('sede', '!=', '')
                ->distinct()
                ->orderBy('sede')
                ->pluck('sede'),
            'empresa' => [
                'direccion' => $tenant->address,
                'ciudad' => $tenant->city,
                'num_trabajadores' => $tenant->num_trabajadores,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de registrar sedes.']);
        }

        $sede = PesvSede::create($this->validated($request));
        $this->garantizarUnaPrincipal($sede);

        return back()->with('success', 'Sede registrada.');
    }

    public function update(Request $request, PesvSede $sede): RedirectResponse
    {
        $sede->update($this->validated($request));
        $this->garantizarUnaPrincipal($sede);

        return back()->with('success', 'Sede actualizada.');
    }

    public function destroy(PesvSede $sede): RedirectResponse
    {
        $sede->delete();

        return back()->with('success', 'Sede eliminada.');
    }

    /** Solo una sede puede ser la principal; marcar una desmarca el resto. */
    private function garantizarUnaPrincipal(PesvSede $sede): void
    {
        if ($sede->es_principal) {
            PesvSede::where('id', '!=', $sede->id)->update(['es_principal' => false]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:120'],
            'departamento' => ['nullable', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'responsable' => ['nullable', 'string', 'max:255'],
            'num_trabajadores' => ['nullable', 'integer', 'min:0'],
            'es_principal' => ['boolean'],
        ]);
    }
}
