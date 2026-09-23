<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PesvDriverCheck;
use App\Models\PesvDriverTest;
use App\Models\PesvInfraction;
use App\Models\PesvVehicle;
use App\Support\Pesv\RequisitosPaso11;
use App\Support\Pesv\SemaforoDocumentos;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ficha del conductor para el PASO 11 del PESV: requisitos del operador
 * (RE-SST-51), pruebas de idoneidad, comparendos y vencimientos.
 *
 * El conductor es el empleado que ya existe (ficha de conductor sobre
 * Empleados): aquí no se crean personas.
 *
 * Permisos: ver -> pesv.view | registrar -> pesv.manage
 */
class PesvConductorController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(Employee $empleado): Response
    {
        $check = PesvDriverCheck::firstWhere('employee_id', $empleado->id);
        $hoy = now()->startOfDay();

        return Inertia::render('pesv/conductor', [
            'conductor' => [
                ...$empleado->only(['id', 'nombres', 'apellidos', 'numero_documento', 'cargo', 'area', 'es_conductor',
                    'licencia_numero', 'licencia_categoria']),
                'licencia_vence' => $empleado->licencia_vence?->toDateString(),
                'examen_psicosensometrico_vence' => $empleado->examen_psicosensometrico_vence?->toDateString(),
                'fecha_ingreso' => $empleado->fecha_ingreso?->toDateString(),
            ],
            'documentos' => collect([
                ['Licencia de conducción', $empleado->licencia_vence],
                ['Examen psicosensométrico', $empleado->examen_psicosensometrico_vence],
            ])->map(fn ($d) => ['documento' => $d[0], 'vence' => $d[1]?->toDateString(), 'estado' => SemaforoDocumentos::estado($d[1], $hoy)]),
            'requisitos' => [
                'catalogo' => RequisitosPaso11::OPERADOR,
                'respuestas' => (object) ($check?->respuestas ?? []),
                'resultado' => $check?->resultado ?? 'pendiente',
                'fecha' => $check?->fecha?->toDateString(),
                'placa_asignada' => $check?->placa_asignada,
                'verificado_por' => $check?->verificado_por,
                'observaciones' => $check?->observaciones,
            ],
            'pruebas' => PesvDriverTest::where('employee_id', $empleado->id)->orderByDesc('fecha')->get()
                ->map(fn (PesvDriverTest $p) => [
                    ...$p->only(['id', 'tipo', 'resultado', 'evaluador', 'observaciones']),
                    'puntaje' => $p->puntaje !== null ? (float) $p->puntaje : null,
                    'fecha' => $p->fecha->toDateString(),
                    'vigente_hasta' => $p->vigente_hasta?->toDateString(),
                ]),
            'infracciones' => PesvInfraction::where('employee_id', $empleado->id)->orderByDesc('fecha')->get()
                ->map(fn (PesvInfraction $i) => [
                    ...$i->only(['id', 'codigo', 'descripcion', 'estado']),
                    'fecha' => $i->fecha->toDateString(),
                    'valor' => $i->valor !== null ? (float) $i->valor : null,
                ]),
            'tiposPrueba' => PesvDriverTest::TIPOS,
            'puntajeMinimo' => PesvDriverTest::PUNTAJE_MINIMO,
            'estadosInfraccion' => PesvInfraction::ESTADOS,
            'placas' => PesvVehicle::where('is_active', true)->orderBy('placa')->pluck('placa'),
        ]);
    }

    public function requisitos(Request $request, Employee $empleado): RedirectResponse
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'placa_asignada' => ['nullable', 'string', 'max:10'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'respuestas' => ['nullable', 'array'],
        ]);
        $claves = RequisitosPaso11::clavesOperador();
        $respuestas = RequisitosPaso11::sanear($claves, $datos['respuestas'] ?? []);

        PesvDriverCheck::updateOrCreate(['employee_id' => $empleado->id], [
            'fecha' => $datos['fecha'],
            'placa_asignada' => $datos['placa_asignada'] ?? null,
            'observaciones' => $datos['observaciones'] ?? null,
            'respuestas' => $respuestas,
            'resultado' => RequisitosPaso11::resultado($claves, $respuestas),
            'verificado_por' => $request->user()->name,
        ]);

        return back()->with('success', 'Requisitos del conductor guardados.');
    }

    /**
     * La teórica y la práctica se califican con puntaje: el resultado sale
     * de él (RE-SST-48: más de 60 % es apto). La psicosensométrica viene del
     * CRC con su concepto y vigencia, que actualizan la ficha del conductor.
     */
    public function guardarPrueba(Request $request, Employee $empleado): RedirectResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(PesvDriverTest::TIPOS))],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'puntaje' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_unless:tipo,psicosensometrica'],
            'resultado' => ['nullable', Rule::in(['apto', 'no_apto']), 'required_if:tipo,psicosensometrica'],
            'vigente_hasta' => ['nullable', 'date', 'after:fecha'],
            'evaluador' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], ['puntaje.required_unless' => 'La prueba teórica y la práctica se registran con su puntaje.']);

        if ($datos['tipo'] !== 'psicosensometrica') {
            $datos['resultado'] = (float) $datos['puntaje'] > PesvDriverTest::PUNTAJE_MINIMO ? 'apto' : 'no_apto';
        }
        PesvDriverTest::create([...$datos, 'employee_id' => $empleado->id]);

        if ($datos['tipo'] === 'psicosensometrica' && $datos['resultado'] === 'apto' && ! empty($datos['vigente_hasta'])) {
            $empleado->update(['examen_psicosensometrico_vence' => $datos['vigente_hasta']]);
        }

        return back()->with('success', PesvDriverTest::TIPOS[$datos['tipo']].': '.($datos['resultado'] === 'apto' ? 'apto' : 'no apto').'.');
    }

    public function borrarPrueba(PesvDriverTest $prueba): RedirectResponse
    {
        $prueba->delete();

        return back()->with('success', 'Prueba eliminada.');
    }
}
