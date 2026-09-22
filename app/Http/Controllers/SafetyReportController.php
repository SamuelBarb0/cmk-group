<?php

namespace App\Http\Controllers;

use App\Models\AcpmAction;
use App\Models\Employee;
use App\Models\SafetyReport;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reportes de actos y condiciones inseguras del cliente activo.
 *
 * Permisos: ver -> incidents.view | gestionar -> incidents.manage
 */
class SafetyReportController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('reportes-ac/index', [
                'needsClient' => true,
                'reportes' => [],
                'empleados' => [],
                'acciones' => [],
                'stats' => ['total' => 0, 'pendientes' => 0, 'intervenidos' => 0, 'porcentaje' => 0, 'criticos' => 0],
                'catalogos' => $this->catalogos(),
            ]);
        }

        $reportes = SafetyReport::query()->orderByDesc('fecha')->orderByDesc('id')->get();
        $intervenidos = $reportes->whereIn('estado', ['intervenido', 'cerrado'])->count();

        return Inertia::render('reportes-ac/index', [
            'needsClient' => false,
            'reportes' => $reportes,
            'empleados' => Employee::query()->where('is_active', true)
                ->orderBy('apellidos')->get(['id', 'nombres', 'apellidos', 'cargo', 'area']),
            // Para enlazar el reporte con una acción ACPM ya registrada.
            'acciones' => AcpmAction::query()->pendientes()->get(['id', 'codigo', 'accion']),
            'stats' => [
                'total' => $reportes->count(),
                'pendientes' => $reportes->where('estado', 'reportado')->count(),
                'intervenidos' => $intervenidos,
                // Indicador RED-AC.
                'porcentaje' => $reportes->count() > 0
                    ? (int) round($intervenidos / $reportes->count() * 100)
                    : 0,
                // Severidad crítica todavía sin intervenir: lo que hay que
                // atender hoy, no en el próximo comité.
                'criticos' => $reportes->where('severidad', 'critico')->where('estado', 'reportado')->count(),
            ],
            'catalogos' => $this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de registrar reportes.']);
        }

        SafetyReport::create($this->validated($request));

        return back()->with('success', 'Reporte registrado.');
    }

    public function update(Request $request, SafetyReport $reporte): RedirectResponse
    {
        $reporte->update($this->validated($request));

        return back()->with('success', 'Reporte actualizado.');
    }

    public function destroy(SafetyReport $reporte): RedirectResponse
    {
        $reporte->delete();

        return back()->with('success', 'Reporte eliminado.');
    }

    private function catalogos(): array
    {
        return [
            'tipos' => SafetyReport::TIPOS,
            'severidades' => SafetyReport::SEVERIDADES,
            'estados' => SafetyReport::ESTADOS,
            'clasificaciones' => SafetyReport::CLASIFICACIONES,
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'fecha' => ['required', 'date'],
            'reportado_por' => ['required', 'string', 'max:255'],
            // Se valida que el empleado sea del cliente activo: sin esto se
            // podría enlazar el reporte con la nómina de OTRA empresa mandando
            // un id a mano.
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')
                ->where('tenant_id', $this->context->id())],
            'area' => ['nullable', 'string', 'max:255'],
            'lugar' => ['nullable', 'string', 'max:255'],
            'tipo' => ['required', Rule::in(SafetyReport::TIPOS)],
            'descripcion' => ['required', 'string', 'max:2000'],
            'clasificacion_peligro' => ['nullable', Rule::in(SafetyReport::CLASIFICACIONES)],
            'severidad' => ['required', Rule::in(SafetyReport::SEVERIDADES)],
            'accion_inmediata' => ['nullable', 'string', 'max:2000'],
            'estado' => ['required', Rule::in(SafetyReport::ESTADOS)],
            'fecha_intervencion' => ['nullable', 'date'],
            'responsable_intervencion' => ['nullable', 'string', 'max:255'],
            'acpm_action_id' => ['nullable', 'integer', Rule::exists('acpm_actions', 'id')
                ->where('tenant_id', $this->context->id())],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
