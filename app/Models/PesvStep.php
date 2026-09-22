<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uno de los 24 pasos del PESV (Res. 40595 de 2022).
 *
 * Catálogo GLOBAL, no segregado por tenant: los pasos son los mismos para
 * todas las empresas cliente. Lo que cambia por empresa es PesvPlanStep.
 */
class PesvStep extends Model
{
    protected $fillable = ['numero', 'fase', 'fase_nombre', 'titulo', 'descripcion', 'orden'];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'fase' => 'integer',
            'orden' => 'integer',
        ];
    }

    /** @return HasMany<PesvPlanStep, $this> */
    public function planSteps(): HasMany
    {
        return $this->hasMany(PesvPlanStep::class);
    }
}
