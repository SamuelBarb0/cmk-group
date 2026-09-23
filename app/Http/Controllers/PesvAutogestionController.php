<?php

namespace App\Http\Controllers;

use App\Models\PesvAutogestion;
use App\Models\PesvPlan;
use App\Services\Reportes\InformePdf;
use App\Services\Reportes\InformeWord;
use App\Services\Reportes\ReporteAutogestion;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * PESV · paso 20: reporte de autogestión anual ante la entidad verificadora.
 *
 * La pantalla muestra el reporte armado con los datos del módulo, lo que
 * falta, los pocos datos que hay que escribir a mano y la constancia de
 * radicación. Se descarga en Word (para ajustar) o PDF (para radicar).
 *
 * Permisos: ver y descargar -> pesv.view | editar -> pesv.manage
 */
class PesvAutogestionController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ReporteAutogestion $reporte,
    ) {}

    public function index(Request $request): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('pesv/autogestion', ['needsClient' => true]);
        }

        $anio = $this->anio($request);
        $datos = $this->datos($anio);
        $plan = $this->plan();

        return Inertia::render('pesv/autogestion', [
            'needsClient' => false,
            'anio' => $anio,
            'informe' => $this->reporte->generar($this->context->get(), $plan, $datos, $anio, $request->user()->name),
            'datos' => [
                ...$datos->only(['lider_email', 'objetivos_siguiente', 'programas_siguiente', 'analisis', 'entidad_verificadora', 'radicado']),
                'auditores' => $datos->auditores ?? [],
                'reportado_at' => $datos->reportado_at?->toDateString(),
            ],
            'entidades' => PesvAutogestion::ENTIDADES,
            'entidadSugerida' => $plan->misionalidad === 1 ? 'supertransporte' : 'mintrabajo',
            'historial' => PesvAutogestion::orderByDesc('anio')->get(['anio', 'reportado_at', 'entidad_verificadora', 'radicado'])
                ->map(fn (PesvAutogestion $a) => [...$a->only(['anio', 'entidad_verificadora', 'radicado']), 'reportado_at' => $a->reportado_at?->toDateString()]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($this->context->has(), 404);
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'between:2022,2100'],
            'lider_email' => ['nullable', 'email', 'max:255'],
            'auditores' => ['nullable', 'array', 'max:10'],
            'auditores.*.nombre' => ['required', 'string', 'max:255'],
            'auditores.*.cargo' => ['nullable', 'string', 'max:255'],
            'auditores.*.email' => ['nullable', 'email', 'max:255'],
            'objetivos_siguiente' => ['nullable', 'string', 'max:5000'],
            'programas_siguiente' => ['nullable', 'string', 'max:5000'],
            'analisis' => ['nullable', 'string', 'max:10000'],
            'entidad_verificadora' => ['nullable', Rule::in(array_keys(PesvAutogestion::ENTIDADES))],
            'reportado_at' => ['nullable', 'date', 'before_or_equal:today'],
            'radicado' => ['nullable', 'string', 'max:255'],
        ]);
        $datos['auditores'] = array_values(array_map(
            fn ($a) => ['nombre' => trim($a['nombre']), 'cargo' => $a['cargo'] ?? null, 'email' => $a['email'] ?? null],
            $datos['auditores'] ?? [],
        ));

        PesvAutogestion::updateOrCreate(['anio' => $datos['anio']], $datos);

        return back()->with('success', "Reporte de autogestión {$datos['anio']} guardado.");
    }

    public function descargar(Request $request): BinaryFileResponse
    {
        abort_unless($this->context->has(), 404);
        $request->validate(['formato' => ['required', Rule::in(['word', 'pdf'])]]);
        $anio = $this->anio($request);
        $datos = $this->datos($anio);
        $informe = $this->reporte->generar($this->context->get(), $this->plan(), $datos, $anio, $request->user()->name);

        $ruta = $request->input('formato') === 'pdf'
            ? app(InformePdf::class)->exportar($informe, $datos->analisis)
            : app(InformeWord::class)->exportar($informe, $datos->analisis);

        return response()->download($ruta, basename($ruta))->deleteFileAfterSend();
    }

    /** En enero se reporta el año que acaba de cerrar; el resto del año se prepara el actual. */
    private function anio(Request $request): int
    {
        $anio = $request->integer('anio');

        return $anio >= 2022 && $anio <= (int) now()->year ? $anio : ((int) now()->month === 1 ? (int) now()->year - 1 : (int) now()->year);
    }

    /** Datos a mano del año; si aún no hay, arranca con los del último reporte (el líder y los auditores suelen repetirse). */
    private function datos(int $anio): PesvAutogestion
    {
        $datos = PesvAutogestion::firstWhere('anio', $anio);
        if ($datos) {
            return $datos;
        }
        $anterior = PesvAutogestion::where('anio', '<', $anio)->orderByDesc('anio')->first();

        return new PesvAutogestion([
            'anio' => $anio,
            'lider_email' => $anterior?->lider_email,
            'auditores' => $anterior?->auditores,
            'entidad_verificadora' => $anterior?->entidad_verificadora,
        ]);
    }

    private function plan(): PesvPlan
    {
        return PesvPlan::firstOrCreate([], ['nivel' => 'basico']);
    }
}
