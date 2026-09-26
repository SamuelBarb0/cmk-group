<?php

namespace App\Http\Controllers;

use App\Models\ControlledDocument;
use App\Models\ControlledDocumentVersion;
use App\Models\DocumentCatalogEntry;
use App\Models\GeneratedDocument;
use App\Models\Norm;
use App\Models\NormRequirement;
use App\Models\Process;
use App\Services\ControlDocumental\CicloDocumental;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M01 — Control documental: listado maestro de la empresa activa, ficha de
 * cada documento y cobertura de requisitos por norma.
 *
 * Permisos: ver -> documents.view | gestionar -> documents.manage
 */
class ControlDocumentalController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CicloDocumental $ciclo,
    ) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('control-documental/index', [
                'needsClient' => true,
                'documentos' => [],
                'procesos' => [],
                'stats' => ['total' => 0, 'vigentes' => 0, 'en_flujo' => 0, 'revision_vencida' => 0, 'obsoletos' => 0],
                'catalogo' => [],
                'catalogos' => $this->catalogos(),
            ]);
        }

        Process::asegurarBase($this->context->id());

        $documentos = ControlledDocument::query()
            ->with(['process:id,sigla,nombre', 'catalogEntry:id,modulo'])
            ->orderBy('codigo')
            ->get();

        // Versión en curso de cada documento (borrador, revisión o aprobación),
        // para marcar los vigentes que se están actualizando.
        $enCurso = ControlledDocumentVersion::query()
            ->whereIn('controlled_document_id', $documentos->pluck('id'))
            ->whereIn('estado', ControlledDocumentVersion::EN_CURSO)
            ->pluck('estado', 'controlled_document_id');

        $filas = $documentos->map(fn (ControlledDocument $d) => [
            'id' => $d->id,
            'codigo' => $d->codigo,
            'tipo' => $d->tipo,
            'nivel' => $d->nivel,
            'titulo' => $d->titulo,
            'sistemas' => $d->sistemas,
            'condicional' => $d->condicional,
            'estado' => $d->estado,
            'version_vigente' => $d->version_vigente,
            'proxima_revision' => $d->proxima_revision?->toDateString(),
            'revision_vencida' => $d->revision_vencida,
            'dias_para_revision' => $d->dias_para_revision,
            'codigo_historico' => $d->codigo_historico,
            'proceso' => $d->process?->only(['id', 'sigla', 'nombre']),
            'en_curso' => $d->estado === 'vigente' ? ($enCurso[$d->id] ?? null) : null,
        ]);

        $porCatalogo = $documentos->whereNotNull('document_catalog_id')->countBy(
            fn (ControlledDocument $d) => $d->catalogEntry?->modulo
        );

        // El catálogo que le aplica a la empresa: lo que CMK contrató en el mapa (null = todo).
        $contratados = $this->context->get()?->documentos_sig;
        $catalogo = DocumentCatalogEntry::query()
            ->when(is_array($contratados), fn ($q) => $q->whereIn('id', $contratados))
            ->get(['modulo', 'condicional'])->groupBy('modulo')
            ->map(fn (Collection $g, string $m) => [
                'modulo' => $m,
                'nombre' => DocumentCatalogEntry::MODULOS[$m] ?? $m,
                'total' => $g->count(),
                'condicionales' => $g->where('condicional', true)->count(),
                'ya' => $porCatalogo[$m] ?? 0,
            ])->sortKeys()->values();

        return Inertia::render('control-documental/index', [
            'needsClient' => false,
            'documentos' => $filas,
            'procesos' => Process::query()->withCount('documents')->orderBy('orden')->get(),
            'stats' => [
                'total' => $documentos->count(),
                'vigentes' => $documentos->where('estado', 'vigente')->count(),
                // Los que nunca se han publicado, más los vigentes con una versión
                // nueva en curso. La v1 de un borrador no se cuenta dos veces.
                'en_flujo' => $documentos->whereIn('estado', ['borrador', 'en_revision', 'en_aprobacion'])->count()
                    + $filas->whereNotNull('en_curso')->count(),
                'revision_vencida' => $documentos->where('revision_vencida', true)->count(),
                'obsoletos' => $documentos->where('estado', 'obsoleto')->count(),
            ],
            'catalogo' => $catalogo,
            'catalogos' => $this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de crear documentos.']);
        }

        $datos = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(ControlledDocument::TIPOS))],
            'process_id' => ['required', 'integer'],
            'titulo' => ['required', 'string', 'max:255', $this->sinCodigoEnTitulo()],
            'sistemas' => ['required', 'array', 'min:1'],
            'sistemas.*' => [Rule::in(Norm::SISTEMAS)],
            'condicional' => ['boolean'],
            'codigo_historico' => ['nullable', 'string', 'max:30'],
            'confirmar_duplicado' => ['boolean'],
        ]);

        // Pasa por el TenantScope: un process_id de otra empresa da 404.
        $proceso = Process::query()->findOrFail($datos['process_id']);

        if (! $request->boolean('confirmar_duplicado')) {
            $parecidos = ControlledDocument::parecidos($datos['titulo']);
            if ($parecidos->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'duplicado' => 'Ya existe un documento parecido: '
                        .$parecidos->map(fn ($d) => $d->codigo.' «'.$d->titulo.'»')->implode(', ')
                        .'. Si de verdad es otro documento, confirma para crearlo.',
                ]);
            }
        }

        unset($datos['confirmar_duplicado'], $datos['process_id']);
        $doc = $this->ciclo->crear($datos, $proceso, auth()->user());

        return redirect()->route('control-documental.show', $doc)->with('success', "Documento {$doc->codigo} creado.");
    }

    public function show(ControlledDocument $documento): Response
    {
        $documento->load('process:id,sigla,nombre');

        $versiones = $documento->versions()->withCount('reads')->get();
        $vigente = $versiones->firstWhere('estado', 'vigente');

        $vinculadas = $documento->requirements()->pluck('clave_comun')->unique()->values();

        return Inertia::render('control-documental/show', [
            'documento' => $documento,
            'versiones' => $versiones,
            'lecturas' => $vigente ? $vigente->reads()->get(['user_nombre', 'leido_at']) : [],
            'leidoPorMi' => $vigente ? $vigente->reads()->where('user_id', auth()->id())->exists() : false,
            'puedeEliminarse' => $this->ciclo->puedeEliminarse($documento),
            'requisitos' => $this->requisitosPara($documento->sistemas),
            'vinculados' => $vinculadas,
            // Para traer a un borrador el texto de un documento redactado con IA.
            'documentosIa' => GeneratedDocument::query()
                ->whereNotIn('estado', ['generando', 'error'])
                ->latest()
                ->get(['id', 'titulo', 'version']),
            'catalogos' => $this->catalogos(),
        ]);
    }

    /** Metadatos de la ficha. Tipo y proceso no cambian: forman el código. */
    public function update(Request $request, ControlledDocument $documento): RedirectResponse
    {
        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:255', $this->sinCodigoEnTitulo()],
            'sistemas' => ['required', 'array', 'min:1'],
            'sistemas.*' => [Rule::in(Norm::SISTEMAS)],
            'condicional' => ['boolean'],
            'frecuencia_revision_meses' => ['required', 'integer', 'min:1', 'max:120'],
            'retencion_anios' => ['nullable', 'integer', 'min:1', 'max:100'],
            'disposicion_final' => ['nullable', Rule::in(ControlledDocument::DISPOSICIONES)],
            'ubicacion' => ['nullable', 'string', 'max:255'],
            'codigo_historico' => ['nullable', 'string', 'max:30'],
        ]);

        // El Dec. 1072 fija 20 años para el SG-SST; la empresa puede conservar
        // más, pero no menos.
        if (in_array('sst', $datos['sistemas'], true) && $datos['retencion_anios'] !== null
            && $datos['retencion_anios'] < ControlledDocument::RETENCION_SST) {
            throw ValidationException::withMessages([
                'retencion_anios' => 'Los documentos del SG-SST se conservan mínimo 20 años (Dec. 1072, art. 2.2.4.6.13).',
            ]);
        }

        $documento->fill($datos);

        // Cambiar la frecuencia mueve la próxima revisión desde la última aprobación.
        if ($documento->isDirty('frecuencia_revision_meses') && $documento->estado === 'vigente') {
            $aprobada = $documento->versions()->where('estado', 'vigente')->value('aprobado_at');
            if ($aprobada) {
                $documento->proxima_revision = $aprobada->copy()->addMonths($documento->frecuencia_revision_meses)->toDateString();
            }
        }

        $documento->save();

        // Si se quitó una norma, sus vínculos dejan de tener sentido.
        $documento->requirements()->detach(
            $documento->requirements()->whereHas('norm', fn ($q) => $q->whereNotIn('clave', $documento->sistemas))->pluck('norm_requirements.id')
        );

        return back()->with('success', 'Ficha actualizada.');
    }

    public function destroy(ControlledDocument $documento): RedirectResponse
    {
        if (! $this->ciclo->puedeEliminarse($documento)) {
            return back()->withErrors(['estado' => 'Un documento que ya fue publicado no se elimina: se retira y se conserva como obsoleto.']);
        }

        $codigo = $documento->codigo;
        $documento->delete();

        return redirect()->route('control-documental.index')->with('success', "Documento {$codigo} eliminado. Su código no se volverá a usar.");
    }

    public function retirar(Request $request, ControlledDocument $documento): RedirectResponse
    {
        $this->ciclo->retirar($documento, (string) $request->input('motivo'));

        return back()->with('success', 'Documento retirado. Queda como obsoleto para consulta.');
    }

    public function leido(ControlledDocument $documento): RedirectResponse
    {
        $this->ciclo->confirmarLectura($documento, auth()->user());

        return back()->with('success', 'Lectura confirmada.');
    }

    /**
     * Vincula el documento a requisitos comunes (SIG-01…SIG-62). Cada clave se
     * expande a las filas de las normas que el documento evidencia: la política
     * integrada se marca una vez y queda vinculada al 5.2 de las tres ISO, al
     * paso 3 del PESV y al 2.2.4.6.5 del Dec. 1072.
     */
    public function requisitos(Request $request, ControlledDocument $documento): RedirectResponse
    {
        $datos = $request->validate([
            'claves' => ['present', 'array'],
            'claves.*' => ['string', 'max:20'],
        ]);

        $ids = NormRequirement::query()
            ->whereIn('clave_comun', $datos['claves'])
            ->whereHas('norm', fn ($q) => $q->where('vigente', true)->whereIn('clave', $documento->sistemas))
            ->pluck('id');

        $documento->requirements()->sync($ids);

        return back()->with('success', 'Requisitos vinculados.');
    }

    /** Envía un documento de Documentos IA al listado maestro, como borrador. */
    public function desdeIa(GeneratedDocument $generado): RedirectResponse
    {
        if (in_array($generado->estado, ['generando', 'error'], true)) {
            return back()->withErrors(['estado' => 'El documento de IA todavía no tiene un texto listo para enviar.']);
        }

        $doc = $this->ciclo->recibirDeIa($generado, auth()->user());

        return redirect()->route('control-documental.show', $doc)
            ->with('success', "Texto de «{$generado->titulo}» cargado en el borrador de {$doc->codigo}. Revísalo y envíalo a revisión.");
    }

    public function inicializar(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        $datos = $request->validate([
            'modulos' => ['required', 'array', 'min:1'],
            'modulos.*' => [Rule::in(array_keys(DocumentCatalogEntry::MODULOS))],
            'incluir_condicionales' => ['boolean'],
        ]);

        $r = $this->ciclo->inicializarDesdeCatalogo(
            $this->context->id(), $datos['modulos'], $request->boolean('incluir_condicionales'), auth()->user()
        );

        $mensaje = "{$r['creados']} documentos agregados al listado maestro";
        if ($r['omitidos']) {
            $mensaje .= ", {$r['omitidos']} ya estaban";
        }
        if ($r['sin_proceso']) {
            $mensaje .= '. Sin proceso dueño (créalo y vuelve a correr): '.implode(', ', $r['sin_proceso']);
        }

        return back()->with('success', $mensaje.'.');
    }

    /** Cobertura de requisitos por norma para la empresa activa. */
    public function cobertura(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('control-documental/requisitos', [
                'needsClient' => true, 'normas' => [], 'requisitos' => [], 'etapas' => NormRequirement::ETAPAS,
            ]);
        }

        $normas = Norm::query()->where('vigente', true)->orderBy('orden')->get();
        $filas = NormRequirement::query()
            ->whereIn('norm_id', $normas->pluck('id'))
            ->with(['documents' => fn ($q) => $q->select('controlled_documents.id', 'codigo', 'titulo', 'estado')])
            ->orderBy('orden')
            ->get();

        $clavePorNorma = $normas->pluck('clave', 'id');

        $resumen = $normas->map(function (Norm $n) use ($filas) {
            $suyas = $filas->where('norm_id', $n->id);
            $cubiertos = $suyas->filter(fn ($r) => $r->documents->contains('estado', 'vigente'))->count();
            $enProceso = $suyas->filter(fn ($r) => $r->documents->isNotEmpty() && ! $r->documents->contains('estado', 'vigente'))->count();

            return [
                'clave' => $n->clave,
                'nombre' => $n->nombre,
                'edicion' => $n->edicion,
                'total' => $suyas->count(),
                'cubiertos' => $cubiertos,
                'en_proceso' => $enProceso,
                'cobertura' => $suyas->count() ? (int) round($cubiertos * 100 / $suyas->count()) : 0,
            ];
        })->values();

        $requisitos = $filas->groupBy('clave_comun')->map(function (Collection $g) use ($clavePorNorma) {
            $r = $g->first();

            return [
                'clave_comun' => $r->clave_comun,
                'titulo' => $r->titulo,
                'etapa' => $r->etapa,
                'evidencia' => $r->evidencia,
                'modulo' => $r->modulo,
                'nota' => $r->nota,
                'referencias' => $g->mapWithKeys(fn ($f) => [$clavePorNorma[$f->norm_id] => [
                    'referencia' => $f->referencia,
                    'cubierto' => $f->documents->contains('estado', 'vigente'),
                ]]),
                'documentos' => $g->flatMap->documents->unique('id')->map->only(['id', 'codigo', 'titulo', 'estado'])->values(),
            ];
        })->values();

        return Inertia::render('control-documental/requisitos', [
            'needsClient' => false,
            'normas' => $resumen,
            'requisitos' => $requisitos,
            'etapas' => NormRequirement::ETAPAS,
        ]);
    }

    /**
     * Los 62 requisitos comunes, cada uno con la referencia de las normas que
     * el documento evidencia. Los que no tocan ninguna de sus normas se omiten.
     *
     * @param  list<string>  $sistemas
     */
    private function requisitosPara(array $sistemas): Collection
    {
        return NormRequirement::query()
            ->with('norm:id,clave,nombre')
            ->whereHas('norm', fn ($q) => $q->where('vigente', true)->whereIn('clave', $sistemas))
            ->orderBy('orden')
            ->get()
            ->groupBy('clave_comun')
            ->map(fn (Collection $g) => [
                'clave_comun' => $g->first()->clave_comun,
                'titulo' => $g->first()->titulo,
                'etapa' => $g->first()->etapa,
                'modulo' => $g->first()->modulo,
                'referencias' => $g->map(fn ($f) => ['norma' => $f->norm->clave, 'referencia' => $f->referencia])->values(),
            ])
            ->values();
    }

    private function sinCodigoEnTitulo(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && preg_match(ControlledDocument::CODIGO_EN_TITULO_REGEX, $value)) {
                $fail('El título no puede llevar un código: el código lo asigna el sistema.');
            }
        };
    }

    private function catalogos(): array
    {
        return [
            'tipos' => collect(ControlledDocument::TIPOS)->map(fn ($t, $k) => ['clave' => $k] + $t)->values(),
            'niveles' => ControlledDocument::NIVELES,
            'sistemas' => Norm::NOMBRES,
            'disposiciones' => ControlledDocument::DISPOSICIONES,
            'modulos' => DocumentCatalogEntry::MODULOS,
        ];
    }
}
