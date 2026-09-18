<?php

namespace App\Http\Controllers;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\WorkAccident;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accidentes e incidentes de trabajo del cliente activo, con su investigación.
 *
 * Permisos: ver -> incidents.view | gestionar -> incidents.manage
 */
class WorkAccidentController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('accidentes/index', [
                'needsClient' => true,
                'accidentes' => [],
                'empleados' => [],
                'ausencias' => [],
                'stats' => ['total' => 0, 'accidentes' => 0, 'mortales' => 0, 'sin_investigar' => 0, 'vencidos' => 0, 'dias_perdidos' => 0],
                'anio' => (int) now()->year,
                'catalogos' => ['clases' => WorkAccident::CLASES],
            ]);
        }

        $anio = (int) $request->integer('anio', now()->year);

        $accidentes = WorkAccident::query()
            ->with(['employee:id,nombres,apellidos,cargo,area', 'ausencia:id,dias,fecha_inicio'])
            ->whereYear('fecha', $anio)
            ->orderByDesc('fecha')
            ->get();

        return Inertia::render('accidentes/index', [
            'needsClient' => false,
            'accidentes' => $accidentes,
            'empleados' => Employee::query()->where('is_active', true)
                ->orderBy('apellidos')->get(['id', 'nombres', 'apellidos', 'cargo', 'area']),
            // Solo las ausencias por accidente de trabajo: son las únicas que
            // tiene sentido enlazar a un AT para sacar los días perdidos.
            'ausencias' => Absence::query()
                ->where('tipo', 'accidente_trabajo')
                ->with('employee:id,nombres,apellidos')
                ->orderByDesc('fecha_inicio')
                ->get(['id', 'employee_id', 'fecha_inicio', 'fecha_fin', 'dias']),
            'stats' => [
                'total' => $accidentes->count(),
                'accidentes' => $accidentes->where('clase', 'accidente')->count(),
                'mortales' => $accidentes->where('mortal', true)->count(),
                'sin_investigar' => $accidentes->where('investigado', false)->count(),
                // Los que ya pasaron los 15 días calendario de la Res. 1401.
                // Es la cifra que le cuesta una sanción al cliente.
                'vencidos' => $accidentes->where('investigacion_vencida', true)->count(),
                // Días perdidos del año: salen de la ausencia enlazada, no de
                // un campo propio, para que no haya dos versiones del número.
                'dias_perdidos' => (int) $accidentes->sum(fn ($a) => $a->ausencia?->dias ?? 0),
            ],
            'anio' => $anio,
            'catalogos' => ['clases' => WorkAccident::CLASES],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de registrar accidentes.']);
        }

        WorkAccident::create($this->validated($request));

        return back()->with('success', 'Evento registrado.');
    }

    public function update(Request $request, WorkAccident $accidente): RedirectResponse
    {
        $accidente->update($this->validated($request));

        return back()->with('success', 'Evento actualizado.');
    }

    public function destroy(WorkAccident $accidente): RedirectResponse
    {
        $accidente->delete();

        return back()->with('success', 'Evento eliminado.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')
                ->where('tenant_id', $this->context->id())],
            'clase' => ['required', Rule::in(WorkAccident::CLASES)],
            'fecha' => ['required', 'date'],
            'hora' => ['nullable', 'date_format:H:i'],
            'lugar' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['required', 'string', 'max:4000'],
            'tipo_lesion' => ['nullable', 'string', 'max:80'],
            'parte_cuerpo' => ['nullable', 'string', 'max:80'],
            'mecanismo' => ['nullable', 'string', 'max:120'],
            'agente' => ['nullable', 'string', 'max:120'],
            'mortal' => ['boolean'],
            'grave' => ['boolean'],
            'reportado_arl' => ['boolean'],
            'fecha_reporte_arl' => ['nullable', 'date'],
            'absence_id' => ['nullable', 'integer', Rule::exists('absences', 'id')
                ->where('tenant_id', $this->context->id())],
            'investigado' => ['boolean'],
            'fecha_investigacion' => ['nullable', 'date'],
            'equipo_investigador' => ['nullable', 'string', 'max:255'],
            'causas_inmediatas' => ['nullable', 'array'],
            'causas_inmediatas.*' => ['string', 'max:255'],
            'causas_basicas' => ['nullable', 'array'],
            'causas_basicas.*' => ['string', 'max:255'],
            'causa_raiz' => ['nullable', 'string', 'max:2000'],
            'leccion_aprendida' => ['nullable', 'string', 'max:2000'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
