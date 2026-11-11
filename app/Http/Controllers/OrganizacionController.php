<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
    /** Nombre de cada imagen de identidad en los mensajes al usuario. */
    private const NOMBRES_MARCA = [
        'logo' => 'Logo de la empresa',
        'firma_rep' => 'Firma del representante legal',
        'firma_resp' => 'Firma del responsable del SG-SST',
    ];

    public function __construct(private readonly TenantContext $context) {}

    public function show(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('organizacion/index', [
                'needsClient' => true,
                'organizacion' => null,
                'empleadosCount' => 0,
                'marca' => null,
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
            'marca' => $tenant === null ? null : $this->marca($tenant),
        ]);
    }

    /**
     * URL de cada imagen de identidad subida (null si falta). Lleva la fecha de
     * modificación del archivo para que el navegador no muestre la anterior
     * después de reemplazarla.
     *
     * @return array<string, string|null>
     */
    private function marca(Tenant $tenant): array
    {
        return collect(Tenant::MARCA)->map(function (string $columna, string $tipo) use ($tenant) {
            $archivo = $tenant->archivoMarca($tipo);

            return $archivo === null ? null : route('organizacion.marca.ver', ['tipo' => $tipo, 'v' => filemtime($archivo)]);
        })->all();
    }

    public function verMarca(string $tipo): BinaryFileResponse
    {
        $archivo = $this->context->get()?->archivoMarca($tipo);
        abort_if($archivo === null, 404);

        return response()->file($archivo, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** Sube o reemplaza el logo o una firma del cliente activo. */
    public function subirMarca(Request $request, string $tipo): RedirectResponse
    {
        $tenant = $this->context->get();
        if ($tenant === null) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de subir su logo o sus firmas.']);
        }

        // Solo PNG o JPG: es lo que PhpWord sabe incrustar en el .docx. Un SVG
        // o un WebP pasaría la subida y luego tumbaría la exportación.
        $request->validate([
            'archivo' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:2048'],
        ], [], ['archivo' => self::NOMBRES_MARCA[$tipo]]);

        $columna = Tenant::MARCA[$tipo];
        $anterior = $tenant->{$columna};

        $ruta = $request->file('archivo')->store("tenants/{$tenant->id}/marca", 'local');
        $tenant->update([$columna => $ruta]);

        if (filled($anterior) && $anterior !== $ruta) {
            Storage::disk('local')->delete($anterior);
        }

        return back()->with('success', 'Se guardó: '.self::NOMBRES_MARCA[$tipo].'.');
    }

    public function quitarMarca(string $tipo): RedirectResponse
    {
        $tenant = $this->context->get();
        if ($tenant === null) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de quitar su logo o sus firmas.']);
        }

        $columna = Tenant::MARCA[$tipo];
        if (filled($tenant->{$columna})) {
            Storage::disk('local')->delete($tenant->{$columna});
            $tenant->update([$columna => null]);
        }

        return back()->with('success', 'Se quitó: '.self::NOMBRES_MARCA[$tipo].'.');
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
