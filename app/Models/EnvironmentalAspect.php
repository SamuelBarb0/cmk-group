<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aspecto ambiental con su impacto (ISO 14001 6.1.2). Segregado por tenant.
 *
 * Significancia: frecuencia × severidad ≥ UMBRAL, o de una vez si el aspecto
 * tiene un requisito legal asociado o preocupa a las partes interesadas, o si
 * es de emergencia con severidad ≥ SEVERIDAD_EMERGENCIA (una emergencia es
 * rara por definición: multiplicar por la frecuencia la escondería). Es
 * el criterio más usado en Colombia y cada empresa puede ajustar el umbral en
 * su procedimiento; aquí queda fijo para que la matriz sea comparable.
 */
class EnvironmentalAspect extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'process_id', 'actividad', 'aspecto', 'impacto', 'tipo_impacto', 'condicion', 'etapa',
        'frecuencia', 'severidad', 'requisito_legal', 'preocupa_partes', 'controles', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'frecuencia' => 'integer',
            'severidad' => 'integer',
            'requisito_legal' => 'boolean',
            'preocupa_partes' => 'boolean',
        ];
    }

    protected $appends = ['valor', 'significativo'];

    public const UMBRAL = 12;

    public const SEVERIDAD_EMERGENCIA = 4;

    public const TIPOS_IMPACTO = ['negativo', 'positivo'];

    public const CONDICIONES = ['normal', 'anormal', 'emergencia'];

    /** Etapas del ciclo de vida (ISO 14001:2026, perspectiva de ciclo de vida). */
    public const ETAPAS = [
        'materias_primas' => 'Materias primas y compras',
        'diseno' => 'Diseño',
        'operacion' => 'Operación y producción',
        'transporte' => 'Transporte y entrega',
        'uso' => 'Uso del producto o servicio',
        'fin_vida' => 'Fin de vida y disposición final',
    ];

    public function getValorAttribute(): int
    {
        return (int) $this->frecuencia * (int) $this->severidad;
    }

    public function getSignificativoAttribute(): bool
    {
        return $this->tipo_impacto === 'negativo'
            && ($this->valor >= self::UMBRAL || $this->requisito_legal || $this->preocupa_partes
                || ($this->condicion === 'emergencia' && $this->severidad >= self::SEVERIDAD_EMERGENCIA));
    }

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    /** @return BelongsTo<AcpmAction, $this> */
    public function acpmAction(): BelongsTo
    {
        return $this->belongsTo(AcpmAction::class);
    }

    /**
     * Aspectos típicos para arrancar (oficina, flota y operación general). La
     * empresa borra los que no le aplican y ajusta los puntajes a su realidad.
     *
     * [actividad, aspecto, impacto, condicion, etapa, frecuencia, severidad, requisito_legal, controles]
     */
    public const BASE = [
        ['Uso de equipos y oficinas', 'Consumo de energía eléctrica', 'Agotamiento de recursos naturales', 'normal', 'operacion', 5, 2, false, 'Programa de uso eficiente de la energía, apagado de equipos.'],
        ['Uso de baños, cocina y aseo', 'Consumo de agua', 'Agotamiento del recurso hídrico', 'normal', 'operacion', 5, 2, false, 'Programa de uso eficiente y ahorro del agua.'],
        ['Labores administrativas', 'Consumo de papel', 'Agotamiento de recursos naturales', 'normal', 'operacion', 4, 1, false, 'Uso de documentos digitales e impresión a doble cara.'],
        ['Labores generales', 'Generación de residuos aprovechables y ordinarios', 'Contaminación del suelo', 'normal', 'operacion', 5, 2, true, 'Separación en la fuente con el código de colores vigente.'],
        ['Mantenimiento de equipos y cambio de luminarias', 'Generación de residuos peligrosos (RESPEL): luminarias, tóner, baterías, aceites', 'Contaminación del suelo y el agua', 'normal', 'fin_vida', 3, 4, true, 'Almacenamiento temporal y entrega a gestor autorizado con certificado.'],
        ['Desplazamientos en vehículos de la empresa', 'Emisiones de gases de combustión', 'Contaminación del aire y aporte al cambio climático', 'normal', 'transporte', 5, 3, true, 'Mantenimiento preventivo y revisión técnico-mecánica al día.'],
        ['Tanqueo y mantenimiento de vehículos', 'Derrame de combustible o aceite', 'Contaminación del suelo y el agua', 'emergencia', 'transporte', 1, 4, true, 'Kit de derrames y procedimiento de respuesta.'],
        ['Compra de insumos y materiales', 'Consumo de materias primas', 'Agotamiento de recursos naturales', 'normal', 'materias_primas', 4, 2, false, 'Criterios ambientales en la evaluación de proveedores.'],
        ['Uso de baños y cocina', 'Generación de aguas residuales domésticas', 'Contaminación del agua', 'normal', 'operacion', 5, 2, true, 'Conexión al alcantarillado y mantenimiento de redes.'],
        ['Emergencia por incendio', 'Emisiones de humo y residuos del incendio', 'Contaminación del aire y el suelo', 'emergencia', 'operacion', 1, 5, false, 'Plan de emergencias, extintores y brigada.'],
    ];
}
