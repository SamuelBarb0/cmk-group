<?php

namespace App\Http\Controllers;

use App\Models\ChangeRequest;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestión del cambio del cliente activo.
 *
 * Las reglas del procedimiento de CMK se hacen cumplir al guardar, no solo en
 * pantalla: sin las dos aprobaciones no hay cierre, y si el cambio exige
 * actualizar la matriz de peligros tampoco.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class ChangeRequestController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('gestion-cambio/index', [
                'needsClient' => true,
                'cambios' => [],
                'stats' => ['pendientes' => 0, 'en_curso' => 0, 'vencidos' => 0, 'cerrados' => 0, 'eficacia' => null],
                'catalogos' => $this->catalogos(),
            ]);
        }

        $cambios = ChangeRequest::query()->with('actions')
            ->orderByDesc('fecha_solicitud')->orderByDesc('id')
            ->get();

        $cerrados = $cambios->where('estado', 'cerrado');

        return Inertia::render('gestion-cambio/index', [
            'needsClient' => false,
            'cambios' => $cambios,
            'stats' => [
                'pendientes' => $cambios->where('estado', 'pendiente_aprobacion')->count(),
                'en_curso' => $cambios->where('estado', 'aprobado')->count(),
                'vencidos' => $cambios->where('vencido', true)->count(),
                'cerrados' => $cerrados->count(),
                // De los cerrados, cuántos se evaluaron eficaces: es la pregunta
                // final del formato y la que mira un auditor.
                'eficacia' => $cerrados->count() > 0
                    ? (int) round($cerrados->where('cierre_eficaz', true)->count() / $cerrados->count() * 100)
                    : null,
            ],
            'catalogos' => $this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->context->has(), 422, 'Selecciona un cliente antes de registrar un cambio.');

        [$datos, $acciones] = $this->validated($request);

        DB::transaction(function () use ($datos, $acciones): void {
            $cambio = ChangeRequest::create($datos);
            $this->reemplazarAcciones($cambio, $acciones);
        });

        return back()->with('success', 'Cambio registrado.');
    }

    public function update(Request $request, ChangeRequest $cambio): RedirectResponse
    {
        [$datos, $acciones] = $this->validated($request);

        DB::transaction(function () use ($cambio, $datos, $acciones): void {
            $cambio->update($datos);
            $this->reemplazarAcciones($cambio, $acciones);
        });

        return back()->with('success', 'Cambio actualizado.');
    }

    public function destroy(ChangeRequest $cambio): RedirectResponse
    {
        $cambio->delete();

        return back()->with('success', 'Cambio eliminado.');
    }

    /** @param  list<array<string, mixed>>  $acciones */
    private function reemplazarAcciones(ChangeRequest $cambio, array $acciones): void
    {
        $cambio->actions()->delete();
        foreach ($acciones as $i => $a) {
            $cambio->actions()->create($a + ['orden' => $i + 1]);
        }
    }

    private function catalogos(): array
    {
        return [
            'tipos' => ChangeRequest::TIPOS,
            'condiciones' => ChangeRequest::CONDICIONES,
            'elementos' => ChangeRequest::ELEMENTOS,
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function validated(Request $request): array
    {
        $datos = $request->validate([
            'fecha_solicitud' => ['required', 'date'],
            'solicitante' => ['required', 'string', 'max:255'],
            'cargo_solicitante' => ['nullable', 'string', 'max:255'],
            'area' => ['required', 'string', 'max:255'],
            'procesos_involucrados' => ['nullable', 'string', 'max:255'],
            'tipo' => ['required', Rule::in(ChangeRequest::TIPOS)],
            'tipo_otro' => ['nullable', 'string', 'max:255', 'required_if:tipo,otro'],
            'condicion' => ['required', Rule::in(ChangeRequest::CONDICIONES)],
            'descripcion' => ['required', 'string', 'max:5000'],
            'fecha_limite' => ['nullable', 'date', 'after_or_equal:fecha_solicitud'],

            'analisis' => ['nullable', 'array'],
            'costo_presupuestado' => ['nullable', 'numeric', 'min:0'],
            'costo_ejecutado' => ['nullable', 'numeric', 'min:0'],

            'requiere_actualizar_iperc' => ['boolean'],
            'iperc_actualizada_at' => ['nullable', 'date'],
            'requiere_capacitacion' => ['boolean'],

            // Quien aprueba y cuándo van juntos: una fecha sin nombre no es
            // una aprobación que se pueda mostrar en una auditoría.
            'aprobacion_gerencia_nombre' => ['nullable', 'string', 'max:255', 'required_with:aprobacion_gerencia_fecha'],
            'aprobacion_gerencia_fecha' => ['nullable', 'date', 'after_or_equal:fecha_solicitud', 'required_with:aprobacion_gerencia_nombre'],
            'aprobacion_sst_nombre' => ['nullable', 'string', 'max:255', 'required_with:aprobacion_sst_fecha'],
            'aprobacion_sst_fecha' => ['nullable', 'date', 'after_or_equal:fecha_solicitud', 'required_with:aprobacion_sst_nombre'],
            'rechazado_at' => ['nullable', 'date'],
            'motivo_rechazo' => ['nullable', 'string', 'max:3000', 'required_with:rechazado_at'],

            'cierre_fecha' => ['nullable', 'date', 'after_or_equal:fecha_solicitud'],
            'cierre_implementado' => ['nullable', 'boolean', 'required_with:cierre_fecha'],
            'cierre_a_tiempo' => ['nullable', 'boolean', 'required_with:cierre_fecha'],
            'cierre_eficaz' => ['nullable', 'boolean', 'required_with:cierre_fecha'],
            'cierre_justificacion' => ['nullable', 'string', 'max:3000'],

            'observaciones' => ['nullable', 'string', 'max:3000'],

            'actions' => ['nullable', 'array'],
            'actions.*.descripcion' => ['required', 'string', 'max:500'],
            'actions.*.responsable' => ['nullable', 'string', 'max:255'],
            'actions.*.fecha_compromiso' => ['nullable', 'date'],
            'actions.*.ejecutada' => ['boolean'],
            'actions.*.fecha_ejecucion' => ['nullable', 'date'],
            'actions.*.observacion' => ['nullable', 'string', 'max:2000'],
        ], [
            'tipo_otro.required_if' => 'Indica cuál es el tipo de cambio.',
            'motivo_rechazo.required_with' => 'Indica por qué se rechaza el cambio.',
            'actions.*.descripcion.required' => 'Cada actividad del plan necesita una descripción.',
        ], [
            // Sin esto el mensaje sale con el nombre de la columna: «el campo
            // aprobacion gerencia fecha debe ser…».
            'fecha_solicitud' => 'fecha de la solicitud',
            'fecha_limite' => 'fecha límite',
            'tipo_otro' => 'tipo de cambio',
            'aprobacion_gerencia_nombre' => 'nombre de quien aprueba por Gerencia',
            'aprobacion_gerencia_fecha' => 'fecha de aprobación de Gerencia',
            'aprobacion_sst_nombre' => 'nombre de quien aprueba por el SG-SST',
            'aprobacion_sst_fecha' => 'fecha de aprobación del SG-SST',
            'rechazado_at' => 'fecha de rechazo',
            'cierre_fecha' => 'fecha de cierre',
            'cierre_implementado' => '«¿se implementaron las actividades?»',
            'cierre_a_tiempo' => '«¿en el tiempo establecido?»',
            'cierre_eficaz' => '«¿fue eficaz?»',
            'iperc_actualizada_at' => 'fecha de actualización de la IPERC',
            'costo_presupuestado' => 'costo presupuestado',
            'costo_ejecutado' => 'costo ejecutado',
        ]);

        $this->reglasDelProcedimiento($datos);

        $acciones = array_values($datos['actions'] ?? []);
        unset($datos['actions']);
        $datos['analisis'] = $this->limpiarAnalisis($datos['analisis'] ?? []);

        return [$datos, $acciones];
    }

    /**
     * Lo que el procedimiento de CMK exige y que un formulario suelto no
     * puede garantizar.
     *
     * @param  array<string, mixed>  $d
     */
    private function reglasDelProcedimiento(array $d): void
    {
        $errores = [];
        $aprobado = ! empty($d['aprobacion_gerencia_fecha']) && ! empty($d['aprobacion_sst_fecha']);

        if (! empty($d['rechazado_at']) && ! empty($d['cierre_fecha'])) {
            $errores['cierre_fecha'] = 'Un cambio rechazado no se cierra: no se implementó.';
        }

        if (! empty($d['cierre_fecha'])) {
            // «Ninguna gestión de proceso de cambio se puede realizar si no es
            // aprobada y revisada por la Gerencia y el encargado del SG SST.»
            if (! $aprobado) {
                $errores['cierre_fecha'] = 'Para cerrar el cambio faltan aprobaciones: lo deben aprobar la Gerencia y el encargado del SG-SST.';
            }

            // «Revisión y aprobación de los documentos, actualización de las
            // matrices de riesgos de salud y seguridad en el trabajo.»
            if (! empty($d['requiere_actualizar_iperc']) && empty($d['iperc_actualizada_at'])) {
                $errores['iperc_actualizada_at'] = 'Este cambio exige actualizar la matriz IPERC antes de cerrarlo.';
            }

            // El formato pide justificar cualquier «no» de la evaluación.
            $algunNo = ($d['cierre_implementado'] ?? null) === false
                || ($d['cierre_a_tiempo'] ?? null) === false
                || ($d['cierre_eficaz'] ?? null) === false;
            if ($algunNo && blank($d['cierre_justificacion'] ?? null)) {
                $errores['cierre_justificacion'] = 'Justifica por qué no se implementó, no se hizo a tiempo o no fue eficaz.';
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * Solo las seis filas del formato y solo si traen algo. Lo que venga de más
     * se ignora en vez de guardarse.
     *
     * @return array<string, array{actividad: string, costo: float|null, observaciones: string}>
     */
    private function limpiarAnalisis(array $analisis): array
    {
        $limpio = [];
        foreach (array_keys(ChangeRequest::ELEMENTOS) as $clave) {
            $fila = $analisis[$clave] ?? [];
            $actividad = trim((string) ($fila['actividad'] ?? ''));
            $observaciones = trim((string) ($fila['observaciones'] ?? ''));
            $costo = is_numeric($fila['costo'] ?? null) ? (float) $fila['costo'] : null;

            if ($actividad === '' && $observaciones === '' && $costo === null) {
                continue;
            }
            $limpio[$clave] = [
                'actividad' => mb_substr($actividad, 0, 1000),
                'costo' => $costo,
                'observaciones' => mb_substr($observaciones, 0, 1000),
            ];
        }

        return $limpio;
    }
}
