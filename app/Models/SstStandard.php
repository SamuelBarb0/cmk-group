<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Estándar mínimo del SG-SST (Resolución 0312 de 2019).
 * Catálogo GLOBAL, compartido por todos los clientes (no lleva tenant_id).
 */
class SstStandard extends Model
{
    protected $fillable = [
        'codigo',
        'ciclo',
        'grupo',
        'peso_grupo',
        'item',
        'valor',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'peso_grupo' => 'integer',
            'orden' => 'integer',
        ];
    }
}
