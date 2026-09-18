<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PpeAssignment;
use App\Models\PpeDelivery;
use App\Models\PpeItem;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * EPP del cliente activo: catálogo, matriz por cargo y entregas.
 *
 * Un solo controlador para las tres tablas porque son una sola pantalla con
 * tres pestañas: separarlo en tres obligaría a tres peticiones para pintar algo
 * que siempre se mira junto.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class PpeController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('epp/index', [
                'needsClient' => true,
                'items' => [],
                'matriz' => [],
                'entregas' => [],
                'empleados' => [],
                'cargos' => [],
                'stats' => ['items' => 0, 'cargos_cubiertos' => 0, 'entregas' => 0, 'sin_firmar' => 0],
                'catalogos' => $this->catalogos(),
            ]);
        }

        $entregas = PpeDelivery::query()
            ->with(['employee:id,nombres,apellidos,cargo', 'item:id,nombre,categoria'])
            ->orderByDesc('fecha_entrega')
            ->get();

        return Inertia::render('epp/index', [
            'needsClient' => false,
            'items' => PpeItem::query()->orderBy('categoria')->orderBy('nombre')->get(),
            'matriz' => PpeAssignment::query()->with('item:id,nombre,categoria')->orderBy('cargo')->get(),
            'entregas' => $entregas,
            'empleados' => Employee::query()->where('is_active', true)
                ->orderBy('apellidos')->get(['id', 'nombres', 'apellidos', 'cargo']),
            // Los cargos que de verdad existen en la nómina, para no escribirlos
            // a mano en la matriz y que luego no casen con los de `employees`.
            'cargos' => Employee::query()->whereNotNull('cargo')
                ->distinct()->orderBy('cargo')->pluck('cargo'),
            'stats' => [
                'items' => PpeItem::query()->where('activo', true)->count(),
                'cargos_cubiertos' => PpeAssignment::query()->distinct('cargo')->count('cargo'),
                'entregas' => $entregas->count(),
                // Sin firma la entrega no es evidencia ante una auditoría.
                'sin_firmar' => $entregas->where('firmada', false)->count(),
            ],
            'catalogos' => $this->catalogos(),
        ]);
    }

    // ------------------------------------------------------------- catálogo

    public function storeItem(Request $request): RedirectResponse
    {
        $this->exigeCliente();
        PpeItem::create($this->validatedItem($request));

        return back()->with('success', 'Elemento agregado al catálogo.');
    }

    public function updateItem(Request $request, PpeItem $item): RedirectResponse
    {
        $item->update($this->validatedItem($request));

        return back()->with('success', 'Elemento actualizado.');
    }

    public function destroyItem(PpeItem $item): RedirectResponse
    {
        $item->delete();

        return back()->with('success', 'Elemento eliminado.');
    }

    // --------------------------------------------------------------- matriz

    public function storeAssignment(Request $request): RedirectResponse
    {
        $this->exigeCliente();
        PpeAssignment::create($this->validatedAssignment($request));

        return back()->with('success', 'EPP asignado al cargo.');
    }

    public function destroyAssignment(PpeAssignment $asignacion): RedirectResponse
    {
        $asignacion->delete();

        return back()->with('success', 'Asignación eliminada.');
    }

    // ------------------------------------------------------------- entregas

    public function storeDelivery(Request $request): RedirectResponse
    {
        $this->exigeCliente();
        PpeDelivery::create($this->validatedDelivery($request));

        return back()->with('success', 'Entrega registrada.');
    }

    public function updateDelivery(Request $request, PpeDelivery $entrega): RedirectResponse
    {
        $entrega->update($this->validatedDelivery($request));

        return back()->with('success', 'Entrega actualizada.');
    }

    public function destroyDelivery(PpeDelivery $entrega): RedirectResponse
    {
        $entrega->delete();

        return back()->with('success', 'Entrega eliminada.');
    }

    private function exigeCliente(): void
    {
        abort_unless($this->context->has(), 422, 'Selecciona un cliente antes de registrar EPP.');
    }

    private function catalogos(): array
    {
        return [
            'categorias' => PpeItem::CATEGORIAS,
            'requerimientos' => PpeAssignment::REQUERIMIENTOS,
            'motivos' => PpeDelivery::MOTIVOS,
        ];
    }

    private function validatedItem(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'categoria' => ['required', Rule::in(PpeItem::CATEGORIAS)],
            'norma' => ['nullable', 'string', 'max:255'],
            'uso' => ['nullable', 'string', 'max:2000'],
            'vida_util' => ['nullable', 'string', 'max:60'],
            'criterio_reposicion' => ['nullable', 'string', 'max:2000'],
            'activo' => ['boolean'],
        ]);
    }

    private function validatedAssignment(Request $request): array
    {
        return $request->validate([
            'ppe_item_id' => ['required', 'integer', Rule::exists('ppe_items', 'id')
                ->where('tenant_id', $this->context->id())],
            'cargo' => [
                'required', 'string', 'max:255',
                // El mismo elemento no se le asigna dos veces al mismo cargo. La
                // tabla ya lo impide, pero sin esta regla saldría un error de SQL
                // en vez de un mensaje entendible.
                Rule::unique('ppe_assignments', 'cargo')
                    ->where('tenant_id', $this->context->id())
                    ->where('ppe_item_id', $request->input('ppe_item_id')),
            ],
            'area' => ['nullable', 'string', 'max:255'],
            'requerimiento' => ['required', Rule::in(PpeAssignment::REQUERIMIENTOS)],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function validatedDelivery(Request $request): array
    {
        return $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')
                ->where('tenant_id', $this->context->id())],
            'ppe_item_id' => ['required', 'integer', Rule::exists('ppe_items', 'id')
                ->where('tenant_id', $this->context->id())],
            'fecha_entrega' => ['required', 'date'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:999'],
            'talla' => ['nullable', 'string', 'max:20'],
            'entregado_por' => ['nullable', 'string', 'max:255'],
            'recibido_por' => ['nullable', 'string', 'max:255'],
            'fecha_firma' => ['nullable', 'date'],
            'motivo' => ['required', Rule::in(PpeDelivery::MOTIVOS)],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
