<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): Response
    {
        $user = $request->user();

        // Dashboard maestro consolidado (personal de CMK, sin cliente seleccionado).
        if ($user->belongsToCmk() && ! $context->has()) {
            return Inertia::render('dashboard', [
                'view' => 'master',
                'stats' => [
                    'clients' => Tenant::count(),
                    'clients_active' => Tenant::where('is_active', true)->count(),
                    'client_users' => User::whereNotNull('tenant_id')->count(),
                    'cmk_users' => User::whereNull('tenant_id')->count(),
                ],
                'clients' => Tenant::query()
                    ->withCount('users')
                    ->latest()
                    ->take(8)
                    ->get(['id', 'name', 'city', 'nit', 'is_active']),
            ]);
        }

        // Dashboard individual del cliente (o consultor trabajando un cliente).
        $tenant = $context->get();

        return Inertia::render('dashboard', [
            'view' => 'client',
            'tenant' => $tenant?->only(['id', 'name', 'nit', 'city']),
            'stats' => [
                'users' => $tenant ? User::where('tenant_id', $tenant->id)->count() : 0,
            ],
        ]);
    }
}
