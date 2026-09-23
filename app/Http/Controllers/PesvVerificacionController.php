<?php

namespace App\Http\Controllers;

use App\Models\PesvCriterion;
use App\Models\PesvEvidence;
use App\Models\PesvPlan;
use App\Models\PesvPlanCriterion;
use App\Support\LimiteSubida;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lista de verificación del PESV (Tabla 16 de la Res. 40595): respuesta a
 * cada pregunta y sus evidencias.
 *
 * Cada respuesta recalcula el estado de su paso y el avance del plan: el
 * estado del paso ya no se elige a mano, sale de sus preguntas.
 *
 * Permisos: ver/bajar evidencias -> pesv.view | responder y subir -> pesv.manage
 */
class PesvVerificacionController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function responder(Request $request, PesvCriterion $criterio): RedirectResponse
    {
        abort_unless($this->context->has(), 404);

        $datos = $request->validate([
            'estado' => ['required', Rule::in(PesvPlanCriterion::ESTADOS)],
            'observaciones' => ['nullable', 'string', 'max:5000'],
        ]);

        $plan = $this->plan();
        $verificada = $datos['estado'] !== 'no_verificado';
        $plan->criterios()->updateOrCreate(['pesv_criterion_id' => $criterio->id], [
            ...$datos,
            'verificado_at' => $verificada ? now()->toDateString() : null,
            'verificado_por' => $verificada ? $request->user()->name : null,
        ]);

        $plan->sincronizarPaso($criterio->step);
        $plan->recalcular();

        return back()->with('success', "Pregunta {$criterio->codigo} guardada.");
    }

    public function subir(Request $request, PesvCriterion $criterio): RedirectResponse
    {
        abort_unless($this->context->has(), 404);

        $request->validate([
            // Por extensión: los .doc/.xls viejos se detectan como «CDFV2» y
            // `mimes` los rechazaría siendo válidos.
            'archivo' => ['required', 'file', 'extensions:'.implode(',', PesvEvidence::EXTENSIONES), 'max:'.LimiteSubida::kilobytes()],
        ], [
            'archivo.extensions' => 'La evidencia debe ser '.implode(', ', PesvEvidence::EXTENSIONES).'.',
            'archivo.uploaded' => 'El archivo no se pudo subir: supera el límite del servidor.',
        ]);

        $archivo = $request->file('archivo');
        $plan = $this->plan();
        $nombre = Str::slug($criterio->codigo).'-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6)).'.'.Str::lower($archivo->getClientOriginalExtension());

        PesvEvidence::create([
            'pesv_plan_id' => $plan->id,
            'pesv_criterion_id' => $criterio->id,
            'archivo' => $archivo->storeAs('pesv/'.$this->context->id(), $nombre, 'local'),
            'nombre' => Str::limit($archivo->getClientOriginalName(), 250, ''),
            'bytes' => $archivo->getSize(),
            'subido_por' => $request->user()->name,
        ]);

        return back()->with('success', "Evidencia agregada a la pregunta {$criterio->codigo}.");
    }

    /** El binding pasa por el TenantScope: la evidencia de otra empresa da 404. */
    public function descargar(PesvEvidence $evidencia): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($evidencia->archivo), 404, 'El archivo ya no está en el servidor.');

        return Storage::disk('local')->download($evidencia->archivo, $evidencia->nombre);
    }

    public function borrar(PesvEvidence $evidencia): RedirectResponse
    {
        Storage::disk('local')->delete($evidencia->archivo);
        $evidencia->delete();

        return back()->with('success', 'Evidencia eliminada.');
    }

    private function plan(): PesvPlan
    {
        return PesvPlan::firstOrCreate([], ['nivel' => 'basico']);
    }
}
