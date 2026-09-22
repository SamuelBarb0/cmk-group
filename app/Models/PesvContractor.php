<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Contratista, subcontratista o tercero con impacto en el PESV.
 *
 * Caracterización del Paso 5; insumo de los pasos 11 (evaluación de terceros)
 * y 18 (requisitos de seguridad vial exigidos a contratistas).
 */
class PesvContractor extends Model
{
    use BelongsToTenant;

    public const TIPOS = ['contratista', 'subcontratista', 'tercero', 'proveedor', 'propietario_vehiculo'];

    protected $fillable = [
        'nombre',
        'nit',
        'tipo',
        'actividad',
        'contacto_nombre',
        'contacto_telefono',
        'contacto_email',
        'num_conductores',
        'num_vehiculos',
        'tiene_pesv',
        'evaluado_at',
        'calificacion',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'evaluado_at' => 'date:Y-m-d',
            'num_conductores' => 'integer',
            'num_vehiculos' => 'integer',
            'calificacion' => 'integer',
            'tiene_pesv' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
