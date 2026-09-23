<?php

namespace App\Http\Controllers;

use App\Models\ContractorDocument;
use App\Models\ContractorEvaluation;
use App\Models\PesvContractor;
use App\Support\EvaluacionesContratistas;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contratistas y proveedores del cliente activo (estándar 2.10.1 de la Res.
 * 0312 e ISO 8.4): hoja de vida, documentos, selección, requisitos SST y
 * evaluación. Usa el mismo registro que el PESV (`pesv_contractors`).
 *
 * Los documentos no tienen rutas propias (se guardan con el contratista);
 * las evaluaciones sí, porque llevan tenant_id y pasan por el TenantScope.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class ContratistaController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        $anio = (int) $request->integer('anio', (int) now()->year);

        if (! $this->context->has()) {
            return Inertia::render('contratistas/index', [
                'needsClient' => true, 'anio' => $anio, 'contratistas' => [], 'stats' => null, 'catalogos' => $this->catalogos(),
            ]);
        }

        $contratistas = PesvContractor::query()
            ->with(['documents', 'evaluations'])
            ->orderByDesc('is_active')->orderBy('nombre')
            ->get()
            ->map(fn (PesvContractor $c) => [
                ...$c->only(['id', 'nombre', 'nit', 'tipo', 'persona', 'actividad', 'ciudad', 'supervisor', 'is_active']),
                'situacion' => $c->situacion($anio),
            ]);

        $activos = $contratistas->where('is_active', true);

        return Inertia::render('contratistas/index', [
            'needsClient' => false,
            'anio' => $anio,
            'contratistas' => $contratistas->values(),
            'stats' => [
                'activos' => $activos->count(),
                'sin_evaluar' => $activos->filter(fn ($c) => $c['situacion']['evaluacion'] === null)->count(),
                'evaluacion_vencida' => $activos->filter(fn ($c) => $c['situacion']['evaluacion_vencida'])->count(),
                'no_confiables' => $activos->filter(fn ($c) => ($c['situacion']['evaluacion']['resultado'] ?? null) === 'no_confiable')->count(),
                'documentos_vencidos' => $activos->sum(fn ($c) => $c['situacion']['documentos_vencidos']),
            ],
            'catalogos' => $this->catalogos(),
        ]);
    }

    public function show(Request $request, PesvContractor $contratista): Response
    {
        $anio = (int) $request->integer('anio', (int) now()->year);
        $contratista->load(['documents', 'evaluations']);

        return Inertia::render('contratistas/show', [
            'needsClient' => false,
            'anio' => $anio,
            'contratista' => [
                ...$contratista->only(['id', 'nombre', 'nit', 'tipo', 'persona', 'actividad', 'direccion', 'ciudad',
                    'representante_legal', 'supervisor', 'contacto_nombre', 'contacto_telefono', 'contacto_email',
                    'tiene_pesv', 'observaciones', 'is_active']),
                // only() se salta el cast: fechas a mano (ver el bug del 17-sep).
                'fecha_ingreso' => $contratista->fecha_ingreso?->toDateString(),
            ],
            'documentos' => $contratista->documents->map(fn (ContractorDocument $d) => [
                ...$d->only(['id', 'tipo', 'nombre', 'estado', 'observacion']),
                'fecha_expedicion' => $d->fecha_expedicion?->toDateString(),
                'fecha_vencimiento' => $d->fecha_vencimiento?->toDateString(),
                'alerta' => $d->alerta()['alerta'] ?? null,
            ]),
            'evaluaciones' => $contratista->evaluations->sortByDesc(fn ($e) => $e->fecha->format('Y-m-d').sprintf('%010d', $e->id))->values(),
            'situacion' => $contratista->situacion($anio),
            'catalogos' => $this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->context->has(), 422, 'Selecciona un cliente antes de registrar contratistas.');
        $datos = $this->validatedContratista($request);

        $contratista = DB::transaction(function () use ($datos): PesvContractor {
            $c = PesvContractor::create($datos);
            // Arranca con la lista de documentos que pide la hoja de selección.
            foreach (ContractorDocument::REQUERIDOS[$c->persona ?? 'juridica'] as $i => $tipo) {
                $c->documents()->create(['tipo' => $tipo, 'estado' => 'no_entregado', 'orden' => $i + 1]);
            }

            return $c;
        });

        return to_route('contratistas.show', $contratista)->with('success', 'Contratista registrado.');
    }

    public function update(Request $request, PesvContractor $contratista): RedirectResponse
    {
        $datos = $this->validatedContratista($request);
        $docs = $request->validate([
            'documentos' => ['present', 'array', 'max:40'],
            'documentos.*.tipo' => ['required', Rule::in(array_keys(ContractorDocument::TIPOS))],
            'documentos.*.nombre' => ['nullable', 'string', 'max:255', 'required_if:documentos.*.tipo,otro'],
            'documentos.*.estado' => ['required', Rule::in(ContractorDocument::ESTADOS)],
            'documentos.*.fecha_expedicion' => ['nullable', 'date'],
            'documentos.*.fecha_vencimiento' => ['nullable', 'date'],
            'documentos.*.observacion' => ['nullable', 'string', 'max:500'],
        ], [
            'documentos.*.nombre.required_if' => 'Un documento «Otro» necesita nombre.',
        ])['documentos'];

        DB::transaction(function () use ($contratista, $datos, $docs): void {
            $contratista->update($datos);
            // Nada apunta a un documento: se pueden reemplazar enteros.
            $contratista->documents()->delete();
            foreach (array_values($docs) as $i => $d) {
                $contratista->documents()->create($d + ['orden' => $i + 1]);
            }
        });

        return back()->with('success', 'Contratista actualizado.');
    }

    public function destroy(PesvContractor $contratista): RedirectResponse
    {
        $contratista->delete();

        return to_route('contratistas.index')->with('success', 'Contratista eliminado, también del PESV.');
    }

    // ------------------------------------------------------------ evaluaciones

    public function storeEvaluacion(Request $request, PesvContractor $contratista): RedirectResponse
    {
        $formatos = EvaluacionesContratistas::formatos();
        $clave = $request->validate(['formato' => ['required', Rule::in(array_keys($formatos))]])['formato'];
        $formato = $formatos[$clave];
        $datos = $this->validatedEvaluacion($request, $formato);

        DB::transaction(function () use ($contratista, $clave, $formato, $datos): void {
            $e = new ContractorEvaluation($datos + [
                'pesv_contractor_id' => $contratista->id,
                'formato' => $clave,
                'uso' => $formato['uso'],
                'estructura' => $formato,
            ]);
            $e->calificar();
            $e->save();
            $contratista->sincronizarCalificacion();
        });

        return back()->with('success', 'Evaluación registrada.');
    }

    public function updateEvaluacion(Request $request, ContractorEvaluation $evaluacion): RedirectResponse
    {
        // Se valida y recalifica contra la estructura guardada, no la actual.
        $datos = $this->validatedEvaluacion($request, $evaluacion->estructura);

        DB::transaction(function () use ($evaluacion, $datos): void {
            $evaluacion->fill($datos);
            $evaluacion->calificar();
            $evaluacion->save();
            $evaluacion->contractor->sincronizarCalificacion();
        });

        return back()->with('success', 'Evaluación actualizada.');
    }

    public function destroyEvaluacion(ContractorEvaluation $evaluacion): RedirectResponse
    {
        $contratista = $evaluacion->contractor;
        $borrada = $evaluacion->uso;
        $evaluacion->delete();
        // Solo si era una evaluación: borrar una selección no cambia la nota.
        if ($borrada === 'evaluacion') {
            $contratista?->sincronizarCalificacion(trasBorrar: true);
        }

        return back()->with('success', 'Evaluación eliminada.');
    }

    // ------------------------------------------------------------------ apoyo

    private function validatedContratista(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'nit' => ['nullable', 'string', 'max:30'],
            'tipo' => ['required', Rule::in(PesvContractor::TIPOS)],
            'persona' => ['required', Rule::in(PesvContractor::PERSONAS)],
            'actividad' => ['nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'representante_legal' => ['nullable', 'string', 'max:255'],
            'supervisor' => ['nullable', 'string', 'max:255'],
            'fecha_ingreso' => ['nullable', 'date'],
            'contacto_nombre' => ['nullable', 'string', 'max:255'],
            'contacto_telefono' => ['nullable', 'string', 'max:50'],
            'contacto_email' => ['nullable', 'email', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ], [], ['persona' => 'tipo de persona', 'contacto_email' => 'correo de contacto']);
    }

    /**
     * Todo ítem tiene que tener respuesta: una opción válida del ítem o «na».
     * Un ítem sin responder no puede contar ni como cumplido ni como no.
     */
    private function validatedEvaluacion(Request $request, array $formato): array
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'evaluador' => ['nullable', 'string', 'max:255'],
            'evaluador_cargo' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
            'respuestas' => ['required', 'array'],
            // Sin regla propia, validate() DESCARTA la clave anidada: solo
            // devuelve lo que tiene regla. Lo fino se comprueba abajo, ítem por ítem.
            'respuestas.*.opcion' => ['nullable'],
            'respuestas.*.observacion' => ['nullable', 'string', 'max:500'],
        ], ['fecha.before_or_equal' => 'Una evaluación no puede tener fecha futura.']);

        $faltan = [];
        $respuestas = [];
        foreach ($formato['secciones'] as $s) {
            foreach ($s['items'] as $item) {
                $r = $datos['respuestas'][$item['key']] ?? null;
                $opcion = $r['opcion'] ?? null;
                $valida = $opcion === 'na' || (is_numeric($opcion) && isset($item['opciones'][(int) $opcion]));
                if (! $valida) {
                    $faltan[] = $item['texto'];

                    continue;
                }
                $respuestas[$item['key']] = [
                    'opcion' => $opcion === 'na' ? 'na' : (int) $opcion,
                    'observacion' => $r['observacion'] ?? null,
                ];
            }
        }

        if ($faltan) {
            throw ValidationException::withMessages([
                'respuestas' => count($faltan) === 1
                    ? "Falta responder: «{$faltan[0]}»."
                    : 'Faltan '.count($faltan).' criterios por responder, empezando por «'.$faltan[0].'».',
            ]);
        }

        $datos['respuestas'] = $respuestas;

        return $datos;
    }

    private function catalogos(): array
    {
        return [
            'tipos' => PesvContractor::TIPOS,
            'documentos' => ContractorDocument::TIPOS,
            'requeridos' => ContractorDocument::REQUERIDOS,
            'formatos' => EvaluacionesContratistas::formatos(),
            'resultados' => EvaluacionesContratistas::RESULTADOS,
            'umbrales' => [
                'confiable' => EvaluacionesContratistas::CONFIABLE,
                'regular' => EvaluacionesContratistas::REGULAR,
                'seleccion' => EvaluacionesContratistas::UMBRAL_SELECCION,
                'meses' => EvaluacionesContratistas::MESES_ENTRE_EVALUACIONES,
            ],
        ];
    }
}
