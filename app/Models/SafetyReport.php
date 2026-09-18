<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reporte de acto o condición insegura. Segregado por tenant.
 *
 * Alimenta el indicador RED-AC: intervenidas sobre reportadas. Los scopes de
 * abajo son ese numerador y ese denominador.
 */
class SafetyReport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'fecha', 'reportado_por', 'employee_id', 'area', 'lugar',
        'tipo', 'descripcion', 'clasificacion_peligro', 'severidad',
        'accion_inmediata', 'estado', 'fecha_intervencion', 'responsable_intervencion',
        'acpm_action_id', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'fecha_intervencion' => 'date:Y-m-d',
        ];
    }

    public const TIPOS = ['acto', 'condicion'];

    public const SEVERIDADES = ['bajo', 'medio', 'alto', 'critico'];

    public const ESTADOS = ['reportado', 'intervenido', 'cerrado'];

    /** Misma taxonomía de la GTC 45 que usa el IPERC, para poder cruzarlos. */
    public const CLASIFICACIONES = [
        'Biológico', 'Físico', 'Químico', 'Biomecánico', 'Psicosocial',
        'Condiciones de seguridad', 'Fenómenos naturales',
    ];

    protected static function booted(): void
    {
        static::saving(function (SafetyReport $reporte): void {
            // Intervenir sin fecha deja el reporte contando como pendiente.
            if (in_array($reporte->estado, ['intervenido', 'cerrado'], true) && $reporte->fecha_intervencion === null) {
                $reporte->fecha_intervencion = now()->toDateString();
            }

            if ($reporte->estado === 'reportado') {
                $reporte->fecha_intervencion = null;
            }
        });
    }

    /**
     * Numerador de RED-AC. `cerrado` cuenta como intervenido: cerrar es
     * intervenir y además verificar, así que excluirlo dejaría fuera justo los
     * reportes mejor gestionados.
     */
    public function scopeIntervenidos(Builder $query): Builder
    {
        return $query->whereIn('estado', ['intervenido', 'cerrado']);
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', 'reportado');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<AcpmAction, $this> */
    public function accion(): BelongsTo
    {
        return $this->belongsTo(AcpmAction::class, 'acpm_action_id');
    }
}
