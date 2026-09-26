<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TieneConsecutivo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Salida no conforme: producto o servicio que no cumple sus requisitos
 * (ISO 9001 8.7). Segregado por tenant.
 */
class NonconformingOutput extends Model
{
    use BelongsToTenant;
    use TieneConsecutivo;

    public const CONSECUTIVO = 'SNC';

    public const CAMPO_CONSECUTIVO = 'codigo';

    protected $fillable = [
        'fecha', 'producto', 'process_id', 'detectado_en', 'descripcion', 'cantidad', 'tratamiento',
        'detalle_tratamiento', 'autorizado_por', 'verificado_por', 'fecha_verificacion', 'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'fecha_verificacion' => 'date:Y-m-d',
        ];
    }

    public const DETECCION = [
        'recepcion' => 'Recepción de insumos',
        'proceso' => 'Durante el proceso',
        'final' => 'Inspección final',
        'cliente' => 'Por el cliente',
    ];

    /** Las acciones de 8.7.1 (a–d) en lenguaje de planta. */
    public const TRATAMIENTOS = [
        'correccion' => 'Corrección',
        'reproceso' => 'Reproceso',
        'segregacion' => 'Separación o contención',
        'devolucion' => 'Devolución o suspensión',
        'concesion' => 'Concesión (aceptar con autorización)',
        'desecho' => 'Desecho',
    ];

    /** Tratamientos que exigen verificar la conformidad antes de cerrar (8.7.1: «verificar la conformidad cuando se corrigen»). */
    public const VERIFICABLES = ['correccion', 'reproceso'];

    public const ESTADOS = ['abierta' => 'Abierta', 'cerrada' => 'Cerrada'];

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    /** @return BelongsTo<AcpmAction, $this> */
    public function acpmAction(): BelongsTo
    {
        return $this->belongsTo(AcpmAction::class);
    }
}
