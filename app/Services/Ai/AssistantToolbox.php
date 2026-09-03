<?php

namespace App\Services\Ai;

use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\GeneratedDocument;
use App\Models\Indicator;
use App\Models\IpercRow;
use App\Models\SstDiagnostic;
use App\Models\Tenant;
use App\Models\WorkPlan;
use App\Services\DocumentFiller;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Herramientas que el asistente conversacional puede ejecutar dentro de la app.
 *
 * Dos familias:
 *  - LECTURA: le dan a Claude los datos reales del cliente (organización,
 *    empleados, IPERC, indicadores, plan de trabajo, diagnóstico) para que
 *    redacte con cifras y no con [PENDIENTE].
 *  - ESCRITURA: crear/actualizar documentos. SIEMPRE nacen en estado
 *    «borrador» — el consultor sigue siendo quien revisa y aprueba, igual
 *    que en el flujo de Documentos IA. Requieren permiso documents.manage.
 *
 * Toda consulta pasa por los modelos con BelongsToTenant, así que el aislamiento
 * por cliente lo garantiza el TenantScope: el asistente no puede leer ni escribir
 * datos de otra empresa aunque se lo pidan.
 */
class AssistantToolbox
{
    /** Máximo de filas que se le entregan a la IA por herramienta de lectura. */
    private const LIMITE_FILAS = 200;

    /** Listas blancas de los filtros: lo que no esté aquí se ignora. */
    private const CATEGORIAS = ['SGI', 'SST', 'PESV', 'HSEQ'];

    private const ESTADOS = ['borrador', 'en_revision', 'aprobado'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly DocumentFiller $filler,
    ) {}

    /**
     * Definiciones de las herramientas para la Messages API.
     *
     * Los filtros opcionales van fuera de `required` (ver tool()) y además se
     * contrastan contra su lista blanca al ejecutarlos.
     *
     * @return array<int,array<string,mixed>>
     */
    public function definiciones(bool $puedeEscribir): array
    {
        $tools = [
            $this->tool(
                'contexto_organizacion',
                'Datos SGI de la empresa cliente activa: NIT, actividad económica, CIIU, sector, nivel de riesgo ARL, tamaño, número de trabajadores, representante legal, responsable del SG-SST y su licencia. Úsala SIEMPRE antes de redactar un documento.',
            ),
            $this->tool(
                'listar_plantillas',
                'Catálogo de plantillas de documentos del SGI (código, nombre, tipo, categoría, normas que cubre y si tiene documento modelo base). Úsala para saber qué documentos se pueden generar. Sin parámetros devuelve TODAS.',
                [
                    'categoria' => ['type' => 'string', 'enum' => self::CATEGORIAS, 'description' => 'Filtro opcional por categoría. Omítelo para ver todas.'],
                ],
            ),
            $this->tool(
                'listar_documentos',
                'Documentos ya generados para la empresa activa, con id, título, estado y versión. Sin parámetros devuelve TODOS.',
                [
                    'estado' => ['type' => 'string', 'enum' => self::ESTADOS, 'description' => 'Filtro opcional por estado. Omítelo para ver todos.'],
                ],
            ),
            $this->tool(
                'leer_documento',
                'Contenido completo (Markdown) de un documento generado. Úsala antes de proponer cambios sobre un documento existente.',
                [
                    'id' => ['type' => 'integer', 'description' => 'Id del documento, tomado de listar_documentos.'],
                ],
                ['id'],
            ),
            $this->tool(
                'listar_empleados',
                'Trabajadores activos de la empresa: nombre, cargo, área, sede, tipo de contrato, EPS, AFP y nivel de riesgo. Útil para coberturas, matrices y actas.',
            ),
            $this->tool(
                'resumen_iperc',
                'Matriz IPERC de la empresa: peligros identificados con proceso, actividad, clasificación, controles, nivel de riesgo y aceptabilidad.',
            ),
            $this->tool(
                'resumen_indicadores',
                'Indicadores del SG-SST con su meta y las lecturas mensuales cargadas (numerador, denominador y valor calculado).',
                [
                    'anio' => ['type' => 'integer', 'description' => 'Año a consultar. Omítelo para el año en curso.'],
                ],
            ),
            $this->tool(
                'resumen_plan_trabajo',
                'Plan de trabajo anual: responsable, objetivos, metas, recursos, porcentaje de cumplimiento y actividades programadas/ejecutadas por mes.',
                [
                    'anio' => ['type' => 'integer', 'description' => 'Año del plan. Omítelo para el año en curso.'],
                ],
            ),
            $this->tool(
                'resumen_diagnostico',
                'Último diagnóstico de estándares mínimos (Resolución 0312): puntaje, clasificación e ítems que NO cumplen.',
            ),
        ];

        if (! $puedeEscribir) {
            return $tools;
        }

        return array_merge($tools, [
            $this->tool(
                'generar_desde_plantilla',
                'Genera un documento a partir de una plantilla del catálogo. Si la plantilla tiene documento modelo base, se rellena de forma exacta con los datos del cliente y el documento queda creado al instante. Si NO tiene base, devuelve la instrucción de redacción para que la escribas tú y luego llames a crear_documento.',
                [
                    'codigo' => ['type' => 'string', 'description' => 'Código de la plantilla (p. ej. POL-SGI), de listar_plantillas.'],
                ],
                ['codigo'],
            ),
            $this->tool(
                'crear_documento',
                'Crea un documento nuevo para la empresa activa, en estado borrador, con el contenido que hayas redactado. Úsala después de generar_desde_plantilla cuando la plantilla no tenga modelo base, o para documentos a medida.',
                [
                    'titulo' => ['type' => 'string', 'description' => 'Título del documento.'],
                    'contenido_markdown' => ['type' => 'string', 'description' => 'Documento completo en Markdown, listo para revisión.'],
                    'plantilla_codigo' => ['type' => 'string', 'description' => 'Código de la plantilla de origen. Omítelo si el documento es a medida.'],
                ],
                ['titulo', 'contenido_markdown'],
            ),
            $this->tool(
                'actualizar_documento',
                'Reemplaza el título y/o el contenido de un documento existente y sube su versión. No toca documentos aprobados.',
                [
                    'id' => ['type' => 'integer', 'description' => 'Id del documento a actualizar.'],
                    'titulo' => ['type' => 'string', 'description' => 'Nuevo título. Omítelo para dejar el actual.'],
                    'contenido_markdown' => ['type' => 'string', 'description' => 'Nuevo contenido completo en Markdown. Omítelo para dejar el actual.'],
                ],
                ['id'],
            ),
        ]);
    }

    /**
     * Ejecuta una herramienta y devuelve el texto que se le entrega a Claude
     * como tool_result. Nunca lanza: un fallo se le informa a la IA para que
     * lo explique o reintente por otra vía.
     *
     * @param  array<string,mixed>  $input
     */
    public function ejecutar(string $nombre, array $input, bool $puedeEscribir): string
    {
        $escritura = in_array($nombre, ['generar_desde_plantilla', 'crear_documento', 'actualizar_documento'], true);

        if ($escritura && ! $puedeEscribir) {
            return 'ERROR: este usuario no tiene permiso para crear ni modificar documentos (documents.manage). Explícale que solo puedes consultar información.';
        }

        $tenant = $this->context->get();

        if ($tenant === null) {
            return 'ERROR: no hay una empresa cliente activa. Pídele al usuario que seleccione un cliente en el selector superior antes de continuar.';
        }

        try {
            return match ($nombre) {
                'contexto_organizacion' => $this->contextoOrganizacion($tenant),
                'listar_plantillas' => $this->listarPlantillas($input['categoria'] ?? ''),
                'listar_documentos' => $this->listarDocumentos($input['estado'] ?? ''),
                'leer_documento' => $this->leerDocumento((int) ($input['id'] ?? 0)),
                'listar_empleados' => $this->listarEmpleados(),
                'resumen_iperc' => $this->resumenIperc(),
                'resumen_indicadores' => $this->resumenIndicadores((int) ($input['anio'] ?? 0)),
                'resumen_plan_trabajo' => $this->resumenPlanTrabajo((int) ($input['anio'] ?? 0)),
                'resumen_diagnostico' => $this->resumenDiagnostico(),
                'generar_desde_plantilla' => $this->generarDesdePlantilla((string) ($input['codigo'] ?? ''), $tenant),
                'crear_documento' => $this->crearDocumento($input),
                'actualizar_documento' => $this->actualizarDocumento($input),
                default => "ERROR: la herramienta «{$nombre}» no existe.",
            };
        } catch (Throwable $e) {
            Log::error('Asistente: falló la herramienta '.$nombre, ['error' => $e->getMessage()]);

            return 'ERROR al ejecutar '.$nombre.': '.$e->getMessage();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Herramientas de LECTURA
    |--------------------------------------------------------------------------
    */

    private function contextoOrganizacion(Tenant $t): string
    {
        return $this->json([
            'empresa' => $t->name,
            'razon_social' => $t->legal_name,
            'nit' => $t->nit,
            'ciudad' => $t->city,
            'direccion' => $t->address,
            'actividad_economica' => $t->actividad_economica,
            'codigo_ciiu' => $t->codigo_ciiu,
            'sector' => $t->sector,
            'nivel_riesgo_arl' => $t->nivel_riesgo,
            'arl' => $t->arl,
            'tamano_empresa' => $t->tamano_empresa,
            'num_trabajadores' => $t->num_trabajadores,
            'representante_legal' => $t->representante_legal,
            'representante_cc' => $t->representante_cc,
            'responsable_sgsst' => $t->responsable_sgsst,
            'licencia_sgsst' => $t->licencia_sgsst,
            'licencia_vence' => optional($t->licencia_sgsst_vence)->format('Y-m-d'),
            'curso_sst_horas' => $t->curso_sst_horas,
            'empleados_registrados' => Employee::where('is_active', true)->count(),
            'consultora' => config('cmk.company.legal_name').' (NIT '.config('cmk.company.nit').')',
            'fecha_de_hoy' => now()->format('Y-m-d'),
        ]);
    }

    private function listarPlantillas(string $categoria): string
    {
        // El catálogo de CMK más los formatos propios de esta empresa.
        $q = DocumentTemplate::visibles($this->context->id())->orderBy('orden');

        // Solo se filtra por un valor de la lista blanca: cualquier otra cosa
        // se ignora y se devuelve el catálogo completo, que es lo útil.
        if (in_array($categoria, self::CATEGORIAS, true)) {
            $q->where('categoria', $categoria);
        }

        return $this->json($q->get()->map(fn (DocumentTemplate $t) => [
            'codigo' => $t->codigo,
            'nombre' => $t->nombre,
            'tipo' => $t->tipo,
            'categoria' => $t->categoria,
            'normas' => $t->normas,
            'descripcion' => $t->descripcion,
            'tiene_modelo_base' => $t->tieneBase(),
            'alcance' => $t->esGlobal() ? 'catálogo CMK' : 'formato propio de esta empresa',
        ])->all());
    }

    private function listarDocumentos(string $estado): string
    {
        $q = GeneratedDocument::latest();

        if (in_array($estado, self::ESTADOS, true)) {
            $q->where('estado', $estado);
        }

        return $this->json($q->limit(self::LIMITE_FILAS)->get()->map(fn (GeneratedDocument $d) => [
            'id' => $d->id,
            'titulo' => $d->titulo,
            'estado' => $d->estado,
            'version' => $d->version,
            'actualizado' => $d->updated_at?->format('Y-m-d H:i'),
            'caracteres' => mb_strlen((string) $d->contenido),
        ])->all());
    }

    private function leerDocumento(int $id): string
    {
        $doc = GeneratedDocument::find($id);

        if ($doc === null) {
            return "ERROR: no existe un documento con id {$id} para esta empresa.";
        }

        return $this->json([
            'id' => $doc->id,
            'titulo' => $doc->titulo,
            'estado' => $doc->estado,
            'version' => $doc->version,
            'contenido' => $doc->contenido,
        ]);
    }

    private function listarEmpleados(): string
    {
        $empleados = Employee::where('is_active', true)
            ->orderBy('apellidos')
            ->limit(self::LIMITE_FILAS)
            ->get(['nombres', 'apellidos', 'tipo_documento', 'numero_documento', 'cargo', 'area', 'sede', 'tipo_contrato', 'eps', 'afp', 'arl', 'nivel_riesgo', 'fecha_ingreso']);

        if ($empleados->isEmpty()) {
            return 'La empresa no tiene trabajadores registrados en el módulo de Empleados.';
        }

        return $this->json([
            'total' => $empleados->count(),
            'trabajadores' => $empleados->all(),
        ]);
    }

    private function resumenIperc(): string
    {
        $filas = IpercRow::orderBy('proceso')->limit(self::LIMITE_FILAS)->get([
            'proceso', 'zona', 'actividad', 'tarea', 'rutinaria', 'clasificacion', 'peligro',
            'efectos', 'control_fuente', 'control_medio', 'control_individuo',
            'np', 'nr', 'nivel_riesgo', 'aceptabilidad', 'medidas', 'expuestos',
        ]);

        if ($filas->isEmpty()) {
            return 'La matriz IPERC de esta empresa está vacía.';
        }

        return $this->json([
            'total_peligros' => $filas->count(),
            'no_aceptables' => $filas->where('aceptabilidad', 'No Aceptable')->count(),
            'peligros' => $filas->all(),
        ]);
    }

    private function resumenIndicadores(int $anio): string
    {
        $anio = $anio > 0 ? $anio : (int) now()->year;
        $tenantId = $this->context->id();

        $indicadores = Indicator::where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderBy('orden')
            ->with(['readings' => fn ($q) => $q->where('anio', $anio)])
            ->get();

        if ($indicadores->isEmpty()) {
            return 'No hay indicadores configurados.';
        }

        return $this->json([
            'anio' => $anio,
            'indicadores' => $indicadores->map(fn (Indicator $i) => [
                'codigo' => $i->codigo,
                'nombre' => $i->nombre,
                'categoria' => $i->categoria,
                'unidad' => $i->unidad,
                'meta' => $i->meta,
                'sentido' => $i->sentido,
                'es_legal' => (bool) $i->es_legal,
                'lecturas' => $i->readings->map(fn ($l) => [
                    'mes' => $l->mes,
                    'numerador' => $l->numerador,
                    'denominador' => $l->denominador,
                    'valor' => $i->calcular($l->numerador, $l->denominador),
                ])->all(),
            ])->all(),
        ]);
    }

    private function resumenPlanTrabajo(int $anio): string
    {
        $anio = $anio > 0 ? $anio : (int) now()->year;

        $plan = WorkPlan::where('anio', $anio)->with('items.activity')->first();

        if ($plan === null) {
            return "No hay plan de trabajo cargado para el año {$anio}.";
        }

        return $this->json([
            'anio' => $plan->anio,
            'responsable' => $plan->responsable,
            'objetivos' => $plan->objetivos,
            'metas' => $plan->metas,
            'recursos' => $plan->recursos,
            'cumplimiento_pct' => $plan->cumplimiento,
            'firmado_por_representante' => filled($plan->firma_rep_nombre),
            'actividades' => $plan->items->map(fn ($i) => [
                'codigo' => $i->activity?->codigo,
                'fase' => $i->activity?->fase,
                'nombre' => $i->activity?->nombre,
                'normas' => $i->activity?->normas,
                'meses_programados' => $i->meses_programados,
                'meses_ejecutados' => $i->meses_ejecutados,
                'responsable' => $i->responsable,
            ])->all(),
        ]);
    }

    private function resumenDiagnostico(): string
    {
        $dx = SstDiagnostic::latest('fecha')->with('items.standard')->first();

        if ($dx === null) {
            return 'Esta empresa no tiene diagnóstico de estándares mínimos registrado.';
        }

        $incumple = $dx->items
            ->filter(fn ($i) => $i->estado !== 'cumple')
            ->map(fn ($i) => [
                'codigo' => $i->standard?->codigo,
                'item' => $i->standard?->item,
                'ciclo' => $i->standard?->ciclo,
                'estado' => $i->estado,
                'justificacion' => $i->justificacion,
            ])->values();

        return $this->json([
            'fecha' => optional($dx->fecha)->format('Y-m-d'),
            'evaluador' => $dx->evaluador,
            'puntaje' => $dx->puntaje,
            'clasificacion' => $dx->clasificacion,
            'items_que_no_cumplen' => $incumple->all(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Herramientas de ESCRITURA
    |--------------------------------------------------------------------------
    */

    private function generarDesdePlantilla(string $codigo, Tenant $tenant): string
    {
        $plantilla = DocumentTemplate::visibles($tenant->id)->where('codigo', $codigo)->first();

        if ($plantilla === null) {
            return "ERROR: no existe la plantilla «{$codigo}». Consulta listar_plantillas para ver los códigos válidos.";
        }

        if (! $plantilla->tieneBase()) {
            // Sin modelo base la IA redacta: se le devuelve la instrucción de
            // la plantilla para que escriba y cierre con crear_documento.
            return $this->json([
                'accion_requerida' => 'redactar',
                'mensaje' => 'Esta plantilla no tiene documento modelo base. Redacta el documento completo en Markdown siguiendo la instrucción y luego llama a crear_documento con plantilla_codigo='.$plantilla->codigo.'.',
                'nombre' => $plantilla->nombre,
                'normas' => $plantilla->normas,
                'instruccion' => $plantilla->prompt,
            ]);
        }

        // Con modelo base: relleno DETERMINISTA. No pasa por la IA para no
        // alterar el texto de cumplimiento del documento oficial de CMK.
        $doc = GeneratedDocument::create([
            'document_template_id' => $plantilla->id,
            'titulo' => $plantilla->nombre,
            'contenido' => $this->filler->fill($plantilla->contenido_base, $tenant),
            'estado' => 'borrador',
            'version' => 1,
            'generado_por' => auth()->user()?->name,
        ]);

        return $this->json([
            'creado' => true,
            'id' => $doc->id,
            'titulo' => $doc->titulo,
            'metodo' => 'relleno determinista del documento modelo (sin IA)',
            'mensaje' => 'Documento creado en estado borrador. Avísale al usuario que ya lo puede revisar en Documentos IA.',
        ]);
    }

    /** @param  array<string,mixed>  $input */
    private function crearDocumento(array $input): string
    {
        $titulo = trim((string) ($input['titulo'] ?? ''));
        $contenido = (string) ($input['contenido_markdown'] ?? '');
        $codigo = trim((string) ($input['plantilla_codigo'] ?? ''));

        if ($titulo === '' || trim($contenido) === '') {
            return 'ERROR: crear_documento necesita título y contenido_markdown no vacíos.';
        }

        $doc = GeneratedDocument::create([
            'document_template_id' => $codigo !== ''
                ? DocumentTemplate::visibles($this->context->id())->where('codigo', $codigo)->value('id')
                : null,
            'titulo' => $titulo,
            'contenido' => $contenido,
            'estado' => 'borrador',
            'version' => 1,
            'generado_por' => auth()->user()?->name,
        ]);

        return $this->json([
            'creado' => true,
            'id' => $doc->id,
            'titulo' => $doc->titulo,
            'mensaje' => 'Documento creado en estado borrador, listo para revisión en Documentos IA.',
        ]);
    }

    /** @param  array<string,mixed>  $input */
    private function actualizarDocumento(array $input): string
    {
        $doc = GeneratedDocument::find((int) ($input['id'] ?? 0));

        if ($doc === null) {
            return 'ERROR: no existe ese documento para la empresa activa.';
        }

        if ($doc->estado === 'aprobado') {
            return "ERROR: el documento «{$doc->titulo}» está APROBADO y no se puede modificar desde el asistente. Pídele al usuario que lo devuelva a borrador si realmente quiere cambiarlo.";
        }

        $cambios = [];
        $titulo = trim((string) ($input['titulo'] ?? ''));
        $contenido = (string) ($input['contenido_markdown'] ?? '');

        if ($titulo !== '') {
            $cambios['titulo'] = $titulo;
        }

        if (trim($contenido) !== '' && $contenido !== $doc->contenido) {
            $cambios['contenido'] = $contenido;
            $cambios['version'] = $doc->version + 1;
        }

        if ($cambios === []) {
            return 'No había nada que cambiar: el título y el contenido enviados son iguales a los actuales.';
        }

        $doc->update($cambios);

        return $this->json([
            'actualizado' => true,
            'id' => $doc->id,
            'titulo' => $doc->titulo,
            'version' => $doc->version,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Utilidades
    |--------------------------------------------------------------------------
    */

    /**
     * Arma la definición de una herramienta.
     *
     * `strict` solo se activa cuando TODAS las propiedades son obligatorias:
     * el modo estricto exige que cada propiedad esté en `required`, así que
     * forzar ahí un filtro opcional obliga al modelo a inventarse un valor
     * (en pruebas llegó a mandar un token interno suyo como categoría, que
     * de haberse usado tal cual habría devuelto un catálogo vacío).
     * Por eso los filtros opcionales quedan fuera de `required` y además se
     * validan contra su lista blanca en cada herramienta.
     *
     * @param  array<string,array<string,mixed>>  $propiedades
     * @param  array<int,string>  $requeridos
     * @return array<string,mixed>
     */
    private function tool(string $nombre, string $descripcion, array $propiedades = [], array $requeridos = []): array
    {
        $definicion = [
            'name' => $nombre,
            'description' => $descripcion,
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) $propiedades,
                'required' => $requeridos,
                'additionalProperties' => false,
            ],
        ];

        if (count($requeridos) === count($propiedades)) {
            $definicion['strict'] = true;
        }

        return $definicion;
    }

    private function json(mixed $valor): string
    {
        return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';
    }
}
