<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Requisito legal aplicable a la empresa cliente (matriz de requisitos legales).
 *
 * Alimenta el indicador CUMP-LEG. Ver los scopes `aplicables()` y `cumplidos()`:
 * son literalmente el denominador y el numerador de ese indicador, y viven aquí
 * para que no se calculen de dos formas distintas en dos pantallas.
 */
class LegalRequirement extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'norma', 'anio', 'articulo', 'tema', 'entidad', 'requisito',
        'aplica', 'justificacion_no_aplica',
        'cumplimiento', 'forma_cumplimiento', 'evidencia',
        'responsable', 'fecha_verificacion', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'aplica' => 'boolean',
            'fecha_verificacion' => 'date:Y-m-d',
        ];
    }

    public const CUMPLIMIENTOS = ['cumple', 'parcial', 'no_cumple'];

    protected static function booted(): void
    {
        static::saving(function (LegalRequirement $req): void {
            // Un requisito que no aplica no puede tener veredicto de
            // cumplimiento: quedaría contado como incumplido en la matriz y
            // hundiría el indicador por algo que la empresa no tiene que hacer.
            if (! $req->aplica) {
                $req->cumplimiento = 'no_cumple';
                $req->forma_cumplimiento = null;
            }
        });
    }

    /** Denominador de CUMP-LEG: los que sí le aplican a la empresa. */
    public function scopeAplicables(Builder $query): Builder
    {
        return $query->where('aplica', true);
    }

    /**
     * Numerador de CUMP-LEG. `parcial` NO cuenta como cumplido: para el
     * Ministerio un requisito a medias es un requisito incumplido, y contarlo
     * daría un indicador que se ve mejor de lo que está.
     */
    public function scopeCumplidos(Builder $query): Builder
    {
        return $query->where('aplica', true)->where('cumplimiento', 'cumple');
    }
}
