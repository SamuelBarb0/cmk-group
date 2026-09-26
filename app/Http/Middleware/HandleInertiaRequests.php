<?php

namespace App\Http\Middleware;

use App\Models\PesvStep;
use App\Support\TenantContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            // Módulos contratados por la empresa activa (null = todos / sin cliente activo).
            // Prop de nivel superior: los controladores pisan 'tenant' con su propia
            // versión por página y se perdería si viajara dentro de 'tenant'.
            'modulos_contratados' => $context->get()?->modulos,
            // Partes contratadas de los módulos que tienen partes, ya resueltas
            // (null sin cliente activo = todo visible).
            'partes_contratadas' => $context->get()?->partesContratadas(),
            // Código del mapa documental del SIG de cada pantalla (M01–M20), para
            // nombrar los módulos igual que el mapa. El primero es el principal.
            'codigos_sig' => collect(config('cmk.alcance_documental.pantallas'))
                ->map(fn (array $reglas) => array_values(array_unique(array_column($reglas, 0))))->all(),
            // Los 24 pasos del PESV para armar el árbol del sidebar. Es un
            // catálogo global e inmutable, así que se cachea y no se vuelve a
            // consultar; se comparte aquí para no duplicar los títulos en el
            // TypeScript y que se desincronicen del seeder.
            'pesv_pasos' => $this->pesvPasos(),
            'company' => config('cmk.company'),
            // Mensajes flash de una sola vez (confirmaciones de acciones).
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ]);
    }

    /**
     * Catálogo de pasos del PESV para el árbol del sidebar.
     *
     * Cacheado para siempre porque no cambia: si algún día se reordenan los
     * pasos, el seeder debe limpiar la clave 'pesv.pasos.nav'.
     *
     * @return array<int, array{numero: int, fase: int, fase_nombre: string, titulo: string}>
     */
    private function pesvPasos(): array
    {
        return Cache::rememberForever('pesv.pasos.nav', fn () => PesvStep::orderBy('orden')
            ->get(['numero', 'fase', 'fase_nombre', 'titulo'])
            ->toArray());
    }
}
