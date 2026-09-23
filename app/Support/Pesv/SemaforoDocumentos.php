<?php

namespace App\Support\Pesv;

use App\Models\Employee;
use App\Models\PesvVehicle;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Semáforo de documentos de conductores y vehículos: RE-SST-54 «Seguimiento a
 * documentos» de CMK, con SU regla: menos de 8 días (o vencido) = E (no
 * cumple), menos de 31 = P (por vencer), si no = V (vigente). Cumplimiento =
 * (V + P) / documentos con fecha. Un documento sin fecha no se inventa: sale
 * como «sin dato».
 */
final class SemaforoDocumentos
{
    public const DIAS_NO_CUMPLE = 8;

    public const DIAS_POR_VENCER = 31;

    public static function estado(?CarbonInterface $vence, ?CarbonInterface $hoy = null): string
    {
        if ($vence === null) {
            return 'sin_dato';
        }
        $dias = (int) ($hoy ?? Carbon::today())->diffInDays($vence, false);

        return match (true) {
            $dias < self::DIAS_NO_CUMPLE => 'E',
            $dias < self::DIAS_POR_VENCER => 'P',
            default => 'V',
        };
    }

    /**
     * Una fila por conductor o vehículo, con sus documentos.
     *
     * @return Collection<int, array{tipo: string, id: int, nombre: string, detalle: ?string, documentos: list<array<string, mixed>>}>
     */
    public static function filas(): Collection
    {
        $hoy = Carbon::today();
        $doc = fn (string $nombre, ?CarbonInterface $vence) => [
            'documento' => $nombre,
            'vence' => $vence?->toDateString(),
            'dias' => $vence ? (int) $hoy->diffInDays($vence, false) : null,
            'estado' => self::estado($vence, $hoy),
        ];

        $conductores = Employee::where('is_active', true)->conductores()->orderBy('apellidos')->get()
            ->map(fn (Employee $e) => [
                'tipo' => 'conductor', 'id' => $e->id, 'nombre' => trim("{$e->nombres} {$e->apellidos}"),
                'detalle' => $e->licencia_categoria ? "Licencia {$e->licencia_categoria}" : null,
                'documentos' => [
                    $doc('Licencia de conducción', $e->licencia_vence),
                    $doc('Examen psicosensométrico', $e->examen_psicosensometrico_vence),
                ],
            ]);

        $vehiculos = PesvVehicle::where('is_active', true)->orderBy('placa')->get()
            ->map(fn (PesvVehicle $v) => [
                'tipo' => 'vehiculo', 'id' => $v->id, 'nombre' => $v->placa,
                'detalle' => trim(ucfirst((string) $v->tipo).' '.($v->marca ?? '')),
                'documentos' => [
                    $doc('SOAT', $v->soat_vence),
                    $doc('Revisión técnico-mecánica', $v->tecnomecanica_vence),
                    $doc('Póliza', $v->poliza_vence),
                    $doc('Próximo mantenimiento', $v->proximo_mantenimiento),
                ],
            ]);

        return $conductores->concat($vehiculos)->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array{V: int, P: int, E: int, sin_dato: int, cumplimiento: ?float}
     */
    public static function resumen(Collection $filas): array
    {
        $estados = $filas->flatMap(fn ($f) => collect($f['documentos'])->pluck('estado'));
        $conDato = $estados->reject(fn ($e) => $e === 'sin_dato');
        $ok = $conDato->filter(fn ($e) => $e !== 'E')->count();

        return [
            'V' => $estados->filter(fn ($e) => $e === 'V')->count(),
            'P' => $estados->filter(fn ($e) => $e === 'P')->count(),
            'E' => $estados->filter(fn ($e) => $e === 'E')->count(),
            'sin_dato' => $estados->filter(fn ($e) => $e === 'sin_dato')->count(),
            'cumplimiento' => $conDato->isEmpty() ? null : round($ok * 100 / $conDato->count(), 1),
        ];
    }
}
