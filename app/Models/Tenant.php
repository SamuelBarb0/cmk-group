<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Un Tenant representa una empresa CLIENTE gestionada por CMK GROUP.
 * La información de cada tenant queda segregada mediante tenant_id
 * (Row Level Security a nivel de aplicación; en PostgreSQL se refuerza
 * con políticas RLS nativas en producción).
 */
class Tenant extends Model
{
    /** @use HasFactory<\Database\Factories\TenantFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'legal_name',
        'nit',
        'email',
        'phone',
        'city',
        'address',
        'logo_path',
        'is_active',
        // Módulos contratados por la empresa (null = todos).
        'modulos',
        // Partes contratadas de cada módulo ({modulo: [partes]}; ausente = todas).
        'submodulos',
        // Documentos del mapa del SIG contratados (ids del catálogo; null = todos).
        'documentos_sig',
        // Información de la Organización (contexto SGI)
        'actividad_economica',
        'codigo_ciiu',
        'sector',
        'nivel_riesgo',
        'arl',
        'tamano_empresa',
        'num_trabajadores',
        'representante_legal',
        'representante_cc',
        'responsable_sgsst',
        'licencia_sgsst',
        'licencia_sgsst_vence',
        'curso_sst_horas',
        'curso_sst_fecha',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'num_trabajadores' => 'integer',
            'modulos' => 'array',
            'submodulos' => 'array',
            'documentos_sig' => 'array',
            'licencia_sgsst_vence' => 'date:Y-m-d',
            'curso_sst_fecha' => 'date:Y-m-d',
        ];
    }

    /**
     * ¿La empresa tiene habilitado (contratado) el módulo dado?
     * modulos = null significa que tiene todos los módulos.
     */
    public function moduloHabilitado(string $modulo): bool
    {
        return $this->modulos === null || in_array($modulo, $this->modulos, true);
    }

    /**
     * ¿La empresa tiene la parte `$parte` del módulo? Exige el módulo; si el
     * módulo no tiene selección de partes, las tiene todas.
     */
    public function submoduloHabilitado(string $modulo, string $parte): bool
    {
        if (! $this->moduloHabilitado($modulo)) {
            return false;
        }
        $partes = $this->submodulos[$modulo] ?? null;

        return $partes === null || in_array($parte, $partes, true);
    }

    /**
     * Partes contratadas de cada módulo con partes, ya resueltas (sin nulos):
     * lo que necesita la interfaz para ocultar pestañas.
     *
     * @return array<string, list<string>>
     */
    public function partesContratadas(): array
    {
        return collect(config('cmk.submodulos'))
            ->map(fn (array $partes, string $modulo) => collect(array_keys($partes))
                ->filter(fn (string $p) => $this->submoduloHabilitado($modulo, $p))->values()->all())
            ->all();
    }

    /**
     * La parte de un módulo a la que pertenece una ruta, según los patrones
     * del catálogo (null si la ruta es común al módulo).
     */
    public static function parteDeRuta(string $modulo, ?string $ruta): ?string
    {
        if ($ruta === null) {
            return null;
        }
        foreach (config("cmk.submodulos.{$modulo}", []) as $parte => $def) {
            if ($def['rutas'] !== [] && Str::is($def['rutas'], $ruta)) {
                return $parte;
            }
        }

        return null;
    }

    /**
     * Usuarios (del cliente) que pertenecen a este tenant.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
