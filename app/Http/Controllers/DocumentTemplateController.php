<?php

namespace App\Http\Controllers;

use App\Models\DocumentTemplate;
use App\Services\PlantillaImporter;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * Plantillas de documentos subidas desde la plataforma.
 *
 * Hasta ahora el catalogo solo crecia por seeder, con el texto de cada modelo
 * extraido a mano a un .txt. Aqui el consultor sube el .docx y la plataforma
 * hace ese paso sola.
 *
 * Dos alcances (ver DocumentTemplate):
 *  - global: catalogo de CMK, lo ven todas las empresas. SOLO el admin de CMK.
 *  - cliente: formato propio de la empresa activa, solo lo ve ella.
 *
 * Permisos: ver -> documents.view | subir/editar/borrar -> documents.manage
 */
class DocumentTemplateController extends Controller
{
    /**
     * El catalogo global lo toca SOLO el administrador de CMK.
     *
     * Publicar ahi le cambia el catalogo a todas las empresas a la vez, asi que
     * no basta con ser del equipo: un consultor operativo sube plantillas para
     * la empresa con la que esta trabajando, como cualquier otro usuario.
     */
    private const ROL_CATALOGO_GLOBAL = 'consultor_admin';

    public function __construct(
        private readonly TenantContext $context,
        private readonly PlantillaImporter $importer,
    ) {}

    public function index(Request $request): Response
    {
        $tenantId = $this->context->id();

        $plantillas = DocumentTemplate::visibles($tenantId)
            ->orderBy('tenant_id')
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get()
            ->map(fn (DocumentTemplate $t) => [
                'id' => $t->id,
                'codigo' => $t->codigo,
                'nombre' => $t->nombre,
                'tipo' => $t->tipo,
                'categoria' => $t->categoria,
                'normas' => $t->normas ?? [],
                'descripcion' => $t->descripcion,
                'tiene_base' => $t->tieneBase(),
                'conserva_formato' => filled($t->archivo),
                'es_global' => $t->esGlobal(),
                'subido_por' => $t->subido_por,
                'subida_at' => $t->subida_at?->toDateString(),
                'caracteres' => mb_strlen((string) $t->contenido_base),
                'editable' => $this->puedeEditar($request, $t),
            ]);

        return Inertia::render('documentos-ia/plantillas', [
            'plantillas' => $plantillas,
            'puedeGestionar' => (bool) $request->user()?->can('documents.manage'),
            'puedeSubirGlobal' => $this->puedeTocarCatalogoGlobal($request),
            'tenant' => $this->context->has()
                ? ['id' => $this->context->id(), 'name' => $this->context->get()->name]
                : null,
            'extensiones' => PlantillaImporter::EXTENSIONES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request, archivoObligatorio: true);
        $tenantId = $this->tenantDestino($request, $datos['alcance']);

        $this->exigirCodigoLibre($datos['codigo'], $tenantId);

        try {
            $contenido = $this->importer->extraer($request->file('archivo'));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }

        $plantilla = new DocumentTemplate([
            'tenant_id' => $tenantId,
            'codigo' => $datos['codigo'],
            'nombre' => $datos['nombre'],
            'tipo' => $datos['tipo'],
            'categoria' => $datos['categoria'],
            'normas' => $this->normas($datos['normas'] ?? null),
            'descripcion' => $datos['descripcion'] ?? null,
            'contenido_base' => $contenido,
            'prompt' => $datos['prompt'] ?: $this->promptPorDefecto($datos['nombre'], $datos['tipo']),
            'orden' => 900,
            'subido_por' => $request->user()?->name,
            'subida_at' => now(),
        ]);

        $plantilla->archivo = $this->guardarOriginal($request->file('archivo'), $datos['codigo'], $tenantId);
        $plantilla->save();

        return back()->with('success', "Plantilla «{$plantilla->nombre}» disponible para generar documentos.");
    }

    public function update(Request $request, DocumentTemplate $plantilla): RedirectResponse
    {
        $this->autorizar($request, $plantilla);

        $datos = $this->validar($request, archivoObligatorio: false);

        // El alcance no se cambia aqui: mover una plantilla de un cliente al
        // catalogo global (o al reves) cambia quien la ve, y merece borrarla y
        // volverla a subir a conciencia en vez de un desplegable a medio camino.
        $this->exigirCodigoLibre($datos['codigo'], $plantilla->tenant_id, $plantilla->id);

        $plantilla->fill([
            'codigo' => $datos['codigo'],
            'nombre' => $datos['nombre'],
            'tipo' => $datos['tipo'],
            'categoria' => $datos['categoria'],
            'normas' => $this->normas($datos['normas'] ?? null),
            'descripcion' => $datos['descripcion'] ?? null,
            'prompt' => $datos['prompt'] ?: $plantilla->prompt,
        ]);

        // Solo si suben archivo nuevo se reemplaza el contenido: guardar los
        // metadatos no debe borrar el modelo que ya estaba cargado.
        if ($request->hasFile('archivo')) {
            try {
                $contenido = $this->importer->extraer($request->file('archivo'));
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
            }

            $this->borrarOriginal($plantilla);

            $plantilla->contenido_base = $contenido;
            $plantilla->archivo = $this->guardarOriginal($request->file('archivo'), $datos['codigo'], $plantilla->tenant_id);
            $plantilla->subido_por = $request->user()?->name;
            $plantilla->subida_at = now();
        }

        $plantilla->save();

        return back()->with('success', 'Plantilla actualizada.');
    }

    public function destroy(Request $request, DocumentTemplate $plantilla): RedirectResponse
    {
        $this->autorizar($request, $plantilla);

        $this->borrarOriginal($plantilla);
        $nombre = $plantilla->nombre;
        $plantilla->delete();

        return back()->with('success', "Plantilla «{$nombre}» eliminada.");
    }

    /**
     * Reglas comunes de alta y edicion.
     *
     * @return array<string,mixed>
     */
    private function validar(Request $request, bool $archivoObligatorio): array
    {
        return $request->validate([
            // `extensions` y NO `mimes`: la regla mimes adivina el tipo con finfo,
            // y en el XAMPP de desarrollo un .docx sale como application/octet-stream,
            // asi que rechazaria subidas legitimas. Que el archivo sea de verdad un
            // .docx lo comprueba PlantillaImporter, que le abre el zip y busca el
            // cuerpo del documento: mas fiable que olfatear el mime.
            'archivo' => [
                $archivoObligatorio ? 'required' : 'nullable',
                'file',
                'max:20480',
                'extensions:'.implode(',', PlantillaImporter::EXTENSIONES),
            ],
            'codigo' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/'],
            'nombre' => ['required', 'string', 'max:255'],
            'tipo' => ['required', 'string', 'max:40'],
            'categoria' => ['required', Rule::in(['SGI', 'SST', 'PESV', 'HSEQ'])],
            'normas' => ['nullable', 'string', 'max:400'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'prompt' => ['nullable', 'string', 'max:4000'],
            'alcance' => ['required', Rule::in(['global', 'cliente'])],
        ], [
            'archivo.extensions' => 'La plantilla debe ser un archivo '.implode(', ', PlantillaImporter::EXTENSIONES).'.',
            'archivo.max' => 'El archivo no puede pasar de 20 MB.',
            'codigo.regex' => 'El codigo solo admite letras, numeros y guiones (p. ej. POL-SGI).',
        ]);
    }

    /** A quien pertenece la plantilla segun el alcance pedido. */
    private function tenantDestino(Request $request, string $alcance): ?int
    {
        if ($alcance === 'global') {
            if (! $this->puedeTocarCatalogoGlobal($request)) {
                throw ValidationException::withMessages([
                    'alcance' => 'Solo el administrador de CMK puede publicar plantillas en el catalogo compartido. Subela para la empresa activa.',
                ]);
            }

            return null;
        }

        if (! $this->context->has()) {
            throw ValidationException::withMessages([
                'alcance' => 'Selecciona una empresa cliente antes de subirle una plantilla propia.',
            ]);
        }

        return $this->context->id();
    }

    /**
     * El indice unico es (tenant_id, codigo), pero un choque debe verse como un
     * error de formulario y no como un 500 de base de datos.
     */
    private function exigirCodigoLibre(string $codigo, ?int $tenantId, ?int $exceptoId = null): void
    {
        $existe = DocumentTemplate::where('codigo', $codigo)
            ->where('tenant_id', $tenantId)
            ->when($exceptoId !== null, fn ($q) => $q->whereKeyNot($exceptoId))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'codigo' => "Ya hay una plantilla con el codigo «{$codigo}» en este alcance.",
            ]);
        }
    }

    /**
     * Guarda el .docx original de la plantilla.
     *
     * DocxExporter usa `archivo` para exportar sobre el Word original con
     * TemplateProcessor: eso conserva el membrete, el logo y las tablas exactas.
     *
     * ANTES solo se guardaba si el .docx traia `${TOKEN}`, con el razonamiento de
     * que sin tokens no hay nada que sustituir y el export ignoraria lo redactado.
     * El efecto real fue el contrario: los formatos y actas —que son tablas y
     * membrete, y casi nunca llevan tokens— perdian TODO el formato y salian como
     * un muro de texto. Se guarda siempre; quien decide si se usa el modelo o se
     * reconstruye es `DocxExporter::debeUsarModelo()`, que respeta las ediciones.
     */
    private function guardarOriginal(UploadedFile $archivo, string $codigo, ?int $tenantId): ?string
    {
        if (strtolower($archivo->getClientOriginalExtension()) !== 'docx') {
            return null;
        }

        $carpeta = $tenantId === null ? 'plantillas-base/global' : "plantillas-base/tenant-{$tenantId}";
        $ruta = $carpeta.'/'.Str::slug($codigo).'.docx';

        Storage::disk('local')->put($ruta, file_get_contents($archivo->getRealPath()));

        return $ruta;
    }

    private function borrarOriginal(DocumentTemplate $plantilla): void
    {
        // Las plantillas que trae el seeder comparten carpeta con los modelos
        // oficiales de CMK: solo se borran los archivos que subio la plataforma.
        if (blank($plantilla->archivo) || ! Str::contains($plantilla->archivo, ['plantillas-base/global/', 'plantillas-base/tenant-'])) {
            return;
        }

        try {
            Storage::disk('local')->delete($plantilla->archivo);
        } catch (Throwable) {
            // Un archivo que ya no esta no debe impedir borrar la plantilla.
        }
    }

    /**
     * Las normas llegan como texto separado por comas (el formulario viaja en
     * multipart por el archivo, donde un array es incomodo de armar).
     *
     * @return array<int,string>
     */
    private function normas(?string $texto): array
    {
        if (blank($texto)) {
            return [];
        }

        return collect(explode(',', $texto))
            ->map(fn (string $n) => trim($n))
            ->filter()
            ->unique()
            ->take(10)
            ->values()
            ->all();
    }

    /** Instruccion de redaccion por defecto cuando el consultor no escribe una. */
    private function promptPorDefecto(string $nombre, string $tipo): string
    {
        return "Redacta el documento «{$nombre}» ({$tipo}) para la empresa cliente, "
            .'siguiendo la estructura y el estilo del documento modelo cargado en esta plantilla. '
            .'Conserva sus secciones y adapta los datos a la empresa. '
            .'No inventes informacion: escribe [PENDIENTE] donde falten datos.';
    }

    private function autorizar(Request $request, DocumentTemplate $plantilla): void
    {
        if ($plantilla->esGlobal()) {
            abort_unless($this->puedeTocarCatalogoGlobal($request), 403, 'Solo el administrador de CMK puede editar el catalogo compartido.');

            return;
        }

        // Una plantilla de cliente solo la toca quien esta trabajando con ese
        // cliente: sin esto, cambiar de empresa en el selector daria acceso a
        // los formatos de otra.
        abort_unless($plantilla->tenant_id === $this->context->id(), 403);
    }

    /**
     * Version sin abortar de autorizar(), para pintar (o no) los botones de
     * editar y borrar de cada tarjeta.
     */
    private function puedeEditar(Request $request, DocumentTemplate $plantilla): bool
    {
        if (! $request->user()?->can('documents.manage')) {
            return false;
        }

        return $plantilla->esGlobal()
            ? $this->puedeTocarCatalogoGlobal($request)
            : $plantilla->tenant_id === $this->context->id();
    }

    private function puedeTocarCatalogoGlobal(Request $request): bool
    {
        return (bool) $request->user()?->hasRole(self::ROL_CATALOGO_GLOBAL);
    }
}
