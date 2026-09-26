<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso a un módulo que la empresa cliente NO contrató, y a la
 * parte del módulo que no contrató (config('cmk.submodulos'): la parte sale
 * del nombre de la ruta, así que las rutas no cambian).
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

        $parte = $tenant ? Tenant::parteDeRuta($modulo, $request->route()?->getName()) : null;
        if ($parte && ! $tenant->submoduloHabilitado($modulo, $parte)) {
            abort(403, 'La empresa no tiene contratada esta parte del módulo.');
        }

        return $next($request);
    }
}
