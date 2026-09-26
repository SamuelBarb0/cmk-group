<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Clientes\AlcanceDocumental;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
    public function __construct(private readonly AlcanceDocumental $alcance) {}

    public function index(Request $request): Response
    {
        $clients = Tenant::query()
            ->withCount('users')
            ->latest()
            ->get(['id', 'name', 'legal_name', 'nit', 'email', 'phone', 'city', 'address', 'is_active', 'modulos', 'submodulos', 'documentos_sig']);

        return Inertia::render('clientes/index', [
            'clients' => $clients,
            'stats' => [
                'total' => $clients->count(),
                'active' => $clients->where('is_active', true)->count(),
                'users' => $clients->sum('users_count'),
            ],
            // Catálogo de módulos contratables (clave => etiqueta) para el diálogo.
            'modulosCatalogo' => config('cmk.modulos_contratables'),
            // Partes de cada módulo que se contratan por separado (modulo => parte => nombre).
            'submodulosCatalogo' => collect(config('cmk.submodulos'))
                ->map(fn (array $partes) => collect($partes)->map(fn (array $p) => $p['nombre'])->all())->all(),
            // Mapa documental del SIG: lo que CMK elige para el cliente, y qué
            // pantallas y partes enciende cada documento (vista previa en vivo).
            'catalogoSig' => $this->alcance->catalogo(),
            'reglasAlcance' => $this->alcance->reglas(),
            'herramientas' => collect(config('cmk.herramientas'))
                ->mapWithKeys(fn (string $m) => [$m => config("cmk.modulos_contratables.{$m}")])->all(),
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
        $data = $request->validate([
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
            // Módulos contratados por la empresa (claves del catálogo).
            'modulos' => ['nullable', 'array'],
            'modulos.*' => [Rule::in(array_keys(config('cmk.modulos_contratables')))],
            // Partes por módulo: {modulo: [partes]}.
            'submodulos' => ['nullable', 'array'],
            'submodulos.*' => ['array'],
            // Selección por el mapa documental: si viene, manda sobre las dos de arriba.
            'documentos_sig' => ['sometimes', 'array', 'min:1'],
            'documentos_sig.*' => ['integer', 'exists:document_catalog,id'],
            'herramientas' => ['sometimes', 'array'],
            'herramientas.*' => [Rule::in(config('cmk.herramientas'))],
        ], [
            'documentos_sig.min' => 'Elige al menos un documento del mapa del SIG.',
        ]);

        if (array_key_exists('documentos_sig', $data)) {
            $alcance = $this->alcance->deducir($data['documentos_sig'], $data['herramientas'] ?? []);
            unset($data['herramientas']);

            return array_merge($data, $alcance);
        }

        // Si contrató todos los módulos, se guarda null (= todos, incluye futuros).
        if (array_key_exists('modulos', $data)) {
            $todos = array_keys(config('cmk.modulos_contratables'));
            $data['modulos'] = ($data['modulos'] === null || count($data['modulos']) === count($todos))
                ? null
                : array_values($data['modulos']);
        }
        if (array_key_exists('submodulos', $data)) {
            // Las partes de un módulo no contratado no se guardan: al volver a
            // contratarlo arranca con todas.
            $pedidas = collect($data['submodulos'] ?? [])
                ->filter(fn ($l, $modulo) => ($data['modulos'] ?? null) === null || in_array($modulo, $data['modulos'], true))->all();
            $data['submodulos'] = $this->normalizarPartes($pedidas);
        }

        return $data;
    }

    /**
     * Deja solo módulos y partes del catálogo, y guarda únicamente los
     * módulos a los que se les quitó alguna parte: con todas marcadas el
     * módulo no se guarda (= todas, incluidas las que se agreguen después).
     * Un módulo con todas sus partes quitadas se rechaza: para eso se quita
     * el módulo.
     *
     * @param  array<string, mixed>  $pedidas
     * @return array<string, list<string>>|null
     */
    private function normalizarPartes(array $pedidas): ?array
    {
        $catalogo = config('cmk.submodulos');
        $partes = [];
        foreach ($pedidas as $modulo => $lista) {
            if (! isset($catalogo[$modulo]) || ! is_array($lista)) {
                throw ValidationException::withMessages(['submodulos' => "El módulo «{$modulo}» no tiene partes."]);
            }
            $todas = array_keys($catalogo[$modulo]);
            $elegidas = array_values(array_intersect($todas, $lista));
            if (count($elegidas) !== count(array_unique($lista))) {
                throw ValidationException::withMessages(['submodulos' => "Hay una parte que no existe en el módulo «{$modulo}»."]);
            }
            if ($elegidas === []) {
                $nombre = config("cmk.modulos_contratables.{$modulo}", $modulo);
                throw ValidationException::withMessages(['submodulos' => "{$nombre}: marca al menos una parte o quita el módulo."]);
            }
            if (count($elegidas) < count($todas)) {
                $partes[$modulo] = $elegidas;
            }
        }

        return $partes === [] ? null : $partes;
    }
}
