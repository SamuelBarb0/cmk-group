<?php

namespace App\Http\Controllers;

use App\Models\AcpmAction;
use App\Models\ControlledDocument;
use App\Models\EquipmentCalibration;
use App\Models\MeasuringEquipment;
use App\Services\Calibracion\DocumentosCalibracion;
use App\Services\ControlDocumental\CicloDocumental;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * M09 — Control de los equipos de seguimiento y medición de la empresa activa
 * (ISO 9001 7.1.5, ISO 45001/14001 9.1.1): hoja de vida, calibraciones y
 * verificaciones con su certificado, y qué se hace con un equipo no conforme.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class EquipoMedicionController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly DocumentosCalibracion $documentos,
    ) {}

    public function index(): Response
    {
        $comunes = [
            'controles' => MeasuringEquipment::CONTROLES,
            'estados' => MeasuringEquipment::ESTADOS,
            'situaciones' => DocumentosCalibracion::SITUACIONES,
        ];
        if (! $this->context->has()) {
            return Inertia::render('equipos-medicion/index', $comunes + ['needsClient' => true, 'equipos' => [], 'documentos' => []]);
        }

        $equipos = MeasuringEquipment::query()
            ->with(['calibrations.acpmAction:id,codigo,estado'])
            ->orderBy('codigo')->get();

        return Inertia::render('equipos-medicion/index', $comunes + [
            'needsClient' => false,
            'equipos' => $equipos->map(fn (MeasuringEquipment $e) => $e->toArray() + ['situacion' => $e->situacion()])->values(),
            'documentos' => collect(array_keys(DocumentosCalibracion::DOCUMENTOS))
                ->map(fn (string $d) => $this->estadoDocumento($d))->filter()->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        $equipo = MeasuringEquipment::create($this->validated($request));

        return back()->with('success', "Equipo {$equipo->codigo} registrado.");
    }

    public function update(Request $request, MeasuringEquipment $equipo): RedirectResponse
    {
        $equipo->update($this->validated($request, $equipo));

        return back()->with('success', 'Equipo actualizado.');
    }

    public function destroy(MeasuringEquipment $equipo): RedirectResponse
    {
        $archivos = $equipo->calibrations()->whereNotNull('archivo')->pluck('archivo')->all();
        $equipo->delete();
        Storage::disk('local')->delete($archivos);

        return back()->with('success', "Equipo {$equipo->codigo} eliminado con su historial.");
    }

    /**
     * Registra una calibración o verificación. El resultado mueve el estado
     * del equipo: no conforme lo saca de uso; una conforme lo devuelve al uso
     * si estaba fuera de servicio (7.1.5.2: vuelve solo tras calibrarse bien).
     */
    public function storeCalibracion(Request $request, MeasuringEquipment $equipo): RedirectResponse
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'tipo' => ['required', Rule::in(array_keys(MeasuringEquipment::CONTROLES))],
            'realizado_por' => ['nullable', 'string', 'max:255'],
            'acreditado_onac' => ['boolean'],
            'certificado' => ['nullable', 'string', 'max:60'],
            'error_encontrado' => ['nullable', 'string', 'max:60'],
            'incertidumbre' => ['nullable', 'string', 'max:60'],
            'resultado' => ['required', Rule::in(array_keys(EquipmentCalibration::RESULTADOS))],
            'impacto_mediciones' => ['nullable', 'required_if:resultado,no_conforme', 'string', 'max:3000'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'archivo' => ['nullable', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg'],
        ], [
            'impacto_mediciones.required_if' => 'Un equipo no conforme exige evaluar qué pasó con las mediciones hechas desde la última calibración buena (ISO 9001 7.1.5.2).',
            'fecha.before_or_equal' => 'La fecha no puede ser futura: se registra lo que ya se hizo.',
        ]);

        $archivo = $datos['archivo'] ?? null;
        unset($datos['archivo']);
        $calibracion = $equipo->calibrations()->make($datos);
        if ($archivo) {
            $calibracion->archivo = $archivo->storeAs("tenants/{$equipo->tenant_id}/calibraciones",
                uniqid().'-'.$archivo->getClientOriginalName(), 'local');
            $calibracion->archivo_nombre = $archivo->getClientOriginalName();
        }
        $calibracion->save();

        // Solo cuenta si es la más reciente: registrar hoy una calibración vieja
        // no debe cambiar el estado que dejó la última.
        $ultima = $equipo->calibrations()->first();
        if ($ultima?->is($calibracion)) {
            if ($calibracion->resultado === 'no_conforme' && $equipo->estado === 'en_uso') {
                $equipo->update(['estado' => 'fuera_servicio']);
            } elseif ($calibracion->resultado === 'conforme' && $equipo->estado === 'fuera_servicio') {
                $equipo->update(['estado' => 'en_uso']);
            }
        }

        return back()->with('success', $calibracion->resultado === 'no_conforme'
            ? "Registrado. {$equipo->codigo} quedó fuera de servicio hasta una calibración conforme."
            : 'Registrado.');
    }

    public function destroyCalibracion(MeasuringEquipment $equipo, EquipmentCalibration $calibracion): RedirectResponse
    {
        abort_unless($calibracion->measuring_equipment_id === $equipo->id, 404);
        $archivo = $calibracion->archivo;
        $calibracion->delete();
        if ($archivo) {
            Storage::disk('local')->delete($archivo);
        }

        return back()->with('success', 'Registro eliminado.');
    }

    public function certificado(MeasuringEquipment $equipo, EquipmentCalibration $calibracion): StreamedResponse
    {
        abort_unless($calibracion->measuring_equipment_id === $equipo->id && $calibracion->archivo, 404);
        abort_unless(Storage::disk('local')->exists($calibracion->archivo), 404);

        return Storage::disk('local')->download($calibracion->archivo, $calibracion->archivo_nombre);
    }

    /** La no conformidad del equipo como acción correctiva en ACPM. */
    public function crearAccion(Request $request, MeasuringEquipment $equipo, EquipmentCalibration $calibracion): RedirectResponse
    {
        abort_unless($calibracion->measuring_equipment_id === $equipo->id, 404);
        if ($calibracion->resultado !== 'no_conforme') {
            return back()->withErrors(['acpm' => 'Solo un resultado no conforme lleva acción correctiva.']);
        }
        if ($calibracion->acpm_action_id) {
            return back()->withErrors(['acpm' => 'Ya tiene una acción en ACPM.']);
        }

        $datos = $request->validate([
            'accion' => ['required', 'string', 'max:2000'],
            'responsable' => ['required', 'string', 'max:255'],
            'fecha_limite' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $acpm = AcpmAction::create([
            'tipo' => 'correctiva',
            'origen_tipo' => 'calibracion',
            'origen_id' => $calibracion->id,
            'hallazgo' => "Equipo {$equipo->codigo} ({$equipo->nombre}) no conforme en la ".mb_strtolower(MeasuringEquipment::CONTROLES[$calibracion->tipo])
                .' del '.$calibracion->fecha->toDateString()
                .($calibracion->error_encontrado ? ": error {$calibracion->error_encontrado}" : '')
                .($equipo->error_maximo ? " (máximo permitido {$equipo->error_maximo})" : '').'.',
            'causa' => $calibracion->impacto_mediciones,
            'accion' => $datos['accion'],
            'responsable' => $datos['responsable'],
            'fecha_deteccion' => $calibracion->fecha->toDateString(),
            'fecha_limite' => $datos['fecha_limite'],
            'estado' => 'abierta',
        ]);

        $calibracion->forceFill(['acpm_action_id' => $acpm->id])->save();

        return back()->with('success', "Acción {$acpm->codigo} creada en ACPM.");
    }

    public function enviar(Request $request, CicloDocumental $ciclo): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }
        $documento = $request->validate(['documento' => ['required', Rule::in(array_keys(DocumentosCalibracion::DOCUMENTOS))]])['documento'];
        $entrada = $this->documentos->entrada($documento);
        if (! $entrada) {
            return back()->withErrors(['documento' => 'El catálogo del SIG no está cargado en esta instalación: falta correr SigCatalogSeeder.']);
        }

        $doc = $ciclo->recibirDeModulo($this->context->id(), $entrada, $this->documentos->contenido($documento),
            'Actualizado desde los equipos de medición.', $request->user(), $this->documentos->claves());

        return back()->with('success', "{$doc->codigo} quedó en borrador en el control documental. Revísalo y apruébalo allí.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?MeasuringEquipment $equipo = null): array
    {
        return $request->validate([
            'codigo' => ['required', 'string', 'max:40',
                Rule::unique('measuring_equipment', 'codigo')->where('tenant_id', $this->context->id())->ignore($equipo?->id)],
            'nombre' => ['required', 'string', 'max:255'],
            'marca' => ['nullable', 'string', 'max:255'],
            'modelo' => ['nullable', 'string', 'max:255'],
            'serie' => ['nullable', 'string', 'max:255'],
            'magnitud' => ['nullable', 'string', 'max:60'],
            'unidad' => ['nullable', 'string', 'max:20'],
            'rango' => ['nullable', 'string', 'max:255'],
            'resolucion' => ['nullable', 'string', 'max:40'],
            'error_maximo' => ['nullable', 'string', 'max:40'],
            'uso' => ['nullable', 'string', 'max:255'],
            'ubicacion' => ['nullable', 'string', 'max:255'],
            'responsable' => ['nullable', 'string', 'max:255'],
            'control' => ['required', Rule::in(array_keys(MeasuringEquipment::CONTROLES))],
            'frecuencia_meses' => ['required', 'integer', 'between:1,60'],
            'estado' => ['required', Rule::in(array_keys(MeasuringEquipment::ESTADOS))],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], ['codigo.unique' => 'Ya hay un equipo con ese código.']);
    }

    /** @return array<string, mixed>|null */
    private function estadoDocumento(string $clave): ?array
    {
        $entrada = $this->documentos->entrada($clave);
        if (! $entrada) {
            return null;
        }
        $doc = ControlledDocument::query()->where('document_catalog_id', $entrada->id)->first();

        return ['clave' => $clave, 'titulo' => $entrada->nombre, 'id' => $doc?->id, 'codigo' => $doc?->codigo, 'estado' => $doc?->estado];
    }
}
