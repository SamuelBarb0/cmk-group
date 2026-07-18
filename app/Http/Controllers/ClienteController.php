<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestión de empresas CLIENTE (tenants) de CMK GROUP.
 * Primer módulo funcional (F2): CRUD completo protegido por permiso.
 *   - Ver listado  -> clients.view
 *   - Crear/editar/eliminar -> clients.manage
 */
class ClienteController extends Controller
{
    public function index(Request $request): Response
    {
        $clients = Tenant::query()
            ->withCount('users')
            ->latest()
            ->get(['id', 'name', 'legal_name', 'nit', 'email', 'phone', 'city', 'address', 'is_active']);

        return Inertia::render('clientes/index', [
            'clients' => $clients,
            'stats' => [
                'total' => $clients->count(),
                'active' => $clients->where('is_active', true)->count(),
                'users' => $clients->sum('users_count'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        Tenant::create($data);

        return back()->with('success', 'Cliente creado correctamente.');
    }

    public function update(Request $request, Tenant $cliente): RedirectResponse
    {
        $data = $this->validated($request, $cliente);

        $cliente->update($data);

        return back()->with('success', 'Cliente actualizado correctamente.');
    }

    public function destroy(Tenant $cliente): RedirectResponse
    {
        $cliente->delete();

        return back()->with('success', 'Cliente eliminado.');
    }

    /**
     * Selecciona el cliente activo con el que trabajará el consultor.
     * Guarda active_tenant_id en sesión; SetCurrentTenant lo usa para
     * segregar la información de todos los módulos por-cliente.
     */
    public function select(Request $request, Tenant $cliente): RedirectResponse
    {
        abort_unless((bool) $request->user()?->belongsToCmk(), 403);

        $request->session()->put('active_tenant_id', $cliente->id);

        return back()->with('success', "Trabajando con el cliente: {$cliente->name}.");
    }

    /** Vuelve a la vista consolidada (sin cliente activo). */
    public function clearSelection(Request $request): RedirectResponse
    {
        $request->session()->forget('active_tenant_id');

        return back()->with('success', 'Volviste a la vista consolidada.');
    }

    /**
     * Reglas de validación en español, compartidas por store/update.
     */
    private function validated(Request $request, ?Tenant $cliente = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'nit' => [
                'nullable', 'string', 'max:30',
                Rule::unique('tenants', 'nit')->ignore($cliente?->id),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }
}
