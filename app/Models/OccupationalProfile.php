<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Profesiograma de un cargo: qué exámenes le tocan en el ingreso, en los
 * periódicos y en el retiro.
 */
class OccupationalProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'cargo', 'factores_riesgo', 'pve', 'examenes', 'otros_examenes',
        'periodicidad_meses', 'revisado_por', 'licencia_so',
    ];

    protected function casts(): array
    {
        return [
            'examenes' => 'array',
            'periodicidad_meses' => 'integer',
        ];
    }

    /**
     * Los 16 exámenes de la hoja «3.1.3 Profesiograma», en su orden.
     *
     * @var array<string, string>
     */
    public const EXAMENES = [
        'osteomuscular' => 'Físico con énfasis osteomuscular',
        'optometria' => 'Optometría',
        'audiometria' => 'Audiometría',
        'espirometria' => 'Espirometría',
        'alturas' => 'Certificación trabajo en alturas',
        'electrocardiograma' => 'Electrocardiograma',
        'psicosensometrico' => 'Psicosensométrico (CRC)',
        'cuadro_hematico' => 'Cuadro hemático',
        'perfil_lipidico' => 'Perfil lipídico',
        'glicemia' => 'Glicemia',
        'alcohol_drogas' => 'Alcohol y drogas',
        'psicotecnico' => 'Psicotécnico',
        'vacuna_fiebre_amarilla' => 'Vacuna fiebre amarilla',
        'vacuna_tetano' => 'Vacuna tétano',
        'frotis_unas' => 'Frotis de uñas',
        'frotis_faringeo' => 'Frotis faríngeo',
    ];

    /** Las tres columnas I / P / R de la matriz. */
    public const MOMENTOS = ['ingreso', 'periodico', 'retiro'];

    /**
     * Códigos de examen que el profesiograma exige en un momento dado.
     * Post-incapacidad y reintegro no tienen columna en la matriz: el médico
     * decide qué pedir, así que no se exige nada.
     *
     * @return list<string>
     */
    public function exigidos(string $tipoExamen): array
    {
        if (! in_array($tipoExamen, self::MOMENTOS, true)) {
            return [];
        }

        $requeridos = [];
        foreach ($this->examenes ?? [] as $codigo => $momentos) {
            if (! empty($momentos[$tipoExamen]) && array_key_exists($codigo, self::EXAMENES)) {
                $requeridos[] = $codigo;
            }
        }

        return $requeridos;
    }
}
