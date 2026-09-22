<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * COPASST o Comité de Convivencia Laboral de la empresa cliente.
 *
 * Es la fuente de los indicadores CUMP-COPASST y CUMP-COCOLAB: actividades
 * ejecutadas sobre programadas, que se cuentan en `committee_activities`.
 */
class Committee extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tipo', 'periodo', 'fecha_conformacion', 'fecha_vencimiento',
        'numero_trabajadores', 'acta_conformacion', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'periodo' => 'integer',
            'fecha_conformacion' => 'date:Y-m-d',
            'fecha_vencimiento' => 'date:Y-m-d',
            'numero_trabajadores' => 'integer',
        ];
    }

    public const TIPOS = ['copasst', 'cocolab'];

    /** Vigencia legal de los dos comités: dos años (Res. 2013/1986 y 652/2012). */
    public const ANIOS_VIGENCIA = 2;

    protected $appends = ['vencido', 'composicion_correcta'];

    protected static function booted(): void
    {
        static::saving(function (Committee $comite): void {
            // El vencimiento se calcula si no lo ponen: son dos años desde la
            // conformación en los dos comités, y dejarlo vacío haría que el
            // aviso de comité vencido no se encendiera nunca.
            if ($comite->fecha_vencimiento === null && $comite->fecha_conformacion !== null) {
                $comite->fecha_vencimiento = $comite->fecha_conformacion
                    ->copy()
                    ->addYears(self::ANIOS_VIGENCIA)
                    ->toDateString();
            }
        });
    }

    public function getVencidoAttribute(): bool
    {
        return $this->fecha_vencimiento !== null
            && $this->fecha_vencimiento->isBefore(now()->startOfDay());
    }

    /**
     * Número de representantes que exige la norma según el tamaño de la empresa.
     *
     * COPASST (Res. 2013 de 1986, art. 2): menos de 10 trabajadores no conforma
     * comité sino que nombra un VIGÍA; de ahí en adelante crece por tramos. El
     * número devuelto es por CADA parte —empleador y trabajadores—, que la
     * norma exige paritario.
     *
     * COCOLAB (Res. 652 de 2012, art. 3): dos por parte si hay menos de 20
     * trabajadores, si no cuatro por parte.
     */
    public function representantesPorParte(): int
    {
        $n = $this->numero_trabajadores ?? 0;

        if ($this->tipo === 'cocolab') {
            return $n < 20 ? 2 : 4;
        }

        return match (true) {
            $n < 10 => 1,      // vigía: una sola persona, sin paridad
            $n < 50 => 1,
            $n < 500 => 2,
            $n < 1000 => 3,
            default => 4,
        };
    }

    /**
     * Avisa si la composición no cuadra con la norma. No bloquea nada: en una
     * conformación a medias el consultor necesita guardar e ir completando.
     */
    public function getComposicionCorrectaAttribute(): bool
    {
        $exigidos = $this->representantesPorParte();

        // El vigía de una empresa de menos de 10 no lleva paridad.
        if ($this->tipo === 'copasst' && ($this->numero_trabajadores ?? 0) < 10) {
            return $this->members->count() >= 1;
        }

        $porParte = $this->members->groupBy('representa')->map->count();

        return ($porParte['empleador'] ?? 0) >= $exigidos
            && ($porParte['trabajadores'] ?? 0) >= $exigidos;
    }

    /** @return HasMany<CommitteeMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(CommitteeMember::class);
    }

    /** @return HasMany<CommitteeActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(CommitteeActivity::class)->orderBy('orden');
    }
}
