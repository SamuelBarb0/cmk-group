<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Información de la Organización (contexto SGI) del cliente activo.
 *
 * Edita los datos de la empresa cliente que alimentan el diagnóstico de
 * estándares mínimos, el PESV y la generación de documentos con IA.
 *
 * Permisos: ver -> sst.view | editar -> sst.manage
 */
class OrganizacionController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('organizacion/index', [
                'needsClient' => true,
                'organizacion' => null,
                'empleadosCount' => 0,
            ]);
        }

        $tenant = $this->context->get();

        return Inertia::render('organizacion/index', [
            'needsClient' => false,
            'organizacion' => $tenant === null ? null : array_merge(
                $tenant->only([
                    'id', 'name', 'legal_name', 'nit', 'email', 'phone', 'city', 'address',
                    'actividad_economica', 'codigo_ciiu', 'sector', 'nivel_riesgo', 'arl',
                    'tamano_empresa', 'num_trabajadores', 'representante_legal', 'representante_cc',
                    'responsable_sgsst', 'licencia_sgsst',
                    'curso_sst_horas',
                ]),
                [
                    // Las dos fechas van aparte y formateadas a mano: `only()`
                    // devuelve el Carbon en crudo y se salta la serializacion del
                    // modelo, asi que el cast `date:Y-m-d` NO se aplica y al front
                    // le llegaba «2027-05-01T00:00:00.000000Z». Un
                    // <input type="date"> rechaza ese formato y se queda vacio,
                    // de modo que abrir la ficha y guardar BORRABA las dos fechas.
                    'licencia_sgsst_vence' => $tenant->licencia_sgsst_vence?->toDateString(),
                    'curso_sst_fecha' => $tenant->curso_sst_fecha?->toDateString(),
                ],
            ),
            // Conteo real de empleados registrados (referencia vs. el declarado).
            'empleadosCount' => Employee::count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de editar su organización.']);
        }

        $data = $request->validate([
            'actividad_economica' => ['nullable', 'string', 'max:255'],
            'codigo_ciiu' => ['nullable', 'string', 'max:10'],
            'sector' => ['nullable', 'string', 'max:255'],
            'nivel_riesgo' => ['nullable', 'string', 'max:3'],
            'arl' => ['nullable', 'string', 'max:255'],
            'tamano_empresa' => ['nullable', 'string', 'max:20'],
            'num_trabajadores' => ['nullable', 'integer', 'min:0'],
            'representante_legal' => ['nullable', 'string', 'max:255'],
            'representante_cc' => ['nullable', 'string', 'max:30'],
            'responsable_sgsst' => ['nullable', 'string', 'max:255'],
            'licencia_sgsst' => ['nullable', 'string', 'max:255'],
            'licencia_sgsst_vence' => ['nullable', 'date'],
            'curso_sst_horas' => ['nullable', 'in:50,20'],
            'curso_sst_fecha' => ['nullable', 'date'],
        ]);

        $this->context->get()?->update($data);

        return back()->with('success', 'Información de la organización actualizada.');
    }
}
