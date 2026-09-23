<?php

namespace App\Http\Controllers;

use App\Models\PesvPlan;
use App\Support\Pesv\EncuestaMovilidad;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Encuesta de movilidad del PESV respondida por el trabajador, SIN cuenta en
 * la plataforma: el enlace lleva un token aleatorio del plan de su empresa.
 *
 * El token es lo único que identifica a la empresa; el consultor lo puede
 * renovar (el viejo deja de servir) o cerrar la encuesta. Solo se expone el
 * nombre de la empresa y el formulario: nada de sus datos.
 */
class EncuestaPublicaController extends Controller
{
    public function show(string $token): Response
    {
        $plan = $this->plan($token);

        return Inertia::render('encuesta/movilidad', [
            'empresa' => $plan->tenant?->name,
            'abierta' => $plan->encuesta_activa,
            'secciones' => EncuestaMovilidad::secciones(),
            'token' => $token,
            'enviada' => session('encuesta_enviada', false),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $plan = $this->plan($token);
        abort_unless($plan->encuesta_activa, 403, 'La encuesta está cerrada.');

        // Todo lo que se guarde va a la empresa del token.
        app(TenantContext::class)->set($plan->tenant);
        PesvEncuestaController::guardar($request, 'enlace');

        return redirect()->route('encuesta.movilidad', $token)->with('encuesta_enviada', true);
    }

    private function plan(string $token): PesvPlan
    {
        abort_unless(strlen($token) === 40, 404);

        return PesvPlan::withoutTenantScope()->with('tenant:id,name')->where('encuesta_token', $token)->firstOrFail();
    }
}
