<?php

namespace App\Http\Controllers;

use App\Models\CommunicationLog;
use App\Models\CommunicationPlanItem;
use App\Models\Norm;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M19 — Comunicaciones de la empresa activa: matriz de comunicaciones y
 * registro de comunicaciones enviadas y recibidas.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class ComunicacionController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('comunicaciones/index', [
                'needsClient' => true, 'matriz' => [], 'registro' => [],
                'stats' => ['matriz' => 0, 'registradas' => 0, 'pendientes' => 0, 'vencidas' => 0],
            ]);
        }

        $registro = CommunicationLog::query()->orderByDesc('fecha')->orderByDesc('id')->get();

        return Inertia::render('comunicaciones/index', [
            'needsClient' => false,
            'matriz' => CommunicationPlanItem::query()->orderBy('tipo')->orderBy('orden')->orderBy('id')->get(),
            'registro' => $registro,
            'stats' => [
                'matriz' => CommunicationPlanItem::query()->count(),
                'registradas' => $registro->count(),
                'pendientes' => $registro->where('pendiente', true)->count(),
                'vencidas' => $registro->where('respuesta_vencida', true)->count(),
            ],
        ]);
    }

    /** Carga la matriz base. Solo si la empresa no tiene ninguna fila: no pisa la suya. */
    public function base(): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }
        if (CommunicationPlanItem::query()->exists()) {
            return back()->withErrors(['matriz' => 'La empresa ya tiene matriz de comunicaciones: la base solo se carga sobre una matriz vacía.']);
        }

        foreach (CommunicationPlanItem::BASE as $i => [$tipo, $que, $cuando, $aQuien, $como, $responsable, $sistemas]) {
            CommunicationPlanItem::create([
                'tipo' => $tipo, 'que' => $que, 'cuando' => $cuando, 'a_quien' => $aQuien,
                'como' => $como, 'responsable' => $responsable, 'sistemas' => $sistemas, 'orden' => $i + 1,
            ]);
        }

        return back()->with('success', count(CommunicationPlanItem::BASE).' comunicaciones base cargadas. Ajusta responsables y medios a la empresa.');
    }

    public function storeItem(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        CommunicationPlanItem::create($this->validatedItem($request) + ['orden' => (CommunicationPlanItem::query()->max('orden') ?? 0) + 1]);

        return back()->with('success', 'Comunicación agregada a la matriz.');
    }

    public function updateItem(Request $request, CommunicationPlanItem $item): RedirectResponse
    {
        $item->update($this->validatedItem($request));

        return back()->with('success', 'Matriz actualizada.');
    }

    public function destroyItem(CommunicationPlanItem $item): RedirectResponse
    {
        $item->delete();

        return back()->with('success', 'Comunicación quitada de la matriz.');
    }

    public function storeLog(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        CommunicationLog::create($this->validatedLog($request));

        return back()->with('success', 'Comunicación registrada.');
    }

    public function updateLog(Request $request, CommunicationLog $registro): RedirectResponse
    {
        $registro->update($this->validatedLog($request));

        return back()->with('success', 'Registro actualizado.');
    }

    public function destroyLog(CommunicationLog $registro): RedirectResponse
    {
        $registro->delete();

        return back()->with('success', 'Registro eliminado.');
    }

    private function validatedItem(Request $request): array
    {
        return $request->validate([
            'tipo' => ['required', Rule::in(CommunicationPlanItem::TIPOS)],
            'que' => ['required', 'string', 'max:255'],
            'cuando' => ['required', 'string', 'max:255'],
            'a_quien' => ['required', 'string', 'max:255'],
            'como' => ['required', 'string', 'max:255'],
            'responsable' => ['required', 'string', 'max:255'],
            'sistemas' => ['required', 'array', 'min:1'],
            'sistemas.*' => [Rule::in(Norm::SISTEMAS)],
        ]);
    }

    private function validatedLog(Request $request): array
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date'],
            'tipo' => ['required', Rule::in(CommunicationPlanItem::TIPOS)],
            'direccion' => ['required', Rule::in(CommunicationLog::DIRECCIONES)],
            'parte_interesada' => ['required', 'string', 'max:255'],
            'asunto' => ['required', 'string', 'max:255'],
            'medio' => ['nullable', 'string', 'max:255'],
            'responsable' => ['nullable', 'string', 'max:255'],
            'detalle' => ['nullable', 'string', 'max:4000'],
            'requiere_respuesta' => ['boolean'],
            'fecha_limite_respuesta' => ['nullable', 'date', 'after_or_equal:fecha'],
            // Una respuesta no puede ser anterior a la comunicación.
            'fecha_respuesta' => ['nullable', 'date', 'after_or_equal:fecha'],
            'respuesta' => ['nullable', 'string', 'max:4000'],
            // Pasa por el filtro de la empresa: no se enlaza con la matriz de otra.
            'communication_plan_id' => ['nullable', 'integer', Rule::exists('communication_plan', 'id')->where('tenant_id', $this->context->id())],
        ]);

        // Sin respuesta pedida no hay plazo ni respuesta que guardar.
        if (! ($datos['requiere_respuesta'] ?? false)) {
            $datos['fecha_limite_respuesta'] = $datos['fecha_respuesta'] = $datos['respuesta'] = null;
        }

        return $datos;
    }
}
