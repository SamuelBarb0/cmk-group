<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Integrante de la brigada de emergencias (estándar 5.1.2 de la Res. 0312:
 * «brigada conformada, capacitada y dotada»).
 */
class BrigadeMember extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_id', 'nombres', 'numero_documento', 'cargo', 'telefono',
        'rol', 'especialidad', 'fecha_inscripcion',
        'grupo_sanguineo', 'eps', 'arl', 'limitaciones_fisicas', 'usa_anteojos',
        'contacto_emergencia_nombre', 'contacto_emergencia_telefono',
        'curso_primer_respondiente', 'fecha_curso', 'activo', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inscripcion' => 'date:Y-m-d',
            'fecha_curso' => 'date:Y-m-d',
            'usa_anteojos' => 'boolean',
            'curso_primer_respondiente' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /** El organigrama de la hoja «Estructura brigada», de arriba abajo. */
    public const ROLES = [
        'coordinador_emergencias',
        'jefe_brigada',
        'lider_primeros_auxilios',
        'lider_incendios',
        'lider_evacuacion',
        'seguridad_fisica',
        'enlace_logistica',
        'brigadista',
    ];

    /**
     * Los cargos del organigrama que no pueden quedar vacíos. Seguridad física
     * y enlace no entran: en una empresa pequeña los asume el coordinador.
     */
    public const ROLES_CLAVE = [
        'coordinador_emergencias',
        'jefe_brigada',
        'lider_primeros_auxilios',
        'lider_incendios',
        'lider_evacuacion',
    ];

    /** «En qué brigada se quiere especializar», del formulario de inscripción. */
    public const ESPECIALIDADES = ['primeros_auxilios', 'evacuacion', 'contra_incendios'];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
