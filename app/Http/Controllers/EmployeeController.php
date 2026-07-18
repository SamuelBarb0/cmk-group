<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Empleados (trabajadores) de la empresa cliente activa.
 *
 * Registro base del SGI. Los datos se segregan por tenant:
 *   - Usuario del cliente: ve/gestiona su propia empresa.
 *   - Consultor CMK: debe tener un cliente seleccionado (active_tenant_id);
 *     sin selección se le pide elegir uno.
 *
 * Permisos: ver -> sst.view | crear/editar/eliminar -> sst.manage
 */
class EmployeeController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        // El consultor CMK necesita un cliente activo para ver su nómina.
        if (! $this->context->has()) {
            return Inertia::render('empleados/index', [
                'needsClient' => true,
                'employees' => [],
                'stats' => ['total' => 0, 'active' => 0, 'areas' => 0],
            ]);
        }

        $employees = Employee::query()
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->get();

        return Inertia::render('empleados/index', [
            'needsClient' => false,
            'employees' => $employees,
            'stats' => [
                'total' => $employees->count(),
                'active' => $employees->where('is_active', true)->count(),
                'areas' => $employees->pluck('area')->filter()->unique()->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de registrar empleados.']);
        }

        Employee::create($this->validated($request));

        return back()->with('success', 'Empleado registrado correctamente.');
    }

    public function update(Request $request, Employee $empleado): RedirectResponse
    {
        $empleado->update($this->validated($request, $empleado));

        return back()->with('success', 'Empleado actualizado correctamente.');
    }

    public function destroy(Employee $empleado): RedirectResponse
    {
        $empleado->delete();

        return back()->with('success', 'Empleado eliminado.');
    }

    /**
     * Reglas de validación en español, compartidas por store/update.
     */
    private function validated(Request $request, ?Employee $empleado = null): array
    {
        return $request->validate([
            'nombres' => ['required', 'string', 'max:255'],
            'apellidos' => ['required', 'string', 'max:255'],
            'tipo_documento' => ['required', 'string', 'max:5'],
            'numero_documento' => [
                'required', 'string', 'max:30',
                // Único por cliente (tenant activo).
                Rule::unique('employees', 'numero_documento')
                    ->where('tenant_id', $this->context->id())
                    ->ignore($empleado?->id),
            ],
            'fecha_nacimiento' => ['nullable', 'date'],
            'genero' => ['nullable', 'string', 'max:20'],
            'grupo_sanguineo' => ['nullable', 'string', 'max:5'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:120'],
            'cargo' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'sede' => ['nullable', 'string', 'max:255'],
            'fecha_ingreso' => ['nullable', 'date'],
            'tipo_contrato' => ['nullable', 'string', 'max:40'],
            'salario' => ['nullable', 'numeric', 'min:0'],
            'eps' => ['nullable', 'string', 'max:255'],
            'afp' => ['nullable', 'string', 'max:255'],
            'arl' => ['nullable', 'string', 'max:255'],
            'nivel_riesgo' => ['nullable', 'string', 'max:3'],
            'is_active' => ['boolean'],
        ]);
    }
}
