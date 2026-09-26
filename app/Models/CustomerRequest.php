<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TieneConsecutivo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * PQRS de un cliente (ISO 9001 8.2.1). Segregado por tenant.
 *
 * El plazo por defecto es de 15 días hábiles (Ley 1755 de 2015, art. 14 y 32,
 * y Ley 1480 de 2011, art. 58, para reclamos de consumo). Solo se saltan los
 * sábados y domingos, no los festivos: en una semana con festivo el plazo que
 * se sugiere queda un día corto, del lado seguro. Se puede editar.
 */
class CustomerRequest extends Model
{
    use BelongsToTenant;
    use TieneConsecutivo;

    public const CONSECUTIVO = 'PQRS';

    public const CAMPO_CONSECUTIVO = 'radicado';

    protected $fillable = [
        'fecha', 'tipo', 'cliente', 'contacto', 'canal', 'descripcion', 'process_id', 'responsable',
        'fecha_limite', 'respuesta', 'fecha_respuesta', 'procede', 'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'fecha_limite' => 'date:Y-m-d',
            'fecha_respuesta' => 'date:Y-m-d',
            'procede' => 'boolean',
        ];
    }

    protected $appends = ['vencida', 'a_tiempo', 'dias_respuesta'];

    public const TIPOS = [
        'peticion' => 'Petición',
        'queja' => 'Queja',
        'reclamo' => 'Reclamo',
        'sugerencia' => 'Sugerencia',
        'felicitacion' => 'Felicitación',
    ];

    public const CANALES = [
        'correo' => 'Correo', 'telefono' => 'Teléfono', 'presencial' => 'Presencial',
        'web' => 'Página web', 'whatsapp' => 'WhatsApp', 'escrito' => 'Escrito',
    ];

    public const ESTADOS = ['abierta' => 'Abierta', 'en_tramite' => 'En trámite', 'cerrada' => 'Cerrada'];

    public const PLAZO_DIAS_HABILES = 15;

    /** Tipos en los que cabe preguntar si el cliente tenía razón. */
    public const EVALUABLES = ['queja', 'reclamo'];

    /** Fecha límite: N días hábiles (sin sábados ni domingos) después de `$desde`. */
    public static function plazo(Carbon|string $desde, int $dias = self::PLAZO_DIAS_HABILES): Carbon
    {
        $fecha = Carbon::parse($desde)->startOfDay();
        while ($dias > 0) {
            $fecha->addDay();
            if (! $fecha->isWeekend()) {
                $dias--;
            }
        }

        return $fecha;
    }

    /** Sin respuesta y pasado el plazo. */
    public function getVencidaAttribute(): bool
    {
        return $this->fecha_respuesta === null && $this->fecha_limite !== null && $this->fecha_limite->lt(Carbon::today());
    }

    /** Respondida dentro del plazo (null si todavía no se responde). */
    public function getATiempoAttribute(): ?bool
    {
        return $this->fecha_respuesta ? $this->fecha_respuesta->lte($this->fecha_limite) : null;
    }

    /** Días calendario entre el radicado y la respuesta. */
    public function getDiasRespuestaAttribute(): ?int
    {
        return $this->fecha_respuesta ? (int) $this->fecha->diffInDays($this->fecha_respuesta) : null;
    }

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
