<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Directorio de emergencias del MEDEVAC.
 */
class EmergencyContact extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tipo', 'nombre', 'detalle', 'telefono', 'telefono_alterno',
        'direccion', 'ciudad', 'orden',
    ];

    public const TIPOS = ['interno', 'entidad_apoyo', 'prestador_salud'];

    /**
     * Líneas nacionales de Colombia. La hoja del MEDEVAC trae las entidades
     * pero con el teléfono vacío; estas son las líneas cortas que sirven en
     * todo el país. Las locales (hospital, CAI del cuadrante) las pone cada
     * empresa, porque dependen de dónde opera.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public const LINEAS_NACIONALES = [
        ['Línea única de emergencias', '123', 'Cualquier emergencia'],
        ['Bomberos', '119', 'Incendio y rescate'],
        ['Cruz Roja', '132', 'Emergencias médicas'],
        ['Defensa Civil', '144', 'Terremoto y rescate'],
        ['Policía Nacional', '112', 'Orden público, delincuencia común'],
        ['GAULA', '165', 'Secuestro y extorsión'],
    ];
}
