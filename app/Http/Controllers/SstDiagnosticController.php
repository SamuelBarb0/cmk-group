<?php

namespace App\Http\Controllers;

use App\Models\SstDiagnostic;
use App\Models\SstDiagnosticItem;
use App\Models\SstStandard;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Diagnóstico de Estándares Mínimos del SG-SST (Resolución 0312 de 2019)
 * de la empresa cliente activa.
 *
 * Permisos: ver -> sst.view | diligenciar -> sst.manage
 */
class SstDiagnosticController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('diagnostico/index', [
                'needsClient' => true,
                'standards' => [],
                'diagnostico' => null,
            ]);
        }

        $diagnostic = SstDiagnostic::firstOrCreate([]);

        // Respuestas existentes indexadas por estándar.
        $respuestas = $diagnostic->items()->get()->keyBy('sst_standard_id');

        $standards = SstStandard::orderBy('orden')->get()->map(function (SstStandard $s) use ($respuestas) {
            $r = $respuestas->get($s->id);

            return [
                'id' => $s->id,
                'codigo' => $s->codigo,
                'ciclo' => $s->ciclo,
                'grupo' => $s->grupo,
                'peso_grupo' => $s->peso_grupo,
                'item' => $s->item,
                'valor' => (float) $s->valor,
                'estado' => $r?->estado ?? 'pendiente',
                'justificacion' => $r?->justificacion ?? '',
            ];
        });

        return Inertia::render('diagnostico/index', [
            'needsClient' => false,
            'standards' => $standards,
            'diagnostico' => [
                'id' => $diagnostic->id,
                'fecha' => $diagnostic->fecha?->format('Y-m-d'),
                'evaluador' => $diagnostic->evaluador,
                'puntaje' => (float) $diagnostic->puntaje,
                'clasificacion' => $diagnostic->clasificacion,
            ],
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de diligenciar el diagnóstico.']);
        }

        $data = $request->validate([
            'respuestas' => ['required', 'array'],
            'respuestas.*.standard_id' => ['required', 'integer', 'exists:sst_standards,id'],
            'respuestas.*.estado' => ['required', Rule::in(['cumple', 'no_cumple', 'no_aplica', 'pendiente'])],
            'respuestas.*.justificacion' => ['nullable', 'string', 'max:1000'],
        ]);

        $diagnostic = SstDiagnostic::firstOrCreate([]);

        foreach ($data['respuestas'] as $r) {
            SstDiagnosticItem::updateOrCreate(
                ['sst_diagnostic_id' => $diagnostic->id, 'sst_standard_id' => $r['standard_id']],
                ['estado' => $r['estado'], 'justificacion' => $r['justificacion'] ?? null],
            );
        }

        $diagnostic->fecha = now();
        $diagnostic->evaluador = $request->user()?->name;
        $diagnostic->save();
        $diagnostic->recalcular();

        return back()->with('success', "Diagnóstico guardado. Cumplimiento: {$diagnostic->puntaje}% ({$diagnostic->clasificacion}).");
    }
}
