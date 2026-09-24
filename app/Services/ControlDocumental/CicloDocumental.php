<?php

namespace App\Services\ControlDocumental;

use App\Models\ControlledDocument;
use App\Models\ControlledDocumentRead;
use App\Models\ControlledDocumentVersion;
use App\Models\DocumentCatalogEntry;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Process;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida de un documento controlado (informe, sección 6; ISO 7.5,
 * Dec. 1072 art. 2.2.4.6.12, PESV paso 19):
 *
 *   borrador → en_revision → en_aprobacion → vigente → obsoleta
 *       ↑__________↓_______________↓  (devolución o rechazo, con observaciones)
 *
 * Reglas que el software impone y que el listado llevado a mano no tenía:
 * - Solo el borrador se edita; en revisión queda bloqueado para el autor.
 * - No se publica una versión sin descripción del cambio, sin quien revisó y
 *   sin quien aprobó (el «control de cambios» del listado estaba en «-»).
 * - Publicar una versión vuelve obsoleta la anterior, sola. Nunca hay dos
 *   versiones vigentes del mismo documento.
 * - Una versión publicada no se borra: se retira y se conserva.
 */
class CicloDocumental
{
    /**
     * Crea el documento con su código y la versión 1 en borrador.
     *
     * @param  array<string, mixed>  $datos  campos de ControlledDocument ya validados
     */
    public function crear(array $datos, Process $proceso, User $autor): ControlledDocument
    {
        return DB::transaction(function () use ($datos, $proceso, $autor) {
            $tipo = $datos['tipo'];
            $sistemas = array_values($datos['sistemas']);

            $doc = new ControlledDocument($datos + [
                'nivel' => ControlledDocument::TIPOS[$tipo]['nivel'],
                'frecuencia_revision_meses' => ControlledDocument::TIPOS[$tipo]['frecuencia'],
                'retencion_anios' => ControlledDocument::retencionPorDefecto($sistemas),
            ]);
            $doc->tenant_id = $proceso->tenant_id;
            $doc->process_id = $proceso->id;
            $doc->codigo = ControlledDocument::siguienteCodigo($proceso->tenant_id, $tipo, $proceso->sigla);
            $doc->estado = 'borrador';
            $doc->save();

            $doc->versions()->create([
                'version' => 1,
                'estado' => 'borrador',
                'descripcion_cambio' => 'Creación del documento.',
                'elaboro_user_id' => $autor->id,
                'elaboro_nombre' => $autor->name,
            ]);

            return $doc;
        });
    }

    /**
     * Arma (o completa) el listado maestro de la empresa desde el catálogo de
     * referencia. No duplica: un documento del catálogo que la empresa ya
     * tiene se salta.
     *
     * @param  list<string>  $modulos
     * @return array{creados: int, omitidos: int, sin_proceso: list<string>}
     */
    public function inicializarDesdeCatalogo(int $tenantId, array $modulos, bool $incluirCondicionales, User $autor): array
    {
        Process::asegurarBase($tenantId);

        $procesos = Process::withoutTenantScope()->where('tenant_id', $tenantId)->get()->keyBy('sigla');
        $yaTiene = ControlledDocument::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('document_catalog_id')
            ->pluck('document_catalog_id')
            ->flip();

        $entradas = DocumentCatalogEntry::query()
            ->whereIn('modulo', $modulos)
            ->when(! $incluirCondicionales, fn ($q) => $q->where('condicional', false))
            ->orderBy('orden')
            ->get();

        $creados = 0;
        $omitidos = 0;
        $sinProceso = [];

        foreach ($entradas as $entrada) {
            if ($yaTiene->has($entrada->id)) {
                $omitidos++;

                continue;
            }

            // La empresa pudo haber borrado o renombrado el proceso sugerido.
            // Mejor avisar que inventarle un dueño.
            $proceso = $procesos->get($entrada->proceso);
            if (! $proceso) {
                $sinProceso[$entrada->proceso] = $entrada->proceso;

                continue;
            }

            $this->crear([
                'tipo' => $entrada->tipo,
                'titulo' => $entrada->nombre,
                'sistemas' => $entrada->sistemas,
                'condicional' => $entrada->condicional,
                'document_catalog_id' => $entrada->id,
                'codigo_historico' => $entrada->codigo_referencia,
            ], $proceso, $autor);
            $creados++;
        }

        return ['creados' => $creados, 'omitidos' => $omitidos, 'sin_proceso' => array_values($sinProceso)];
    }

    /**
     * Lleva un documento redactado en Documentos IA al listado maestro.
     *
     * Documentos IA redacta; el control documental revisa, aprueba y publica.
     * El texto cae en el documento del listado que corresponde a la plantilla
     * (por el catálogo) y siempre como borrador:
     * - si la empresa no lo tiene, se crea con su código del SIG;
     * - si tiene un borrador abierto, se le reemplaza el contenido;
     * - si está vigente, se abre la versión siguiente;
     * - si hay una versión en revisión o aprobación, no se toca: pisar un
     *   texto que alguien está revisando le cambiaría lo que ya revisó.
     */
    public function recibirDeIa(GeneratedDocument $generado, User $user): ControlledDocument
    {
        $plantilla = $generado->template;
        $tenantId = $generado->tenant_id;

        return DB::transaction(function () use ($generado, $plantilla, $tenantId, $user) {
            $doc = $this->documentoParaIa($generado, $plantilla, $tenantId);

            if ($doc === null) {
                $doc = $this->crear($this->fichaDesdePlantilla($generado, $plantilla), $this->procesoParaIa($plantilla, $tenantId), $user);
            }

            $abierta = $doc->versions()->whereIn('estado', ControlledDocumentVersion::EN_CURSO)->first();

            if ($abierta && $abierta->estado !== 'borrador') {
                $this->fallar('estado', "{$doc->codigo} tiene la versión {$abierta->version} ".str_replace('_', ' ', $abierta->estado)
                    .'. Termina ese trámite o devuélvelo a borrador antes de traer otro texto.');
            }

            $descripcion = "Texto de Documentos IA: «{$generado->titulo}» v{$generado->version}.";
            $version = $abierta ?? $this->nuevaVersion($doc, $user, $descripcion);

            $version->update([
                'contenido' => $generado->contenido,
                'generated_document_id' => $generado->id,
                // La v1 recién creada trae «Creación del documento.»; se deja.
                'descripcion_cambio' => $version->version === 1 ? $version->descripcion_cambio : ($version->descripcion_cambio ?: $descripcion),
            ]);

            return $doc;
        });
    }

    /** El documento del listado al que va el texto: por catálogo o por envíos anteriores. */
    private function documentoParaIa(GeneratedDocument $generado, ?DocumentTemplate $plantilla, int $tenantId): ?ControlledDocument
    {
        $base = ControlledDocument::withoutTenantScope()->where('tenant_id', $tenantId);

        if ($plantilla?->document_catalog_id) {
            $doc = (clone $base)->where('document_catalog_id', $plantilla->document_catalog_id)->first();
            if ($doc) {
                return $doc;
            }
        }

        // Plantilla sin equivalente en el catálogo: si algún documento de la
        // misma plantilla ya se envió antes, va al mismo documento del listado.
        return (clone $base)->whereHas('versions.generatedDocument', fn ($q) => $q
            ->where('document_template_id', $generado->document_template_id)
            ->whereNotNull('document_template_id'))->first();
    }

    /** @return array<string, mixed> */
    private function fichaDesdePlantilla(GeneratedDocument $generado, ?DocumentTemplate $plantilla): array
    {
        if ($entrada = $plantilla?->catalogEntry) {
            return [
                'tipo' => $entrada->tipo,
                'titulo' => $entrada->nombre,
                'sistemas' => $entrada->sistemas,
                'condicional' => $entrada->condicional,
                'document_catalog_id' => $entrada->id,
                'codigo_historico' => $entrada->codigo_referencia ?? $plantilla->codigo,
            ];
        }

        return [
            'tipo' => self::TIPO_PLANTILLA[$plantilla?->tipo] ?? 'PRC',
            'titulo' => $plantilla?->nombre ?? $generado->titulo,
            'sistemas' => self::sistemasDePlantilla($plantilla),
            'codigo_historico' => $plantilla?->codigo,
        ];
    }

    private function procesoParaIa(?DocumentTemplate $plantilla, int $tenantId): Process
    {
        Process::asegurarBase($tenantId);

        $sigla = $plantilla?->catalogEntry?->proceso ?? self::PROCESO_CATEGORIA[$plantilla?->categoria] ?? 'GSI';
        $procesos = Process::withoutTenantScope()->where('tenant_id', $tenantId);

        $proceso = (clone $procesos)->where('sigla', $sigla)->first() ?? (clone $procesos)->orderBy('orden')->first();
        if (! $proceso) {
            $this->fallar('proceso', 'La empresa no tiene procesos: crea al menos uno en el mapa de procesos.');
        }

        return $proceso;
    }

    /** Tipo de las plantillas de IA → tipo del SIG. */
    private const TIPO_PLANTILLA = [
        'Política' => 'PLT', 'Manual' => 'MAN', 'Procedimiento' => 'PRC', 'Programa' => 'PRG',
        'Plan' => 'PLA', 'Matriz' => 'MTZ', 'Instructivo' => 'INS', 'Formato' => 'FT', 'Reglamento' => 'REG',
    ];

    /** Categoría de la plantilla → proceso dueño cuando no hay catálogo. */
    private const PROCESO_CATEGORIA = ['SST' => 'SST', 'PESV' => 'SVL', 'SGI' => 'GSI', 'HSEQ' => 'GSI'];

    /**
     * Normas que evidencia una plantilla, leídas de su lista de normas. Ojo:
     * «ISO 39001» (seguridad vial) contiene «9001»; por eso se compara
     * «ISO 9001» completo y no el número suelto.
     *
     * @return list<string>
     */
    private static function sistemasDePlantilla(?DocumentTemplate $plantilla): array
    {
        $texto = ' '.implode(' | ', $plantilla?->normas ?? []).' ';
        $sistemas = [];

        if (preg_match('/0312|1072/', $texto)) {
            $sistemas[] = 'sst';
        }
        if (preg_match('/40595|39001/', $texto)) {
            $sistemas[] = 'pesv';
        }
        if (str_contains($texto, '45001')) {
            $sistemas[] = 'iso45001';
        }
        if (preg_match('/ISO 9001\b/', $texto)) {
            $sistemas[] = 'iso9001';
        }
        if (str_contains($texto, '14001')) {
            $sistemas[] = 'iso14001';
        }

        return $sistemas ?: [$plantilla?->categoria === 'PESV' ? 'pesv' : 'sst'];
    }

    public function enviarARevision(ControlledDocumentVersion $version, User $user): void
    {
        $this->exigirEstado($version, ['borrador'], 'Solo un borrador se puede enviar a revisión.');

        if (blank($version->contenido) && blank($version->archivo)) {
            $this->fallar('contenido', 'La versión no tiene contenido ni archivo: no hay nada que revisar.');
        }
        if (blank($version->descripcion_cambio)) {
            $this->fallar('descripcion_cambio', 'Describe qué cambia en esta versión antes de enviarla a revisión.');
        }

        $version->update([
            'estado' => 'en_revision',
            'enviado_revision_at' => now(),
            'elaboro_user_id' => $version->elaboro_user_id ?? $user->id,
            'elaboro_nombre' => $version->elaboro_nombre ?? $user->name,
        ]);
        $version->document->sincronizarEstado();
    }

    public function aprobarRevision(ControlledDocumentVersion $version, User $user): void
    {
        $this->exigirEstado($version, ['en_revision'], 'La versión no está en revisión.');

        $version->update([
            'estado' => 'en_aprobacion',
            'reviso_user_id' => $user->id,
            'reviso_nombre' => $user->name,
            'revisado_at' => now(),
            'observaciones' => null,
        ]);
        $version->document->sincronizarEstado();
    }

    /** Devolución (en revisión) o rechazo (en aprobación): vuelve a borrador. */
    public function devolver(ControlledDocumentVersion $version, string $observaciones): void
    {
        $this->exigirEstado($version, ['en_revision', 'en_aprobacion'], 'Solo se devuelve una versión en revisión o en aprobación.');

        if (blank($observaciones)) {
            $this->fallar('observaciones', 'Indica qué hay que ajustar: sin observaciones el autor no sabe qué corregir.');
        }

        $version->update([
            'estado' => 'borrador',
            'observaciones' => $observaciones,
            // La revisión anterior ya no vale: el texto va a cambiar.
            'reviso_user_id' => null,
            'reviso_nombre' => null,
            'revisado_at' => null,
            'enviado_revision_at' => null,
        ]);
        $version->document->sincronizarEstado();
    }

    /** Aprobación: la versión se publica y la anterior pasa a obsoleta. */
    public function aprobar(ControlledDocumentVersion $version, User $user): void
    {
        $this->exigirEstado($version, ['en_aprobacion'], 'La versión no está pendiente de aprobación.');

        DB::transaction(function () use ($version, $user) {
            $doc = $version->document;

            $doc->versions()
                ->where('estado', 'vigente')
                ->update([
                    'estado' => 'obsoleta',
                    'obsoleto_at' => now(),
                    'motivo_obsoleto' => 'Reemplazada por la versión '.$version->version.'.',
                ]);

            $version->update([
                'estado' => 'vigente',
                'aprobo_user_id' => $user->id,
                'aprobo_nombre' => $user->name,
                'aprobado_at' => now(),
                'observaciones' => null,
            ]);

            $doc->proxima_revision = now()->addMonths($doc->frecuencia_revision_meses)->toDateString();
            $doc->sincronizarEstado();
        });
    }

    /** Abre la versión N+1 a partir de la vigente. */
    public function nuevaVersion(ControlledDocument $doc, User $user, string $descripcion): ControlledDocumentVersion
    {
        $vigente = $doc->versions()->where('estado', 'vigente')->first();
        if (! $vigente) {
            $this->fallar('version', 'Solo se abre una versión nueva sobre un documento vigente.');
        }
        if ($doc->versions()->whereIn('estado', ControlledDocumentVersion::EN_CURSO)->exists()) {
            $this->fallar('version', 'Ya hay una versión en curso. Termínala o descártala antes de abrir otra.');
        }
        if (blank($descripcion)) {
            $this->fallar('descripcion_cambio', 'Describe qué va a cambiar en la nueva versión.');
        }

        $nueva = $doc->versions()->create([
            'version' => $doc->versions()->max('version') + 1,
            'estado' => 'borrador',
            'contenido' => $vigente->contenido,
            // El archivo se referencia, no se copia: los archivos de versiones
            // publicadas no se borran nunca (retención), así que compartir la
            // ruta es seguro. Si el autor sube otro, se guarda aparte.
            'archivo' => $vigente->archivo,
            'archivo_nombre' => $vigente->archivo_nombre,
            'descripcion_cambio' => $descripcion,
            'elaboro_user_id' => $user->id,
            'elaboro_nombre' => $user->name,
        ]);
        $doc->sincronizarEstado();

        return $nueva;
    }

    /** Descarta una versión nueva que no llegó a publicarse. */
    public function descartar(ControlledDocumentVersion $version): void
    {
        $this->exigirEstado($version, ['borrador'], 'Solo se descarta un borrador.');

        if ($version->version === 1) {
            $this->fallar('version', 'La versión 1 no se descarta: si el documento sobra, elimina el documento.');
        }

        $doc = $version->document;
        $version->delete();
        $doc->sincronizarEstado();
    }

    /** Retira el documento: la versión vigente pasa a obsoleta y se conserva. */
    public function retirar(ControlledDocument $doc, string $motivo): void
    {
        if ($doc->estado !== 'vigente') {
            $this->fallar('estado', 'Solo se retira un documento vigente.');
        }
        if ($doc->versions()->whereIn('estado', ControlledDocumentVersion::EN_CURSO)->exists()) {
            $this->fallar('estado', 'Hay una versión en curso. Descártala antes de retirar el documento.');
        }
        if (blank($motivo)) {
            $this->fallar('motivo', 'Indica por qué se retira el documento.');
        }

        $doc->versions()->where('estado', 'vigente')->update([
            'estado' => 'obsoleta',
            'obsoleto_at' => now(),
            'motivo_obsoleto' => $motivo,
        ]);
        $doc->sincronizarEstado();
    }

    /** Lectura confirmada de la versión vigente. Idempotente. */
    public function confirmarLectura(ControlledDocument $doc, User $user): void
    {
        $vigente = $doc->versions()->where('estado', 'vigente')->first();
        if (! $vigente) {
            $this->fallar('estado', 'El documento no tiene una versión vigente.');
        }

        ControlledDocumentRead::firstOrCreate(
            ['controlled_document_version_id' => $vigente->id, 'user_id' => $user->id],
            ['user_nombre' => $user->name, 'leido_at' => now()],
        );
    }

    /** Un documento se puede eliminar mientras nunca haya sido publicado. */
    public function puedeEliminarse(ControlledDocument $doc): bool
    {
        return ! $doc->versions()->whereNotNull('aprobado_at')->exists();
    }

    /** @param list<string> $estados */
    private function exigirEstado(ControlledDocumentVersion $version, array $estados, string $mensaje): void
    {
        if (! in_array($version->estado, $estados, true)) {
            $this->fallar('estado', $mensaje);
        }
    }

    private function fallar(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
