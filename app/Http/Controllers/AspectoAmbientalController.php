<?php

namespace App\Http\Controllers;

use App\Models\AcpmAction;
use App\Models\ControlledDocument;
use App\Models\EnvironmentalAspect;
use App\Models\Process;
use App\Services\ControlDocumental\CicloDocumental;
use App\Services\Riesgos\DocumentosRiesgos;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M04 — Matriz de aspectos e impactos ambientales (ISO 14001 6.1.2) de la
 * empresa activa, con perspectiva de ciclo de vida y significancia.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class AspectoAmbientalController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly DocumentosRiesgos $documentos,
    ) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('aspectos-ambientales/index', [
                'needsClient' => true, 'aspectos' => [], 'procesos' => [],
                'stats' => ['total' => 0, 'significativos' => 0, 'emergencia' => 0, 'sin_control' => 0],
                'documento' => null, 'etapas' => EnvironmentalAspect::ETAPAS, 'umbral' => EnvironmentalAspect::UMBRAL,
            ]);
        }

        $aspectos = EnvironmentalAspect::query()
            ->with(['process:id,sigla,nombre', 'acpmAction:id,codigo,estado'])
            ->orderBy('orden')->orderBy('id')->get();
        $significativos = $aspectos->where('significativo', true);

        $entrada = $this->documentos->entrada('aspectos');
        $doc = $entrada ? ControlledDocument::query()->where('document_catalog_id', $entrada->id)->first() : null;

        return Inertia::render('aspectos-ambientales/index', [
            'needsClient' => false,
            'aspectos' => $aspectos,
            'procesos' => Process::query()->orderBy('orden')->get(['id', 'sigla', 'nombre']),
            'stats' => [
                'total' => $aspectos->count(),
                'significativos' => $significativos->count(),
                'emergencia' => $aspectos->where('condicion', 'emergencia')->count(),
                // Un aspecto significativo sin controles es la brecha típica de 8.1.
                'sin_control' => $significativos->filter(fn ($a) => blank($a->controles))->count(),
            ],
            'documento' => $entrada ? ['titulo' => $entrada->nombre, 'id' => $doc?->id, 'codigo' => $doc?->codigo, 'estado' => $doc?->estado] : null,
            'etapas' => EnvironmentalAspect::ETAPAS,
            'umbral' => EnvironmentalAspect::UMBRAL,
        ]);
    }

    /** Carga los aspectos típicos. Solo sobre una matriz vacía: no pisa la de la empresa. */
    public function base(): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }
        if (EnvironmentalAspect::query()->exists()) {
            return back()->withErrors(['matriz' => 'La empresa ya tiene aspectos: la base solo se carga sobre una matriz vacía.']);
        }

        foreach (EnvironmentalAspect::BASE as $i => [$actividad, $aspecto, $impacto, $condicion, $etapa, $f, $s, $legal, $controles]) {
            EnvironmentalAspect::create([
                'actividad' => $actividad, 'aspecto' => $aspecto, 'impacto' => $impacto, 'tipo_impacto' => 'negativo',
                'condicion' => $condicion, 'etapa' => $etapa, 'frecuencia' => $f, 'severidad' => $s,
                'requisito_legal' => $legal, 'controles' => $controles, 'orden' => $i + 1,
            ]);
        }

        return back()->with('success', count(EnvironmentalAspect::BASE).' aspectos típicos cargados. Quita los que no aplican y ajusta los puntajes a la empresa.');
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        EnvironmentalAspect::create($this->validated($request) + ['orden' => (EnvironmentalAspect::query()->max('orden') ?? 0) + 1]);

        return back()->with('success', 'Aspecto agregado.');
    }

    public function update(Request $request, EnvironmentalAspect $aspecto): RedirectResponse
    {
        $aspecto->update($this->validated($request));

        return back()->with('success', 'Aspecto actualizado.');
    }

    public function destroy(EnvironmentalAspect $aspecto): RedirectResponse
    {
        $aspecto->delete();

        return back()->with('success', 'Aspecto eliminado.');
    }

    /** Control de un aspecto significativo como acción preventiva en ACPM. */
    public function crearAccion(Request $request, EnvironmentalAspect $aspecto): RedirectResponse
    {
        if ($aspecto->acpm_action_id) {
            return back()->withErrors(['acpm' => 'Ya tiene una acción en ACPM.']);
        }

        $datos = $request->validate([
            'accion' => ['required', 'string', 'max:2000'],
            'responsable' => ['required', 'string', 'max:255'],
            'fecha_limite' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $acpm = AcpmAction::create([
            'tipo' => 'preventiva',
            'origen_tipo' => 'aspecto_ambiental',
            'origen_id' => $aspecto->id,
            'hallazgo' => "Aspecto ambiental: {$aspecto->aspecto} ({$aspecto->actividad}) → {$aspecto->impacto}",
            'accion' => $datos['accion'],
            'responsable' => $datos['responsable'],
            'fecha_deteccion' => now()->toDateString(),
            'fecha_limite' => $datos['fecha_limite'],
            'estado' => 'abierta',
        ]);
        // El vínculo no es asignable desde un formulario: se pone aquí, a mano.
        $aspecto->forceFill(['acpm_action_id' => $acpm->id])->save();

        return back()->with('success', "Acción {$acpm->codigo} creada en ACPM.");
    }

    public function enviar(Request $request, CicloDocumental $ciclo): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }
        $entrada = $this->documentos->entrada('aspectos');
        if (! $entrada) {
            return back()->withErrors(['documento' => 'El catálogo del SIG no está cargado en esta instalación: falta correr SigCatalogSeeder.']);
        }

        $doc = $ciclo->recibirDeModulo($this->context->id(), $entrada, $this->documentos->contenido('aspectos'),
            'Actualizado desde la matriz de aspectos e impactos ambientales.', $request->user(), $this->documentos->claves('aspectos'));

        return back()->with('success', "{$doc->codigo} quedó en borrador en el control documental. Revísalo y apruébalo allí.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'process_id' => ['nullable', 'integer', Rule::exists('processes', 'id')->where('tenant_id', $this->context->id())],
            'actividad' => ['required', 'string', 'max:255'],
            'aspecto' => ['required', 'string', 'max:255'],
            'impacto' => ['required', 'string', 'max:255'],
            'tipo_impacto' => ['required', Rule::in(EnvironmentalAspect::TIPOS_IMPACTO)],
            'condicion' => ['required', Rule::in(EnvironmentalAspect::CONDICIONES)],
            'etapa' => ['required', Rule::in(array_keys(EnvironmentalAspect::ETAPAS))],
            'frecuencia' => ['required', 'integer', 'between:1,5'],
            'severidad' => ['required', 'integer', 'between:1,5'],
            'requisito_legal' => ['boolean'],
            'preocupa_partes' => ['boolean'],
            'controles' => ['nullable', 'string', 'max:3000'],
        ]);
    }
}
