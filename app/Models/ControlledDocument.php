<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Documento controlado del SIG: una fila del listado maestro de una empresa.
 * Segregado por tenant.
 *
 * El documento es la ficha estable (código, tipo, proceso dueño, normas que
 * evidencia, frecuencia de revisión, retención). El contenido vive en sus
 * versiones, que son las que recorren el ciclo de vida
 * (ver App\Services\ControlDocumental\CicloDocumental).
 */
class ControlledDocument extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = [
        'tipo', 'nivel', 'process_id', 'titulo', 'sistemas', 'condicional', 'document_catalog_id',
        'frecuencia_revision_meses', 'retencion_anios', 'disposicion_final', 'ubicacion', 'codigo_historico',
        // `codigo`, `estado`, `version_vigente` y `proxima_revision` no son
        // asignables: el código lo genera el sistema (el usuario nunca lo
        // escribe) y los otros tres salen de las versiones.
    ];

    protected function casts(): array
    {
        return [
            'sistemas' => 'array',
            'condicional' => 'boolean',
            'proxima_revision' => 'date:Y-m-d',
        ];
    }

    protected $appends = ['revision_vencida', 'dias_para_revision'];

    /**
     * Tipos de documento: nombre, nivel de la pirámide y frecuencia de revisión
     * por defecto (meses). Políticas, matrices, programas y planes se revisan
     * cada año; el resto cada dos, salvo que la empresa diga otra cosa.
     */
    public const TIPOS = [
        'PLT' => ['nombre' => 'Política', 'nivel' => 1, 'frecuencia' => 12],
        'MAN' => ['nombre' => 'Manual', 'nivel' => 1, 'frecuencia' => 24],
        'REG' => ['nombre' => 'Reglamento', 'nivel' => 2, 'frecuencia' => 24],
        'PRC' => ['nombre' => 'Procedimiento', 'nivel' => 2, 'frecuencia' => 24],
        'PRG' => ['nombre' => 'Programa', 'nivel' => 2, 'frecuencia' => 12],
        'PLA' => ['nombre' => 'Plan', 'nivel' => 2, 'frecuencia' => 12],
        'MTZ' => ['nombre' => 'Matriz', 'nivel' => 2, 'frecuencia' => 12],
        'INS' => ['nombre' => 'Instructivo o estándar', 'nivel' => 3, 'frecuencia' => 24],
        'FT' => ['nombre' => 'Formato', 'nivel' => 4, 'frecuencia' => 24],
    ];

    public const NIVELES = [1 => 'Estratégico', 2 => 'Táctico', 3 => 'Operativo', 4 => 'Evidencia'];

    public const ESTADOS = ['borrador', 'en_revision', 'en_aprobacion', 'vigente', 'obsoleto'];

    public const DISPOSICIONES = ['conservar', 'eliminar', 'digitalizar'];

    /** Validación del informe, sección 5.2. */
    public const CODIGO_REGEX = '/^(MAN|PLT|REG|PRC|PRG|PLA|MTZ|INS|FT)-[A-Z]{3}-\d{3}$/';

    /**
     * Algo con forma de código dentro de un título («FT-SST-068 Formato…» o
     * «-SST-020»). El listado de referencia tenía esos restos y son justo los
     * que desordenan los consecutivos.
     */
    public const CODIGO_EN_TITULO_REGEX = '/[A-Z]{0,4}-[A-Z]{2,5}-\d{2,4}/';

    /** Años mínimos de conservación de los registros del SG-SST (Dec. 1072, 2.2.4.6.13). */
    public const RETENCION_SST = 20;

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    /** @return HasMany<ControlledDocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ControlledDocumentVersion::class)->orderByDesc('version');
    }

    /** @return BelongsToMany<NormRequirement, $this> */
    public function requirements(): BelongsToMany
    {
        return $this->belongsToMany(NormRequirement::class, 'controlled_document_requirement')->withTimestamps();
    }

    /** @return BelongsTo<DocumentCatalogEntry, $this> */
    public function catalogEntry(): BelongsTo
    {
        return $this->belongsTo(DocumentCatalogEntry::class, 'document_catalog_id');
    }

    /**
     * Siguiente código TIPO-PROCESO-### de la empresa.
     *
     * Cuenta también los documentos borrados (soft delete): un consecutivo no
     * se reutiliza nunca, ni aunque el documento haya salido del listado. A
     * diferencia del de ACPM, aquí el número sí importa: es lo que se cita en
     * los demás documentos y en las auditorías.
     */
    public static function siguienteCodigo(int $tenantId, string $tipo, string $sigla): string
    {
        $prefijo = $tipo.'-'.$sigla.'-';

        $ultimo = self::withoutTenantScope()
            ->withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('codigo', 'like', $prefijo.'%')
            ->orderByDesc('codigo')
            ->value('codigo');

        $n = $ultimo ? ((int) substr($ultimo, strlen($prefijo))) + 1 : 1;

        return $prefijo.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Documentos de la empresa con un título parecido. Es la alerta de
     * duplicados: el listado de referencia tenía dos «revisión por la alta
     * dirección» y dos «entrega de EPP» con códigos distintos.
     *
     * @return Collection<int, self>
     */
    public static function parecidos(string $titulo, ?int $excepto = null): Collection
    {
        $buscado = self::normalizarTitulo($titulo);

        return self::query()
            ->when($excepto, fn (Builder $q) => $q->whereKeyNot($excepto))
            ->get(['id', 'codigo', 'titulo'])
            ->filter(function (self $doc) use ($buscado) {
                $otro = self::normalizarTitulo($doc->titulo);
                if ($otro === $buscado) {
                    return true;
                }
                similar_text($buscado, $otro, $porcentaje);

                return $porcentaje >= 85;
            })
            ->values();
    }

    private static function normalizarTitulo(string $titulo): string
    {
        $t = Str::of(Str::ascii($titulo))->lower()->replaceMatches('/[^a-z0-9 ]+/', ' ')->squish();

        return (string) $t;
    }

    /**
     * El estado del documento sale de sus versiones: si alguna está vigente,
     * el documento está vigente (aunque haya otra versión en elaboración); si
     * no, manda la última versión.
     */
    public function sincronizarEstado(): void
    {
        $versiones = $this->versions()->get(['id', 'version', 'estado', 'aprobado_at']);
        $vigente = $versiones->firstWhere('estado', 'vigente');

        if ($vigente) {
            $this->estado = 'vigente';
            $this->version_vigente = $vigente->version;
        } else {
            $ultima = $versiones->first();
            $this->estado = $ultima?->estado === 'obsoleta' ? 'obsoleto' : ($ultima->estado ?? 'borrador');
            $this->version_vigente = null;
            $this->proxima_revision = null;
        }

        $this->save();
    }

    /** Vigente y con la fecha de revisión ya pasada. */
    public function getRevisionVencidaAttribute(): bool
    {
        return $this->estado === 'vigente'
            && $this->proxima_revision !== null
            && $this->proxima_revision->isBefore(now()->startOfDay());
    }

    public function getDiasParaRevisionAttribute(): ?int
    {
        if ($this->estado !== 'vigente' || $this->proxima_revision === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->proxima_revision, false);
    }

    /** Retención por defecto: 20 años si evidencia el SG-SST; si no, la define la empresa. */
    public static function retencionPorDefecto(array $sistemas): ?int
    {
        return in_array('sst', $sistemas, true) ? self::RETENCION_SST : null;
    }
}
