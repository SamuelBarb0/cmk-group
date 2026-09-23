<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceAsset;
use App\Models\MaintenancePlanItem;
use App\Models\MaintenanceRecord;
use App\Models\PesvVehicle;
use App\Support\PlanesMantenimiento;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mantenimiento de activos del cliente activo: inventario, plan por activo
 * (cada N días, km u horas) y registro de lo realizado.
 *
 * Los ítems del plan no llevan tenant_id y no tienen rutas propias: se
 * guardan a través de su activo, que sí pasa por el TenantScope.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class MaintenanceController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        $anio = (int) $request->integer('anio', (int) now()->year);

        if (! $this->context->has()) {
            return Inertia::render('mantenimiento/index', [
                'needsClient' => true,
                'anio' => $anio,
                'activos' => [],
                'registros' => [],
                'vehiculosSinEnlazar' => 0,
                'stats' => $this->stats(collect(), collect(), $anio),
                'catalogos' => $this->catalogos(),
            ]);
        }

        $activos = MaintenanceAsset::query()
            ->with(['planItems', 'records', 'vehicle:id,placa'])
            ->orderByDesc('activo')->orderBy('tipo')->orderBy('nombre')
            ->get();

        $filas = $activos->map(fn (MaintenanceAsset $a) => $this->activo($a));
        $registros = MaintenanceRecord::query()
            ->with(['asset:id,nombre,codigo', 'planItem:id,actividad'])
            ->orderByDesc('fecha')->orderByDesc('id')
            ->get();

        $enlazados = $activos->pluck('pesv_vehicle_id')->filter()->all();

        return Inertia::render('mantenimiento/index', [
            'needsClient' => false,
            'anio' => $anio,
            'activos' => $filas->values(),
            'registros' => $registros,
            'vehiculosSinEnlazar' => PesvVehicle::query()->where('is_active', true)->whereNotIn('id', $enlazados)->count(),
            'stats' => $this->stats($filas, $registros, $anio),
            'catalogos' => $this->catalogos(),
        ]);
    }

    // ---------------------------------------------------------------- activos

    public function storeAsset(Request $request): RedirectResponse
    {
        abort_unless($this->context->has(), 422, 'Selecciona un cliente antes de registrar activos.');
        [$datos, $items] = $this->validatedAsset($request);

        DB::transaction(function () use ($datos, $items): void {
            $activo = MaintenanceAsset::create($datos);
            $this->guardarPlan($activo, $items);
            $activo->sincronizarVehiculo();
        });

        return back()->with('success', 'Activo registrado.');
    }

    public function updateAsset(Request $request, MaintenanceAsset $activo): RedirectResponse
    {
        [$datos, $items] = $this->validatedAsset($request, $activo);

        DB::transaction(function () use ($activo, $datos, $items): void {
            $activo->update($datos);
            $this->guardarPlan($activo, $items);
            $activo->unsetRelation('planItems')->unsetRelation('records');
            $activo->sincronizarVehiculo();
        });

        return back()->with('success', 'Activo actualizado.');
    }

    public function destroyAsset(MaintenanceAsset $activo): RedirectResponse
    {
        $activo->delete();

        return back()->with('success', 'Activo eliminado con su plan y sus registros.');
    }

    /**
     * Crea un activo por cada vehículo activo del PESV que aún no tenga uno,
     * con el plan del PASO 17 cargado. Idempotente: repetirlo no duplica.
     */
    public function importarVehiculos(): RedirectResponse
    {
        abort_unless($this->context->has(), 422, 'Selecciona un cliente primero.');

        $enlazados = MaintenanceAsset::query()->whereNotNull('pesv_vehicle_id')->pluck('pesv_vehicle_id');
        $nuevos = PesvVehicle::query()->where('is_active', true)->whereNotIn('id', $enlazados)->get();
        $plan = PlanesMantenimiento::todos()['vehiculo']['items'];

        DB::transaction(function () use ($nuevos, $plan): void {
            foreach ($nuevos as $v) {
                $activo = MaintenanceAsset::create([
                    'tipo' => 'vehiculo',
                    'nombre' => trim(implode(' ', array_filter([$v->marca, $v->linea]))) ?: 'Vehículo',
                    'codigo' => $v->placa,
                    'modelo' => $v->modelo ? (string) $v->modelo : null,
                    'pesv_vehicle_id' => $v->id,
                    'unidad_lectura' => 'km',
                    'lectura_actual' => $v->kilometraje,
                ]);
                $this->guardarPlan($activo, array_map(fn ($i) => [
                    'actividad' => $i['actividad'],
                    'frecuencia_valor' => $i['valor'],
                    'frecuencia_unidad' => $i['unidad'],
                ], $plan));
            }
        });

        return back()->with('success', $nuevos->isEmpty()
            ? 'Todos los vehículos del PESV ya están en el inventario.'
            : "{$nuevos->count()} vehículo(s) del PESV agregados con el plan del PASO 17.");
    }

    // -------------------------------------------------------------- registros

    public function storeRecord(Request $request): RedirectResponse
    {
        abort_unless($this->context->has(), 422, 'Selecciona un cliente antes de registrar mantenimientos.');
        $datos = $this->validatedRecord($request);

        DB::transaction(function () use ($datos): void {
            $registro = MaintenanceRecord::create($datos);
            $this->alRegistrar($registro->asset);
        });

        return back()->with('success', 'Mantenimiento registrado.');
    }

    public function updateRecord(Request $request, MaintenanceRecord $registro): RedirectResponse
    {
        $datos = $this->validatedRecord($request);
        $anterior = $registro->asset;

        DB::transaction(function () use ($registro, $datos, $anterior): void {
            $registro->update($datos);
            $this->alRegistrar($registro->fresh()->asset);
            if ($anterior && $anterior->id !== $registro->maintenance_asset_id) {
                $this->alRegistrar($anterior);
            }
        });

        return back()->with('success', 'Mantenimiento actualizado.');
    }

    public function destroyRecord(MaintenanceRecord $registro): RedirectResponse
    {
        $activo = $registro->asset;
        $registro->delete();
        if ($activo) {
            $activo->unsetRelation('records');
            $activo->sincronizarVehiculo();
        }

        return back()->with('success', 'Registro eliminado.');
    }

    /**
     * Una lectura mayor que la actual la actualiza (nunca la baja: un registro
     * viejo cargado tarde no puede «desgastar» el odómetro hacia atrás), y el
     * vehículo del PESV recibe el resumen.
     */
    private function alRegistrar(MaintenanceAsset $activo): void
    {
        $max = $activo->records()->max('lectura');
        if ($max !== null && $max > (int) $activo->lectura_actual) {
            $activo->update(['lectura_actual' => $max]);
        }
        $activo->unsetRelation('records')->unsetRelation('planItems');
        $activo->sincronizarVehiculo();
    }

    // ---------------------------------------------------------------- apoyo

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function validatedAsset(Request $request, ?MaintenanceAsset $activo = null): array
    {
        $data = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(MaintenanceAsset::TIPOS))],
            'nombre' => ['required', 'string', 'max:255'],
            'codigo' => ['nullable', 'string', 'max:40'],
            'marca' => ['nullable', 'string', 'max:255'],
            'modelo' => ['nullable', 'string', 'max:255'],
            'serie' => ['nullable', 'string', 'max:255'],
            'ubicacion' => ['nullable', 'string', 'max:255'],
            'pesv_vehicle_id' => ['nullable', 'integer'],
            'unidad_lectura' => ['nullable', Rule::in(MaintenanceAsset::UNIDADES_LECTURA)],
            'lectura_actual' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'fecha_ingreso' => ['nullable', 'date'],
            'responsable' => ['nullable', 'string', 'max:255'],
            'activo' => ['boolean'],
            'observaciones' => ['nullable', 'string', 'max:5000'],

            'plan' => ['present', 'array', 'max:60'],
            'plan.*.id' => ['nullable', 'integer'],
            'plan.*.actividad' => ['required', 'string', 'max:500'],
            'plan.*.frecuencia_valor' => ['nullable', 'integer', 'min:1', 'max:9999999', 'required_with:plan.*.frecuencia_unidad'],
            'plan.*.frecuencia_unidad' => ['nullable', Rule::in(MaintenancePlanItem::UNIDADES), 'required_with:plan.*.frecuencia_valor'],
            'plan.*.responsable' => ['nullable', 'string', 'max:255'],
        ], [], [
            'plan.*.actividad' => 'actividad del plan',
            'plan.*.frecuencia_valor' => 'frecuencia',
            'plan.*.frecuencia_unidad' => 'unidad de la frecuencia',
            'pesv_vehicle_id' => 'vehículo del PESV',
        ]);

        // El vehículo tiene que ser de ESTA empresa (la regla `exists` no pasa
        // por el TenantScope) y no estar ya enlazado a otro activo.
        if (! empty($data['pesv_vehicle_id'])) {
            if (! PesvVehicle::query()->whereKey($data['pesv_vehicle_id'])->exists()) {
                throw ValidationException::withMessages(['pesv_vehicle_id' => 'El vehículo no existe en el PESV de esta empresa.']);
            }
            $ocupado = MaintenanceAsset::query()->where('pesv_vehicle_id', $data['pesv_vehicle_id'])
                ->when($activo, fn ($q) => $q->whereKeyNot($activo->id))->exists();
            if ($ocupado) {
                throw ValidationException::withMessages(['pesv_vehicle_id' => 'Ese vehículo ya tiene su activo en el inventario.']);
            }
        }

        // Un plan por uso sin unidad de lectura en el activo nunca podría vencer.
        $porUso = collect($data['plan'])->pluck('frecuencia_unidad')->intersect(['km', 'horas'])->unique();
        foreach ($porUso as $u) {
            if (($data['unidad_lectura'] ?? null) !== $u) {
                throw ValidationException::withMessages(['unidad_lectura' => "El plan tiene ítems cada N {$u}: el activo tiene que llevar la lectura en {$u}."]);
            }
        }

        $items = $data['plan'];
        unset($data['plan']);

        return [$data, $items];
    }

    /**
     * Actualiza el plan conservando los ids: los registros apuntan a su ítem,
     * y borrar y recrear rompería ese enlace (y con él el «próximo»).
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function guardarPlan(MaintenanceAsset $activo, array $items): void
    {
        $existentes = $activo->planItems()->get()->keyBy('id');
        $conservar = [];

        foreach (array_values($items) as $i => $it) {
            $valores = [
                'actividad' => $it['actividad'],
                'frecuencia_valor' => $it['frecuencia_valor'] ?? null,
                'frecuencia_unidad' => $it['frecuencia_unidad'] ?? null,
                'responsable' => $it['responsable'] ?? null,
                'orden' => $i + 1,
            ];
            $id = $it['id'] ?? null;
            if ($id && $existentes->has($id)) {
                $existentes[$id]->update($valores);
                $conservar[] = $id;
            } else {
                // Un id ajeno a este activo se trata como ítem nuevo.
                $conservar[] = $activo->planItems()->create($valores)->id;
            }
        }

        $activo->planItems()->whereNotIn('id', $conservar)->delete();
    }

    private function validatedRecord(Request $request): array
    {
        $data = $request->validate([
            'maintenance_asset_id' => ['required', 'integer'],
            'maintenance_plan_item_id' => ['nullable', 'integer'],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'tipo' => ['required', Rule::in(MaintenanceRecord::TIPOS)],
            'descripcion' => ['required', 'string', 'max:5000'],
            'lectura' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'realizado_por' => ['nullable', 'string', 'max:255'],
            'proveedor_idoneo' => ['nullable', 'boolean'],
            'factura' => ['nullable', 'string', 'max:60'],
            'valor' => ['nullable', 'numeric', 'min:0'],
            'verificado_por' => ['nullable', 'string', 'max:255'],
            'hallazgos' => ['nullable', 'string', 'max:5000'],
            'estado' => ['required', Rule::in(MaintenanceRecord::ESTADOS)],
        ], [
            'fecha.before_or_equal' => 'Un mantenimiento no puede registrarse con fecha futura.',
        ], [
            'maintenance_asset_id' => 'activo',
            'maintenance_plan_item_id' => 'ítem del plan',
            'descripcion' => 'descripción',
        ]);

        // Ni `exists` ni el binding filtran esto por empresa: se comprueba a mano.
        $activo = MaintenanceAsset::query()->find($data['maintenance_asset_id']);
        if (! $activo) {
            throw ValidationException::withMessages(['maintenance_asset_id' => 'El activo no existe en esta empresa.']);
        }
        if (! empty($data['maintenance_plan_item_id']) && ! $activo->planItems()->whereKey($data['maintenance_plan_item_id'])->exists()) {
            throw ValidationException::withMessages(['maintenance_plan_item_id' => 'Ese ítem no es del plan de este activo.']);
        }

        return $data;
    }

    private function activo(MaintenanceAsset $a): array
    {
        $plan = $a->estadoDelPlan();

        return [
            ...$a->only(['id', 'tipo', 'nombre', 'codigo', 'marca', 'modelo', 'serie', 'ubicacion', 'pesv_vehicle_id',
                'unidad_lectura', 'lectura_actual', 'responsable', 'activo', 'observaciones']),
            // only() se salta el cast: la fecha se formatea a mano (ver el bug del 17-sep).
            'fecha_ingreso' => $a->fecha_ingreso?->toDateString(),
            'placa_pesv' => $a->vehicle?->placa,
            'registros' => $a->records->count(),
            'plan' => array_map(fn ($p) => [
                'id' => $p['item']->id,
                'actividad' => $p['item']->actividad,
                'frecuencia_valor' => $p['item']->frecuencia_valor,
                'frecuencia_unidad' => $p['item']->frecuencia_unidad,
                'responsable' => $p['item']->responsable,
                'estado' => $p['estado'],
                'proxima_fecha' => $p['proxima_fecha'],
                'proxima_lectura' => $p['proxima_lectura'],
                'restante' => $p['restante'],
                'ultimo' => $p['ultimo']?->fecha?->toDateString(),
            ], $plan),
        ];
    }

    private function stats(Collection $activos, Collection $registros, int $anio): array
    {
        $enUso = $activos->where('activo', true);
        $estados = $enUso->flatMap(fn ($a) => array_column($a['plan'], 'estado'))->countBy();
        $delAnio = $registros->filter(fn (MaintenanceRecord $r) => $r->fecha->year === $anio);

        // M2 del PASO 17: activos con plan que tuvieron algún mantenimiento en el año.
        $conPlan = $enUso->filter(fn ($a) => count($a['plan']) > 0);
        $atendidos = $delAnio->pluck('maintenance_asset_id')->unique()->intersect($conPlan->pluck('id'));

        return [
            'activos' => $enUso->count(),
            'vencidos' => $estados['vencido'] ?? 0,
            'por_vencer' => $estados['por_vencer'] ?? 0,
            'sin_registro' => $estados['sin_registro'] ?? 0,
            'correctivos' => $delAnio->where('tipo', 'correctivo')->count(),
            'preventivos' => $delAnio->where('tipo', 'preventivo')->count(),
            'costo' => (float) $delAnio->sum('valor'),
            'abiertos' => $registros->where('estado', 'abierta')->count(),
            'cobertura' => $conPlan->count() > 0 ? (int) round($atendidos->count() / $conPlan->count() * 100) : null,
        ];
    }

    private function catalogos(): array
    {
        return [
            'tipos' => MaintenanceAsset::TIPOS,
            'planes' => PlanesMantenimiento::todos(),
        ];
    }
}
