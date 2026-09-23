<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PesvSiniestro;
use App\Models\PesvVehicle;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Siniestros viales del cliente activo.
 *
 * Insumo del Paso 13 (investigación interna) y del Paso 21 (análisis
 * estadístico de siniestralidad).
 *
 * Permisos: ver -> pesv.view | gestionar -> pesv.manage
 */
class PesvSiniestroController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('pesv/siniestros', [
                'needsClient' => true,
                'siniestros' => [],
                'stats' => null,
                'vehiculos' => [],
                'conductores' => [],
                'tipos' => PesvSiniestro::TIPOS,
                'gravedades' => PesvSiniestro::GRAVEDADES,
            ]);
        }

        $siniestros = PesvSiniestro::with(['vehiculo:id,placa', 'conductor:id,nombres,apellidos'])
            ->orderByDesc('fecha')
            ->get();

        return Inertia::render('pesv/siniestros', [
            'needsClient' => false,
            'siniestros' => $siniestros,
            'stats' => [
                'total' => $siniestros->count(),
                'sin_investigar' => $siniestros->where('investigado', false)->count(),
                'con_heridos' => $siniestros->where('gravedad', 'con_heridos')->count(),
                'fatales' => $siniestros->where('gravedad', 'fatal')->count(),
                'lesionados' => (int) $siniestros->sum('lesionados'),
                'dias_incapacidad' => (int) $siniestros->sum('dias_incapacidad'),
                // Serie por mes del año en curso: es lo que el Paso 21 pide
                // graficar y lo que va al reporte de autogestión (Paso 20).
                'por_mes' => $this->porMes($siniestros),
            ],
            'vehiculos' => PesvVehicle::orderBy('placa')->get(['id', 'placa']),
            'conductores' => Employee::where('is_active', true)->conductores()
                ->orderBy('nombres')->get(['id', 'nombres', 'apellidos']),
            'tipos' => PesvSiniestro::TIPOS,
            'gravedades' => PesvSiniestro::GRAVEDADES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de registrar siniestros.']);
        }

        PesvSiniestro::create($this->validated($request));

        return back()->with('success', 'Siniestro registrado.');
    }

    public function update(Request $request, PesvSiniestro $siniestro): RedirectResponse
    {
        $siniestro->update($this->validated($request));

        return back()->with('success', 'Siniestro actualizado.');
    }

    public function destroy(PesvSiniestro $siniestro): RedirectResponse
    {
        $siniestro->delete();

        return back()->with('success', 'Siniestro eliminado.');
    }

    /**
     * Conteo por mes del año en curso (12 posiciones, enero a diciembre).
     *
     * @param  Collection<int, PesvSiniestro>  $siniestros
     * @return array<int, int>
     */
    private function porMes(Collection $siniestros): array
    {
        $anio = now()->year;
        $serie = array_fill(0, 12, 0);

        foreach ($siniestros as $s) {
            if ($s->fecha?->year === $anio) {
                $serie[$s->fecha->month - 1]++;
            }
        }

        return $serie;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'fecha' => ['required', 'date'],
            'hora' => ['nullable', 'date_format:H:i'],
            'lugar' => ['nullable', 'string', 'max:255'],
            'tipo' => ['required', Rule::in(PesvSiniestro::TIPOS)],
            'gravedad' => ['required', Rule::in(PesvSiniestro::GRAVEDADES)],
            // Del cliente activo: un `exists` pelado aceptaba ids de otra empresa.
            'pesv_vehicle_id' => ['nullable', 'integer', Rule::exists('pesv_vehicles', 'id')->where('tenant_id', $this->context->id())],
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $this->context->id())],
            'descripcion' => ['nullable', 'string'],
            'causa_probable' => ['nullable', 'string'],
            'lesionados' => ['nullable', 'integer', 'min:0'],
            'fallecidos' => ['nullable', 'integer', 'min:0'],
            'dias_incapacidad' => ['nullable', 'integer', 'min:0'],
            'costo' => ['nullable', 'numeric', 'min:0'],
            'investigado' => ['boolean'],
            'acciones' => ['nullable', 'string'],
        ]);
    }
}
