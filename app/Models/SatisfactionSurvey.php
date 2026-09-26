<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Encuesta de satisfacción de un cliente (ISO 9001 9.1.2). Criterios fijos de
 * 1 a 5 para que el índice se pueda comparar entre periodos.
 */
class SatisfactionSurvey extends Model
{
    use BelongsToTenant;

    protected $fillable = ['fecha', 'cliente', 'producto', 'calidad', 'oportunidad', 'atencion', 'cumplimiento', 'recomendaria', 'comentario'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d'] + array_fill_keys(array_keys(self::CRITERIOS), 'integer');
    }

    protected $appends = ['indice'];

    public const CRITERIOS = [
        'calidad' => 'Calidad del producto o servicio',
        'oportunidad' => 'Oportunidad en la entrega',
        'atencion' => 'Atención y trato',
        'cumplimiento' => 'Cumplimiento de lo acordado',
        'recomendaria' => 'Nos recomendaría',
    ];

    /** Índice por debajo del cual el informe lo marca. Es una referencia: cada empresa fija su meta en Indicadores. */
    public const META = 80;

    /** De 0 a 100: la suma de los criterios sobre el máximo posible. */
    public function getIndiceAttribute(): float
    {
        $suma = array_sum(array_map(fn (string $c) => (int) $this->{$c}, array_keys(self::CRITERIOS)));

        return round(100 * $suma / (5 * count(self::CRITERIOS)), 1);
    }

    /**
     * Índice y promedio por criterio de un grupo de encuestas.
     *
     * @param  Collection<int, SatisfactionSurvey>  $encuestas
     * @return array{n: int, indice: ?float, criterios: array<string, ?float>}
     */
    public static function resumen(Collection $encuestas): array
    {
        return [
            'n' => $encuestas->count(),
            'indice' => $encuestas->isEmpty() ? null : round((float) $encuestas->avg('indice'), 1),
            'criterios' => collect(self::CRITERIOS)
                ->map(fn ($etiqueta, string $c) => $encuestas->isEmpty() ? null : round((float) $encuestas->avg($c), 2))->all(),
        ];
    }
}
