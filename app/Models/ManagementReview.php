<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Revisión por la dirección de una empresa. Segregada por tenant.
 *
 * Mientras está en borrador se recopilan los datos y se escribe el análisis;
 * cerrada, es un registro: no cambia (solo el seguimiento de sus decisiones).
 */
class ManagementReview extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'periodo_desde', 'periodo_hasta', 'fecha_reunion', 'sistemas', 'participantes',
        'analisis', 'conclusiones_sistema', 'conclusiones',
        // `codigo`, `datos`, `estado` y `cerrada_*` los ponen el modelo y el
        // controlador: no llegan de la petición.
    ];

    protected function casts(): array
    {
        return [
            'periodo_desde' => 'date:Y-m-d',
            'periodo_hasta' => 'date:Y-m-d',
            'fecha_reunion' => 'date:Y-m-d',
            'sistemas' => 'array',
            'datos' => 'array',
            'datos_at' => 'datetime',
            'analisis' => 'array',
            'conclusiones_sistema' => 'array',
            'cerrada_at' => 'datetime',
        ];
    }

    /**
     * Entradas de la revisión: lo que ISO 9.3 y el art. 2.2.4.6.31 del
     * Dec. 1072 obligan a revisar, y de qué secciones del informe de gestión
     * salen sus datos. Las referencias al decreto van por artículo, sin
     * literal: conviene verificarlas contra el texto vigente antes de citarlas
     * más finas. Las que no tienen sección (contexto, recursos, mejora)
     * se alimentan solo del análisis de la dirección. `sistemas` limita la
     * entrada a las revisiones que cubren alguna de esas normas (sin la clave,
     * aplica a todas).
     */
    public const ENTRADAS = [
        'acciones_previas' => [
            'titulo' => 'Estado de las acciones de revisiones anteriores',
            'referencias' => 'ISO 9001 9.3.2 a · ISO 45001/14001 9.3 a · Dec. 1072 2.2.4.6.31',
            'secciones' => [],
        ],
        'contexto' => [
            'titulo' => 'Cambios en el contexto, las partes interesadas y los procesos',
            'referencias' => 'ISO 9001 9.3.2 b · ISO 45001/14001 9.3 b',
            'secciones' => ['gestion-cambio'],
        ],
        'objetivos' => [
            'titulo' => 'Cumplimiento de la política, los objetivos, el plan de trabajo y los indicadores',
            'referencias' => 'ISO 9001 9.3.2 c · Dec. 1072 2.2.4.6.31 · Res. 0312 6.1.3',
            'secciones' => ['diagnostico', 'plan-trabajo', 'indicadores'],
        ],
        'riesgos' => [
            'titulo' => 'Desempeño en seguridad y salud: incidentes, ausentismo, riesgos y controles',
            'referencias' => 'ISO 45001 9.3 · ISO 9001 9.3.2 e · Dec. 1072 2.2.4.6.31',
            'secciones' => ['accidentes', 'ausentismo', 'reportes-ac', 'iperc', 'salud-ocupacional', 'programas', 'epp', 'inspecciones', 'emergencias', 'mantenimiento'],
            'sistemas' => ['sst', 'pesv', 'iso45001', 'iso14001'],
        ],
        'acpm' => [
            'titulo' => 'No conformidades y acciones correctivas, preventivas y de mejora',
            'referencias' => 'ISO 9001 9.3.2 c · ISO 45001/14001 9.3 · Dec. 1072 2.2.4.6.31',
            'secciones' => ['acpm'],
        ],
        'auditorias' => [
            'titulo' => 'Resultados de las auditorías',
            'referencias' => 'ISO 9001 9.3.2 c · ISO 45001/14001 9.3 · Dec. 1072 2.2.4.6.31',
            'secciones' => ['auditoria'],
        ],
        'legal' => [
            'titulo' => 'Cumplimiento de los requisitos legales y otros requisitos',
            'referencias' => 'ISO 45001/14001 9.3 · Dec. 1072 2.2.4.6.31',
            'secciones' => ['requisitos-legales'],
        ],
        'participacion' => [
            'titulo' => 'Consulta y participación de los trabajadores, competencia y formación',
            'referencias' => 'ISO 45001 9.3 · Dec. 1072 2.2.4.6.31',
            'secciones' => ['comites', 'capacitaciones'],
            'sistemas' => ['sst', 'pesv', 'iso45001'],
        ],
        'proveedores' => [
            'titulo' => 'Desempeño de proveedores y contratistas',
            'referencias' => 'ISO 9001 9.3.2 c',
            'secciones' => ['contratistas'],
        ],
        'pesv' => [
            'titulo' => 'Desempeño del Plan Estratégico de Seguridad Vial',
            'referencias' => 'Res. 40595 (seguimiento y mejora del PESV)',
            'secciones' => ['pesv'],
            'sistemas' => ['pesv'],
        ],
        'recursos' => [
            'titulo' => 'Adecuación de los recursos (financieros, humanos, técnicos y tecnológicos)',
            'referencias' => 'ISO 9001 9.3.2 d · ISO 45001/14001 9.3 · Dec. 1072 2.2.4.6.31',
            'secciones' => [],
        ],
        'mejora' => [
            'titulo' => 'Oportunidades de mejora continua',
            'referencias' => 'ISO 9001 9.3.2 f · ISO 45001/14001 9.3',
            'secciones' => [],
        ],
    ];

    /** Conclusiones que ISO 9.3 pide sobre el sistema. */
    public const CRITERIOS = [
        'conveniente' => 'Conveniencia',
        'adecuado' => 'Adecuación',
        'eficaz' => 'Eficacia',
    ];

    public const VALORACIONES = ['si', 'parcial', 'no'];

    protected static function booted(): void
    {
        static::creating(function (ManagementReview $r): void {
            $r->codigo ??= self::siguienteCodigo($r->tenant_id);
        });
    }

    /** Consecutivo RXD-<año>-<###> por empresa. Ver AcpmAction::siguienteCodigo. */
    public static function siguienteCodigo(?int $tenantId): string
    {
        $prefijo = 'RXD-'.now()->year.'-';

        $ultimo = self::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('codigo', 'like', $prefijo.'%')
            ->orderByDesc('codigo')
            ->value('codigo');

        $n = $ultimo ? ((int) substr($ultimo, strlen($prefijo))) + 1 : 1;

        return $prefijo.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    /** @return HasMany<ManagementReviewDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(ManagementReviewDecision::class)->orderBy('id');
    }

    public function estaCerrada(): bool
    {
        return $this->estado === 'cerrada';
    }

    /**
     * Decisiones de las revisiones anteriores de la empresa, con su estado de
     * hoy. Es la primera entrada de toda revisión.
     */
    public function decisionesAnteriores(): Collection
    {
        return ManagementReviewDecision::query()
            ->whereHas('review', fn ($q) => $q->where('tenant_id', $this->tenant_id)
                ->where('id', '!=', $this->id)
                ->where('periodo_hasta', '<=', $this->periodo_hasta))
            ->with('review:id,codigo')
            ->orderBy('id')
            ->get();
    }

    /**
     * Entradas que aplican a las normas de esta revisión.
     *
     * @return array<string, array{titulo: string, referencias: string, secciones: list<string>}>
     */
    public function entradasAplicables(): array
    {
        $sistemas = $this->sistemas ?? [];

        return array_filter(
            self::ENTRADAS,
            fn (array $e) => ! isset($e['sistemas']) || array_intersect($e['sistemas'], $sistemas) !== [],
        );
    }

    /**
     * Entradas a las que todavía les falta el análisis de la dirección. Una
     * revisión no se cierra así: la norma pide considerar cada una.
     *
     * @return list<string>
     */
    public function entradasSinAnalisis(): array
    {
        $analisis = $this->analisis ?? [];

        return array_values(array_filter(
            array_keys($this->entradasAplicables()),
            fn (string $clave) => blank($analisis[$clave] ?? null),
        ));
    }
}
