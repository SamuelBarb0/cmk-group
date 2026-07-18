<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $user = $request->user();
        $context = app(TenantContext::class);

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $user,
                'roles' => $user ? $user->getRoleNames()->values() : [],
                'permissions' => $user ? $user->getAllPermissions()->pluck('name')->values() : [],
                'is_cmk' => $user ? $user->belongsToCmk() : false,
                'role_label' => $user?->primaryRoleLabel(),
            ],
            // Tenant activo: cliente seleccionado por el consultor, o empresa del usuario cliente.
            'tenant' => $context->has()
                ? ['id' => $context->get()?->id, 'name' => $context->get()?->name]
                : null,
            'company' => config('cmk.company'),
            // Mensajes flash de una sola vez (confirmaciones de acciones).
            'flash' => [
                'success' => $request->session()->get('success'),
            ],
        ]);
    }
}
