<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Dashboard\Agenda;
use App\Services\Reportes\InformeGestion;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Cifras por tarjeta de módulo: las primeras de su sección del informe. */
    private const CIFRAS_POR_MODULO = 4;

    /** Alertas de la vista consolidada: las más urgentes de todos los clientes. */
    private const ALERTAS_CONSOLIDADAS = 20;

    public function __invoke(Request $request, TenantContext $context, Agenda $agenda, InformeGestion $informe): Response
    {
        $user = $request->user();

        if ($user->belongsToCmk() && ! $context->has()) {
            return $this->maestro($user, $context, $agenda);
        }

        // Dashboard individual del cliente (o consultor trabajando un cliente).
        $tenant = $context->get();
        $items = $tenant ? $agenda->items($user) : [];

        return Inertia::render('dashboard', [
            'view' => 'client',
            'tenant' => $tenant?->only(['id', 'name', 'nit', 'city']),
            'stats' => [
                'users' => $tenant ? User::where('tenant_id', $tenant->id)->count() : 0,
            ],
            'agenda' => $items,
            'agenda_resumen' => Agenda::resumen($items),
            'agenda_dias' => ['por_vencer' => Agenda::DIAS_POR_VENCER, 'horizonte' => Agenda::HORIZONTE],
            'modulos' => $tenant ? $this->modulos($user, $informe, $items) : [],
        ]);
    }

    /**
     * Una tarjeta por módulo contratado que el usuario puede ver: sus cifras
     * del año en curso y sus alertas. Salen de la MISMA sección del informe de
     * gestión, así que el panel y el informe nunca cuentan distinto.
     *
     * @param  list<array>  $agenda
     * @return list<array>
     */
    private function modulos(User $user, InformeGestion $informe, array $agenda): array
    {
        $periodo = Periodo::desde(null, null);
        $porModulo = collect($agenda)->groupBy('modulo');

        return collect($informe->disponibles($user))
            ->map(function (Seccion $s) use ($periodo, $porModulo) {
                $datos = $s->generar($periodo);
                $modulo = $s->modulo() ?? ($s->clave() === 'empresa' ? 'organizacion' : $s->clave());
                $fechas = collect($porModulo->get($modulo, []));

                return [
                    'clave' => $s->clave(),
                    'modulo' => $modulo,
                    'titulo' => $datos['titulo'],
                    'url' => $this->urlModulo($modulo),
                    'cifras' => collect($datos['cifras'])->take(self::CIFRAS_POR_MODULO)
                        ->map(fn ($c) => ['etiqueta' => $c['etiqueta'], 'valor' => $c['valor']])->values()->all(),
                    'alertas' => collect($datos['cifras'])->pluck('alerta')->filter()->values()->all(),
                    'vencidos' => $fechas->where('estado', 'vencido')->count(),
                    'por_vencer' => $fechas->where('estado', 'por_vencer')->count(),
                ];
            })
            ->values()->all();
    }

    private function urlModulo(string $modulo): ?string
    {
        $nombre = match ($modulo) {
            'organizacion' => 'organizacion.show',
            'inspecciones' => 'formatos.index',
            default => $modulo.'.index',
        };

        return Route::has($nombre) ? route($nombre) : null;
    }

    /**
     * Vista consolidada del consultor: por cada cliente activo, cuánto tiene
     * vencido y por vencer, y las alertas más urgentes de todos juntos.
     *
     * La agenda de cada cliente se calcula con SU contexto (módulos contratados
     * y scope de datos) y al final se deja el contexto como estaba: sin cliente.
     */
    private function maestro(User $user, TenantContext $context, Agenda $agenda): Response
    {
        $clientes = [];
        $alertas = collect();

        try {
            foreach (Tenant::where('is_active', true)->orderBy('name')->get() as $tenant) {
                $context->set($tenant);
                $items = collect($agenda->items($user));

                $clientes[] = [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'nit' => $tenant->nit,
                    'city' => $tenant->city,
                    'users_count' => User::where('tenant_id', $tenant->id)->count(),
                ] + Agenda::resumen($items->all());

                $alertas = $alertas->concat($items->whereIn('estado', ['vencido', 'por_vencer'])
                    ->map(fn (array $i) => $i + ['tenant' => ['id' => $tenant->id, 'name' => $tenant->name]]));
            }
        } finally {
            $context->set(null);
        }

        // Con alertas primero: el consultor abre el panel para saber dónde ir.
        usort($clientes, fn ($a, $b) => [$b['vencido'], $b['por_vencer'], $a['name']] <=> [$a['vencido'], $a['por_vencer'], $b['name']]);

        return Inertia::render('dashboard', [
            'view' => 'master',
            'stats' => [
                'clients' => Tenant::count(),
                'clients_active' => Tenant::where('is_active', true)->count(),
                'client_users' => User::whereNotNull('tenant_id')->count(),
                'cmk_users' => User::whereNull('tenant_id')->count(),
            ],
            'clientes' => $clientes,
            'alertas' => $alertas->sortBy('fecha')->take(self::ALERTAS_CONSOLIDADAS)->values()->all(),
            'agenda_dias' => ['por_vencer' => Agenda::DIAS_POR_VENCER, 'horizonte' => Agenda::HORIZONTE],
        ]);
    }
}
