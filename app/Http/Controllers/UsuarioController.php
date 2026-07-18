<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestión de usuarios de la plataforma CMK.
 *   - Ver listado -> users.view
 *   - Crear/editar/eliminar -> users.manage
 *
 * Cada usuario tiene UN rol (config cmk.roles). Los roles con scope "cmk"
 * (consultores) no pertenecen a ningún cliente (tenant_id = null); los de
 * scope "client" pertenecen a una empresa cliente (tenant obligatorio).
 */
class UsuarioController extends Controller
{
    public function index(): Response
    {
        $roles = collect(config('cmk.roles'));

        $users = User::with(['tenant:id,name', 'roles:id,name'])
            ->latest()
            ->get(['id', 'name', 'email', 'tenant_id', 'is_active'])
            ->map(function (User $u) use ($roles) {
                $role = $u->roles->first()?->name;
                return [
                    'id'         => $u->id,
                    'name'       => $u->name,
                    'email'      => $u->email,
                    'is_active'  => (bool) $u->is_active,
                    'role'       => $role,
                    'role_label' => $role ? ($roles[$role]['label'] ?? $role) : '—',
                    'tenant_id'  => $u->tenant_id,
                    'tenant'     => $u->tenant?->name,
                ];
            });

        return Inertia::render('usuarios/index', [
            'users'   => $users,
            'roles'   => $roles->map(fn ($r, $key) => [
                'value' => $key,
                'label' => $r['label'],
                'scope' => $r['scope'],
            ])->values(),
            'tenants' => Tenant::orderBy('name')->get(['id', 'name']),
            'stats'   => [
                'total'  => $users->count(),
                'cmk'    => $users->whereIn('role', ['consultor_admin', 'consultor_operativo'])->count(),
                'client' => $users->filter(fn ($u) => $u['tenant_id'] !== null)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => $data['password'], // el cast 'hashed' lo encripta
            'tenant_id' => $this->tenantForRole($data['role'], $data['tenant_id'] ?? null),
            'is_active' => $data['is_active'] ?? true,
        ]);
        $user->syncRoles([$data['role']]);

        return back()->with('success', 'Usuario creado correctamente.');
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $data = $this->validated($request, $usuario);

        $usuario->name      = $data['name'];
        $usuario->email     = $data['email'];
        $usuario->tenant_id = $this->tenantForRole($data['role'], $data['tenant_id'] ?? null);
        $usuario->is_active = $data['is_active'] ?? true;
        if (! empty($data['password'])) {
            $usuario->password = $data['password'];
        }
        $usuario->save();
        $usuario->syncRoles([$data['role']]);

        return back()->with('success', 'Usuario actualizado correctamente.');
    }

    public function destroy(Request $request, User $usuario): RedirectResponse
    {
        if ($usuario->id === $request->user()->id) {
            return back()->with('error', 'No puedes eliminar tu propio usuario.');
        }

        $usuario->delete();

        return back()->with('success', 'Usuario eliminado.');
    }

    /** Los roles de consultor no pertenecen a ningún cliente. */
    private function tenantForRole(string $role, ?int $tenantId): ?int
    {
        return config("cmk.roles.{$role}.scope") === 'client' ? $tenantId : null;
    }

    private function validated(Request $request, ?User $usuario = null): array
    {
        $roleKeys = array_keys(config('cmk.roles'));

        return $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario?->id)],
            'password'  => [$usuario ? 'nullable' : 'required', 'nullable', Password::min(8)],
            'role'      => ['required', Rule::in($roleKeys)],
            'tenant_id' => [
                'nullable', 'exists:tenants,id',
                function ($attr, $value, $fail) use ($request) {
                    $scope = config('cmk.roles.' . $request->input('role') . '.scope');
                    if ($scope === 'client' && empty($value)) {
                        $fail('Debes seleccionar el cliente para este rol.');
                    }
                },
            ],
            'is_active' => ['boolean'],
        ], [
            'required' => 'Este campo es obligatorio.',
            'email'    => 'Debe ser un correo válido.',
            'unique'   => 'Ya existe un usuario con este correo.',
        ]);
    }
}
