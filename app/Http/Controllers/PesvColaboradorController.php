<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Colaboradores y conductores del PESV (Paso 5) del cliente activo.
 *
 * NO crea personas: trabaja sobre los empleados que la empresa ya cargó en el
 * módulo de Empleados. Aquí solo se marca quién conduce y se completa su ficha
 * de conductor (licencia, categoría, vencimientos). Los conductores que no son
 * empleados de la empresa se registran como contratistas.
 *
 * Permisos: ver -> pesv.view | gestionar -> pesv.manage
 */
class PesvColaboradorController extends Controller
{
    /** Categorías de licencia de conducción (Ley 769 de 2002). */
    private const CATEGORIAS = ['A1', 'A2', 'B1', 'B2', 'B3', 'C1', 'C2', 'C3'];

    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('pesv/colaboradores', [
                'needsClient' => true,
                'colaboradores' => [],
                'stats' => null,
                'categorias' => self::CATEGORIAS,
            ]);
        }

        $colaboradores = Employee::where('is_active', true)
            ->orderByDesc('es_conductor')
            ->orderBy('nombres')
            ->get([
                'id', 'nombres', 'apellidos', 'numero_documento', 'cargo', 'area', 'sede',
                'es_conductor', 'licencia_numero', 'licencia_categoria', 'licencia_vence',
                'examen_psicosensometrico_vence', 'curso_manejo_defensivo', 'observaciones_conductor',
            ])
            ->map(fn (Employee $e) => [
                ...$e->toArray(),
                'nombre_completo' => $e->nombre_completo,
                'alertas' => $this->alertas($e),
            ]);

        $conductores = $colaboradores->where('es_conductor', true);

        return Inertia::render('pesv/colaboradores', [
            'needsClient' => false,
            'colaboradores' => $colaboradores->values(),
            'stats' => [
                'total' => $colaboradores->count(),
                'conductores' => $conductores->count(),
                'sin_licencia' => $conductores->whereNull('licencia_numero')->count(),
                'con_alertas' => $conductores->filter(fn ($c) => count($c['alertas']) > 0)->count(),
            ],
            'categorias' => self::CATEGORIAS,
        ]);
    }

    /** Guarda la ficha de conductor de un colaborador ya existente. */
    public function update(Request $request, Employee $colaborador): RedirectResponse
    {
        $datos = $request->validate([
            'es_conductor' => ['required', 'boolean'],
            'licencia_numero' => ['nullable', 'string', 'max:30'],
            'licencia_categoria' => ['nullable', Rule::in(self::CATEGORIAS)],
            'licencia_vence' => ['nullable', 'date'],
            'examen_psicosensometrico_vence' => ['nullable', 'date'],
            'curso_manejo_defensivo' => ['nullable', 'date'],
            'observaciones_conductor' => ['nullable', 'string'],
        ]);

        $colaborador->update($datos);

        return back()->with('success', 'Ficha de conductor actualizada.');
    }

    /**
     * Vencimientos de la ficha de conductor.
     *
     * Solo tienen sentido si la persona conduce: a alguien que no conduce no
     * se le reclama la licencia.
     *
     * @return array<int, array{documento: string, vence: string, dias: int}>
     */
    private function alertas(Employee $empleado, int $dias = 30): array
    {
        if (! $empleado->es_conductor) {
            return [];
        }

        $hoy = Carbon::today();
        $alertas = [];

        foreach ([
            'Licencia' => $empleado->licencia_vence,
            'Psicosensométrico' => $empleado->examen_psicosensometrico_vence,
        ] as $documento => $vence) {
            if ($vence === null) {
                continue;
            }

            $restantes = (int) $hoy->diffInDays($vence, false);

            if ($restantes <= $dias) {
                $alertas[] = [
                    'documento' => $documento,
                    'vence' => $vence->toDateString(),
                    'dias' => $restantes,
                ];
            }
        }

        return $alertas;
    }
}
