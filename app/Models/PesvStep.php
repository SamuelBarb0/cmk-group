<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uno de los 24 pasos del PESV (Res. 40595 de 2022).
 *
 * Catálogo GLOBAL, no segregado por tenant: los pasos son los mismos para
 * todas las empresas cliente. Lo que cambia por empresa es PesvPlanStep.
 *
 * `niveles`: en qué niveles del PESV es exigible el paso. Sale de las tablas
 * 2, 6, 9 y 12 del anexo de la resolución (texto en docs/normativa).
 */
class PesvStep extends Model
{
    /**
     * Pasos que NO aplican a todos los niveles; los demás aplican a los tres.
     *
     * @var array<int, list<string>>
     */
    public const NIVELES_POR_PASO = [
        2 => ['estandar', 'avanzado'],    // Comité de seguridad vial
        11 => ['avanzado'],               // Responsabilidad y comportamiento seguro
        13 => ['estandar', 'avanzado'],   // Investigación interna de siniestros viales
        18 => ['estandar', 'avanzado'],   // Gestión del cambio y de contratistas
        19 => ['estandar', 'avanzado'],   // Archivo y retención documental
        21 => ['avanzado'],               // Registro y análisis estadístico de siniestros
    ];

    protected $fillable = ['numero', 'fase', 'fase_nombre', 'titulo', 'descripcion', 'orden', 'niveles'];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'fase' => 'integer',
            'orden' => 'integer',
            'niveles' => 'array',
        ];
    }

    /** @return list<string> */
    public static function nivelesDe(int $numero): array
    {
        return self::NIVELES_POR_PASO[$numero] ?? PesvPlan::NIVELES;
    }

    public function aplicaA(?string $nivel): bool
    {
        return in_array($nivel, $this->niveles ?? self::nivelesDe($this->numero), true);
    }

    /** @return HasMany<PesvPlanStep, $this> */
    public function planSteps(): HasMany
    {
        return $this->hasMany(PesvPlanStep::class);
    }
}
