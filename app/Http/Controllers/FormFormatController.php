<?php

namespace App\Http\Controllers;

use App\Models\FormFormat;
use App\Models\FormRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de formatos (motor Tier 4): crear, editar y retirar formatos sin
 * pasar por el seeder.
 *
 * El catálogo es GLOBAL (lo ven todas las empresas), así que solo lo toca el
 * administrador de CMK, igual que el catálogo de plantillas de documentos.
 *
 * Editar un formato es seguro para lo ya diligenciado: cada registro guarda su
 * propia copia del esquema al crearse (FormatoController::store). Los cambios
 * solo aplican a los registros nuevos.
 *
 * Todo lo editado aquí queda marcado (`editado_at`) y FormFormatsSeeder deja
 * de pisarlo en los despliegues.
 */
class FormFormatController extends Controller
{
    private const ROL_CATALOGO_GLOBAL = 'consultor_admin';

    public function index(Request $request): Response
    {
        $this->autorizar($request);

        // Registros de TODAS las empresas: es lo que decide si se puede borrar.
        $usos = FormRecord::withoutTenantScope()
            ->selectRaw('form_format_id, count(*) as total')
            ->groupBy('form_format_id')
            ->pluck('total', 'form_format_id');

        return Inertia::render('formatos/catalogo', [
            'formats' => FormFormat::orderBy('orden')->orderBy('id')->get()
                ->map(fn (FormFormat $f) => [
                    ...$f->only(['id', 'codigo', 'nombre', 'categoria', 'grupo', 'descripcion', 'activo', 'editado_por']),
                    'editado_at' => $f->editado_at?->toDateTimeString(),
                    'secciones' => count($f->schema['secciones'] ?? []),
                    'campos' => collect($f->schema['secciones'] ?? [])->sum(fn ($s) => count($s['campos'] ?? [])),
                    'registros' => (int) ($usos[$f->id] ?? 0),
                ]),
        ]);
    }

    /** Editor vacío, o copia de un formato existente con `?desde=ID`. */
    public function create(Request $request): Response
    {
        $this->autorizar($request);

        $base = $request->integer('desde') ? FormFormat::find($request->integer('desde')) : null;

        return $this->editor($base ? [
            ...$base->only(['categoria', 'grupo', 'descripcion', 'schema']),
            'codigo' => $this->codigoLibre($base->codigo.'-COPIA'),
            'nombre' => $base->nombre.' (copia)',
            'orden' => $base->orden + 1,
        ] : null, null);
    }

    public function edit(Request $request, FormFormat $formFormat): Response
    {
        $this->autorizar($request);

        return $this->editor(
            $formFormat->only(['id', 'codigo', 'nombre', 'categoria', 'grupo', 'descripcion', 'schema', 'orden', 'activo']),
            FormRecord::withoutTenantScope()->where('form_format_id', $formFormat->id)->count(),
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $this->autorizar($request);

        $formato = FormFormat::create([
            ...$this->validar($request),
            'activo' => true,
            'editado_at' => now(),
            'editado_por' => $request->user()->name,
        ]);

        return redirect()->route('formatos.catalogo.index')->with('success', "Formato «{$formato->nombre}» creado.");
    }

    public function update(Request $request, FormFormat $formFormat): RedirectResponse
    {
        $this->autorizar($request);

        $formFormat->update([
            ...$this->validar($request, $formFormat),
            'editado_at' => now(),
            'editado_por' => $request->user()->name,
        ]);

        return redirect()->route('formatos.catalogo.index')->with('success', "Formato «{$formFormat->nombre}» guardado.");
    }

    /**
     * Retirar un formato lo saca de «Formatos disponibles» sin tocar sus
     * registros. No marca `editado_at`: el seeder no maneja `activo`, así que
     * no lo revertiría, y el formato sigue recibiendo sus correcciones.
     */
    public function toggle(Request $request, FormFormat $formFormat): RedirectResponse
    {
        $this->autorizar($request);

        $formFormat->update(['activo' => ! $formFormat->activo]);

        return back()->with('success', $formFormat->activo
            ? "Formato «{$formFormat->nombre}» activado."
            : "Formato «{$formFormat->nombre}» retirado: ya no aparece para diligenciar.");
    }

    /** Solo si nadie lo ha usado: si tiene registros, se retira en vez de borrarse. */
    public function destroy(Request $request, FormFormat $formFormat): RedirectResponse
    {
        $this->autorizar($request);

        $usos = FormRecord::withoutTenantScope()->where('form_format_id', $formFormat->id)->count();
        if ($usos > 0) {
            return back()->withErrors(['formato' => "«{$formFormat->nombre}» tiene {$usos} registro(s) diligenciado(s). Retíralo en lugar de eliminarlo."]);
        }

        $formFormat->delete();

        return back()->with('success', "Formato «{$formFormat->nombre}» eliminado.");
    }

    private function editor(?array $formato, ?int $registros): Response
    {
        return Inertia::render('formatos/editor', [
            'formato' => $formato,
            'registros' => $registros,
            'tipos' => FormFormat::TIPOS_CAMPO,
            'grupos' => FormFormat::GRUPOS,
            'categorias' => FormFormat::CATEGORIAS,
        ]);
    }

    /**
     * Valida y NORMALIZA el formato. El esquema se reconstruye campo a campo
     * con solo lo que el motor entiende: nada que llegue de más se guarda.
     */
    private function validar(Request $request, ?FormFormat $actual = null): array
    {
        $request->merge(['codigo' => Str::upper(trim((string) $request->input('codigo')))]);

        $v = $request->validate([
            'codigo' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9-]*$/', Rule::unique('form_formats', 'codigo')->ignore($actual?->id)],
            'nombre' => ['required', 'string', 'max:255'],
            'categoria' => ['required', Rule::in(FormFormat::CATEGORIAS)],
            'grupo' => ['required', Rule::in(array_keys(FormFormat::GRUPOS))],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'schema' => ['required', 'array'],
            'schema.secciones' => ['required', 'array', 'min:1', 'max:30'],
            'schema.secciones.*.titulo' => ['required', 'string', 'max:255'],
            'schema.secciones.*.campos' => ['required', 'array', 'min:1', 'max:100'],
            'schema.secciones.*.campos.*.key' => ['nullable', 'string', 'max:60'],
            'schema.secciones.*.campos.*.label' => ['required', 'string', 'max:500'],
            'schema.secciones.*.campos.*.tipo' => ['required', Rule::in(array_keys(FormFormat::TIPOS_CAMPO))],
            'schema.secciones.*.campos.*.requerido' => ['nullable', 'boolean'],
            'schema.secciones.*.campos.*.opciones' => ['nullable', 'array', 'required_if:schema.secciones.*.campos.*.tipo,select', 'max:50'],
            // Líneas en blanco se descartan al normalizar, no son un error.
            'schema.secciones.*.campos.*.opciones.*' => ['nullable', 'string', 'max:255'],
            'schema.secciones.*.campos.*.items' => ['nullable', 'array', 'required_if:schema.secciones.*.campos.*.tipo,checklist', 'max:200'],
            'schema.secciones.*.campos.*.items.*' => ['nullable', 'string', 'max:500'],
        ], [
            'codigo.regex' => 'El código solo admite letras, números y guiones (ej. INS-EXT-01).',
            'schema.secciones.required' => 'El formato necesita al menos una sección.',
            'schema.secciones.*.campos.required' => 'Cada sección necesita al menos un campo.',
            'schema.secciones.*.campos.*.opciones.required_if' => 'Una lista de opciones necesita al menos una opción.',
            'schema.secciones.*.campos.*.items.required_if' => 'Una lista de chequeo necesita al menos un ítem.',
        ], [
            'schema.secciones.*.titulo' => 'título de la sección',
            'schema.secciones.*.campos.*.label' => 'etiqueta del campo',
            'schema.secciones.*.campos.*.tipo' => 'tipo de campo',
            'schema.secciones.*.campos.*.opciones' => 'opciones',
            'schema.secciones.*.campos.*.items' => 'ítems',
        ]);

        $usadas = [];
        $secciones = [];
        $vacias = [];
        foreach ($v['schema']['secciones'] as $i => $s) {
            $campos = [];
            foreach ($s['campos'] as $k => $c) {
                $campo = [
                    'key' => $this->claveUnica($c['key'] ?? null, $c['label'], $usadas),
                    'label' => trim($c['label']),
                    'tipo' => $c['tipo'],
                ];
                if (! empty($c['requerido'])) {
                    $campo['requerido'] = true;
                }
                $lista = ['select' => 'opciones', 'checklist' => 'items'][$c['tipo']] ?? null;
                if ($lista) {
                    $campo[$lista] = $this->lista($c[$lista] ?? []);
                    // Solo líneas en blanco: pasa required_if pero queda vacía.
                    if ($campo[$lista] === []) {
                        $vacias["schema.secciones.{$i}.campos.{$k}.{$lista}"] = $lista === 'opciones'
                            ? 'Una lista de opciones necesita al menos una opción.'
                            : 'Una lista de chequeo necesita al menos un ítem.';
                    }
                }
                $campos[] = $campo;
            }
            $secciones[] = ['titulo' => trim($s['titulo']), 'campos' => $campos];
        }
        if ($vacias) {
            throw ValidationException::withMessages($vacias);
        }

        return [
            'codigo' => $v['codigo'],
            'nombre' => trim($v['nombre']),
            'categoria' => $v['categoria'],
            'grupo' => $v['grupo'],
            'descripcion' => filled($v['descripcion'] ?? null) ? trim($v['descripcion']) : null,
            'orden' => $v['orden'] ?? ($actual?->orden ?? ((int) FormFormat::max('orden') + 10)),
            'schema' => ['secciones' => $secciones],
        ];
    }

    /**
     * La clave es donde se guarda el valor en `data` del registro. La de un
     * campo existente se respeta (cambiar la etiqueta no la cambia); la de uno
     * nuevo sale de la etiqueta. Nunca se repite dentro del formato.
     */
    private function claveUnica(?string $clave, string $label, array &$usadas): string
    {
        $base = $clave !== null && preg_match('/^[a-z0-9_]+$/', $clave)
            ? $clave
            : (Str::slug(Str::limit($label, 40, ''), '_') ?: 'campo');

        $key = $base;
        for ($i = 2; in_array($key, $usadas, true); $i++) {
            $key = "{$base}_{$i}";
        }
        $usadas[] = $key;

        return $key;
    }

    /** @param  array<int, ?string>  $valores */
    private function lista(array $valores): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($x) => trim((string) $x), $valores), 'strlen')));
    }

    private function codigoLibre(string $codigo): string
    {
        $codigo = Str::limit($codigo, 26, '');
        $libre = $codigo;
        for ($i = 2; FormFormat::where('codigo', $libre)->exists(); $i++) {
            $libre = "{$codigo}{$i}";
        }

        return $libre;
    }

    private function autorizar(Request $request): void
    {
        abort_unless($request->user()?->hasRole(self::ROL_CATALOGO_GLOBAL), 403, 'Solo el administrador de CMK puede editar el catálogo de formatos.');
    }
}
