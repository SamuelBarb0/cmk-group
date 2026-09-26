<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Presentación (.pptx) generada por la IA con el contexto del cliente.
 * Segregada por tenant.
 */
class Presentation extends Model
{
    use BelongsToTenant;

    // `estado`, `contenido`, `archivo`, `titulo` y `error` los pone el job.
    protected $fillable = ['modulo', 'proposito', 'instrucciones', 'diapositivas', 'desde', 'hasta'];

    protected $hidden = ['archivo', 'contenido'];

    protected function casts(): array
    {
        return [
            'contenido' => 'array',
            'desde' => 'date:Y-m-d',
            'hasta' => 'date:Y-m-d',
            'diapositivas' => 'integer',
        ];
    }

    /** propósito => [etiqueta, qué le pide a la IA] */
    public const PROPOSITOS = [
        'gerencia' => ['Presentación a la gerencia', 'Para la alta dirección: resultados, brechas, riesgos y decisiones que se necesitan de ella. Directa y ejecutiva.'],
        'trabajadores' => ['Socialización con los trabajadores', 'Para socializar con los trabajadores: lenguaje sencillo, qué significa para ellos, qué se espera de ellos y cómo participar.'],
        'capacitacion' => ['Capacitación', 'Una capacitación: objetivos de aprendizaje, conceptos clave con ejemplos de la empresa, qué hacer y qué no, y una evaluación corta al final.'],
        'auditoria' => ['Apertura o cierre de auditoría', 'Para una auditoría: alcance, criterios (normas), estado del sistema, evidencias disponibles y puntos pendientes.'],
        'comite' => ['Reunión de comité', 'Para un comité (COPASST, convivencia o seguridad vial): seguimiento, cifras del periodo, compromisos y temas a decidir.'],
        'otro' => ['Otro propósito', 'Sigue las instrucciones adicionales.'],
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
