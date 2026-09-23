<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceAsset;
use App\Models\PesvInfraction;
use App\Models\PesvSiniestro;
use App\Models\PesvVehicle;
use App\Models\PesvVehicleCheck;
use App\Support\Pesv\RequisitosPaso11;
use App\Support\Pesv\SemaforoDocumentos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ficha del vehículo para el PESV: requisitos (RE-SST-50), vencimientos,
 * y lo que otros módulos ya saben de él (mantenimiento, siniestros,
 * comparendos). Es la «hoja de vida» que pide el paso 17.
 */
class PesvVehiculoFichaController extends Controller
{
    public function show(PesvVehicle $vehiculo): Response
    {
        $check = PesvVehicleCheck::firstWhere('pesv_vehicle_id', $vehiculo->id);
        $hoy = now()->startOfDay();
        $activo = MaintenanceAsset::with('records')->firstWhere('pesv_vehicle_id', $vehiculo->id);

        return Inertia::render('pesv/vehiculo', [
            'vehiculo' => [
                ...$vehiculo->only(['id', 'placa', 'tipo', 'marca', 'linea', 'modelo', 'propiedad', 'propietario', 'kilometraje', 'is_active']),
                'ultimo_mantenimiento' => $vehiculo->ultimo_mantenimiento?->toDateString(),
            ],
            'documentos' => collect([
                ['SOAT', $vehiculo->soat_vence],
                ['Revisión técnico-mecánica', $vehiculo->tecnomecanica_vence],
                ['Póliza', $vehiculo->poliza_vence],
                ['Próximo mantenimiento', $vehiculo->proximo_mantenimiento],
            ])->map(fn ($d) => ['documento' => $d[0], 'vence' => $d[1]?->toDateString(), 'estado' => SemaforoDocumentos::estado($d[1], $hoy)]),
            'requisitos' => [
                'catalogo' => RequisitosPaso11::VEHICULO,
                'respuestas' => (object) ($check?->respuestas ?? []),
                'resultado' => $check?->resultado ?? 'pendiente',
                'fecha' => $check?->fecha?->toDateString(),
                'verificado_por' => $check?->verificado_por,
                'observaciones' => $check?->observaciones,
            ],
            'historial' => [
                'mantenimientos' => $activo ? $activo->records->sortByDesc('fecha')->take(10)->map(fn ($r) => [
                    'fecha' => $r->fecha->toDateString(), 'tipo' => $r->tipo, 'descripcion' => $r->descripcion,
                ])->values() : [],
                'mantenimiento_url' => $activo ? '/mantenimiento' : null,
                'siniestros' => PesvSiniestro::where('pesv_vehicle_id', $vehiculo->id)->orderByDesc('fecha')->get(['fecha', 'tipo', 'gravedad'])
                    ->map(fn ($s) => ['fecha' => $s->fecha->toDateString(), 'tipo' => $s->tipo, 'gravedad' => $s->gravedad]),
                'infracciones' => PesvInfraction::where('pesv_vehicle_id', $vehiculo->id)->count(),
            ],
        ]);
    }

    public function requisitos(Request $request, PesvVehicle $vehiculo): RedirectResponse
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'respuestas' => ['nullable', 'array'],
        ]);
        $claves = collect(RequisitosPaso11::VEHICULO)->map(fn ($i) => $i[0])->all();
        $respuestas = RequisitosPaso11::sanear($claves, $datos['respuestas'] ?? []);

        PesvVehicleCheck::updateOrCreate(['pesv_vehicle_id' => $vehiculo->id], [
            'fecha' => $datos['fecha'],
            'observaciones' => $datos['observaciones'] ?? null,
            'respuestas' => $respuestas,
            'resultado' => RequisitosPaso11::resultado($claves, $respuestas),
            'verificado_por' => $request->user()->name,
        ]);

        return back()->with('success', "Requisitos del vehículo {$vehiculo->placa} guardados.");
    }
}
