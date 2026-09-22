<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Sede o centro de trabajo de la empresa (caracterización del Paso 5). */
class PesvSede extends Model
{
    use BelongsToTenant;

    protected $table = 'pesv_sedes';

    protected $fillable = [
        'nombre',
        'direccion',
        'ciudad',
        'departamento',
        'telefono',
        'responsable',
        'num_trabajadores',
        'es_principal',
    ];

    protected function casts(): array
    {
        return [
            'num_trabajadores' => 'integer',
            'es_principal' => 'boolean',
        ];
    }
}
