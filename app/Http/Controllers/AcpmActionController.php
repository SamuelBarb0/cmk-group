<?php

namespace App\Http\Controllers;

use App\Models\AcpmAction;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ACPM — acciones correctivas, preventivas y de mejora del cliente activo.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class AcpmActionController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('acpm/index', [
                'needsClient' => true,
                'acciones' => [],
                'stats' => ['total' => 0, 'pendientes' => 0, 'vencidas' => 0, 'sin_verificar' => 0],
                'catalogos' => $this->catalogos(),
            ]);
        }

        // Las pendientes primero y, dentro de ellas, la más próxima a vencer:
        // es el orden en que el consultor las tiene que atacar.
        $acciones = AcpmAction::query()
            // CASE y no FIELD(): FIELD() solo existe en MySQL y reventaría en
            // SQLite (los tests) y en PostgreSQL (producción).
            ->orderByRaw("CASE estado WHEN 'abierta' THEN 1 WHEN 'en_proceso' THEN 2 ELSE 3 END")
            ->orderBy('fecha_limite')
            ->get();

        return Inertia::render('acpm/index', [
            'needsClient' => false,
            'acciones' => $acciones,
            'stats' => [
                'total' => $acciones->count(),
                'pendientes' => $acciones->whereIn('estado', ['abierta', 'en_proceso'])->count(),
                'vencidas' => $acciones->where('vencida', true)->count(),
                // Cerradas a las que nadie verificó si la acción sirvió. Cerrar
                // no es lo mismo que resolver, y esta es la cifra que delata la
                // diferencia.
                'sin_verificar' => $acciones->where('estado', 'cerrada')->whereNull('eficaz')->count(),
            ],
            'catalogos' => $this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de registrar acciones.']);
        }

        AcpmAction::create($this->validated($request));

        return back()->with('success', 'Acción registrada.');
    }

    public function update(Request $request, AcpmAction $accion): RedirectResponse
    {
        $accion->update($this->validated($request));

        return back()->with('success', 'Acción actualizada.');
    }

    public function destroy(AcpmAction $accion): RedirectResponse
    {
        $accion->delete();

        return back()->with('success', 'Acción eliminada.');
    }

    private function catalogos(): array
    {
        return [
            'tipos' => AcpmAction::TIPOS,
            'estados' => AcpmAction::ESTADOS,
            'origenes' => AcpmAction::ORIGENES,
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'tipo' => ['required', Rule::in(AcpmAction::TIPOS)],
            'origen_tipo' => ['required', Rule::in(AcpmAction::ORIGENES)],
            'origen_id' => ['nullable', 'integer'],
            'hallazgo' => ['required', 'string', 'max:2000'],
            'causa' => ['nullable', 'string', 'max:2000'],
            'accion' => ['required', 'string', 'max:2000'],
            'responsable' => ['required', 'string', 'max:255'],
            'fecha_deteccion' => ['required', 'date'],
            // La fecha límite no puede ser anterior a la detección: una acción
            // que nace vencida no es un plazo, es un error de captura.
            'fecha_limite' => ['required', 'date', 'after_or_equal:fecha_deteccion'],
            'estado' => ['required', Rule::in(AcpmAction::ESTADOS)],
            'eficaz' => ['nullable', 'boolean'],
            'verificacion' => ['nullable', 'string', 'max:2000'],
            'fecha_verificacion' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
