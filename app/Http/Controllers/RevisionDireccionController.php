<?php

namespace App\Http\Controllers;

use App\Models\AcpmAction;
use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Models\Norm;
use App\Services\Reportes\InformeGestion;
use App\Services\Reportes\Periodo;
use App\Services\RevisionDireccionExporter;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * M16 — Revisión por la dirección de la empresa activa.
 *
 * Permisos: ver -> reports.view | gestionar -> reports.generate
 */
class RevisionDireccionController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly InformeGestion $informes,
    ) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('revision-direccion/index', [
                'needsClient' => true, 'revisiones' => [], 'stats' => ['total' => 0, 'pendientes' => 0, 'vencidas' => 0],
            ]);
        }

        $revisiones = ManagementReview::query()->withCount('decisions')->orderByDesc('periodo_hasta')->get();
        $decisiones = ManagementReviewDecision::query()
            ->whereIn('management_review_id', $revisiones->pluck('id'))
            ->whereIn('estado', ['pendiente', 'en_proceso'])
            ->get();

        return Inertia::render('revision-direccion/index', [
            'needsClient' => false,
            'revisiones' => $revisiones->map(fn (ManagementReview $r) => array_merge($r->only([
                'id', 'codigo', 'sistemas', 'estado', 'decisions_count',
            ]), [
                // only() entrega el Carbon crudo, no el cast Y-m-d.
                'periodo_desde' => $r->periodo_desde->toDateString(),
                'periodo_hasta' => $r->periodo_hasta->toDateString(),
                'fecha_reunion' => $r->fecha_reunion?->toDateString(),
            ])),
            'stats' => [
                'total' => $revisiones->count(),
                'pendientes' => $decisiones->count(),
                'vencidas' => $decisiones->filter(fn ($d) => $d->fecha_limite?->isPast())->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de programar la revisión.']);
        }

        $revision = ManagementReview::create($request->validate([
            'periodo_desde' => ['required', 'date'],
            'periodo_hasta' => ['required', 'date', 'after_or_equal:periodo_desde'],
            'sistemas' => ['required', 'array', 'min:1'],
            'sistemas.*' => [Rule::in(Norm::SISTEMAS)],
        ]));

        return redirect()->route('revision-direccion.show', $revision)->with('success', "Revisión {$revision->codigo} creada. Recopila los datos del periodo.");
    }

    public function show(ManagementReview $revision): Response
    {
        $revision->load('decisions.accion:id,codigo,estado');
        $datos = $revision->datos ?? [];

        $entradas = collect($revision->entradasAplicables())->map(fn (array $e, string $clave) => [
            'clave' => $clave,
            'titulo' => $e['titulo'],
            'referencias' => $e['referencias'],
            // Solo las secciones que se recopilaron: si la empresa no contrató
            // un módulo, su sección no está y no se inventa.
            'secciones' => collect($e['secciones'])->map(fn ($s) => $datos['secciones'][$s] ?? null)->filter()->values(),
            'analisis' => $revision->analisis[$clave] ?? '',
        ])->values();

        // Cerrada, la primera entrada se lee de lo que quedó congelado; en
        // borrador, del estado de hoy de las decisiones anteriores.
        $previas = $revision->estaCerrada()
            ? ($datos['acciones_previas'] ?? [])
            : $this->resumenDecisiones($revision->decisionesAnteriores());

        return Inertia::render('revision-direccion/show', [
            'revision' => array_merge($revision->only([
                'id', 'codigo', 'sistemas', 'participantes', 'conclusiones_sistema', 'conclusiones', 'estado', 'cerrada_por',
            ]), [
                'periodo_desde' => $revision->periodo_desde->toDateString(),
                'periodo_hasta' => $revision->periodo_hasta->toDateString(),
                'fecha_reunion' => $revision->fecha_reunion?->toDateString(),
                'datos_at' => $revision->datos_at?->toIso8601String(),
                'cerrada_at' => $revision->cerrada_at?->toIso8601String(),
                'atencion' => $datos['atencion'] ?? [],
            ]),
            'entradas' => $entradas,
            'previas' => $previas,
            'decisiones' => $revision->decisions,
            'catalogos' => [
                'criterios' => ManagementReview::CRITERIOS,
                'valoraciones' => ManagementReview::VALORACIONES,
                'tipos' => ManagementReviewDecision::TIPOS,
                'estados' => ManagementReviewDecision::ESTADOS,
            ],
        ]);
    }

    public function update(Request $request, ManagementReview $revision): RedirectResponse
    {
        $this->exigirBorrador($revision);

        $datos = $request->validate([
            'periodo_desde' => ['required', 'date'],
            'periodo_hasta' => ['required', 'date', 'after_or_equal:periodo_desde'],
            'fecha_reunion' => ['nullable', 'date'],
            'sistemas' => ['required', 'array', 'min:1'],
            'sistemas.*' => [Rule::in(Norm::SISTEMAS)],
            'participantes' => ['nullable', 'string', 'max:2000'],
            'analisis' => ['nullable', 'array'],
            'analisis.*' => ['nullable', 'string', 'max:5000'],
            'conclusiones_sistema' => ['nullable', 'array'],
            'conclusiones_sistema.*' => ['nullable', Rule::in(ManagementReview::VALORACIONES)],
            'conclusiones' => ['nullable', 'string', 'max:5000'],
        ]);

        $datos['analisis'] = array_intersect_key($datos['analisis'] ?? [], ManagementReview::ENTRADAS);
        $datos['conclusiones_sistema'] = array_intersect_key($datos['conclusiones_sistema'] ?? [], ManagementReview::CRITERIOS);

        $cambiaPeriodo = $revision->periodo_desde->toDateString() !== $datos['periodo_desde']
            || $revision->periodo_hasta->toDateString() !== $datos['periodo_hasta'];

        $revision->fill($datos)->save();

        return back()->with('success', $cambiaPeriodo && $revision->datos
            ? 'Revisión guardada. Cambió el periodo: vuelve a recopilar los datos.'
            : 'Revisión guardada.');
    }

    /**
     * Recopila y congela los datos del periodo desde los módulos. Se puede
     * repetir mientras la revisión esté en borrador.
     */
    public function recopilar(ManagementReview $revision): RedirectResponse
    {
        $this->exigirBorrador($revision);

        $claves = collect($revision->entradasAplicables())->pluck('secciones')->flatten()->unique()->values()->all();
        $periodo = Periodo::desde($revision->periodo_desde->toDateString(), $revision->periodo_hasta->toDateString());
        $informe = $this->informes->generar(auth()->user(), $periodo, $claves);

        $revision->datos = [
            'secciones' => collect($informe['secciones'])->keyBy('clave')->all(),
            'atencion' => $informe['atencion'],
            'generado_por' => $informe['generado']['por'],
        ];
        $revision->datos_at = now();
        $revision->save();

        return back()->with('success', 'Datos del periodo recopilados de los módulos.');
    }

    public function cerrar(ManagementReview $revision): RedirectResponse
    {
        $this->exigirBorrador($revision);

        $faltan = [];
        if (! $revision->datos) {
            $faltan[] = 'recopilar los datos del periodo';
        }
        if (! $revision->fecha_reunion) {
            $faltan[] = 'la fecha de la reunión';
        }
        if (blank($revision->participantes)) {
            $faltan[] = 'los participantes';
        }
        if ($sin = $revision->entradasSinAnalisis()) {
            $faltan[] = 'el análisis de '.count($sin).' entrada(s)';
        }
        $criterios = array_filter($revision->conclusiones_sistema ?? []);
        if (count(array_intersect_key($criterios, ManagementReview::CRITERIOS)) < count(ManagementReview::CRITERIOS)) {
            $faltan[] = 'la conclusión sobre conveniencia, adecuación y eficacia';
        }
        if ($faltan) {
            throw ValidationException::withMessages(['cierre' => 'Para cerrar la revisión falta: '.implode(', ', $faltan).'.']);
        }

        // La primera entrada se congela también: el acta tiene que decir cómo
        // estaban las decisiones anteriores el día de la reunión.
        $datos = $revision->datos;
        $datos['acciones_previas'] = $this->resumenDecisiones($revision->decisionesAnteriores());

        $revision->datos = $datos;
        $revision->estado = 'cerrada';
        $revision->cerrada_at = now();
        $revision->cerrada_por = auth()->user()?->name;
        $revision->save();

        return back()->with('success', "Revisión {$revision->codigo} cerrada. El seguimiento de sus decisiones sigue abierto.");
    }

    public function destroy(ManagementReview $revision): RedirectResponse
    {
        if ($revision->estaCerrada()) {
            return back()->withErrors(['estado' => 'Una revisión cerrada es un registro: no se elimina.']);
        }

        $revision->delete();

        return redirect()->route('revision-direccion.index')->with('success', 'Revisión eliminada.');
    }

    /** Las decisiones se toman en la reunión: solo se agregan en borrador. */
    public function storeDecision(Request $request, ManagementReview $revision): RedirectResponse
    {
        $this->exigirBorrador($revision);

        $revision->decisions()->create($request->validate([
            'tipo' => ['required', Rule::in(ManagementReviewDecision::TIPOS)],
            'descripcion' => ['required', 'string', 'max:2000'],
            'responsable' => ['nullable', 'string', 'max:255'],
            'fecha_limite' => ['nullable', 'date'],
        ]) + ['estado' => 'pendiente']);

        return back()->with('success', 'Decisión registrada.');
    }

    /**
     * En borrador se edita todo; cerrada la revisión, solo el seguimiento
     * (estado y avance): lo que se decidió no cambia, lo que se hizo sí.
     */
    public function updateDecision(Request $request, ManagementReview $revision, ManagementReviewDecision $decision): RedirectResponse
    {
        $reglas = [
            'estado' => ['required', Rule::in(ManagementReviewDecision::ESTADOS)],
            'seguimiento' => ['nullable', 'string', 'max:2000'],
        ];
        if (! $revision->estaCerrada()) {
            $reglas += [
                'tipo' => ['required', Rule::in(ManagementReviewDecision::TIPOS)],
                'descripcion' => ['required', 'string', 'max:2000'],
                'responsable' => ['nullable', 'string', 'max:255'],
                'fecha_limite' => ['nullable', 'date'],
            ];
        }

        $decision->update($request->validate($reglas));

        return back()->with('success', 'Decisión actualizada.');
    }

    public function destroyDecision(ManagementReview $revision, ManagementReviewDecision $decision): RedirectResponse
    {
        $this->exigirBorrador($revision);
        $decision->delete();

        return back()->with('success', 'Decisión eliminada.');
    }

    /** Convierte una decisión en una acción de mejora del ACPM y las enlaza. */
    public function acpm(ManagementReview $revision, ManagementReviewDecision $decision): RedirectResponse
    {
        if ($decision->acpm_action_id) {
            return back()->withErrors(['acpm' => 'Esta decisión ya tiene su acción en el ACPM.']);
        }

        $deteccion = $revision->fecha_reunion ?? now();
        $limite = $decision->fecha_limite && $decision->fecha_limite->gte($deteccion)
            ? $decision->fecha_limite
            : $deteccion->copy()->addDays(30);

        $accion = AcpmAction::create([
            'tipo' => 'mejora',
            'origen_tipo' => 'revision_direccion',
            'origen_id' => $decision->id,
            'hallazgo' => "Decisión de la revisión por la dirección {$revision->codigo}.",
            'accion' => $decision->descripcion,
            'responsable' => $decision->responsable ?: 'Por asignar',
            'fecha_deteccion' => $deteccion->toDateString(),
            'fecha_limite' => $limite->toDateString(),
            'estado' => 'abierta',
        ]);

        $decision->update(['acpm_action_id' => $accion->id, 'estado' => $decision->estado === 'pendiente' ? 'en_proceso' : $decision->estado]);

        return back()->with('success', "Acción {$accion->codigo} creada en el ACPM.");
    }

    public function export(ManagementReview $revision, RevisionDireccionExporter $exporter): BinaryFileResponse
    {
        $path = $exporter->export($revision);

        return response()->download($path, basename($path))->deleteFileAfterSend();
    }

    private function exigirBorrador(ManagementReview $revision): void
    {
        if ($revision->estaCerrada()) {
            throw ValidationException::withMessages(['estado' => "La revisión {$revision->codigo} está cerrada: es un registro y no se modifica."]);
        }
    }

    /**
     * @param  Collection<int, ManagementReviewDecision>  $decisiones
     * @return list<array<string, mixed>>
     */
    private function resumenDecisiones($decisiones): array
    {
        return $decisiones->map(fn (ManagementReviewDecision $d) => [
            'revision' => $d->review?->codigo,
            'tipo' => $d->tipo,
            'descripcion' => $d->descripcion,
            'responsable' => $d->responsable,
            'fecha_limite' => $d->fecha_limite?->toDateString(),
            'estado' => $d->estado,
            'seguimiento' => $d->seguimiento,
        ])->values()->all();
    }
}
