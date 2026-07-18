<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve el tenant activo para el request autenticado.
 *
 * - Usuario de un cliente: su tenant_id queda fijo (no puede cambiarlo).
 * - Personal de CMK: puede tener un cliente seleccionado en sesión
 *   ('active_tenant_id'); si no, el contexto queda null = vista consolidada.
 */
class SetCurrentTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            if ($user->tenant_id !== null) {
                // Usuario del cliente: contexto bloqueado a su empresa.
                $this->context->setId($user->tenant_id);
            } elseif ($user->belongsToCmk()) {
                // Consultor: cliente seleccionado en sesión, o vista consolidada.
                $selected = $request->session()->get('active_tenant_id');
                $this->context->setId($selected ? (int) $selected : null);
            }
        }

        $this->context->markResolved();

        return $next($request);
    }
}
