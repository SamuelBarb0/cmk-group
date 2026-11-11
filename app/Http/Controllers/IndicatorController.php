<?php

namespace App\Http\Controllers;

use App\Models\Indicator;
use App\Models\IndicatorGoal;
use App\Models\IndicatorReading;
use App\Services\Indicadores\Tablero;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard de Indicadores del SGI de la empresa cliente activa.
 *
 * Motor genérico: valor = (numerador / denominador) × constante. Trae los
 * presets legales globales (Res. 0312 / Dec. 1072) + los indicadores propios
 * del cliente, con captura mensual y semáforo contra la meta.
 *
 * Permisos: ver -> sst.view | diligenciar/gestionar -> sst.manage
 */
class IndicatorController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Tablero $tablero): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('indicadores/index', [
                'needsClient' => true,
                'indicators' => [],
                'anio' => (int) now()->year,
            ]);
        }

        $anio = (int) now()->year;

        return Inertia::render('indicadores/index', [
            'needsClient' => false,
            // El mismo cálculo que usa el dashboard (Tablero): nunca cuentan distinto.
            'indicators' => $tablero->indicadores($anio),
            'anio' => $anio,
        ]);
    }

    /** Guarda las lecturas mensuales (numerador/denominador) de un indicador. */
    public function save(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de registrar indicadores.']);
        }

        $data = $request->validate([
            'indicator_id' => ['required', 'integer', 'exists:indicators,id'],
            'anio' => ['required', 'integer', 'between:2000,2100'],
            'lecturas' => ['required', 'array'],
            'lecturas.*.mes' => ['required', 'integer', 'between:1,12'],
            'lecturas.*.numerador' => ['nullable', 'numeric', 'min:0'],
            'lecturas.*.denominador' => ['nullable', 'numeric', 'min:0'],
        ]);

        foreach ($data['lecturas'] as $l) {
            $clave = ['indicator_id' => $data['indicator_id'], 'anio' => $data['anio'], 'mes' => $l['mes']];

            // Mes con los dos campos vacíos = sin medir: se borra la lectura.
            // Antes quedaba un 0/0, que no altera el promedio (calcular()
            // lo descarta) pero en pantalla parecía un mes medido en cero.
            if (($l['numerador'] ?? null) === null && ($l['denominador'] ?? null) === null) {
                IndicatorReading::where($clave)->delete();

                continue;
            }

            IndicatorReading::updateOrCreate(
                $clave,
                ['numerador' => $l['numerador'] ?? 0, 'denominador' => $l['denominador'] ?? 0],
            );
        }

        return back()->with('success', 'Indicador actualizado.');
    }

    /** Fija (o restaura) la meta propia del cliente para un indicador preset. */
    public function goal(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de ajustar metas.']);
        }

        $data = $request->validate([
            'indicator_id' => ['required', 'integer', 'exists:indicators,id'],
            'meta' => ['nullable', 'numeric', 'min:0'],
        ]);

        $indicator = Indicator::findOrFail($data['indicator_id']);

        if ($indicator->tenant_id !== null) {
            // Indicador propio: su meta vive en el indicador, no hay override.
            return back()->withErrors(['meta' => 'La meta de un indicador propio se edita en el propio indicador.']);
        }

        if ($data['meta'] === null) {
            IndicatorGoal::where('indicator_id', $indicator->id)->delete(); // scoped al tenant activo

            return back()->with('success', "Meta legal restaurada para «{$indicator->nombre}».");
        }

        IndicatorGoal::updateOrCreate(
            ['indicator_id' => $indicator->id],
            ['meta' => $data['meta']],
        );

        return back()->with('success', "Meta de «{$indicator->nombre}» ajustada para este cliente.");
    }

    /** Crea un indicador propio del cliente. */
    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de crear indicadores.']);
        }

        $data = $request->validate($this->reglasIndicador());

        Indicator::create([
            ...$data,
            'tenant_id' => $this->context->id(),
            'es_legal' => false,
            'orden' => 900,
        ]);

        return back()->with('success', "Indicador «{$data['nombre']}» creado.");
    }

    /** Elimina un indicador propio del cliente (nunca los presets legales). */
    /**
     * Edita un indicador PROPIO del cliente. Los legales no: su definición
     * viene de la norma y lo único ajustable es la meta (ver goal()).
     */
    public function update(Request $request, Indicator $indicator): RedirectResponse
    {
        if (! $this->context->has() || $indicator->tenant_id !== $this->context->id()) {
            return back()->withErrors(['indicator' => 'Solo se editan los indicadores propios de este cliente.']);
        }

        $indicator->update($request->validate($this->reglasIndicador()));

        return back()->with('success', "Indicador «{$indicator->nombre}» actualizado.");
    }

    /** Las mismas reglas al crear y al editar: si divergen, uno acepta lo que el otro rechaza. */
    private function reglasIndicador(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:20'],
            'nombre' => ['required', 'string', 'max:255'],
            'categoria' => ['required', Rule::in(['SST', 'HSEQ', 'PESV', 'Proceso'])],
            'numerador_label' => ['required', 'string', 'max:255'],
            'denominador_label' => ['required', 'string', 'max:255'],
            'constante' => ['required', 'integer', 'min:1', 'max:1000000'],
            'unidad' => ['required', Rule::in(['%', 'tasa'])],
            'sentido' => ['required', Rule::in(['asc', 'desc'])],
            'meta' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function destroy(Indicator $indicator): RedirectResponse
    {
        if ($indicator->tenant_id !== $this->context->id()) {
            return back()->withErrors(['indicator' => 'No puedes eliminar este indicador.']);
        }

        $indicator->delete();

        return back()->with('success', 'Indicador eliminado.');
    }
}
