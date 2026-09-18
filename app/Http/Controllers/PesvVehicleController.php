<?php

namespace App\Http\Controllers;

use App\Models\PesvVehicle;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Flota de vehículos del cliente activo (PESV, Paso 5).
 *
 * Alimenta también los pasos 16 (preoperacional) y 17 (mantenimiento).
 *
 * Permisos: ver -> pesv.view | gestionar -> pesv.manage
 */
class PesvVehicleController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('pesv/vehiculos', [
                'needsClient' => true,
                'vehiculos' => [],
                'stats' => ['total' => 0, 'alertas' => 0],
                'tipos' => PesvVehicle::TIPOS,
                'propiedades' => PesvVehicle::PROPIEDADES,
            ]);
        }

        $vehiculos = PesvVehicle::orderBy('placa')->get()
            ->map(fn (PesvVehicle $v) => [
                ...$v->toArray(),
                'alertas' => $v->alertas(),
            ]);

        return Inertia::render('pesv/vehiculos', [
            'needsClient' => false,
            'vehiculos' => $vehiculos,
            'stats' => [
                'total' => $vehiculos->count(),
                'activos' => $vehiculos->where('is_active', true)->count(),
                // Un vehículo con SOAT o tecnomecánica vencida es un hallazgo,
                // así que se cuenta aparte y se destaca en pantalla.
                'alertas' => $vehiculos->filter(fn ($v) => count($v['alertas']) > 0)->count(),
            ],
            'tipos' => PesvVehicle::TIPOS,
            'propiedades' => PesvVehicle::PROPIEDADES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de registrar vehículos.']);
        }

        PesvVehicle::create($this->validated($request));

        return back()->with('success', 'Vehículo agregado a la flota.');
    }

    public function update(Request $request, PesvVehicle $vehiculo): RedirectResponse
    {
        $vehiculo->update($this->validated($request, $vehiculo->id));

        return back()->with('success', 'Vehículo actualizado.');
    }

    public function destroy(PesvVehicle $vehiculo): RedirectResponse
    {
        $vehiculo->delete();

        return back()->with('success', 'Vehículo eliminado.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $ignorar = null): array
    {
        return $request->validate([
            'placa' => [
                'required', 'string', 'max:10',
                // La placa es única dentro de la empresa, no globalmente: dos
                // clientes distintos pueden tener el mismo vehículo tercerizado.
                Rule::unique('pesv_vehicles')
                    ->where('tenant_id', $this->context->id())
                    ->ignore($ignorar),
            ],
            'tipo' => ['required', Rule::in(PesvVehicle::TIPOS)],
            'marca' => ['nullable', 'string', 'max:60'],
            'linea' => ['nullable', 'string', 'max:60'],
            'modelo' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'propiedad' => ['required', Rule::in(PesvVehicle::PROPIEDADES)],
            'propietario' => ['nullable', 'string', 'max:255'],
            'soat_vence' => ['nullable', 'date'],
            'tecnomecanica_vence' => ['nullable', 'date'],
            'poliza_vence' => ['nullable', 'date'],
            'kilometraje' => ['nullable', 'integer', 'min:0'],
            'ultimo_mantenimiento' => ['nullable', 'date'],
            'proximo_mantenimiento' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);
    }
}
