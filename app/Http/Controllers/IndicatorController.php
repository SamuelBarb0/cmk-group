<?php

namespace App\Http\Controllers;

use App\Models\Indicator;
use App\Models\IndicatorReading;
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

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('indicadores/index', [
                'needsClient' => true,
                'indicators' => [],
                'anio' => (int) now()->year,
            ]);
        }

        $anio = (int) now()->year;
        $tenantId = $this->context->id();

        $indicators = Indicator::query()
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderBy('orden')->orderBy('id')->get();

        $readings = IndicatorReading::where('anio', $anio)->get()
            ->groupBy('indicator_id');

        $payload = $indicators->map(function (Indicator $ind) use ($readings) {
            $meses = [];
            $suma = 0;
            $conteo = 0;

            $porMes = ($readings->get($ind->id) ?? collect())->keyBy('mes');
            for ($m = 1; $m <= 12; $m++) {
                $r = $porMes->get($m);
                $num = $r ? (float) $r->numerador : 0.0;
                $den = $r ? (float) $r->denominador : 0.0;
                $valor = $r ? $ind->calcular($num, $den) : null;
                if ($valor !== null) {
                    $suma += $valor;
                    $conteo++;
                }
                $meses[$m] = ['numerador' => $num, 'denominador' => $den, 'valor' => $valor];
            }

            $promedio = $conteo > 0 ? round($suma / $conteo, 2) : null;

            return [
                'id' => $ind->id,
                'codigo' => $ind->codigo,
                'nombre' => $ind->nombre,
                'categoria' => $ind->categoria,
                'numerador_label' => $ind->numerador_label,
                'denominador_label' => $ind->denominador_label,
                'constante' => $ind->constante,
                'unidad' => $ind->unidad,
                'sentido' => $ind->sentido,
                'meta' => (float) $ind->meta,
                'es_legal' => $ind->es_legal,
                'propio' => $ind->tenant_id !== null,
                'meses' => $meses,
                'promedio' => $promedio,
                'cumple' => $ind->cumpleMeta($promedio),
            ];
        });

        return Inertia::render('indicadores/index', [
            'needsClient' => false,
            'indicators' => $payload,
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
            IndicatorReading::updateOrCreate(
                ['indicator_id' => $data['indicator_id'], 'anio' => $data['anio'], 'mes' => $l['mes']],
                ['numerador' => $l['numerador'] ?? 0, 'denominador' => $l['denominador'] ?? 0],
            );
        }

        return back()->with('success', 'Indicador actualizado.');
    }

    /** Crea un indicador propio del cliente. */
    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de crear indicadores.']);
        }

        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:20'],
            'nombre' => ['required', 'string', 'max:255'],
            'categoria' => ['required', Rule::in(['SST', 'HSEQ', 'PESV', 'Proceso'])],
            'numerador_label' => ['required', 'string', 'max:255'],
            'denominador_label' => ['required', 'string', 'max:255'],
            'constante' => ['required', 'integer', 'min:1', 'max:1000000'],
            'unidad' => ['required', Rule::in(['%', 'tasa'])],
            'sentido' => ['required', Rule::in(['asc', 'desc'])],
            'meta' => ['required', 'numeric', 'min:0'],
        ]);

        Indicator::create([
            ...$data,
            'tenant_id' => $this->context->id(),
            'es_legal' => false,
            'orden' => 900,
        ]);

        return back()->with('success', "Indicador «{$data['nombre']}» creado.");
    }

    /** Elimina un indicador propio del cliente (nunca los presets legales). */
    public function destroy(Indicator $indicator): RedirectResponse
    {
        if ($indicator->tenant_id !== $this->context->id()) {
            return back()->withErrors(['indicator' => 'No puedes eliminar este indicador.']);
        }

        $indicator->delete();

        return back()->with('success', 'Indicador eliminado.');
    }
}
