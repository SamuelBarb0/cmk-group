<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Builder;
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
    use BelongsToTenant;

    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

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
        // Ficha de conductor (PESV, Res. 40595 Paso 5). Ver la migración
        // 2026_09_10_000002: los conductores que no son empleados van en
        // pesv_contractors, no aquí.
        'es_conductor',
        'licencia_numero',
        'licencia_categoria',
        'licencia_vence',
        'examen_psicosensometrico_vence',
        'curso_manejo_defensivo',
        'observaciones_conductor',
        // Perfil sociodemográfico (encuesta 3.1.1 del libro de CMK). Ver la
        // migración 2026_09_17_000002: edad, género, antigüedad en la empresa y
        // tipo de contrato NO están aquí porque ya salen de los campos de
        // arriba, y tenerlos dos veces daría dos respuestas distintas.
        'estado_civil',
        'personas_a_cargo',
        'escolaridad',
        'tenencia_vivienda',
        'uso_tiempo_libre',
        'ingresos_smlv',
        'antiguedad_cargo',
        'actividades_salud',
        'consume_alcohol',
        'alcohol_frecuencia',
        'fuma',
        'fuma_promedio_dia',
        'practica_deporte',
        'deporte_frecuencia',
        'consentimiento_datos',
        'consentimiento_fecha',
        // `perfil_actualizado_at` NO es asignable: lo pone el modelo cuando
        // alguna respuesta de la encuesta cambia de verdad. Dejarlo asignable
        // permitiría marcar el perfil como diligenciado sin diligenciarlo.
    ];

    /**
     * Campos que componen la encuesta sociodemográfica. Se usan para saber si
     * el perfil se tocó en un guardado y así fechar `perfil_actualizado_at`.
     */
    public const CAMPOS_PERFIL = [
        'estado_civil', 'personas_a_cargo', 'escolaridad', 'tenencia_vivienda',
        'uso_tiempo_libre', 'ingresos_smlv', 'antiguedad_cargo', 'actividades_salud',
        'consume_alcohol', 'alcohol_frecuencia', 'fuma', 'fuma_promedio_dia',
        'practica_deporte', 'deporte_frecuencia',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date:Y-m-d',
            'fecha_ingreso' => 'date:Y-m-d',
            'salario' => 'decimal:2',
            'is_active' => 'boolean',
            'es_conductor' => 'boolean',
            'licencia_vence' => 'date:Y-m-d',
            'examen_psicosensometrico_vence' => 'date:Y-m-d',
            'curso_manejo_defensivo' => 'date:Y-m-d',
            'actividades_salud' => 'array',
            'consume_alcohol' => 'boolean',
            'fuma' => 'boolean',
            'practica_deporte' => 'boolean',
            'consentimiento_datos' => 'boolean',
            'consentimiento_fecha' => 'date:Y-m-d',
            'perfil_actualizado_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Fecha el perfil sociodemográfico cuando alguna respuesta cambia de
        // verdad. Se mira `isDirty` y no la presencia de los campos porque el
        // formulario los manda siempre, incluso vacíos: sin esto, editar el
        // teléfono de alguien marcaría su encuesta como recién diligenciada y
        // la alerta de perfiles vencidos nunca se encendería.
        static::saving(function (Employee $empleado): void {
            if ($empleado->isDirty(self::CAMPOS_PERFIL)) {
                $empleado->perfil_actualizado_at = now();
            }
        });
    }

    /** Solo los colaboradores marcados como conductores (caracterización PESV). */
    public function scopeConductores(Builder $query): Builder
    {
        return $query->where('es_conductor', true);
    }

    /** Nombre completo para listados. */
    public function getNombreCompletoAttribute(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }
}
