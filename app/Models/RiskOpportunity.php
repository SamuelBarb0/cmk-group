<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riesgo u oportunidad de un proceso (ISO 45001 6.1.1, ISO 9001 6.1, ISO 14001
 * 6.1.1). Segregado por tenant.
 *
 * El nivel sale de probabilidad × impacto en una matriz 5×5. En una
 * oportunidad «impacto» es el beneficio si se aprovecha.
 */
class RiskOpportunity extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'process_id', 'context_issue_id', 'tipo', 'descripcion', 'causa', 'efecto',
        'probabilidad', 'impacto', 'tratamiento', 'acciones', 'responsable', 'fecha_limite',
        'estado', 'eficacia', 'evaluado_at', 'sistemas', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'sistemas' => 'array',
            'probabilidad' => 'integer',
            'impacto' => 'integer',
            'fecha_limite' => 'date:Y-m-d',
            'evaluado_at' => 'date:Y-m-d',
        ];
    }

    protected $appends = ['valor', 'nivel'];

    public const TIPOS = ['riesgo', 'oportunidad'];

    /** Opciones de tratamiento según el tipo (ISO 31000, lenguaje llano). */
    public const TRATAMIENTOS = [
        'riesgo' => ['evitar', 'reducir', 'compartir', 'aceptar'],
        'oportunidad' => ['aprovechar', 'potenciar', 'compartir', 'aceptar'],
    ];

    public const ESTADOS = ['abierto', 'en_tratamiento', 'cerrado'];

    /** Tope de cada nivel en la matriz 5×5 (el producto va de 1 a 25). */
    public const NIVELES = ['bajo' => 4, 'medio' => 9, 'alto' => 16, 'critico' => 25];

    public function getValorAttribute(): int
    {
        return (int) $this->probabilidad * (int) $this->impacto;
    }

    public function getNivelAttribute(): string
    {
        return self::nivelDe($this->valor);
    }

    public static function nivelDe(int $valor): string
    {
        foreach (self::NIVELES as $nivel => $tope) {
            if ($valor <= $tope) {
                return $nivel;
            }
        }

        return 'critico';
    }

    /** Un riesgo alto o crítico sin acción ni responsable: lo primero que pregunta un auditor. */
    public function sinTratar(): bool
    {
        return $this->tipo === 'riesgo'
            && in_array($this->nivel, ['alto', 'critico'], true)
            && $this->estado !== 'cerrado'
            && $this->tratamiento !== 'aceptar'
            && (blank($this->acciones) || blank($this->responsable));
    }

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    /** @return BelongsTo<ContextIssue, $this> */
    public function contextIssue(): BelongsTo
    {
        return $this->belongsTo(ContextIssue::class);
    }

    /** @return BelongsTo<AcpmAction, $this> */
    public function acpmAction(): BelongsTo
    {
        return $this->belongsTo(AcpmAction::class);
    }
}
