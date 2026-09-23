<?php

namespace App\Http\Controllers;

use App\Models\PesvRoute;
use App\Support\Pesv\PlanDesplazamiento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Planificación del desplazamiento de una ruta (paso 15, RE-SST-69): horario,
 * límites de velocidad por zona, paradas, puestos de control, puntos
 * críticos, apoyo y directorio de emergencia.
 */
class PesvRutaPlanController extends Controller
{
    public function show(PesvRoute $ruta): Response
    {
        return Inertia::render('pesv/ruta-plan', [
            'ruta' => $ruta->only(['id', 'nombre', 'origen', 'destino', 'tipo_via', 'duracion_min', 'frecuencia', 'horario', 'peligros', 'nivel_riesgo'])
                + ['distancia_km' => $ruta->distancia_km !== null ? (float) $ruta->distancia_km : null],
            'plan' => $ruta->plan ?? PlanDesplazamiento::vacio(),
            'campos' => PlanDesplazamiento::CAMPOS,
            'tablas' => PlanDesplazamiento::TABLAS,
            'completo' => PlanDesplazamiento::completo($ruta->plan),
        ]);
    }

    public function update(Request $request, PesvRoute $ruta): RedirectResponse
    {
        $request->validate(['campos' => ['nullable', 'array'], 'tablas' => ['nullable', 'array']]);
        $ruta->update(['plan' => PlanDesplazamiento::sanear($request->only(['campos', 'tablas']))]);

        return back()->with('success', "Plan de desplazamiento de «{$ruta->nombre}» guardado.");
    }
}
