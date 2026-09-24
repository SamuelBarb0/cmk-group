<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Auditoría interna o externa del sistema de gestión.
 *
 * La lista de chequeo y las actas de apertura y cierre NO viven aquí: son
 * formatos del motor (FT-LCH-AUD, FT-ACTA-AUD-APER, FT-ACTA-AUD-CIERRE).
 * Aquí está la auditoría como evento y sus hallazgos.
 */
class Audit extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tipo', 'objetivo', 'alcance', 'criterios', 'sistemas', 'procesos',
        'fecha_programada', 'fecha_inicio', 'fecha_fin',
        'auditor_lider', 'equipo_auditor', 'estado',
        'conclusiones', 'observaciones',
        // `codigo` lo genera el modelo, igual que ACPM y accidentes.
    ];

    protected function casts(): array
    {
        return [
            'fecha_programada' => 'date:Y-m-d',
            'fecha_inicio' => 'date:Y-m-d',
            'fecha_fin' => 'date:Y-m-d',
            'sistemas' => 'array',
        ];
    }

    public const TIPOS = ['interna', 'externa', 'contratistas', 'terceros'];

    public const ESTADOS = ['programada', 'en_curso', 'cerrada'];

    protected $appends = ['no_conformidades'];

    protected static function booted(): void
    {
        static::creating(function (Audit $aud): void {
            $aud->codigo ??= self::siguienteCodigo($aud->tenant_id);
        });
    }

    /** Consecutivo AUD-<año>-<###> por cliente. Ver AcpmAction::siguienteCodigo. */
    public static function siguienteCodigo(?int $tenantId): string
    {
        $prefijo = 'AUD-'.now()->year.'-';

        $ultimo = self::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('codigo', 'like', $prefijo.'%')
            ->orderByDesc('codigo')
            ->value('codigo');

        $n = $ultimo ? ((int) substr($ultimo, strlen($prefijo))) + 1 : 1;

        return $prefijo.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Cuenta solo las no conformidades, mayores y menores. Las observaciones,
     * oportunidades y fortalezas NO son incumplimientos y contarlas junto a
     * ellas daría un informe que se ve peor de lo que está.
     */
    public function getNoConformidadesAttribute(): int
    {
        return $this->findings
            ->whereIn('tipo', ['no_conformidad_mayor', 'no_conformidad_menor'])
            ->count();
    }

    /** @return HasMany<AuditFinding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(AuditFinding::class);
    }

    /** @return HasMany<AuditCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(AuditCheck::class);
    }

    /**
     * Requisitos del alcance: las filas de las normas elegidas (edición
     * vigente). Sin normas elegidas no hay lista de verificación.
     *
     * @return Collection<int, NormRequirement>
     */
    public function requisitosDelAlcance(): Collection
    {
        if (empty($this->sistemas)) {
            return new Collection;
        }

        return NormRequirement::query()
            ->with('norm:id,clave,nombre')
            ->whereHas('norm', fn ($q) => $q->where('vigente', true)->whereIn('clave', $this->sistemas))
            ->orderBy('orden')
            ->get();
    }

    /**
     * Ids de las filas de requisito que corresponden a unas claves comunes
     * dentro del alcance. Sin normas elegidas se toman todas las vigentes.
     *
     * @param  list<string>  $claves
     * @return list<int>
     */
    public function requisitosDeClaves(array $claves): array
    {
        if ($claves === []) {
            return [];
        }

        return NormRequirement::query()
            ->whereIn('clave_comun', $claves)
            ->whereHas('norm', fn ($q) => $q->where('vigente', true)
                ->when(! empty($this->sistemas), fn ($q) => $q->whereIn('clave', $this->sistemas)))
            ->pluck('id')
            ->all();
    }

    /**
     * Informe por norma: cada requisito evaluado cuenta en todas las normas
     * del alcance donde existe. `observacion` cumple; `no_aplica` sale del
     * denominador; los pendientes no cuentan pero se informan.
     *
     * @return list<array{clave: string, nombre: string, requisitos: int, conformes: int, no_conformes: int, no_aplica: int, pendientes: int, cumplimiento: int|null, hallazgos: int}>
     */
    public function cumplimientoPorNorma(): array
    {
        $requisitos = $this->requisitosDelAlcance();
        $respuestas = $this->checks()->pluck('resultado', 'clave_comun');

        // Hallazgos por norma, a partir de los requisitos que cada uno incumple.
        $hallazgosPorNorma = AuditFinding::query()
            ->where('audit_id', $this->id)
            ->with('requirements.norm:id,clave')
            ->get()
            ->flatMap(fn (AuditFinding $f) => $f->requirements->map(fn ($r) => $r->norm->clave)->unique()->values())
            ->countBy();

        return $requisitos->groupBy(fn (NormRequirement $r) => $r->norm->clave)
            ->sortBy(fn ($g, $clave) => array_search($clave, Norm::SISTEMAS, true))
            ->map(function ($filas, string $clave) use ($respuestas, $hallazgosPorNorma) {
                $resultados = $filas->map(fn ($r) => $respuestas[$r->clave_comun] ?? null);
                $conformes = $resultados->filter(fn ($x) => in_array($x, ['conforme', 'observacion'], true))->count();
                $noConformes = $resultados->filter(fn ($x) => $x === 'no_conforme')->count();
                $evaluados = $conformes + $noConformes;

                return [
                    'clave' => $clave,
                    'nombre' => Norm::NOMBRES[$clave] ?? $clave,
                    'requisitos' => $filas->count(),
                    'conformes' => $conformes,
                    'no_conformes' => $noConformes,
                    'no_aplica' => $resultados->filter(fn ($x) => $x === 'no_aplica')->count(),
                    'pendientes' => $resultados->filter(fn ($x) => $x === null)->count(),
                    'cumplimiento' => $evaluados ? (int) round($conformes * 100 / $evaluados) : null,
                    'hallazgos' => $hallazgosPorNorma[$clave] ?? 0,
                ];
            })
            ->values()
            ->all();
    }
}
