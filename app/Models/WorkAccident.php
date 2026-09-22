<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accidente o incidente de trabajo, con su investigación (Res. 1401 de 2007).
 *
 * Los días perdidos NO están aquí: viven en la ausencia enlazada, con tipo
 * `accidente_trabajo`. Tener el mismo número en dos tablas es asegurarse de que
 * algún día no coincidan.
 */
class WorkAccident extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_id', 'clase', 'fecha', 'hora', 'lugar', 'area', 'descripcion',
        'tipo_lesion', 'parte_cuerpo', 'mecanismo', 'agente',
        'mortal', 'grave', 'reportado_arl', 'fecha_reporte_arl', 'absence_id',
        'investigado', 'fecha_investigacion', 'equipo_investigador',
        'causas_inmediatas', 'causas_basicas', 'causa_raiz',
        'leccion_aprendida', 'observaciones',
        // `codigo` lo genera el modelo, igual que en ACPM.
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'fecha_reporte_arl' => 'date:Y-m-d',
            'fecha_investigacion' => 'date:Y-m-d',
            'mortal' => 'boolean',
            'grave' => 'boolean',
            'reportado_arl' => 'boolean',
            'investigado' => 'boolean',
            'causas_inmediatas' => 'array',
            'causas_basicas' => 'array',
        ];
    }

    public const CLASES = ['incidente', 'accidente', 'casi_accidente'];

    /**
     * Plazo legal para reportar a la ARL: dos días hábiles desde que ocurre
     * (Res. 156 de 2005). Se usa para avisar, no para bloquear.
     */
    public const DIAS_REPORTE_ARL = 2;

    /**
     * Plazo legal para investigar: quince días calendario (Res. 1401 de 2007,
     * art. 4). Es la fecha que se le vence al cliente sin darse cuenta.
     */
    public const DIAS_INVESTIGACION = 15;

    protected $appends = ['dias_para_investigar', 'investigacion_vencida'];

    protected static function booted(): void
    {
        static::creating(function (WorkAccident $at): void {
            $at->codigo ??= self::siguienteCodigo($at->tenant_id);
        });

        static::saving(function (WorkAccident $at): void {
            // Un evento mortal es grave por definición. Dejar que se guarde
            // mortal sin grave permitiría que se cayera de los listados de
            // eventos graves, que son los que la ARL revisa primero.
            if ($at->mortal) {
                $at->grave = true;
            }

            if ($at->investigado && $at->fecha_investigacion === null) {
                $at->fecha_investigacion = now()->toDateString();
            }

            if (! $at->investigado) {
                $at->fecha_investigacion = null;
            }
        });
    }

    /** Consecutivo AT-<año>-<###> por cliente. Ver AcpmAction::siguienteCodigo. */
    public static function siguienteCodigo(?int $tenantId): string
    {
        $prefijo = 'AT-'.now()->year.'-';

        $ultimo = self::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('codigo', 'like', $prefijo.'%')
            ->orderByDesc('codigo')
            ->value('codigo');

        $n = $ultimo ? ((int) substr($ultimo, strlen($prefijo))) + 1 : 1;

        return $prefijo.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    /** Días que quedan del plazo de 15 para investigar. Negativo = vencido. */
    public function getDiasParaInvestigarAttribute(): ?int
    {
        if ($this->investigado || $this->fecha === null) {
            return null;
        }

        $limite = $this->fecha->copy()->addDays(self::DIAS_INVESTIGACION);

        return now()->startOfDay()->diffInDays($limite, false);
    }

    public function getInvestigacionVencidaAttribute(): bool
    {
        $dias = $this->dias_para_investigar;

        return $dias !== null && $dias < 0;
    }

    /** Solo los accidentes: excluye incidentes y casi accidentes. */
    public function scopeAccidentes(Builder $query): Builder
    {
        return $query->where('clase', 'accidente');
    }

    public function scopeEnPeriodo(Builder $query, string $desde, string $hasta): Builder
    {
        return $query->whereBetween('fecha', [$desde, $hasta]);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Absence, $this> */
    public function ausencia(): BelongsTo
    {
        return $this->belongsTo(Absence::class, 'absence_id');
    }
}
