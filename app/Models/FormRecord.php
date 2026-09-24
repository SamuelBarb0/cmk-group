<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro diligenciado de un formato para una empresa cliente (por tenant).
 *
 * `schema` es un SNAPSHOT tomado del FormFormat al crear el registro: si el
 * catálogo cambia después, los registros ya diligenciados conservan su
 * estructura original. `data` guarda los valores por `key` de campo.
 */
class FormRecord extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'form_format_id',
        'codigo',
        'titulo',
        'categoria',
        'grupo',
        'schema',
        'data',
        'estado',
        'fecha',
        'responsable',
        'generado_por',
        'reemplaza_id',
        // Consecutivo, completado_* y anulado_* no son asignables: los ponen
        // completar() y anular(), que son las únicas puertas de salida del
        // borrador.
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'data' => 'array',
            'fecha' => 'date:Y-m-d',
            'completado_at' => 'datetime',
            'anulado_at' => 'datetime',
        ];
    }

    public const ESTADOS = ['borrador', 'completado', 'anulado'];

    /** Campos que se escriben al anular un registro completado. */
    private const CAMPOS_ANULACION = ['estado', 'anulado_at', 'anulado_por', 'motivo_anulacion', 'updated_at'];

    protected static function booted(): void
    {
        // Red de seguridad por debajo del controlador: un registro completado o
        // anulado no cambia, venga el cambio de donde venga (importador,
        // tinker, un controlador futuro). Lo único permitido es anular un
        // completado.
        static::updating(function (FormRecord $r): void {
            $antes = $r->getOriginal('estado');
            if ($antes === 'borrador') {
                return;
            }

            $anulando = $antes === 'completado' && $r->estado === 'anulado'
                && array_diff(array_keys($r->getDirty()), self::CAMPOS_ANULACION) === [];

            if (! $anulando) {
                throw new \LogicException("El registro {$r->consecutivo} está {$antes} y no se puede modificar.");
            }
        });

        static::deleting(function (FormRecord $r): void {
            if ($r->estado !== 'borrador') {
                throw new \LogicException("El registro {$r->consecutivo} está {$r->estado}: no se borra, se anula.");
            }
        });
    }

    /**
     * Cierra el registro: le da su consecutivo y lo vuelve inmutable.
     *
     * Consecutivo = código del formato + año de la fecha + número de cuatro
     * cifras, por empresa. Cuenta también los anulados: el número de un
     * registro anulado no se vuelve a usar.
     */
    public function completar(string $por): void
    {
        $anio = ($this->fecha ?? now())->format('Y');
        $prefijo = $this->codigo.'-'.$anio.'-';

        $ultimo = self::withoutTenantScope()
            ->where('tenant_id', $this->tenant_id)
            ->where('consecutivo', 'like', $prefijo.'%')
            ->orderByDesc('consecutivo')
            ->value('consecutivo');

        $n = $ultimo ? ((int) substr($ultimo, strlen($prefijo))) + 1 : 1;

        $this->consecutivo = $prefijo.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        $this->estado = 'completado';
        $this->completado_at = now();
        $this->completado_por = $por;
        $this->save();
    }

    public function anular(string $motivo, string $por): void
    {
        $this->estado = 'anulado';
        $this->anulado_at = now();
        $this->anulado_por = $por;
        $this->motivo_anulacion = $motivo;
        $this->save();
    }

    /** Los que cuentan como evidencia: todo menos los anulados. */
    public function scopeValidos(Builder $query): Builder
    {
        return $query->where('estado', '!=', 'anulado');
    }

    /** @return BelongsTo<FormRecord, $this> */
    public function reemplaza(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reemplaza_id');
    }

    /** @return BelongsTo<FormFormat, $this> */
    public function format(): BelongsTo
    {
        return $this->belongsTo(FormFormat::class, 'form_format_id');
    }
}
