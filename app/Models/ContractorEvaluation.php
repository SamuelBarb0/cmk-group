<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\EvaluacionesContratistas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un formulario de selección, evaluación o requisitos SST diligenciado. */
class ContractorEvaluation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'pesv_contractor_id', 'formato', 'uso', 'fecha', 'evaluador', 'evaluador_cargo',
        'estructura', 'respuestas', 'puntaje', 'porcentaje', 'resultado', 'incumplimientos', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'estructura' => 'array',
            'respuestas' => 'array',
            'puntaje' => 'float',
            'porcentaje' => 'float',
            'incumplimientos' => 'integer',
        ];
    }

    /** @return BelongsTo<PesvContractor, $this> */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(PesvContractor::class, 'pesv_contractor_id');
    }

    /** Recalifica con la estructura GUARDADA, no con el catálogo actual. */
    public function calificar(): void
    {
        $r = EvaluacionesContratistas::calificar($this->estructura, $this->respuestas);
        $this->puntaje = $r['puntaje'];
        $this->porcentaje = $r['porcentaje'];
        $this->resultado = $r['resultado'];
        $this->incumplimientos = $r['incumplimientos'];
    }

    public function resumen(): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha->toDateString(),
            'formato' => $this->formato,
            'puntaje' => $this->puntaje,
            'porcentaje' => $this->porcentaje,
            'resultado' => $this->resultado,
            'incumplimientos' => $this->incumplimientos,
        ];
    }
}
