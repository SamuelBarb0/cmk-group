<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Empleado (trabajador) de una empresa cliente.
 *
 * Registro base del SGI. Segregado por tenant mediante BelongsToTenant:
 * al crear se asigna automáticamente el tenant_id del cliente activo.
 */
class Employee extends Model
{
    /** @use HasFactory<\Database\Factories\EmployeeFactory> */
    use HasFactory;
    use BelongsToTenant;

    protected $fillable = [
        'nombres',
        'apellidos',
        'tipo_documento',
        'numero_documento',
        'fecha_nacimiento',
        'genero',
        'grupo_sanguineo',
        'telefono',
        'email',
        'direccion',
        'ciudad',
        'cargo',
        'area',
        'sede',
        'fecha_ingreso',
        'tipo_contrato',
        'salario',
        'eps',
        'afp',
        'arl',
        'nivel_riesgo',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'fecha_ingreso' => 'date',
            'salario' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** Nombre completo para listados. */
    public function getNombreCompletoAttribute(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }
}
