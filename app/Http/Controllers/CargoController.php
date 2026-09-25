<?php

namespace App\Http\Controllers;

use App\Models\CompetencyAssessment;
use App\Models\ControlledDocument;
use App\Models\Employee;
use App\Models\JobPosition;
use App\Models\JobPositionRequirement;
use App\Models\Process;
use App\Models\Training;
use App\Models\TrainingTopic;
use App\Services\ControlDocumental\CicloDocumental;
use App\Services\Talento\DocumentosTalento;
use App\Services\Talento\MatrizCompetencias;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M07 — Perfiles de cargo y matriz de competencias de la empresa activa
 * (ISO 5.3 y 7.2, Dec. 1072 2.2.4.6.8 y 2.2.4.6.11, PESV paso 10).
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class CargoController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MatrizCompetencias $matriz,
        private readonly DocumentosTalento $documentos,
    ) {}

    public function index(Request $request): Response
    {
        $vacio = ['cargos' => [], 'brechas' => [], 'sin_cargo' => 0];
        if (! $this->context->has()) {
            return Inertia::render('cargos/index', [
                'needsClient' => true, 'resumen' => $vacio, 'cargo' => null, 'procesos' => [], 'temas' => [],
                'cargosNomina' => [], 'documentos' => [], 'tipos' => JobPositionRequirement::TIPOS, 'estados' => MatrizCompetencias::ESTADOS,
            ]);
        }

        $resumen = $this->matriz->resumen();
        $seleccionado = $request->integer('cargo') ?: ($resumen['cargos'][0]['id'] ?? null);
        $cargo = $seleccionado ? JobPosition::query()->with('process:id,sigla,nombre')->find($seleccionado) : null;

        return Inertia::render('cargos/index', [
            'needsClient' => false,
            'resumen' => $resumen,
            'cargo' => $cargo ? $this->detalle($cargo) : null,
            'procesos' => Process::query()->orderBy('orden')->get(['id', 'sigla', 'nombre']),
            'temas' => TrainingTopic::query()->where('activo', true)->orderBy('orden')->orderBy('titulo')->get(['id', 'codigo', 'titulo']),
            'cargosNomina' => $this->cargosNominaSinPerfil(),
            'documentos' => collect(array_keys(DocumentosTalento::DOCUMENTOS))
                ->map(fn (string $d) => $this->estadoDocumento($d))->filter()->values(),
            'tipos' => JobPositionRequirement::TIPOS,
            'estados' => MatrizCompetencias::ESTADOS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        $cargo = JobPosition::create($this->validated($request) + ['orden' => (JobPosition::query()->max('orden') ?? 0) + 1]);

        return redirect()->route('cargos.index', ['cargo' => $cargo->id])->with('success', "Cargo «{$cargo->nombre}» creado.");
    }

    public function update(Request $request, JobPosition $cargo): RedirectResponse
    {
        $cargo->update($this->validated($request, $cargo));

        return back()->with('success', 'Perfil actualizado.');
    }

    public function destroy(JobPosition $cargo): RedirectResponse
    {
        $cargo->delete();

        return redirect()->route('cargos.index')->with('success', "Cargo «{$cargo->nombre}» eliminado.");
    }

    /**
     * Crea un perfil vacío por cada cargo que aparece en la nómina y todavía
     * no tiene perfil. Toma el nombre como está escrito en el primer
     * trabajador que lo tiene.
     */
    public function desdeNomina(): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        $nuevos = $this->cargosNominaSinPerfil();
        $orden = JobPosition::query()->max('orden') ?? 0;
        foreach ($nuevos as $c) {
            JobPosition::create(['nombre' => $c['nombre'], 'orden' => ++$orden]);
        }

        return back()->with('success', count($nuevos)
            ? count($nuevos).' cargos traídos de la nómina. Completa su perfil y sus requisitos.'
            : 'Todos los cargos de la nómina ya tienen perfil.');
    }

    public function storeRequisito(Request $request, JobPosition $cargo): RedirectResponse
    {
        $cargo->requirements()->create($this->validatedRequisito($request)
            + ['orden' => ($cargo->requirements()->max('orden') ?? 0) + 1]);

        return back()->with('success', 'Requisito agregado.');
    }

    public function updateRequisito(Request $request, JobPosition $cargo, JobPositionRequirement $requisito): RedirectResponse
    {
        abort_unless($requisito->job_position_id === $cargo->id, 404);
        $requisito->update($this->validatedRequisito($request));

        return back()->with('success', 'Requisito actualizado.');
    }

    public function destroyRequisito(JobPosition $cargo, JobPositionRequirement $requisito): RedirectResponse
    {
        abort_unless($requisito->job_position_id === $cargo->id, 404);
        $requisito->delete();

        return back()->with('success', 'Requisito eliminado.');
    }

    /**
     * Evalúa a mano a un trabajador contra un requisito de su cargo. `cumple`
     * vacío borra la evaluación (la celda vuelve a lo que digan las
     * capacitaciones, o a «sin evaluar»).
     */
    public function evaluar(Request $request, JobPosition $cargo): RedirectResponse
    {
        $tenant = $this->context->id();
        $datos = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenant)],
            'job_position_requirement_id' => ['required', 'integer', Rule::exists('job_position_requirements', 'id')->where('job_position_id', $cargo->id)],
            'cumple' => ['nullable', 'boolean'],
            'evidencia' => ['nullable', 'string', 'max:500'],
        ]);

        $empleado = Employee::query()->findOrFail($datos['employee_id']);
        if (JobPosition::normalizar($empleado->cargo) !== JobPosition::normalizar($cargo->nombre)) {
            return back()->withErrors(['employee_id' => 'Ese trabajador no tiene este cargo.']);
        }

        $clave = ['employee_id' => $empleado->id, 'job_position_requirement_id' => $datos['job_position_requirement_id']];
        if (($datos['cumple'] ?? null) === null) {
            CompetencyAssessment::query()->where($clave)->delete();

            return back()->with('success', 'Evaluación borrada.');
        }

        CompetencyAssessment::updateOrCreate($clave, [
            'cumple' => $datos['cumple'],
            'evidencia' => $datos['evidencia'] ?? null,
            'evaluado_at' => now()->toDateString(),
            'evaluado_por' => $request->user()?->name,
        ]);

        return back()->with('success', 'Evaluación guardada.');
    }

    /**
     * Cierra una brecha de formación: programa una capacitación del tema del
     * requisito con las personas que no lo cumplen ya inscritas (sin marcar
     * asistencia: eso se hace cuando se dicte).
     */
    public function programar(Request $request, JobPosition $cargo, JobPositionRequirement $requisito): RedirectResponse
    {
        abort_unless($requisito->job_position_id === $cargo->id, 404);
        if (! $requisito->training_topic_id) {
            return back()->withErrors(['requisito' => 'El requisito no tiene un tema de capacitación asociado.']);
        }

        $datos = $this->matriz->deCargo($cargo);
        $personas = $datos['empleados']->filter(fn (Employee $e) => in_array($datos['celdas']["{$e->id}-{$requisito->id}"]['estado'], MatrizCompetencias::BRECHA, true));
        if ($personas->isEmpty()) {
            return back()->withErrors(['requisito' => 'Nadie en este cargo tiene brecha en ese requisito.']);
        }

        $tema = $requisito->topic;
        $capacitacion = Training::create([
            'training_topic_id' => $tema->id,
            'titulo' => $tema->titulo,
            'categoria' => $tema->categoria ?: 'SST',
            'fecha' => now()->toDateString(),
            'duracion_minutos' => $tema->duracion_sugerida,
            'objetivo' => $tema->descripcion,
            'estado' => 'programada',
            'observaciones' => "Programada desde la matriz de competencias: brecha de «{$requisito->descripcion}» en el cargo {$cargo->nombre}.",
            'creado_por' => $request->user()?->name,
        ]);
        foreach ($personas as $e) {
            $capacitacion->attendees()->create([
                'employee_id' => $e->id,
                'nombres' => trim("{$e->nombres} {$e->apellidos}"),
                'numero_documento' => $e->numero_documento,
                'cargo' => $e->cargo,
                'asistio' => false,
            ]);
        }

        return redirect()->route('capacitaciones.show', $capacitacion)
            ->with('success', "Capacitación «{$capacitacion->titulo}» programada con {$personas->count()} personas. Ajusta la fecha.");
    }

    public function enviar(Request $request, CicloDocumental $ciclo): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }
        $documento = $request->validate(['documento' => ['required', Rule::in(array_keys(DocumentosTalento::DOCUMENTOS))]])['documento'];
        $entrada = $this->documentos->entrada($documento);
        if (! $entrada) {
            return back()->withErrors(['documento' => 'El catálogo del SIG no está cargado en esta instalación: falta correr SigCatalogSeeder.']);
        }

        $doc = $ciclo->recibirDeModulo($this->context->id(), $entrada, $this->documentos->contenido($documento),
            'Actualizado desde los perfiles de cargo.', $request->user(), $this->documentos->claves($documento));

        return back()->with('success', "{$doc->codigo} quedó en borrador en el control documental. Revísalo y apruébalo allí.");
    }

    /** @return array<string, mixed> */
    private function detalle(JobPosition $cargo): array
    {
        $datos = $this->matriz->deCargo($cargo);

        return $cargo->toArray() + [
            'requisitos' => $datos['requisitos']->values(),
            'empleados' => $datos['empleados']->map(fn (Employee $e) => [
                'id' => $e->id, 'nombre' => trim("{$e->nombres} {$e->apellidos}"), 'documento' => $e->numero_documento,
            ])->values(),
            'celdas' => (object) $datos['celdas'],
        ];
    }

    /**
     * Cargos que aparecen en la nómina activa sin perfil, con cuántas personas.
     *
     * @return list<array{nombre: string, trabajadores: int}>
     */
    private function cargosNominaSinPerfil(): array
    {
        $existentes = JobPosition::query()->pluck('nombre')->map(fn ($n) => JobPosition::normalizar($n))->all();

        return Employee::query()->where('is_active', true)->whereNotNull('cargo')->orderBy('id')->get(['id', 'cargo'])
            ->filter(fn (Employee $e) => JobPosition::normalizar($e->cargo) !== '')
            ->groupBy(fn (Employee $e) => JobPosition::normalizar($e->cargo))
            ->reject(fn ($grupo, string $clave) => in_array($clave, $existentes, true))
            ->map(fn ($grupo) => ['nombre' => trim((string) preg_replace('/\s+/u', ' ', $grupo->first()->cargo)), 'trabajadores' => $grupo->count()])
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?JobPosition $cargo = null): array
    {
        $tenant = $this->context->id();
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'process_id' => ['nullable', 'integer', Rule::exists('processes', 'id')->where('tenant_id', $tenant)],
            'reporta_a' => ['nullable', 'string', 'max:255'],
            'objetivo' => ['nullable', 'string', 'max:2000'],
            'funciones' => ['nullable', 'string', 'max:6000'],
            'responsabilidades_sig' => ['nullable', 'string', 'max:6000'],
            'autoridad' => ['nullable', 'string', 'max:3000'],
        ]);
        $datos['nombre'] = trim((string) preg_replace('/\s+/u', ' ', $datos['nombre']));

        // Único sin importar mayúsculas: es la llave que lo une con la nómina.
        $repetido = JobPosition::query()->when($cargo, fn ($q) => $q->whereKeyNot($cargo->id))->pluck('nombre')
            ->contains(fn ($n) => JobPosition::normalizar($n) === JobPosition::normalizar($datos['nombre']));
        if ($repetido) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe un cargo con ese nombre.']);
        }

        return $datos;
    }

    /** @return array<string, mixed> */
    private function validatedRequisito(Request $request): array
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(JobPositionRequirement::TIPOS))],
            'descripcion' => ['required', 'string', 'max:500'],
            'training_topic_id' => ['nullable', 'integer', 'exists:training_topics,id'],
        ]);
        // Solo la formación se cumple con una capacitación.
        if ($datos['tipo'] !== 'formacion') {
            $datos['training_topic_id'] = null;
        }

        return $datos;
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
