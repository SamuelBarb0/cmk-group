<?php

namespace App\Http\Controllers;

use App\Models\FormFormat;
use App\Models\ManagementProgram;
use App\Models\ProgramPlan;
use App\Models\ProgramPlanIndicator;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Programas de gestión del cliente activo (PVE, alcohol, fatiga, velocidad,
 * distracción, actores viales, ambiental…).
 *
 * El consultor adopta un programa del catálogo para un año; eso copia sus
 * actividades e indicadores, que desde ahí se adaptan a la empresa. Las
 * actividades y los indicadores NO tienen rutas propias: no llevan tenant_id y
 * el route model binding no los filtraría. Se guardan siempre a través del
 * programa, que sí pasa por el TenantScope.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class ProgramaGestionController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        $anio = (int) $request->integer('anio', (int) now()->year);

        if (! $this->context->has()) {
            return Inertia::render('programas/index', [
                'needsClient' => true,
                'anio' => $anio,
                'programas' => [],
                'catalogo' => [],
                'categorias' => ManagementProgram::CATEGORIAS,
            ]);
        }

        $planes = ProgramPlan::query()
            ->where('anio', $anio)
            ->with(['activities', 'indicators'])
            ->orderBy('codigo')
            ->get();

        $adoptados = $planes->pluck('management_program_id')->filter()->all();

        return Inertia::render('programas/index', [
            'needsClient' => false,
            'anio' => $anio,
            'programas' => $planes->map(fn (ProgramPlan $p) => $this->resumen($p))->values(),
            'catalogo' => ManagementProgram::query()->orderBy('orden')->get()->map(fn (ManagementProgram $m) => [
                'id' => $m->id,
                'codigo' => $m->codigo,
                'nombre' => $m->nombre,
                'categoria' => $m->categoria,
                'objetivo' => $m->objetivo,
                'actividades' => count($m->actividades ?? []),
                'indicadores' => array_column($m->indicadores ?? [], 'nombre'),
                'adoptado' => in_array($m->id, $adoptados, true),
            ]),
            'categorias' => ManagementProgram::CATEGORIAS,
        ]);
    }

    /** Adopta un programa del catálogo, o crea uno propio de la empresa. */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->context->has(), 422, 'Selecciona un cliente antes de adoptar un programa.');

        $data = $request->validate([
            'anio' => ['required', 'integer', 'between:2020,2100'],
            'management_program_id' => ['nullable', 'integer', 'exists:management_programs,id'],
            'codigo' => ['required_without:management_program_id', 'nullable', 'string', 'max:30'],
            'nombre' => ['required_without:management_program_id', 'nullable', 'string', 'max:255'],
            'categoria' => ['required_without:management_program_id', 'nullable', Rule::in(array_keys(ManagementProgram::CATEGORIAS))],
        ], [], [
            'management_program_id' => 'programa',
            'codigo' => 'código',
            'nombre' => 'nombre del programa',
        ]);

        $catalogo = isset($data['management_program_id']) ? ManagementProgram::find($data['management_program_id']) : null;
        $codigo = $catalogo?->codigo ?? mb_strtoupper(trim($data['codigo']));

        $this->exigeCodigoLibre($codigo, (int) $data['anio']);

        $plan = DB::transaction(function () use ($data, $catalogo, $codigo): ProgramPlan {
            $plan = ProgramPlan::create([
                'management_program_id' => $catalogo?->id,
                'anio' => $data['anio'],
                'codigo' => $codigo,
                'nombre' => $catalogo?->nombre ?? $data['nombre'],
                'categoria' => $catalogo?->categoria ?? $data['categoria'],
                'objetivo' => $catalogo?->objetivo,
                'alcance' => $catalogo?->alcance,
                'recursos' => $catalogo?->recursos,
                'formato_codigo' => $catalogo?->formato_codigo,
            ]);

            foreach ($catalogo?->actividades ?? [] as $i => $a) {
                $plan->activities()->create([
                    'fase' => $a['fase'],
                    'nombre' => $a['nombre'],
                    'responsable' => $a['responsable'] ?? null,
                    'meses_programados' => $a['meses'] ?? [],
                    'meses_ejecutados' => [],
                    'observaciones' => $a['observaciones'] ?? null,
                    'orden' => $i + 1,
                ]);
            }

            // Un programa propio arranca con el indicador que todos comparten.
            $indicadores = $catalogo?->indicadores ?? [[
                'clave' => 'cumplimiento', 'nombre' => 'Cumplimiento',
                'numerador' => 'Actividades ejecutadas', 'denominador' => 'Actividades programadas',
                'meta' => 80, 'sentido' => 'asc', 'frecuencia' => 'semestral', 'automatico' => true,
            ]];

            foreach ($indicadores as $i => $ind) {
                $plan->indicators()->create([
                    'clave' => $ind['clave'],
                    'nombre' => $ind['nombre'],
                    'numerador_label' => $ind['numerador'],
                    'denominador_label' => $ind['denominador'],
                    'meta' => $ind['meta'],
                    'meta_texto' => $ind['meta_texto'] ?? null,
                    'sentido' => $ind['sentido'],
                    'frecuencia' => $ind['frecuencia'],
                    'automatico' => $ind['automatico'],
                    'orden' => $i + 1,
                ]);
            }

            return $plan;
        });

        return to_route('programas.show', $plan)->with('success', "Programa {$plan->codigo} adoptado para {$plan->anio}.");
    }

    public function show(ProgramPlan $programa): Response
    {
        $programa->load(['activities', 'indicators']);

        $formato = $programa->formato_codigo
            ? FormFormat::query()->where('codigo', $programa->formato_codigo)->first(['codigo', 'nombre'])
            : null;

        return Inertia::render('programas/show', [
            'needsClient' => false,
            'programa' => [
                ...$programa->only(['id', 'anio', 'codigo', 'nombre', 'categoria', 'objetivo', 'alcance', 'recursos', 'responsable', 'observaciones']),
                'del_catalogo' => $programa->management_program_id !== null,
                'cumplimiento' => $programa->cumplimiento(),
            ],
            'actividades' => $programa->activities->map(fn ($a) => [
                'fase' => $a->fase,
                'nombre' => $a->nombre,
                'responsable' => $a->responsable,
                'presupuesto' => $a->presupuesto,
                'programados' => $a->meses_programados ?? [],
                'ejecutados' => $a->meses_ejecutados ?? [],
                'observaciones' => $a->observaciones,
            ]),
            'indicadores' => $programa->indicators->map(fn (ProgramPlanIndicator $i) => $this->indicador($i, $programa)),
            'formato' => $formato,
            'siguienteExiste' => ProgramPlan::query()
                ->where('codigo', $programa->codigo)->where('anio', $programa->anio + 1)->exists(),
            'categorias' => ManagementProgram::CATEGORIAS,
        ]);
    }

    /**
     * Guarda el programa entero: ficha, cronograma e indicadores con sus
     * lecturas. Todo se valida antes de escribir y se escribe en una
     * transacción, para no dejar un cronograma a medias.
     */
    public function update(Request $request, ProgramPlan $programa): RedirectResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'objetivo' => ['nullable', 'string', 'max:5000'],
            'alcance' => ['nullable', 'string', 'max:5000'],
            'recursos' => ['nullable', 'string', 'max:255'],
            'responsable' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:5000'],

            'actividades' => ['present', 'array', 'max:200'],
            'actividades.*.fase' => ['required', Rule::in(ProgramPlan::FASES)],
            'actividades.*.nombre' => ['required', 'string', 'max:500'],
            'actividades.*.responsable' => ['nullable', 'string', 'max:255'],
            'actividades.*.presupuesto' => ['nullable', 'numeric', 'min:0'],
            'actividades.*.programados' => ['array'],
            'actividades.*.programados.*' => ['integer', 'between:1,12'],
            'actividades.*.ejecutados' => ['array'],
            'actividades.*.ejecutados.*' => ['integer', 'between:1,12'],
            'actividades.*.observaciones' => ['nullable', 'string', 'max:1000'],

            'indicadores' => ['present', 'array', 'max:20'],
            'indicadores.*.clave' => ['required', 'string', 'max:30'],
            'indicadores.*.nombre' => ['required', 'string', 'max:255'],
            'indicadores.*.numerador_label' => ['required', 'string', 'max:255'],
            'indicadores.*.denominador_label' => ['required', 'string', 'max:255'],
            'indicadores.*.meta' => ['nullable', 'numeric', 'min:0'],
            'indicadores.*.meta_texto' => ['nullable', 'string', 'max:255'],
            'indicadores.*.sentido' => ['required', Rule::in(['asc', 'desc'])],
            'indicadores.*.frecuencia' => ['required', Rule::in(array_keys(ProgramPlan::FRECUENCIAS))],
            'indicadores.*.automatico' => ['boolean'],
            'indicadores.*.lecturas' => ['nullable', 'array'],
            'indicadores.*.lecturas.*.numerador' => ['nullable', 'numeric', 'min:0'],
            'indicadores.*.lecturas.*.denominador' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'actividades.*.nombre' => 'nombre de la actividad',
            'actividades.*.fase' => 'fase de la actividad',
            'indicadores.*.nombre' => 'nombre del indicador',
            'indicadores.*.numerador_label' => 'numerador del indicador',
            'indicadores.*.denominador_label' => 'denominador del indicador',
        ]);

        DB::transaction(function () use ($programa, $data): void {
            $programa->update(collect($data)->only(['nombre', 'objetivo', 'alcance', 'recursos', 'responsable', 'observaciones'])->all());

            $programa->activities()->delete();
            foreach ($data['actividades'] as $i => $a) {
                $prog = $this->meses($a['programados'] ?? []);
                $programa->activities()->create([
                    'fase' => $a['fase'],
                    'nombre' => $a['nombre'],
                    'responsable' => $a['responsable'] ?? null,
                    'presupuesto' => $a['presupuesto'] ?? null,
                    'meses_programados' => $prog,
                    'meses_ejecutados' => $this->meses($a['ejecutados'] ?? []),
                    'observaciones' => $a['observaciones'] ?? null,
                    'orden' => $i + 1,
                ]);
            }

            $programa->indicators()->delete();
            foreach ($data['indicadores'] as $i => $ind) {
                $automatico = (bool) ($ind['automatico'] ?? false);
                $programa->indicators()->create([
                    'clave' => $ind['clave'],
                    'nombre' => $ind['nombre'],
                    'numerador_label' => $ind['numerador_label'],
                    'denominador_label' => $ind['denominador_label'],
                    'meta' => $ind['meta'] ?? null,
                    'meta_texto' => $ind['meta_texto'] ?? null,
                    'sentido' => $ind['sentido'],
                    'frecuencia' => $ind['frecuencia'],
                    'automatico' => $automatico,
                    // El automático no guarda lecturas: saldría del cronograma
                    // y de lo digitado a la vez, y no se sabría cuál manda.
                    'lecturas' => $automatico ? null : $this->lecturas($ind['lecturas'] ?? [], $ind['frecuencia']),
                    'orden' => $i + 1,
                ]);
            }
        });

        $programa->load('activities');
        $c = $programa->cumplimiento();

        return back()->with('success', 'Programa guardado.'.($c !== null ? " Cumplimiento: {$c} %." : ''));
    }

    public function destroy(ProgramPlan $programa): RedirectResponse
    {
        $anio = $programa->anio;
        $programa->delete();

        return to_route('programas.index', ['anio' => $anio])->with('success', 'Programa eliminado.');
    }

    /**
     * Programa el año siguiente a partir de este: mismas actividades y meses
     * programados, sin ejecución ni lecturas. Es lo que el consultor hace cada
     * enero con la hoja del Excel.
     */
    public function renovar(ProgramPlan $programa): RedirectResponse
    {
        $anio = $programa->anio + 1;
        $this->exigeCodigoLibre($programa->codigo, $anio);
        $programa->load(['activities', 'indicators']);

        $nuevo = DB::transaction(function () use ($programa, $anio): ProgramPlan {
            $nuevo = $programa->replicate(['anio']);
            $nuevo->anio = $anio;
            $nuevo->save();

            foreach ($programa->activities as $a) {
                $nuevo->activities()->create([
                    ...$a->only(['fase', 'nombre', 'responsable', 'presupuesto', 'meses_programados', 'observaciones', 'orden']),
                    'meses_ejecutados' => [],
                ]);
            }
            foreach ($programa->indicators as $i) {
                $nuevo->indicators()->create([
                    ...$i->only(['clave', 'nombre', 'numerador_label', 'denominador_label', 'constante', 'meta', 'meta_texto', 'sentido', 'frecuencia', 'automatico', 'orden']),
                    'lecturas' => null,
                ]);
            }

            return $nuevo;
        });

        return to_route('programas.show', $nuevo)->with('success', "Programa {$nuevo->codigo} programado para {$anio}.");
    }

    // ---------------------------------------------------------------- apoyo

    private function exigeCodigoLibre(string $codigo, int $anio): void
    {
        if (ProgramPlan::query()->where('codigo', $codigo)->where('anio', $anio)->exists()) {
            throw ValidationException::withMessages([
                'codigo' => "La empresa ya tiene el programa {$codigo} para {$anio}.",
            ]);
        }
    }

    /** @return list<int> */
    private function meses(array $meses): array
    {
        $m = array_values(array_unique(array_map('intval', $meses)));
        sort($m);

        return $m;
    }

    /** Solo se guardan los periodos que existen en la frecuencia y traen algún dato. */
    private function lecturas(array $lecturas, string $frecuencia): ?array
    {
        $out = [];
        for ($p = 1; $p <= ProgramPlan::FRECUENCIAS[$frecuencia]; $p++) {
            $l = $lecturas[$p] ?? $lecturas[(string) $p] ?? null;
            $n = $l['numerador'] ?? null;
            $d = $l['denominador'] ?? null;
            if ($n === null && $d === null) {
                continue;
            }
            $out[(string) $p] = [
                'numerador' => $n === null ? null : (float) $n,
                'denominador' => $d === null ? null : (float) $d,
            ];
        }

        return $out ?: null;
    }

    private function indicador(ProgramPlanIndicator $i, ProgramPlan $plan): array
    {
        $v = $i->valores($plan);

        return [
            'clave' => $i->clave,
            'nombre' => $i->nombre,
            'numerador_label' => $i->numerador_label,
            'denominador_label' => $i->denominador_label,
            'meta' => $i->meta,
            'meta_texto' => $i->meta_texto,
            'sentido' => $i->sentido,
            'frecuencia' => $i->frecuencia,
            'automatico' => $i->automatico,
            'lecturas' => $i->lecturas ?? (object) [],
            'periodos' => $v['periodos'],
            'anual' => $v['anual'],
            'cumple' => $i->cumpleMeta($v['anual']),
        ];
    }

    private function resumen(ProgramPlan $p): array
    {
        $indicadores = $p->indicators->map(function (ProgramPlanIndicator $i) use ($p) {
            $anual = $i->valores($p)['anual'];

            return ['nombre' => $i->nombre, 'anual' => $anual, 'meta' => $i->meta, 'cumple' => $i->cumpleMeta($anual)];
        });
        $conteo = $p->conteo();

        return [
            'id' => $p->id,
            'codigo' => $p->codigo,
            'nombre' => $p->nombre,
            'categoria' => $p->categoria,
            'responsable' => $p->responsable,
            'cumplimiento' => $p->cumplimiento(),
            'programadas' => $conteo['programadas'],
            'ejecutadas' => $conteo['ejecutadas'],
            'actividades' => $p->activities->count(),
            'indicadores' => $indicadores->values(),
        ];
    }
}
