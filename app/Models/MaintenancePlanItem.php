<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Qué se le hace a un activo y cada cuánto: cada N días, N km o N horas.
 * Sin tenant propio ni rutas: se guarda a través de su activo.
 */
class MaintenancePlanItem extends Model
{
    protected $fillable = ['actividad', 'frecuencia_valor', 'frecuencia_unidad', 'responsable', 'orden'];

    protected function casts(): array
    {
        return ['frecuencia_valor' => 'integer'];
    }

    public const UNIDADES = ['dias', 'km', 'horas'];

    /** @return BelongsTo<MaintenanceAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MaintenanceAsset::class, 'maintenance_asset_id');
    }

    /**
     * Cuándo toca el siguiente, a partir del último registro de este ítem.
     *
     * Se calcula, no se guarda: si fuera columna habría que recorrerlo todo
     * cada noche, y un kilometraje nuevo no movería nada hasta entonces.
     * «Por vencer» es el último 10 % del intervalo, en cualquier unidad: un
     * cambio de aceite cada 5.000 km avisa a los 500 km, uno cada 20 días a
     * los 2 días. Un umbral fijo de 30 días no sirve para los dos.
     *
     * @return array{estado: string, proxima_fecha: ?string, proxima_lectura: ?int, restante: ?int}
     */
    public function vencimiento(?MaintenanceRecord $ultimo, ?int $lecturaActual, ?Carbon $hoy = null): array
    {
        $hoy = ($hoy ?? now())->copy()->startOfDay();
        $vacio = ['proxima_fecha' => null, 'proxima_lectura' => null, 'restante' => null];
        $n = $this->frecuencia_valor;

        if (! $n || ! $this->frecuencia_unidad) {
            return ['estado' => 'sin_frecuencia'] + $vacio;
        }
        if (! $ultimo) {
            return ['estado' => 'sin_registro'] + $vacio;
        }

        $margen = (int) max(1, ceil($n * 0.1));

        if ($this->frecuencia_unidad === 'dias') {
            $proxima = $ultimo->fecha->copy()->startOfDay()->addDays($n);
            $restante = (int) $hoy->diffInDays($proxima, false);

            return [
                'estado' => $restante < 0 ? 'vencido' : ($restante <= $margen ? 'por_vencer' : 'al_dia'),
                'proxima_fecha' => $proxima->toDateString(),
                'proxima_lectura' => null,
                'restante' => $restante,
            ];
        }

        // Por uso (km u horas): hace falta la lectura del último y la actual.
        if ($ultimo->lectura === null || $lecturaActual === null) {
            return ['estado' => 'sin_lectura'] + $vacio;
        }

        $proxima = $ultimo->lectura + $n;
        $restante = $proxima - $lecturaActual;

        return [
            'estado' => $restante <= 0 ? 'vencido' : ($restante <= $margen ? 'por_vencer' : 'al_dia'),
            'proxima_fecha' => null,
            'proxima_lectura' => $proxima,
            'restante' => $restante,
        ];
    }
}
