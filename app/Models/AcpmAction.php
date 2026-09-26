<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Acción correctiva, preventiva o de mejora (ACPM). Segregada por tenant.
 *
 * Registro común al que llegan los hallazgos de todos los módulos: accidentes,
 * reportes de actos y condiciones, auditorías, inspecciones y el IPERC.
 */
class AcpmAction extends Model
{
    use BelongsToTenant;

    protected $table = 'acpm_actions';

    protected $fillable = [
        'tipo', 'origen_tipo', 'origen_id',
        'hallazgo', 'causa', 'accion',
        'responsable', 'fecha_deteccion', 'fecha_limite', 'fecha_cierre',
        'estado', 'eficaz', 'verificacion', 'fecha_verificacion',
        'observaciones',
        // `codigo` no es asignable: lo genera el modelo de forma consecutiva
        // por cliente. Si se pudiera mandar desde la petición, dos personas
        // guardando a la vez crearían el mismo número.
    ];

    protected function casts(): array
    {
        return [
            'fecha_deteccion' => 'date:Y-m-d',
            'fecha_limite' => 'date:Y-m-d',
            'fecha_cierre' => 'date:Y-m-d',
            'fecha_verificacion' => 'date:Y-m-d',
            'eficaz' => 'boolean',
        ];
    }

    public const TIPOS = ['correctiva', 'preventiva', 'mejora'];

    public const ESTADOS = ['abierta', 'en_proceso', 'cerrada'];

    public const ORIGENES = ['manual', 'accidente', 'reporte', 'auditoria', 'inspeccion', 'iperc', 'siniestro_vial', 'revision_direccion', 'riesgo_oportunidad', 'aspecto_ambiental', 'calibracion', 'pqrs', 'salida_no_conforme'];

    /** Va al front: la tabla marca ahí las vencidas. */
    protected $appends = ['vencida', 'dias_restantes'];

    protected static function booted(): void
    {
        static::creating(function (AcpmAction $accion): void {
            $accion->codigo ??= self::siguienteCodigo($accion->tenant_id);
        });

        static::saving(function (AcpmAction $accion): void {
            // Cerrar sin fecha de cierre deja el registro contando como abierto
            // en los informes. Se fecha solo.
            if ($accion->estado === 'cerrada' && $accion->fecha_cierre === null) {
                $accion->fecha_cierre = now()->toDateString();
            }

            // Y al revés: reabrir una acción tiene que limpiar el cierre, o
            // quedaría abierta y cerrada a la vez.
            if ($accion->estado !== 'cerrada') {
                $accion->fecha_cierre = null;
            }
        });
    }

    /**
     * Consecutivo ACPM-<año>-<###> por cliente.
     *
     * Se calcula sobre el máximo existente del año y no con un contador propio:
     * si se borra la última acción, el número se reutiliza, y eso es preferible
     * a mantener una tabla de secuencias por tenant para un consecutivo que solo
     * es de lectura humana.
     */
    public static function siguienteCodigo(?int $tenantId): string
    {
        $anio = now()->year;
        $prefijo = 'ACPM-'.$anio.'-';

        $ultimo = self::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('codigo', 'like', $prefijo.'%')
            ->orderByDesc('codigo')
            ->value('codigo');

        $n = $ultimo ? ((int) substr($ultimo, strlen($prefijo))) + 1 : 1;

        return $prefijo.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Vencida = pasó la fecha límite y todavía no está cerrada.
     *
     * No es un estado guardado en la tabla a propósito: si lo fuera, habría que
     * recorrer todas las acciones cada noche para irlas venciendo, y una acción
     * vencida el fin de semana aparecería como al día hasta el lunes.
     */
    public function getVencidaAttribute(): bool
    {
        return $this->estado !== 'cerrada'
            && $this->fecha_limite !== null
            && $this->fecha_limite->isBefore(now()->startOfDay());
    }

    public function getDiasRestantesAttribute(): ?int
    {
        if ($this->estado === 'cerrada' || $this->fecha_limite === null) {
            return null;
        }

        return now()->startOfDay()->diffInDays($this->fecha_limite, false);
    }

    /** Abiertas o en proceso: lo que de verdad está pendiente. */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->whereIn('estado', ['abierta', 'en_proceso']);
    }
}
