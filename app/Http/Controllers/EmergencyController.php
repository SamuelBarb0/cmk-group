<?php

namespace App\Http\Controllers;

use App\Models\BrigadeMember;
use App\Models\EmergencyContact;
use App\Models\EmergencyDrill;
use App\Models\EmergencyEquipment;
use App\Models\Employee;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plan de emergencias del cliente activo: brigada, simulacros, equipos y
 * directorio del MEDEVAC. Una pantalla con cuatro pestañas, igual que EPP.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class EmergencyController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        $anio = (int) $request->integer('anio', (int) now()->year);

        if (! $this->context->has()) {
            return Inertia::render('emergencias/index', [
                'needsClient' => true,
                'anio' => $anio,
                'brigada' => [],
                'simulacros' => [],
                'equipos' => [],
                'directorio' => [],
                'empleados' => [],
                'stats' => $this->stats(collect(), collect(), collect(), $anio),
                'catalogos' => $this->catalogos(),
            ]);
        }

        $brigada = BrigadeMember::query()->orderBy('nombres')->get();
        $simulacros = EmergencyDrill::query()->with('recommendations')->orderByDesc('fecha')->get();
        $equipos = EmergencyEquipment::query()->orderBy('ubicacion')->orderBy('elemento')->get();

        return Inertia::render('emergencias/index', [
            'needsClient' => false,
            'anio' => $anio,
            'brigada' => $brigada,
            'simulacros' => $simulacros,
            'equipos' => $equipos,
            'directorio' => EmergencyContact::query()->orderBy('tipo')->orderBy('orden')->orderBy('nombre')->get(),
            // Los datos del MEDEVAC (RH, EPS, ARL) ya están en la nómina: al
            // elegir al trabajador se copian en vez de volver a escribirlos.
            'empleados' => Employee::query()->where('is_active', true)->orderBy('apellidos')->get([
                'id', 'nombres', 'apellidos', 'numero_documento', 'cargo', 'telefono',
                'grupo_sanguineo', 'eps', 'arl',
            ]),
            'stats' => $this->stats($brigada, $simulacros, $equipos, $anio),
            'catalogos' => $this->catalogos(),
        ]);
    }

    /**
     * Las cifras del programa de emergencias. Se calculan sobre las colecciones
     * ya cargadas y en PHP, no con YEAR() en SQL, para no casarse con MySQL:
     * la suite corre en sqlite y producción irá a PostgreSQL.
     */
    private function stats(Collection $brigada, Collection $simulacros, Collection $equipos, int $anio): array
    {
        $activos = $brigada->where('activo', true);
        $rolesCubiertos = $activos->pluck('rol')->unique();

        $delAnio = $simulacros->filter(fn (EmergencyDrill $s) => $s->fecha->year === $anio);
        $realizados = $delAnio->where('estado', 'realizado');

        $recomendaciones = $delAnio->flatMap->recommendations;

        // Participación: solo cuenta lo realizado y con convocatoria registrada.
        // Un simulacro sin convocados no dice nada del porcentaje.
        $conConvocatoria = $realizados->filter(fn (EmergencyDrill $s) => ($s->convocados ?? 0) > 0);
        $convocados = $conConvocatoria->sum('convocados');

        return [
            'brigadistas' => $activos->count(),
            'sin_primer_respondiente' => $activos->where('curso_primer_respondiente', false)->count(),
            'roles_vacantes' => array_values(array_diff(BrigadeMember::ROLES_CLAVE, $rolesCubiertos->all())),

            'simulacros_programados' => $delAnio->count(),
            'simulacros_realizados' => $realizados->count(),
            'simulacros_externos' => $realizados->where('tipo', 'externo')->count(),
            'meta_anual' => EmergencyDrill::META_ANUAL,
            'meta_externos' => EmergencyDrill::META_EXTERNOS,

            'recomendaciones_total' => $recomendaciones->count(),
            'recomendaciones_implementadas' => $recomendaciones->where('implementada', true)->count(),
            'participacion' => $convocados > 0
                ? (int) round($conConvocatoria->sum('participantes') / $convocados * 100)
                : null,

            'equipos_malos' => $equipos->where('estado', 'malo')->count(),
            'equipos_vencidos' => $equipos->where('vencido', true)->count(),
            'equipos_por_vencer' => $equipos->where('por_vencer', true)->count(),
        ];
    }

    // -------------------------------------------------------------- brigada

    public function storeBrigadista(Request $request): RedirectResponse
    {
        $this->exigeCliente();
        BrigadeMember::create($this->validatedBrigadista($request));

        return back()->with('success', 'Brigadista inscrito.');
    }

    public function updateBrigadista(Request $request, BrigadeMember $brigadista): RedirectResponse
    {
        $brigadista->update($this->validatedBrigadista($request, $brigadista));

        return back()->with('success', 'Brigadista actualizado.');
    }

    public function destroyBrigadista(BrigadeMember $brigadista): RedirectResponse
    {
        $brigadista->delete();

        return back()->with('success', 'Brigadista retirado de la brigada.');
    }

    // ----------------------------------------------------------- simulacros

    public function storeSimulacro(Request $request): RedirectResponse
    {
        $this->exigeCliente();

        // Todo se valida antes de escribir: una recomendación mal diligenciada
        // no puede dejar el simulacro creado a medias (el bug de orden que se
        // arregló en comités y auditoría el 17-sep).
        $datos = $this->validatedSimulacro($request);
        $recomendaciones = $this->validatedRecomendaciones($request);

        DB::transaction(function () use ($datos, $recomendaciones): void {
            $simulacro = EmergencyDrill::create($datos);
            $this->reemplazarRecomendaciones($simulacro, $recomendaciones);
        });

        return back()->with('success', 'Simulacro registrado.');
    }

    public function updateSimulacro(Request $request, EmergencyDrill $simulacro): RedirectResponse
    {
        $datos = $this->validatedSimulacro($request);
        $recomendaciones = $this->validatedRecomendaciones($request);

        DB::transaction(function () use ($simulacro, $datos, $recomendaciones): void {
            $simulacro->update($datos);
            $this->reemplazarRecomendaciones($simulacro, $recomendaciones);
        });

        return back()->with('success', 'Simulacro actualizado.');
    }

    public function destroySimulacro(EmergencyDrill $simulacro): RedirectResponse
    {
        $simulacro->delete();

        return back()->with('success', 'Simulacro eliminado.');
    }

    /** @param  list<array<string, mixed>>  $recomendaciones */
    private function reemplazarRecomendaciones(EmergencyDrill $simulacro, array $recomendaciones): void
    {
        $simulacro->recommendations()->delete();
        foreach ($recomendaciones as $i => $r) {
            $simulacro->recommendations()->create($r + ['orden' => $i + 1]);
        }
    }

    // -------------------------------------------------------------- equipos

    public function storeEquipo(Request $request): RedirectResponse
    {
        $this->exigeCliente();
        EmergencyEquipment::create($this->validatedEquipo($request));

        return back()->with('success', 'Equipo agregado al inventario.');
    }

    public function updateEquipo(Request $request, EmergencyEquipment $equipo): RedirectResponse
    {
        $equipo->update($this->validatedEquipo($request));

        return back()->with('success', 'Equipo actualizado.');
    }

    public function destroyEquipo(EmergencyEquipment $equipo): RedirectResponse
    {
        $equipo->delete();

        return back()->with('success', 'Equipo eliminado del inventario.');
    }

    // ----------------------------------------------------------- directorio

    public function storeContacto(Request $request): RedirectResponse
    {
        $this->exigeCliente();
        EmergencyContact::create($this->validatedContacto($request));

        return back()->with('success', 'Contacto agregado al directorio.');
    }

    public function updateContacto(Request $request, EmergencyContact $contacto): RedirectResponse
    {
        $contacto->update($this->validatedContacto($request));

        return back()->with('success', 'Contacto actualizado.');
    }

    public function destroyContacto(EmergencyContact $contacto): RedirectResponse
    {
        $contacto->delete();

        return back()->with('success', 'Contacto eliminado.');
    }

    /**
     * Carga las líneas nacionales que falten. Se puede pulsar dos veces sin
     * duplicar nada: compara por nombre dentro de la empresa.
     */
    public function cargarLineasNacionales(): RedirectResponse
    {
        $this->exigeCliente();

        $existentes = EmergencyContact::query()->pluck('nombre')->map(fn ($n) => mb_strtolower($n))->all();
        $nuevas = 0;

        foreach (EmergencyContact::LINEAS_NACIONALES as $i => [$nombre, $telefono, $detalle]) {
            if (in_array(mb_strtolower($nombre), $existentes, true)) {
                continue;
            }
            EmergencyContact::create([
                'tipo' => 'entidad_apoyo',
                'nombre' => $nombre,
                'telefono' => $telefono,
                'detalle' => $detalle,
                'orden' => $i + 1,
            ]);
            $nuevas++;
        }

        return back()->with('success', $nuevas > 0
            ? "Se agregaron {$nuevas} líneas nacionales."
            : 'Las líneas nacionales ya estaban en el directorio.');
    }

    // ---------------------------------------------------------- validación

    private function exigeCliente(): void
    {
        abort_unless($this->context->has(), 422, 'Selecciona un cliente antes de registrar el plan de emergencias.');
    }

    private function catalogos(): array
    {
        return [
            'roles' => BrigadeMember::ROLES,
            'roles_clave' => BrigadeMember::ROLES_CLAVE,
            'especialidades' => BrigadeMember::ESPECIALIDADES,
            'tipos_simulacro' => EmergencyDrill::TIPOS,
            'estados_simulacro' => EmergencyDrill::ESTADOS,
            'tipos_equipo' => EmergencyEquipment::TIPOS,
            'estados_equipo' => EmergencyEquipment::ESTADOS,
            'tipos_contacto' => EmergencyContact::TIPOS,
        ];
    }

    private function validatedBrigadista(Request $request, ?BrigadeMember $actual = null): array
    {
        return $request->validate([
            'employee_id' => [
                'nullable', 'integer',
                // Solo trabajadores de ESTA empresa: sin el where, un id ajeno
                // colaría a una persona de otro cliente en la brigada.
                Rule::exists('employees', 'id')->where('tenant_id', $this->context->id()),
                Rule::unique('brigade_members', 'employee_id')
                    ->where('tenant_id', $this->context->id())
                    ->ignore($actual?->id),
            ],
            'nombres' => ['required', 'string', 'max:255'],
            'numero_documento' => ['nullable', 'string', 'max:40'],
            'cargo' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'rol' => ['required', Rule::in(BrigadeMember::ROLES)],
            'especialidad' => ['nullable', Rule::in(BrigadeMember::ESPECIALIDADES)],
            'fecha_inscripcion' => ['nullable', 'date'],
            'grupo_sanguineo' => ['nullable', 'string', 'max:5'],
            'eps' => ['nullable', 'string', 'max:255'],
            'arl' => ['nullable', 'string', 'max:255'],
            'limitaciones_fisicas' => ['nullable', 'string', 'max:255'],
            'usa_anteojos' => ['boolean'],
            'contacto_emergencia_nombre' => ['nullable', 'string', 'max:255'],
            'contacto_emergencia_telefono' => ['nullable', 'string', 'max:50'],
            'curso_primer_respondiente' => ['boolean'],
            'fecha_curso' => ['nullable', 'date', 'before_or_equal:today'],
            'activo' => ['boolean'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], [
            'employee_id.unique' => 'Esta persona ya está inscrita en la brigada.',
        ]);
    }

    private function validatedSimulacro(Request $request): array
    {
        $datos = $request->validate([
            'fecha' => [
                'required', 'date',
                // Un simulacro «realizado» con fecha futura es un error de
                // captura, y contaría en el indicador antes de tiempo.
                Rule::when($request->input('estado') === 'realizado', ['before_or_equal:today']),
            ],
            'tipo' => ['required', Rule::in(EmergencyDrill::TIPOS)],
            'escenario' => ['required', 'string', 'max:255'],
            'estado' => ['required', Rule::in(EmergencyDrill::ESTADOS)],
            'sede' => ['nullable', 'string', 'max:255'],
            'entidades_apoyo' => ['nullable', 'string', 'max:255'],
            'hora_inicio' => ['nullable', 'date_format:H:i'],
            'hora_fin' => ['nullable', 'date_format:H:i', 'after:hora_inicio'],
            'tiempo_evacuacion_segundos' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'evacuados' => ['nullable', 'integer', 'min:0'],
            'convocados' => ['nullable', 'integer', 'min:0'],
            'participantes' => ['nullable', 'integer', 'min:0'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
        ], [
            'fecha.before_or_equal' => 'Un simulacro realizado no puede tener fecha futura.',
            'hora_fin.after' => 'La hora de finalización debe ser posterior a la de inicio.',
        ]);

        // A mano y no con `lte:convocados`, que falla con mensaje confuso
        // cuando convocados viene vacío.
        if (isset($datos['convocados'], $datos['participantes']) && $datos['participantes'] > $datos['convocados']) {
            throw ValidationException::withMessages([
                'participantes' => 'No puede haber más participantes que convocados.',
            ]);
        }

        return $datos;
    }

    /** @return list<array<string, mixed>> */
    private function validatedRecomendaciones(Request $request): array
    {
        $datos = $request->validate([
            'recommendations' => ['nullable', 'array'],
            'recommendations.*.descripcion' => ['required', 'string', 'max:500'],
            'recommendations.*.responsable' => ['nullable', 'string', 'max:255'],
            'recommendations.*.fecha_limite' => ['nullable', 'date'],
            'recommendations.*.implementada' => ['boolean'],
            'recommendations.*.fecha_implementacion' => ['nullable', 'date'],
        ], [
            'recommendations.*.descripcion.required' => 'Cada recomendación necesita una descripción.',
        ]);

        return array_values($datos['recommendations'] ?? []);
    }

    private function validatedEquipo(Request $request): array
    {
        return $request->validate([
            'ubicacion' => ['required', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:120'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'elemento' => ['required', 'string', 'max:255'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:9999'],
            'ubicacion_exacta' => ['nullable', 'string', 'max:255'],
            'tipo' => ['required', Rule::in(EmergencyEquipment::TIPOS)],
            'estado' => ['required', Rule::in(EmergencyEquipment::ESTADOS)],
            'fecha_revision' => ['nullable', 'date'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function validatedContacto(Request $request): array
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(EmergencyContact::TIPOS)],
            'nombre' => ['required', 'string', 'max:255'],
            'detalle' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'telefono_alterno' => ['nullable', 'string', 'max:60'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:120'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        // La columna es NOT NULL con default: un null explícito la rompe.
        $datos['orden'] ??= 0;

        return $datos;
    }
}
