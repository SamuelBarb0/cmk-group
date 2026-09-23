<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Programa de gestión adoptado por una empresa para un año.
 *
 * El cumplimiento se calcula igual que en el Plan de trabajo: meses ejecutados
 * que estaban programados, sobre meses programados. Un mes ejecutado sin
 * programar no suma, porque el Excel mide «ejecutadas / programadas» y dejarlo
 * sumar permitiría pasar del 100 %.
 */
class ProgramPlan extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'management_program_id', 'anio', 'codigo', 'nombre', 'categoria',
        'objetivo', 'alcance', 'recursos', 'formato_codigo', 'responsable', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
        ];
    }

    public const FASES = ['planear', 'hacer', 'verificar', 'actuar'];

    public const FRECUENCIAS = ['trimestral' => 4, 'semestral' => 2, 'anual' => 1];

    /** @return BelongsTo<ManagementProgram, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(ManagementProgram::class, 'management_program_id');
    }

    /** @return HasMany<ProgramPlanActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(ProgramPlanActivity::class)->orderBy('orden');
    }

    /** @return HasMany<ProgramPlanIndicator, $this> */
    public function indicators(): HasMany
    {
        return $this->hasMany(ProgramPlanIndicator::class)->orderBy('orden');
    }

    /** Meses (1..12) que abarca el periodo `$n` de una frecuencia. */
    public static function mesesDelPeriodo(string $frecuencia, int $n): array
    {
        $periodos = self::FRECUENCIAS[$frecuencia] ?? 1;
        $largo = intdiv(12, $periodos);

        return range(($n - 1) * $largo + 1, $n * $largo);
    }

    /**
     * Cuenta programado / ejecutado en los meses dados (todos, si es null).
     *
     * @param  list<int>|null  $meses
     * @return array{programadas: int, ejecutadas: int}
     */
    public function conteo(?array $meses = null): array
    {
        $programadas = 0;
        $ejecutadas = 0;

        foreach ($this->activities as $a) {
            $prog = $a->meses_programados ?? [];
            if ($meses !== null) {
                $prog = array_values(array_intersect($prog, $meses));
            }
            $programadas += count($prog);
            $ejecutadas += count(array_intersect($prog, $a->meses_ejecutados ?? []));
        }

        return ['programadas' => $programadas, 'ejecutadas' => $ejecutadas];
    }

    /** % de cumplimiento del año, o null si no hay nada programado. */
    public function cumplimiento(?array $meses = null): ?float
    {
        $c = $this->conteo($meses);

        return $c['programadas'] > 0 ? round($c['ejecutadas'] / $c['programadas'] * 100, 1) : null;
    }
}
