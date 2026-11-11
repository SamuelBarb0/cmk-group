<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
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
        // Firmas digitalizadas (imagen) para los documentos exportados.
        'firma_rep_path',
        'firma_resp_path',
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

    /**
     * Imágenes de identidad del cliente que se estampan en los documentos:
     * tipo => columna. Los archivos van en el disco privado («local»).
     */
    public const MARCA = [
        'logo' => 'logo_path',
        'firma_rep' => 'firma_rep_path',
        'firma_resp' => 'firma_resp_path',
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
     * Ruta absoluta de una imagen de identidad (logo o firma), o null si no se
     * ha subido o el archivo ya no está en disco: quien la use debe poder
     * seguir sin ella, igual que con cualquier otro dato que falte.
     */
    public function archivoMarca(string $tipo): ?string
    {
        $ruta = $this->{self::MARCA[$tipo]} ?? null;

        if (blank($ruta) || ! Storage::disk('local')->exists($ruta)) {
            return null;
        }

        return Storage::disk('local')->path($ruta);
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
