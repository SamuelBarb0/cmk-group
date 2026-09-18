<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Vehículo de la flota (caracterización del Paso 5; insumo de los pasos 16 y 17). */
class PesvVehicle extends Model
{
    use BelongsToTenant;

    public const TIPOS = ['automovil', 'camioneta', 'campero', 'camion', 'bus', 'motocicleta', 'maquinaria', 'otro'];

    public const PROPIEDADES = ['propio', 'arrendado', 'leasing', 'contratista', 'colaborador'];

    protected $fillable = [
        'placa',
        'tipo',
        'marca',
        'linea',
        'modelo',
        'propiedad',
        'propietario',
        'soat_vence',
        'tecnomecanica_vence',
        'poliza_vence',
        'kilometraje',
        'ultimo_mantenimiento',
        'proximo_mantenimiento',
        'observaciones',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'soat_vence' => 'date:Y-m-d',
            'tecnomecanica_vence' => 'date:Y-m-d',
            'poliza_vence' => 'date:Y-m-d',
            'ultimo_mantenimiento' => 'date:Y-m-d',
            'proximo_mantenimiento' => 'date:Y-m-d',
            'modelo' => 'integer',
            'kilometraje' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Documentos vencidos o por vencer dentro de los próximos $dias días.
     *
     * Es lo que hace útil la pantalla de vehículos: un SOAT vencido es un
     * hallazgo de auditoría, no un dato más de la ficha. Los días salen
     * negativos cuando el documento ya está vencido.
     *
     * @return array<int, array{documento: string, vence: string, dias: int}>
     */
    public function alertas(int $dias = 30): array
    {
        $hoy = Carbon::today();
        $alertas = [];

        foreach ([
            'SOAT' => $this->soat_vence,
            'Tecnomecánica' => $this->tecnomecanica_vence,
            'Póliza' => $this->poliza_vence,
        ] as $documento => $vence) {
            if ($vence === null) {
                continue;
            }

            $restantes = (int) $hoy->diffInDays($vence, false);

            if ($restantes <= $dias) {
                $alertas[] = [
                    'documento' => $documento,
                    'vence' => $vence->toDateString(),
                    'dias' => $restantes,
                ];
            }
        }

        return $alertas;
    }
}
