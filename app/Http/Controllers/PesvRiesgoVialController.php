<?php

namespace App\Http\Controllers;

use App\Models\PesvRoadRisk;
use App\Support\Pesv\RiesgosViales;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Matriz de riesgos viales del PESV (paso 6, RE-SST-45).
 *
 * Indicador 3 de la Tabla 10 de la Res. 40595, calculado de la matriz:
 *  - RSVI = riesgos identificados al final del año − al inicio.
 *  - GRV = riesgos de valoración alta (críticos) al final − al inicio.
 * «Al inicio / al final» = vigentes en esa fecha: identificados antes y sin
 * cerrar. Un GRV negativo es bueno: hay menos riesgos críticos abiertos.
 */
class PesvRiesgoVialController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('pesv/riesgos-viales', ['needsClient' => true]);
        }

        $anio = $request->integer('anio') ?: (int) now()->year;
        $riesgos = PesvRoadRisk::orderByDesc('valor')->orderBy('desempeno')->orderBy('id')->get();

        return Inertia::render('pesv/riesgos-viales', [
            'needsClient' => false,
            'anio' => $anio,
            'riesgos' => $riesgos->map(fn (PesvRoadRisk $r) => [
                ...$r->only(['id', 'desempeno', 'factor', 'perfil', 'cargo', 'rol_via', 'tipo_vehiculo', 'exposicion', 'probabilidad',
                    'valor', 'nivel', 'accion', 'eficaz', 'observaciones']),
                'controles' => (object) ($r->controles ?? []),
                'lineas' => $r->lineas ?? [],
                'fecha_identificacion' => $r->fecha_identificacion->toDateString(),
                'fecha_cierre' => $r->fecha_cierre?->toDateString(),
            ]),
            'indicadores' => self::indicadores($riesgos, $anio),
            'catalogo' => [
                'factores' => RiesgosViales::FACTORES, 'roles' => RiesgosViales::ROLES, 'exposicion' => RiesgosViales::EXPOSICION,
                'probabilidad' => RiesgosViales::PROBABILIDAD, 'acciones' => RiesgosViales::ACCIONES,
                'controles' => RiesgosViales::CONTROLES, 'lineas' => RiesgosViales::LINEAS,
            ],
        ]);
    }

    /** @return array<string, int> */
    public static function indicadores($riesgos, int $anio): array
    {
        $inicio = CarbonImmutable::create($anio, 1, 1)->subDay();   // cierre del año anterior
        $fin = min(CarbonImmutable::create($anio, 12, 31), CarbonImmutable::today());
        $en = fn ($fecha, bool $soloCriticos) => $riesgos->filter(fn (PesvRoadRisk $r) => $r->vigenteEn($fecha) && (! $soloCriticos || $r->nivel === 'critico'))->count();

        $ri = [$en($inicio, false), $en($fin, false)];
        $rva = [$en($inicio, true), $en($fin, true)];

        return [
            'ri_inicio' => $ri[0], 'ri_fin' => $ri[1], 'rsvi' => $ri[1] - $ri[0],
            'rva_inicio' => $rva[0], 'rva_fin' => $rva[1], 'grv' => $rva[1] - $rva[0],
            'criticos_abiertos' => $riesgos->filter(fn ($r) => $r->nivel === 'critico' && $r->fecha_cierre === null)->count(),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->context->has(), 404);
        PesvRoadRisk::create($this->validar($request));

        return back()->with('success', 'Riesgo agregado a la matriz.');
    }

    public function update(Request $request, PesvRoadRisk $riesgo): RedirectResponse
    {
        $riesgo->update($this->validar($request));

        return back()->with('success', 'Riesgo actualizado.');
    }

    public function destroy(PesvRoadRisk $riesgo): RedirectResponse
    {
        $riesgo->delete();

        return back()->with('success', 'Riesgo eliminado de la matriz.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $request): array
    {
        $datos = $request->validate([
            'desempeno' => ['required', Rule::in(array_keys(RiesgosViales::FACTORES))],
            'factor' => ['required', 'string', 'max:255'],
            'perfil' => ['nullable', 'string', 'max:255'],
            'cargo' => ['nullable', 'string', 'max:255'],
            'rol_via' => ['nullable', Rule::in(array_keys(RiesgosViales::ROLES))],
            'tipo_vehiculo' => ['nullable', 'string', 'max:100'],
            'exposicion' => ['required', 'integer', 'between:1,3'],
            'probabilidad' => ['required', 'integer', 'between:1,3'],
            'accion' => ['nullable', Rule::in(array_keys(RiesgosViales::ACCIONES))],
            'controles' => ['nullable', 'array'],
            'controles.*' => ['nullable', 'string', 'max:1000'],
            'lineas' => ['nullable', 'array'],
            'lineas.*' => [Rule::in(array_keys(RiesgosViales::LINEAS))],
            'eficaz' => ['nullable', 'boolean'],
            'fecha_identificacion' => ['required', 'date', 'before_or_equal:today'],
            'fecha_cierre' => ['nullable', 'date', 'after_or_equal:fecha_identificacion', 'before_or_equal:today'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], ['fecha_cierre.after_or_equal' => 'El cierre no puede ser anterior a la identificación.']);

        $datos['controles'] = collect($datos['controles'] ?? [])
            ->only(array_keys(RiesgosViales::CONTROLES))->map(fn ($v) => trim((string) $v))->filter()->all();
        $datos['lineas'] = array_values(array_unique($datos['lineas'] ?? []));

        return $datos;
    }
}
