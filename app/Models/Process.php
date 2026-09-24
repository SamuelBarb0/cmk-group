<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Proceso del mapa de procesos de una empresa. Segregado por tenant.
 *
 * Es el «dueño» que va en el código de cada documento (PRC-SST-004): se
 * codifica por proceso y no por norma porque un mismo documento sirve a varias
 * normas, y el proceso dueño es lo estable y lo que dice quién aprueba.
 */
class Process extends Model
{
    use BelongsToTenant;

    protected $fillable = ['sigla', 'nombre', 'tipo', 'orden'];

    public const TIPOS = ['estrategico', 'misional', 'apoyo', 'evaluacion'];

    /** Mapa de procesos base del informe (sección 5.3). Cada empresa lo ajusta. */
    public const BASE = [
        ['sigla' => 'DIR', 'nombre' => 'Direccionamiento estratégico', 'tipo' => 'estrategico'],
        ['sigla' => 'GSI', 'nombre' => 'Gestión del sistema integrado', 'tipo' => 'estrategico'],
        ['sigla' => 'OPE', 'nombre' => 'Operación y prestación del servicio', 'tipo' => 'misional'],
        ['sigla' => 'SVL', 'nombre' => 'Seguridad vial y gestión de flota', 'tipo' => 'misional'],
        ['sigla' => 'GTH', 'nombre' => 'Gestión del talento humano', 'tipo' => 'apoyo'],
        ['sigla' => 'SST', 'nombre' => 'Seguridad y salud en el trabajo', 'tipo' => 'apoyo'],
        ['sigla' => 'AMB', 'nombre' => 'Gestión ambiental', 'tipo' => 'apoyo'],
        ['sigla' => 'COM', 'nombre' => 'Compras y proveedores', 'tipo' => 'apoyo'],
        ['sigla' => 'EVA', 'nombre' => 'Evaluación y mejora', 'tipo' => 'evaluacion'],
    ];

    /**
     * Crea el mapa base si la empresa todavía no tiene ningún proceso.
     *
     * Solo si no tiene NINGUNO: si ya borró o renombró alguno, volver a
     * sembrarlo le desharía su propio mapa.
     */
    public static function asegurarBase(int $tenantId): void
    {
        if (self::withoutTenantScope()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        foreach (self::BASE as $i => $p) {
            $proceso = new self($p + ['orden' => $i + 1]);
            $proceso->tenant_id = $tenantId;
            $proceso->save();
        }
    }

    /** @return HasMany<ControlledDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ControlledDocument::class);
    }
}
