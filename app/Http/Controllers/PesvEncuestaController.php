<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PesvMobilitySurvey;
use App\Models\PesvPlan;
use App\Support\Pesv\EncuestaMovilidad;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Encuesta de movilidad del PESV (paso 5): enlace público para que los
 * trabajadores la respondan, respuestas, tabulación (RE-SST-37) y el
 * análisis del diagnóstico.
 *
 * Permisos: ver -> pesv.view | gestionar enlace y respuestas -> pesv.manage
 */
class PesvEncuestaController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('pesv/encuesta', ['needsClient' => true]);
        }

        $plan = $this->plan();
        $anio = $request->integer('anio') ?: (int) now()->year;
        $respuestas = PesvMobilitySurvey::whereYear('fecha', $anio)->orderByDesc('fecha')->orderByDesc('id')->get();
        $activos = Employee::where('is_active', true)->count();

        return Inertia::render('pesv/encuesta', [
            'needsClient' => false,
            'anio' => $anio,
            'enlace' => [
                'url' => $plan->encuesta_token ? route('encuesta.movilidad', $plan->encuesta_token) : null,
                'activa' => $plan->encuesta_activa,
            ],
            'respuestas' => $respuestas->map(fn (PesvMobilitySurvey $r) => [
                'id' => $r->id, 'fecha' => $r->fecha->toDateString(), 'nombre' => $r->nombre, 'documento' => $r->documento,
                'origen' => $r->origen, 'vinculado' => $r->employee_id !== null,
                'rol' => implode(', ', (array) ($r->respuestas['rol'] ?? [])),
            ]),
            'cobertura' => ['respuestas' => $respuestas->count(), 'colaboradores' => $activos],
            'tabulacion' => EncuestaMovilidad::tabular($respuestas->pluck('respuestas')),
            'secciones' => EncuestaMovilidad::secciones(),
            'analisis' => $plan->diagnostico_analisis,
        ]);
    }

    /** Crea (o renueva, invalidando el anterior) el enlace público y lo activa. */
    public function enlace(): RedirectResponse
    {
        abort_unless($this->context->has(), 404);
        $this->plan()->update(['encuesta_token' => Str::random(40), 'encuesta_activa' => true]);

        return back()->with('success', 'Enlace de la encuesta generado. El enlace anterior, si existía, ya no funciona.');
    }

    public function alternarEnlace(): RedirectResponse
    {
        abort_unless($this->context->has(), 404);
        $plan = $this->plan();
        abort_if($plan->encuesta_token === null, 404);
        $plan->update(['encuesta_activa' => ! $plan->encuesta_activa]);

        return back()->with('success', $plan->encuesta_activa ? 'Encuesta abierta.' : 'Encuesta cerrada: el enlace ya no recibe respuestas.');
    }

    /** El consultor la diligencia por el trabajador (entrevista, formato en papel…). */
    public function responder(Request $request): RedirectResponse
    {
        abort_unless($this->context->has(), 404);
        self::guardar($request, 'consultor');

        return back()->with('success', 'Respuesta registrada.');
    }

    public function borrar(PesvMobilitySurvey $respuesta): RedirectResponse
    {
        $respuesta->delete();

        return back()->with('success', 'Respuesta eliminada.');
    }

    public function analisis(Request $request): RedirectResponse
    {
        abort_unless($this->context->has(), 404);
        $datos = $request->validate(['analisis' => ['nullable', 'string', 'max:20000']]);
        $this->plan()->update(['diagnostico_analisis' => $datos['analisis'] ?? null]);

        return back()->with('success', 'Análisis del diagnóstico guardado.');
    }

    /**
     * Guarda una respuesta para la empresa del contexto. Una persona responde
     * una vez por año: si vuelve a responder, se reemplaza la anterior.
     */
    public static function guardar(Request $request, string $origen): PesvMobilitySurvey
    {
        [$respuestas, $errores] = EncuestaMovilidad::sanear((array) $request->input('respuestas', []));
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        $documento = preg_replace('/\D+/', '', (string) $respuestas['documento']) ?: $respuestas['documento'];
        $empleado = Employee::where('numero_documento', $documento)->first();
        $existente = PesvMobilitySurvey::where('documento', $documento)->whereYear('fecha', now()->year)->first();

        $datos = [
            'employee_id' => $empleado?->id,
            'fecha' => now()->toDateString(),
            'nombre' => $respuestas['nombre'],
            'documento' => $documento,
            'respuestas' => $respuestas,
            'origen' => $origen,
        ];

        return $existente ? tap($existente)->update($datos) : PesvMobilitySurvey::create($datos);
    }

    private function plan(): PesvPlan
    {
        return PesvPlan::firstOrCreate([], ['nivel' => 'basico']);
    }
}
