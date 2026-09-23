<?php

namespace App\Http\Controllers;

use App\Models\PesvKmPeriodo;
use App\Models\PesvSiniestro;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registro y análisis estadístico de siniestros viales (paso 21) e
 * indicadores 1 y 2 de la Tabla 10 de la Res. 40595:
 *  - TSV(n) = siniestros del trimestre con nivel de pérdida n × 1.000.000 / km
 *    recorridos por la flota en el trimestre (y acumulado del año).
 *  - $SV(n) = costos directos + indirectos por nivel de pérdida.
 * Con la pirámide por nivel, la separación laboral / no laboral que pide el
 * paso 21 y la línea base (el año anterior).
 */
class PesvEstadisticaController extends Controller
{
    public const K = 1_000_000;

    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('pesv/estadistica', ['needsClient' => true]);
        }

        $anio = $request->integer('anio') ?: (int) now()->year;
        $delAnio = PesvSiniestro::whereYear('fecha', $anio)->get();
        $anterior = PesvSiniestro::whereYear('fecha', $anio - 1)->get();
        $km = PesvKmPeriodo::where('anio', $anio)->pluck('km', 'trimestre')->map(fn ($v) => (float) $v);

        return Inertia::render('pesv/estadistica', [
            'needsClient' => false,
            'anio' => $anio,
            'niveles' => PesvSiniestro::NIVELES_PERDIDA,
            'desplazamientos' => PesvSiniestro::DESPLAZAMIENTOS,
            'piramide' => $this->piramide($delAnio),
            'lineaBase' => $this->piramide($anterior),
            'trimestres' => self::trimestres($delAnio, $km),
            'porMes' => ['actual' => $this->porMes($delAnio), 'anterior' => $this->porMes($anterior)],
            'km' => (object) $km->all(),
        ]);
    }

    public function guardarKm(Request $request): RedirectResponse
    {
        abort_unless($this->context->has(), 404);
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'between:2000,2100'],
            'km' => ['array'],
            'km.*' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);
        foreach (range(1, 4) as $t) {
            $v = $datos['km'][$t] ?? null;
            if ($v === null || $v === '') {
                PesvKmPeriodo::where('anio', $datos['anio'])->where('trimestre', $t)->delete();
            } else {
                PesvKmPeriodo::updateOrCreate(['anio' => $datos['anio'], 'trimestre' => $t], ['km' => $v]);
            }
        }

        return back()->with('success', 'Kilómetros de la flota guardados.');
    }

    /**
     * Por nivel de pérdida (4 a 1) y por tipo de desplazamiento.
     *
     * @return array<int, array<string, int>>
     */
    private function piramide(Collection $siniestros): array
    {
        return collect([4, 3, 2, 1])->mapWithKeys(fn ($n) => [$n => collect(PesvSiniestro::DESPLAZAMIENTOS)->keys()
            ->mapWithKeys(fn ($d) => [$d => $siniestros->filter(fn ($s) => $s->nivel() === $n && $s->tipo_desplazamiento === $d)->count()])
            ->all()])->all();
    }

    /**
     * TSV y $SV por trimestre y nivel, y el acumulado. Sin kilómetros del
     * trimestre la TSV no se puede calcular: sale null, no cero.
     *
     * @return list<array<string, mixed>>
     */
    public static function trimestres(Collection $siniestros, Collection $km): array
    {
        $filas = [];
        foreach ([1, 2, 3, 4, 'año'] as $t) {
            $sub = $t === 'año' ? $siniestros : $siniestros->filter(fn ($s) => (int) ceil($s->fecha->month / 3) === $t);
            $kmT = $t === 'año' ? ($km->count() ? $km->sum() : null) : $km->get($t);
            $filas[] = [
                'trimestre' => $t,
                'km' => $kmT,
                'niveles' => collect([4, 3, 2, 1])->mapWithKeys(function ($n) use ($sub, $kmT) {
                    $deNivel = $sub->filter(fn ($s) => $s->nivel() === $n);

                    return [$n => [
                        'siniestros' => $deNivel->count(),
                        'tsv' => $kmT ? round($deNivel->count() * self::K / $kmT, 2) : null,
                        'costo' => round($deNivel->sum(fn ($s) => $s->costoTotal()), 2),
                    ]];
                })->all(),
            ];
        }

        return $filas;
    }

    /** @return list<int> */
    private function porMes(Collection $siniestros): array
    {
        $serie = array_fill(0, 12, 0);
        foreach ($siniestros as $s) {
            $serie[$s->fecha->month - 1]++;
        }

        return $serie;
    }
}
