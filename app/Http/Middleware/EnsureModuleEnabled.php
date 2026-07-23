<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso a un módulo que la empresa cliente NO contrató.
 *
 * Uso en rutas: ->middleware('module:diagnostico')
 * Sin cliente activo (vista consolidada del consultor CMK) no aplica:
 * la restricción es por contrato de la empresa, no por el consultor.
 */
class EnsureModuleEnabled
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $modulo): Response
    {
        $tenant = $this->context->has() ? $this->context->get() : null;

        if ($tenant && ! $tenant->moduloHabilitado($modulo)) {
            abort(403, 'La empresa no tiene contratado este módulo.');
        }

        return $next($request);
    }
}
