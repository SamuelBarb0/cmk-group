<?php

namespace App\Http\Controllers;

use App\Models\IpercRow;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Matriz IPERC (GTC 45) del cliente activo.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class IpercController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('iperc/index', [
                'needsClient' => true,
                'rows' => [],
                'stats' => ['total' => 0, 'no_aceptables' => 0],
            ]);
        }

        $rows = IpercRow::orderByRaw("FIELD(nivel_riesgo,'I','II','III','IV')")->orderByDesc('nr')->get();

        return Inertia::render('iperc/index', [
            'needsClient' => false,
            'rows' => $rows,
            'stats' => [
                'total' => $rows->count(),
                'no_aceptables' => $rows->whereIn('nivel_riesgo', ['I', 'II'])->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de registrar peligros.']);
        }

        IpercRow::create($this->validated($request));

        return back()->with('success', 'Peligro agregado a la matriz IPERC.');
    }

    public function update(Request $request, IpercRow $peligro): RedirectResponse
    {
        $peligro->update($this->validated($request));

        return back()->with('success', 'Peligro actualizado.');
    }

    public function destroy(IpercRow $peligro): RedirectResponse
    {
        $peligro->delete();

        return back()->with('success', 'Peligro eliminado.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'proceso' => ['required', 'string', 'max:255'],
            'zona' => ['nullable', 'string', 'max:255'],
            'actividad' => ['required', 'string', 'max:255'],
            'tarea' => ['nullable', 'string', 'max:255'],
            'rutinaria' => ['boolean'],
            'clasificacion' => ['required', 'string', 'max:60'],
            'peligro' => ['required', 'string', 'max:255'],
            'efectos' => ['nullable', 'string', 'max:1000'],
            'control_fuente' => ['nullable', 'string', 'max:255'],
            'control_medio' => ['nullable', 'string', 'max:255'],
            'control_individuo' => ['nullable', 'string', 'max:255'],
            'nd' => ['required', Rule::in([0, 2, 6, 10])],
            'ne' => ['required', Rule::in([1, 2, 3, 4])],
            'nc' => ['required', Rule::in([10, 25, 60, 100])],
            'medidas' => ['nullable', 'string', 'max:1000'],
            'expuestos' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
