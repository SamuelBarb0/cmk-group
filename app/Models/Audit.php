<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
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
        'tipo', 'objetivo', 'alcance', 'criterios', 'procesos',
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
}
