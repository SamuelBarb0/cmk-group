<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Training;
use App\Models\TrainingTopic;
use App\Services\TrainingRosterExporter;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Módulo de Capacitaciones del SGI del cliente activo.
 *
 * Biblioteca global de temas (presentaciones modelo de CMK, descargables) +
 * capacitaciones programadas/dictadas por la empresa con su registro de
 * asistencia (asistentes traídos de Empleados o manuales) exportable a Word.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class TrainingController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        return Inertia::render('capacitaciones/index', $this->sharedProps());
    }

    /** Abre una capacitación con sus asistentes para diligenciar. */
    public function show(Training $capacitacion): Response
    {
        $capacitacion->load('attendees');

        return Inertia::render('capacitaciones/index', array_merge($this->sharedProps(), [
            'open' => [
                'id' => $capacitacion->id,
                'training_topic_id' => $capacitacion->training_topic_id,
                'titulo' => $capacitacion->titulo,
                'categoria' => $capacitacion->categoria,
                'fecha' => $capacitacion->fecha?->toDateString(),
                'instructor' => $capacitacion->instructor,
                'modalidad' => $capacitacion->modalidad,
                'duracion_minutos' => $capacitacion->duracion_minutos,
                'lugar' => $capacitacion->lugar,
                'objetivo' => $capacitacion->objetivo,
                'estado' => $capacitacion->estado,
                'observaciones' => $capacitacion->observaciones,
                'attendees' => $capacitacion->attendees->map(fn ($a) => [
                    'employee_id' => $a->employee_id,
                    'nombres' => $a->nombres,
                    'numero_documento' => $a->numero_documento,
                    'cargo' => $a->cargo,
                    'asistio' => $a->asistio,
                ])->values(),
            ],
        ]));
    }

    /** Props compartidas por index() y show(): catálogo, capacitaciones y empleados. */
    private function sharedProps(): array
    {
        if (! $this->context->has()) {
            return [
                'needsClient' => true,
                'topics' => [],
                'trainings' => [],
                'employees' => [],
            ];
        }

        return [
            'needsClient' => false,
            'topics' => TrainingTopic::where('activo', true)->orderBy('orden')->orderBy('id')->get()
                ->map(fn (TrainingTopic $t) => [
                    'id' => $t->id,
                    'codigo' => $t->codigo,
                    'titulo' => $t->titulo,
                    'categoria' => $t->categoria,
                    'descripcion' => $t->descripcion,
                    'duracion_sugerida' => $t->duracion_sugerida,
                    'tiene_archivo' => $t->tieneArchivo(),
                ]),
            'trainings' => Training::with('attendees:id,training_id,asistio')
                ->latest('fecha')->latest('id')->get()
                ->map(fn (Training $t) => [
                    'id' => $t->id,
                    'titulo' => $t->titulo,
                    'categoria' => $t->categoria,
                    'fecha' => $t->fecha?->toDateString(),
                    'instructor' => $t->instructor,
                    'modalidad' => $t->modalidad,
                    'estado' => $t->estado,
                    'asistentes' => $t->attendees->count(),
                    'asistieron' => $t->attendees->where('asistio', true)->count(),
                ]),
            'employees' => Employee::where('is_active', true)->orderBy('nombres')->get(['id', 'nombres', 'apellidos', 'numero_documento', 'cargo'])
                ->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'nombre' => $e->nombre_completo,
                    'numero_documento' => $e->numero_documento,
                    'cargo' => $e->cargo,
                ]),
        ];
    }

    /** Programa una capacitación (opcionalmente a partir de un tema del catálogo). */
    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de programar capacitaciones.']);
        }

        $data = $request->validate([
            'training_topic_id' => ['nullable', 'integer', 'exists:training_topics,id'],
            'titulo' => ['nullable', 'string', 'max:255'],
        ]);

        $topic = filled($data['training_topic_id'] ?? null)
            ? TrainingTopic::find($data['training_topic_id'])
            : null;

        $training = Training::create([
            'training_topic_id' => $topic?->id,
            'titulo' => $topic?->titulo ?: ($data['titulo'] ?: 'Capacitación'),
            'categoria' => $topic?->categoria ?: 'SST',
            'fecha' => now()->toDateString(),
            'duracion_minutos' => $topic?->duracion_sugerida,
            'objetivo' => $topic?->descripcion,
            'estado' => 'programada',
            'creado_por' => $request->user()?->name,
        ]);

        return redirect()->route('capacitaciones.show', $training)->with('success', "Capacitación «{$training->titulo}» creada.");
    }

    /** Guarda los datos de la capacitación y su lista de asistentes. */
    public function update(Request $request, Training $capacitacion): RedirectResponse
    {
        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'fecha' => ['nullable', 'date'],
            'instructor' => ['nullable', 'string', 'max:255'],
            'modalidad' => ['required', Rule::in(['presencial', 'virtual'])],
            'duracion_minutos' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'lugar' => ['nullable', 'string', 'max:255'],
            'objetivo' => ['nullable', 'string', 'max:2000'],
            'estado' => ['required', Rule::in(['programada', 'realizada'])],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'attendees' => ['nullable', 'array'],
            'attendees.*.employee_id' => ['nullable', 'integer'],
            'attendees.*.nombres' => ['required', 'string', 'max:255'],
            'attendees.*.numero_documento' => ['nullable', 'string', 'max:40'],
            'attendees.*.cargo' => ['nullable', 'string', 'max:120'],
            'attendees.*.asistio' => ['boolean'],
        ]);

        $capacitacion->update([
            'titulo' => $data['titulo'],
            'fecha' => $data['fecha'] ?? null,
            'instructor' => $data['instructor'] ?? null,
            'modalidad' => $data['modalidad'],
            'duracion_minutos' => $data['duracion_minutos'] ?? null,
            'lugar' => $data['lugar'] ?? null,
            'objetivo' => $data['objetivo'] ?? null,
            'estado' => $data['estado'],
            'observaciones' => $data['observaciones'] ?? null,
        ]);

        // Sincroniza los asistentes: se reemplaza la lista completa.
        $capacitacion->attendees()->delete();
        foreach ($data['attendees'] ?? [] as $a) {
            $capacitacion->attendees()->create([
                'employee_id' => $a['employee_id'] ?? null,
                'nombres' => $a['nombres'],
                'numero_documento' => $a['numero_documento'] ?? null,
                'cargo' => $a['cargo'] ?? null,
                'asistio' => $a['asistio'] ?? true,
            ]);
        }

        return back()->with('success', 'Capacitación guardada.');
    }

    public function destroy(Training $capacitacion): RedirectResponse
    {
        $capacitacion->delete();

        return back()->with('success', 'Capacitación eliminada.');
    }

    /** Descarga el registro de asistencia en Word con membrete CMK. */
    public function export(Training $capacitacion, TrainingRosterExporter $exporter): BinaryFileResponse
    {
        $path = $exporter->export($capacitacion);

        return response()->download($path, basename($path))->deleteFileAfterSend();
    }

    /** Descarga la presentación (material) de un tema del catálogo. */
    public function material(TrainingTopic $tema): BinaryFileResponse
    {
        abort_unless($tema->tieneArchivo(), 404, 'Este tema no tiene material cargado.');

        $ext = pathinfo($tema->archivo, PATHINFO_EXTENSION);

        return response()->download(
            \Illuminate\Support\Facades\Storage::disk('local')->path($tema->archivo),
            \Illuminate\Support\Str::slug($tema->titulo).'.'.$ext,
        );
    }
}
