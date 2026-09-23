<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PesvInfraction;
use App\Models\PesvVehicle;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Seguimiento de infracciones de tránsito de los conductores (RE-SST-52 de
 * CMK, procedimiento de seguimiento y control a infractores del PASO 11).
 *
 * El resumen por código es lo que pide el reporte de autogestión anual
 * (Res. 40595, paso 20, literal k).
 */
class PesvInfraccionController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('pesv/infracciones', ['needsClient' => true]);
        }

        $anio = $request->integer('anio') ?: (int) now()->year;
        $infracciones = PesvInfraction::with(['employee:id,nombres,apellidos,numero_documento', 'vehiculo:id,placa'])
            ->whereYear('fecha', $anio)->orderByDesc('fecha')->get();

        return Inertia::render('pesv/infracciones', [
            'needsClient' => false,
            'anio' => $anio,
            'infracciones' => $infracciones->map(fn (PesvInfraction $i) => [
                ...$i->only(['id', 'employee_id', 'pesv_vehicle_id', 'codigo', 'descripcion', 'estado', 'registrada_simit', 'acciones']),
                'fecha' => $i->fecha->toDateString(),
                'valor' => $i->valor !== null ? (float) $i->valor : null,
                'conductor' => $i->employee ? trim("{$i->employee->nombres} {$i->employee->apellidos}") : '—',
                'placa' => $i->vehiculo?->placa,
            ]),
            'porCodigo' => $infracciones->groupBy('codigo')->map(fn ($g, $codigo) => [
                'codigo' => $codigo, 'descripcion' => $g->first()->descripcion, 'cantidad' => $g->count(),
            ])->sortByDesc('cantidad')->values(),
            'stats' => [
                'total' => $infracciones->count(),
                'abiertas' => $infracciones->whereNotIn('estado', PesvInfraction::CERRADAS)->count(),
                'conductores' => $infracciones->pluck('employee_id')->unique()->count(),
                'valor' => (float) $infracciones->sum('valor'),
            ],
            'conductores' => Employee::where('is_active', true)->conductores()->orderBy('apellidos')->get(['id', 'nombres', 'apellidos'])
                ->map(fn (Employee $e) => ['id' => $e->id, 'nombre' => trim("{$e->nombres} {$e->apellidos}")]),
            'vehiculos' => PesvVehicle::orderBy('placa')->get(['id', 'placa']),
            'estados' => PesvInfraction::ESTADOS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->context->has(), 404);
        PesvInfraction::create($this->validar($request));

        return back()->with('success', 'Infracción registrada.');
    }

    public function update(Request $request, PesvInfraction $infraccion): RedirectResponse
    {
        $infraccion->update($this->validar($request));

        return back()->with('success', 'Infracción actualizada.');
    }

    public function destroy(PesvInfraction $infraccion): RedirectResponse
    {
        $infraccion->delete();

        return back()->with('success', 'Infracción eliminada.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $request): array
    {
        $request->merge(['codigo' => mb_strtoupper(trim((string) $request->input('codigo')))]);

        return $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $this->context->id())],
            'pesv_vehicle_id' => ['nullable', 'integer', Rule::exists('pesv_vehicles', 'id')->where('tenant_id', $this->context->id())],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'codigo' => ['required', 'string', 'max:20'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'valor' => ['nullable', 'numeric', 'min:0'],
            'estado' => ['required', Rule::in(array_keys(PesvInfraction::ESTADOS))],
            'registrada_simit' => ['boolean'],
            'acciones' => ['nullable', 'string', 'max:2000'],
        ], ['codigo.required' => 'El código de la infracción es obligatorio: el reporte de autogestión las cuenta por código.']);
    }
}
