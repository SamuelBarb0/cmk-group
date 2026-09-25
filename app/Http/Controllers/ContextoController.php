<?php

namespace App\Http\Controllers;

use App\Models\ContextIssue;
use App\Models\ContextProfile;
use App\Models\ControlledDocument;
use App\Models\InterestedParty;
use App\Models\Norm;
use App\Models\Process;
use App\Services\Contexto\DocumentosContexto;
use App\Services\ControlDocumental\CicloDocumental;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M02 — Contexto de la organización de la empresa activa: alcance y cambio
 * climático, cuestiones internas y externas (DOFA/PESTEL), partes
 * interesadas y caracterización de los procesos del mapa. Lo trabajado aquí
 * se manda al control documental como borrador de los documentos del
 * catálogo que el módulo evidencia (ver DocumentosContexto).
 *
 * El mapa de procesos (siglas, nombres, tipos) se administra en el control
 * documental, porque de él salen los códigos de los documentos; aquí solo se
 * caracteriza cada proceso.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class ContextoController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly DocumentosContexto $documentos,
    ) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('contexto/index', ['needsClient' => true] + $this->vacio());
        }

        $perfil = ContextProfile::query()->first();
        $cuestiones = ContextIssue::query()->orderBy('orden')->orderBy('id')->get();
        $partes = InterestedParty::query()->orderBy('orden')->orderBy('id')->get();
        $procesos = Process::query()->orderBy('orden')->orderBy('id')->get();

        // El documento del listado que corresponde a cada pieza, si ya existe.
        $documentos = collect(DocumentosContexto::DOCUMENTOS)->keys()->mapWithKeys(function (string $clave) {
            $entrada = $this->documentos->entrada($clave);
            $doc = $entrada ? ControlledDocument::query()->where('document_catalog_id', $entrada->id)->first() : null;

            return [$clave => [
                'disponible' => $entrada !== null,
                'titulo' => $entrada?->nombre,
                'id' => $doc?->id,
                'codigo' => $doc?->codigo,
                'estado' => $doc?->estado,
            ]];
        });

        return Inertia::render('contexto/index', [
            'needsClient' => false,
            'perfil' => $perfil ? $perfil->toArray() + ['revision_vencida' => $perfil->revisionVencida()] : null,
            'cuestiones' => $cuestiones,
            'partes' => $partes->map(fn (InterestedParty $p) => $p->toArray() + ['estrategia' => $p->estrategia()])->values(),
            'procesos' => $procesos->map(fn (Process $p) => $p->only(['id', 'sigla', 'nombre', 'tipo', 'objetivo', 'lider', 'caracterizacion']) + ['caracterizado' => $p->caracterizado()])->values(),
            'completitud' => DocumentosContexto::completitud($perfil, $cuestiones, $partes, $procesos),
            'documentos' => $documentos,
            'categorias' => InterestedParty::CATEGORIAS,
            'camposCaracterizacion' => Process::CARACTERIZACION,
        ]);
    }

    /** Alcance, exclusiones y decisión sobre el cambio climático. */
    public function updatePerfil(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        $datos = $request->validate([
            'alcance' => ['nullable', 'string', 'max:4000'],
            'sedes' => ['nullable', 'string', 'max:2000'],
            'productos_servicios' => ['nullable', 'string', 'max:2000'],
            'exclusiones' => ['nullable', 'array', 'max:30'],
            'exclusiones.*.requisito' => ['required', 'string', 'max:255'],
            // Excluir un requisito sin decir por qué no pasa una auditoría ISO.
            'exclusiones.*.justificacion' => ['required', 'string', 'max:1000'],
            'cambio_climatico' => ['nullable', 'boolean'],
            'cambio_climatico_justificacion' => ['nullable', 'string', 'max:2000', 'required_with:cambio_climatico'],
        ], [
            'exclusiones.*.justificacion.required' => 'Cada requisito excluido necesita su justificación.',
            'cambio_climatico_justificacion.required_with' => 'Explica por qué el cambio climático es o no es pertinente.',
        ]);

        $datos['exclusiones'] = array_values($datos['exclusiones'] ?? []);

        ContextProfile::query()->updateOrCreate([], $datos);

        return back()->with('success', 'Alcance y contexto guardados.');
    }

    /** Deja constancia de la revisión periódica del contexto (anual). */
    public function revisado(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        ContextProfile::query()->updateOrCreate([], [
            'revisado_at' => now()->toDateString(),
            'revisado_por' => $request->user()->name,
        ]);

        return back()->with('success', 'Revisión del contexto registrada con fecha de hoy.');
    }

    public function storeCuestion(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        ContextIssue::create($this->validatedCuestion($request) + ['orden' => (ContextIssue::query()->max('orden') ?? 0) + 1]);

        return back()->with('success', 'Cuestión agregada.');
    }

    public function updateCuestion(Request $request, ContextIssue $cuestion): RedirectResponse
    {
        $cuestion->update($this->validatedCuestion($request));

        return back()->with('success', 'Cuestión actualizada.');
    }

    public function destroyCuestion(ContextIssue $cuestion): RedirectResponse
    {
        $cuestion->delete();

        return back()->with('success', 'Cuestión eliminada.');
    }

    /** Carga las partes interesadas típicas. Solo sobre una matriz vacía: no pisa la de la empresa. */
    public function baseParts(): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }
        if (InterestedParty::query()->exists()) {
            return back()->withErrors(['partes' => 'La empresa ya tiene partes interesadas: la base solo se carga sobre una matriz vacía.']);
        }

        foreach (InterestedParty::BASE as $i => [$nombre, $tipo, $categoria, $necesidades, $expectativas, $requisito, $influencia, $interes, $como, $sistemas]) {
            InterestedParty::create([
                'nombre' => $nombre, 'tipo' => $tipo, 'categoria' => $categoria, 'necesidades' => $necesidades,
                'expectativas' => $expectativas, 'es_requisito' => $requisito, 'influencia' => $influencia,
                'interes' => $interes, 'como_se_atiende' => $como, 'sistemas' => $sistemas, 'orden' => $i + 1,
            ]);
        }

        return back()->with('success', count(InterestedParty::BASE).' partes interesadas base cargadas. Quita las que no aplican y ajusta las necesidades a la empresa.');
    }

    public function storeParte(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        InterestedParty::create($this->validatedParte($request) + ['orden' => (InterestedParty::query()->max('orden') ?? 0) + 1]);

        return back()->with('success', 'Parte interesada agregada.');
    }

    public function updateParte(Request $request, InterestedParty $parte): RedirectResponse
    {
        $parte->update($this->validatedParte($request));

        return back()->with('success', 'Parte interesada actualizada.');
    }

    public function destroyParte(InterestedParty $parte): RedirectResponse
    {
        $parte->delete();

        return back()->with('success', 'Parte interesada eliminada.');
    }

    /** Caracterización de un proceso del mapa (ISO 4.4). */
    public function updateProceso(Request $request, Process $proceso): RedirectResponse
    {
        $reglas = [
            'objetivo' => ['nullable', 'string', 'max:2000'],
            'lider' => ['nullable', 'string', 'max:255'],
            'caracterizacion' => ['nullable', 'array'],
        ];
        foreach (array_keys(Process::CARACTERIZACION) as $campo) {
            $reglas["caracterizacion.{$campo}"] = ['nullable', 'string', 'max:3000'];
        }
        $datos = $request->validate($reglas);

        // Solo los campos conocidos: nada de claves sueltas en el JSON.
        $datos['caracterizacion'] = array_intersect_key($datos['caracterizacion'] ?? [], Process::CARACTERIZACION);
        $proceso->update($datos);

        return back()->with('success', "Caracterización de {$proceso->sigla} guardada.");
    }

    /** Manda un documento armado desde el módulo al control documental, como borrador. */
    public function enviar(Request $request, CicloDocumental $ciclo): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        $documento = $request->validate([
            'documento' => ['required', Rule::in(array_keys(DocumentosContexto::DOCUMENTOS))],
        ])['documento'];

        $entrada = $this->documentos->entrada($documento);
        if (! $entrada) {
            return back()->withErrors(['documentos' => 'El catálogo del SIG no está cargado en esta instalación: falta correr SigCatalogSeeder.']);
        }

        $doc = $ciclo->recibirDeModulo(
            $this->context->id(),
            $entrada,
            $this->documentos->contenido($documento),
            'Actualizado desde el módulo de contexto de la organización.',
            $request->user(),
            $this->documentos->clavesRequisitos($documento),
        );

        return back()->with('success', "{$doc->codigo} quedó en borrador en el control documental. Revísalo y apruébalo allí.");
    }

    /** @return array<string, mixed> */
    private function validatedCuestion(Request $request): array
    {
        $datos = $request->validate([
            'origen' => ['required', Rule::in(ContextIssue::ORIGENES)],
            'dofa' => ['required', Rule::in(ContextIssue::DOFA[$request->input('origen')] ?? [])],
            'pestel' => ['nullable', Rule::in(ContextIssue::PESTEL)],
            'descripcion' => ['required', 'string', 'max:2000'],
            'impacto' => ['required', Rule::in(ContextIssue::IMPACTOS)],
            'cambio_climatico' => ['boolean'],
            'tratamiento' => ['nullable', 'string', 'max:2000'],
            'sistemas' => ['required', 'array', 'min:1'],
            'sistemas.*' => [Rule::in(Norm::SISTEMAS)],
        ], [
            'dofa.in' => 'Lo interno es fortaleza o debilidad; lo externo, oportunidad o amenaza.',
        ]);

        // PESTEL clasifica el entorno: una cuestión interna no lleva.
        if ($datos['origen'] === 'interno') {
            $datos['pestel'] = null;
        }

        return $datos;
    }

    /** @return array<string, mixed> */
    private function validatedParte(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::in(InterestedParty::TIPOS)],
            'categoria' => ['required', Rule::in(array_keys(InterestedParty::CATEGORIAS))],
            'necesidades' => ['required', 'string', 'max:2000'],
            'expectativas' => ['nullable', 'string', 'max:2000'],
            'es_requisito' => ['boolean'],
            'influencia' => ['required', Rule::in(InterestedParty::NIVELES)],
            'interes' => ['required', Rule::in(InterestedParty::NIVELES_INTERES)],
            'como_se_atiende' => ['nullable', 'string', 'max:2000'],
            'cambio_climatico' => ['boolean'],
            'sistemas' => ['required', 'array', 'min:1'],
            'sistemas.*' => [Rule::in(Norm::SISTEMAS)],
        ]);
    }

    /** @return array<string, mixed> */
    private function vacio(): array
    {
        return [
            'perfil' => null, 'cuestiones' => [], 'partes' => [], 'procesos' => [],
            'completitud' => ['dofa' => false, 'clima' => false, 'partes' => false, 'alcance' => false, 'procesos' => false],
            'documentos' => [], 'categorias' => InterestedParty::CATEGORIAS, 'camposCaracterizacion' => Process::CARACTERIZACION,
        ];
    }
}
