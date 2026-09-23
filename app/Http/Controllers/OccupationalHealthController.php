<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\MedicalExam;
use App\Models\OccupationalProfile;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Salud ocupacional del cliente activo: profesiograma por cargo y exámenes
 * médicos ocupacionales. Una pantalla con tres pestañas: el estado de cada
 * trabajador (lo que se consulta a diario), los exámenes y el profesiograma.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class OccupationalHealthController extends Controller
{
    /** Con cuánta anticipación se avisa de un examen periódico por vencer. */
    public const DIAS_AVISO = 30;

    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('salud-ocupacional/index', [
                'needsClient' => true,
                'trabajadores' => [],
                'examenes' => [],
                'perfiles' => [],
                'empleados' => [],
                'cargosSinPerfil' => [],
                'stats' => $this->statsVacias(),
                'catalogos' => $this->catalogos(),
            ]);
        }

        $perfiles = OccupationalProfile::query()->orderBy('cargo')->get();
        $porCargo = $perfiles->keyBy(fn (OccupationalProfile $p) => $this->clave($p->cargo));

        $empleados = Employee::query()->where('is_active', true)->orderBy('apellidos')
            ->get(['id', 'nombres', 'apellidos', 'numero_documento', 'cargo']);

        $examenes = MedicalExam::query()
            ->with('employee:id,nombres,apellidos,numero_documento,cargo')
            ->orderByDesc('fecha')->orderByDesc('id')
            ->get();

        // Qué exámenes pedía el profesiograma y no se hicieron. Se calcula al
        // vuelo porque el profesiograma puede cambiar después del examen.
        $examenes->each(function (MedicalExam $e) use ($porCargo): void {
            $perfil = $porCargo->get($this->clave($e->employee?->cargo));
            $e->setAttribute('faltantes', $perfil
                ? array_values(array_diff($perfil->exigidos($e->tipo), $e->examenes_realizados ?? []))
                : []);
            $e->setAttribute('requiere_carta', $e->requiereCarta());
        });

        $trabajadores = $this->estadoPorTrabajador($empleados, $examenes, $porCargo);

        $cargosSinPerfil = $empleados->pluck('cargo')->filter()
            ->unique(fn ($c) => $this->clave($c))
            ->reject(fn ($c) => $porCargo->has($this->clave($c)))
            ->sort()->values();

        return Inertia::render('salud-ocupacional/index', [
            'needsClient' => false,
            'trabajadores' => $trabajadores,
            'examenes' => $examenes,
            'perfiles' => $perfiles,
            'empleados' => $empleados,
            'cargosSinPerfil' => $cargosSinPerfil,
            'stats' => [
                'trabajadores' => $trabajadores->count(),
                'al_dia' => $trabajadores->where('estado', 'al_dia')->count(),
                'por_vencer' => $trabajadores->where('estado', 'por_vencer')->count(),
                'vencidos' => $trabajadores->where('estado', 'vencido')->count(),
                'sin_examen' => $trabajadores->where('estado', 'sin_examen')->count(),
                'con_restricciones' => $trabajadores->where('con_restricciones', true)->count(),
                'cartas_pendientes' => $examenes->filter(fn ($e) => $e->requiere_carta && ! $e->carta_entregada)->count(),
                'cargos_sin_perfil' => $cargosSinPerfil->count(),
            ],
            'catalogos' => $this->catalogos(),
        ]);
    }

    /**
     * Estado de cada trabajador activo según su último examen.
     *
     * Un examen de retiro NO cuenta como vigente: si alguien tiene retiro y
     * sigue activo en la nómina, o volvió o la nómina está desactualizada, y
     * en los dos casos le falta un examen.
     */
    private function estadoPorTrabajador(Collection $empleados, Collection $examenes, Collection $porCargo): Collection
    {
        $ultimos = $examenes
            ->reject(fn (MedicalExam $e) => in_array($e->tipo, MedicalExam::TIPOS_SIN_PROXIMO, true))
            ->groupBy('employee_id')
            ->map->first();   // ya vienen ordenados por fecha desc

        $hoy = now()->startOfDay();

        return $empleados->map(function (Employee $emp) use ($ultimos, $porCargo, $hoy) {
            /** @var MedicalExam|null $ultimo */
            $ultimo = $ultimos->get($emp->id);

            $estado = match (true) {
                $ultimo === null => 'sin_examen',
                $ultimo->proximo_examen === null => 'al_dia',
                $ultimo->proximo_examen->lt($hoy) => 'vencido',
                $ultimo->proximo_examen->lte($hoy->copy()->addDays(self::DIAS_AVISO)) => 'por_vencer',
                default => 'al_dia',
            };

            return [
                'employee_id' => $emp->id,
                'nombre' => trim("{$emp->apellidos} {$emp->nombres}"),
                'numero_documento' => $emp->numero_documento,
                'cargo' => $emp->cargo,
                'tiene_perfil' => $porCargo->has($this->clave($emp->cargo)),
                'ultimo_examen' => $ultimo?->fecha?->toDateString(),
                'ultimo_tipo' => $ultimo?->tipo,
                'concepto' => $ultimo?->concepto,
                'proximo_examen' => $ultimo?->proximo_examen?->toDateString(),
                'estado' => $estado,
                'con_restricciones' => in_array($ultimo?->concepto, ['apto_con_restricciones', 'no_apto'], true),
            ];
        })->values();
    }

    // ------------------------------------------------------------ exámenes

    public function storeExamen(Request $request): RedirectResponse
    {
        $this->exigeCliente();
        MedicalExam::create($this->validatedExamen($request));

        return back()->with('success', 'Examen registrado.');
    }

    public function updateExamen(Request $request, MedicalExam $examen): RedirectResponse
    {
        $examen->update($this->validatedExamen($request));

        return back()->with('success', 'Examen actualizado.');
    }

    public function destroyExamen(MedicalExam $examen): RedirectResponse
    {
        $examen->delete();

        return back()->with('success', 'Examen eliminado.');
    }

    // ------------------------------------------------------- profesiograma

    public function storePerfil(Request $request): RedirectResponse
    {
        $this->exigeCliente();
        OccupationalProfile::create($this->validatedPerfil($request));

        return back()->with('success', 'Cargo agregado al profesiograma.');
    }

    public function updatePerfil(Request $request, OccupationalProfile $perfil): RedirectResponse
    {
        $perfil->update($this->validatedPerfil($request, $perfil));

        return back()->with('success', 'Profesiograma actualizado.');
    }

    public function destroyPerfil(OccupationalProfile $perfil): RedirectResponse
    {
        $perfil->delete();

        return back()->with('success', 'Cargo retirado del profesiograma.');
    }

    /**
     * Crea un perfil VACÍO por cada cargo de la nómina que aún no tenga. Vacío
     * a propósito: qué exámenes pide cada cargo lo decide el médico
     * ocupacional, y el modelo de CMK advierte que hay que adecuarlo.
     */
    public function perfilesDesdeNomina(): RedirectResponse
    {
        $this->exigeCliente();

        $existentes = OccupationalProfile::query()->pluck('cargo')->map(fn ($c) => $this->clave($c))->all();
        $nuevos = 0;

        Employee::query()->where('is_active', true)->whereNotNull('cargo')->pluck('cargo')
            ->unique(fn ($c) => $this->clave($c))
            ->each(function (string $cargo) use (&$existentes, &$nuevos): void {
                if ($this->clave($cargo) === '' || in_array($this->clave($cargo), $existentes, true)) {
                    return;
                }
                OccupationalProfile::create(['cargo' => trim($cargo), 'examenes' => []]);
                $existentes[] = $this->clave($cargo);
                $nuevos++;
            });

        return back()->with('success', $nuevos > 0
            ? "Se agregaron {$nuevos} cargos al profesiograma. Falta marcar sus exámenes."
            : 'Todos los cargos de la nómina ya estaban en el profesiograma.');
    }

    // ---------------------------------------------------------- validación

    /**
     * Los cargos se comparan sin mayúsculas ni espacios de más: la nómina se
     * importa de Excel y «Conductor » y «conductor» son el mismo cargo.
     */
    private function clave(?string $cargo): string
    {
        return mb_strtolower(trim((string) $cargo));
    }

    private function exigeCliente(): void
    {
        abort_unless($this->context->has(), 422, 'Selecciona un cliente antes de registrar salud ocupacional.');
    }

    private function statsVacias(): array
    {
        return [
            'trabajadores' => 0, 'al_dia' => 0, 'por_vencer' => 0, 'vencidos' => 0, 'sin_examen' => 0,
            'con_restricciones' => 0, 'cartas_pendientes' => 0, 'cargos_sin_perfil' => 0,
        ];
    }

    private function catalogos(): array
    {
        return [
            'examenes' => OccupationalProfile::EXAMENES,
            'momentos' => OccupationalProfile::MOMENTOS,
            'tipos' => MedicalExam::TIPOS,
            'conceptos' => MedicalExam::CONCEPTOS,
            'dias_aviso' => self::DIAS_AVISO,
        ];
    }

    private function validatedExamen(Request $request): array
    {
        $datos = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')
                ->where('tenant_id', $this->context->id())],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'tipo' => ['required', Rule::in(MedicalExam::TIPOS)],
            'ips' => ['nullable', 'string', 'max:255'],
            'examenes_realizados' => ['nullable', 'array'],
            'examenes_realizados.*' => [Rule::in(array_keys(OccupationalProfile::EXAMENES))],
            'concepto' => ['required', Rule::in(MedicalExam::CONCEPTOS)],
            // Con restricciones hay que decir cuáles, o el concepto no sirve
            // para reubicar a nadie.
            'restricciones' => ['nullable', 'string', 'max:3000', 'required_if:concepto,apto_con_restricciones'],
            'recomendaciones_personales' => ['nullable', 'string', 'max:3000'],
            'recomendaciones_sst' => ['nullable', 'string', 'max:3000'],
            'recomendaciones_medicas' => ['nullable', 'string', 'max:3000'],
            'carta_entregada' => ['boolean'],
            'fecha_carta' => ['nullable', 'date', 'after_or_equal:fecha', 'required_if:carta_entregada,true'],
            'pve' => ['nullable', 'string', 'max:255'],
            'plan_accion' => ['nullable', 'string', 'max:3000'],
            'seguimiento' => ['nullable', 'string', 'max:3000'],
            'proximo_examen' => ['nullable', 'date', 'after:fecha'],
        ], [
            'fecha.before_or_equal' => 'El examen no puede tener fecha futura.',
            'restricciones.required_if' => 'Indica cuáles son las restricciones.',
            'fecha_carta.required_if' => 'Indica la fecha en que se entregó la carta.',
            'proximo_examen.after' => 'El próximo examen debe ser posterior a este.',
        ]);

        $datos['examenes_realizados'] = array_values(array_unique($datos['examenes_realizados'] ?? []));
        $datos['proximo_examen'] = $this->proximoExamen($datos);

        return $datos;
    }

    /**
     * El próximo examen sale de la periodicidad del cargo (12 meses si el
     * cargo no está en el profesiograma). Si lo escriben a mano, manda eso.
     */
    private function proximoExamen(array $datos): ?string
    {
        if (in_array($datos['tipo'], MedicalExam::TIPOS_SIN_PROXIMO, true)) {
            return null;
        }
        if (! empty($datos['proximo_examen'])) {
            return $datos['proximo_examen'];
        }

        $cargo = Employee::query()->whereKey($datos['employee_id'])->value('cargo');
        $perfil = OccupationalProfile::query()->get()
            ->first(fn (OccupationalProfile $p) => $this->clave($p->cargo) === $this->clave($cargo));

        return Carbon::parse($datos['fecha'])
            ->addMonthsNoOverflow($perfil?->periodicidad_meses ?? 12)
            ->toDateString();
    }

    private function validatedPerfil(Request $request, ?OccupationalProfile $actual = null): array
    {
        $datos = $request->validate([
            'cargo' => [
                'required', 'string', 'max:255',
                Rule::unique('occupational_profiles', 'cargo')
                    ->where('tenant_id', $this->context->id())
                    ->ignore($actual?->id),
            ],
            'factores_riesgo' => ['nullable', 'string', 'max:3000'],
            'pve' => ['nullable', 'string', 'max:255'],
            'examenes' => ['nullable', 'array'],
            'otros_examenes' => ['nullable', 'string', 'max:255'],
            'periodicidad_meses' => ['required', 'integer', 'min:1', 'max:60'],
            'revisado_por' => ['nullable', 'string', 'max:255'],
            'licencia_so' => ['nullable', 'string', 'max:60'],
        ], [
            'cargo.unique' => 'Ese cargo ya está en el profesiograma.',
        ]);

        $datos['cargo'] = trim($datos['cargo']);
        $datos['examenes'] = $this->limpiarMatriz($datos['examenes'] ?? []);

        return $datos;
    }

    /**
     * Deja solo códigos del catálogo y los tres momentos como booleanos, y
     * quita los exámenes que no se piden en ningún momento. Lo que venga de
     * más en el JSON se ignora en vez de guardarse.
     */
    private function limpiarMatriz(array $matriz): array
    {
        $limpia = [];
        foreach (OccupationalProfile::EXAMENES as $codigo => $_) {
            $fila = [];
            foreach (OccupationalProfile::MOMENTOS as $momento) {
                $fila[$momento] = filter_var($matriz[$codigo][$momento] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
            if (in_array(true, $fila, true)) {
                $limpia[$codigo] = $fila;
            }
        }

        return $limpia;
    }
}
