<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Kilómetros recorridos por toda la flota en un trimestre: el denominador de
 * la tasa de siniestros viales TSV(n) de la Res. 40595 (Tabla 10, indicador 1).
 */
class PesvKmPeriodo extends Model
{
    use BelongsToTenant;

    protected $table = 'pesv_km_periodos';

    protected $fillable = ['anio', 'trimestre', 'km'];

    protected function casts(): array
    {
        return ['anio' => 'integer', 'trimestre' => 'integer', 'km' => 'decimal:1'];
    }
}
