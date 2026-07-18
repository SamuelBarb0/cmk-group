<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

/**
 * Diagnóstico de Estándares Mínimos SG-SST de una empresa cliente.
 * Segregado por tenant. El puntaje se recalcula a partir de sus ítems.
 */
class SstDiagnostic extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'fecha',
        'evaluador',
        'puntaje',
        'clasificacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'puntaje' => 'decimal:2',
        ];
    }

    /** @return HasMany<SstDiagnosticItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SstDiagnosticItem::class);
    }

    /**
     * Recalcula puntaje (0..100) y clasificación según Resolución 0312.
     * "No aplica" (justificado) cuenta como cumplido; "cumple" suma su valor.
     */
    public function recalcular(): void
    {
        $puntaje = $this->items()
            ->join('sst_standards', 'sst_standards.id', '=', 'sst_diagnostic_items.sst_standard_id')
            ->whereIn('sst_diagnostic_items.estado', ['cumple', 'no_aplica'])
            ->sum('sst_standards.valor');

        $this->puntaje = round((float) $puntaje, 2);
        $this->clasificacion = self::clasificar($this->puntaje);
        $this->save();
    }

    /** Valoración del SG-SST según el puntaje obtenido (Res. 0312). */
    public static function clasificar(float $puntaje): string
    {
        if ($puntaje < 60) {
            return 'Crítico';
        }
        if ($puntaje <= 85) {
            return 'Moderadamente aceptable';
        }

        return 'Aceptable';
    }
}
