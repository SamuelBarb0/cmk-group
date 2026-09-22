<?php

namespace App\Http\Controllers;

use App\Models\Absence;
use App\Models\Employee;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ausentismo laboral del cliente activo.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class AbsenceController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('ausentismo/index', [
                'needsClient' => true,
                'ausencias' => [],
                'empleados' => [],
                'stats' => ['registros' => 0, 'dias_total' => 0, 'dias_medicos' => 0, 'dias_at' => 0, 'casos_el' => 0],
                'anio' => (int) now()->year,
                'catalogos' => ['tipos' => Absence::TIPOS],
            ]);
        }

        // El ausentismo se lee por año: es el periodo en que se calculan los
        // indicadores y el que cierra el informe anual.
        $anio = (int) $request->integer('anio', now()->year);

        $ausencias = Absence::query()
            ->with('employee:id,nombres,apellidos,cargo,area')
            ->whereYear('fecha_inicio', $anio)
            ->orderByDesc('fecha_inicio')
            ->get();

        return Inertia::render('ausentismo/index', [
            'needsClient' => false,
            'ausencias' => $ausencias,
            'empleados' => Employee::query()->where('is_active', true)
                ->orderBy('apellidos')->get(['id', 'nombres', 'apellidos', 'cargo', 'area']),
            'stats' => [
                'registros' => $ausencias->count(),
                'dias_total' => (int) $ausencias->sum('dias'),
                // Los tres números que alimentan indicadores legales.
                'dias_medicos' => (int) $ausencias->whereIn('tipo', Absence::CAUSA_MEDICA)->sum('dias'),
                'dias_at' => (int) $ausencias->where('tipo', 'accidente_trabajo')->sum('dias'),
                'casos_el' => $ausencias->where('tipo', 'enfermedad_laboral')->count(),
            ],
            'anio' => $anio,
            'catalogos' => ['tipos' => Absence::TIPOS],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de registrar ausencias.']);
        }

        Absence::create($this->validated($request));

        return back()->with('success', 'Ausencia registrada.');
    }

    public function update(Request $request, Absence $ausencia): RedirectResponse
    {
        $ausencia->update($this->validated($request));

        return back()->with('success', 'Ausencia actualizada.');
    }

    public function destroy(Absence $ausencia): RedirectResponse
    {
        $ausencia->delete();

        return back()->with('success', 'Ausencia eliminada.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')
                ->where('tenant_id', $this->context->id())],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            // Opcional: si no viene, el modelo lo calcula con las fechas. Se
            // deja editable porque hay empresas que cuentan solo días hábiles.
            'dias' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'tipo' => ['required', Rule::in(Absence::TIPOS)],
            'diagnostico' => ['nullable', 'string', 'max:255'],
            'cie10' => ['nullable', 'string', 'max:10'],
            'entidad' => ['nullable', 'string', 'max:120'],
            'incapacidad_numero' => ['nullable', 'string', 'max:60'],
            'prorroga' => ['boolean'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
