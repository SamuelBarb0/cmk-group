<?php

namespace App\Http\Controllers;

use App\Models\IpercRow;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Matriz IPERC (GTC 45) del cliente activo.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class IpercController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('iperc/index', [
                'needsClient' => true,
                'rows' => [],
                'stats' => ['total' => 0, 'no_aceptables' => 0, 'solo_epp' => 0],
            ]);
        }

        // Los riesgos más altos primero. Se ordena con un CASE y no con FIELD()
        // porque FIELD() solo existe en MySQL: en SQLite (los tests) y en
        // PostgreSQL (producción) la consulta reventaba. Mismo criterio que
        // PesvRouteController, que ya lo tenía bien.
        $rows = IpercRow::orderByRaw(
            "CASE nivel_riesgo WHEN 'I' THEN 1 WHEN 'II' THEN 2 WHEN 'III' THEN 3 ELSE 4 END"
        )
            ->orderByDesc('nr')
            ->get();

        return Inertia::render('iperc/index', [
            'needsClient' => false,
            'rows' => $rows,
            'stats' => [
                'total' => $rows->count(),
                'no_aceptables' => $rows->whereIn('nivel_riesgo', ['I', 'II'])->count(),
                // Peligros cuyo único control propuesto es el EPP. Es la
                // observación que más se repite en auditoría, y ahora se puede
                // ver antes de que la levante el auditor.
                'solo_epp' => $rows->where('solo_epp', true)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de registrar peligros.']);
        }

        IpercRow::create($this->validated($request));

        return back()->with('success', 'Peligro agregado a la matriz IPERC.');
    }

    public function update(Request $request, IpercRow $peligro): RedirectResponse
    {
        $peligro->update($this->validated($request));

        return back()->with('success', 'Peligro actualizado.');
    }

    public function destroy(IpercRow $peligro): RedirectResponse
    {
        $peligro->delete();

        return back()->with('success', 'Peligro eliminado.');
    }

    private function validated(Request $request): array
    {
        return $request->validate(self::reglas());
    }

    /**
     * Reglas del formulario. Públicas y estáticas porque la importación
     * asistida valida cada fila con ESTAS mismas reglas.
     *
     * @return array<string, mixed>
     */
    public static function reglas(): array
    {
        return [
            'proceso' => ['required', 'string', 'max:255'],
            'zona' => ['nullable', 'string', 'max:255'],
            'actividad' => ['required', 'string', 'max:255'],
            'tarea' => ['nullable', 'string', 'max:255'],
            'rutinaria' => ['boolean'],
            'clasificacion' => ['required', 'string', 'max:60'],
            'peligro' => ['required', 'string', 'max:255'],
            'efectos' => ['nullable', 'string', 'max:1000'],
            'peor_consecuencia' => ['nullable', 'string', 'max:255'],
            'control_fuente' => ['nullable', 'string', 'max:255'],
            'control_medio' => ['nullable', 'string', 'max:255'],
            'control_individuo' => ['nullable', 'string', 'max:255'],
            'nd' => ['required', Rule::in([0, 2, 6, 10])],
            'ne' => ['required', Rule::in([1, 2, 3, 4])],
            'nc' => ['required', Rule::in([10, 25, 60, 100])],
            'criterio_controles' => ['nullable', 'string', 'max:1000'],

            // Jerarquía de controles (GTC 45). Cada escalón por separado: es lo
            // que permite detectar una matriz que solo propone EPP.
            'med_eliminacion' => ['nullable', 'string', 'max:1000'],
            'med_sustitucion' => ['nullable', 'string', 'max:1000'],
            'med_ingenieria' => ['nullable', 'string', 'max:1000'],
            'med_administrativos' => ['nullable', 'string', 'max:1000'],
            'med_epp' => ['nullable', 'string', 'max:1000'],

            // Campo libre anterior a la jerarquía. Se conserva como notas para
            // no perder lo que los consultores ya escribieron ahí.
            'medidas' => ['nullable', 'string', 'max:1000'],
            'expuestos' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
