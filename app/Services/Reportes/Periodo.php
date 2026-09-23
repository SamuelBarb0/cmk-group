<?php

namespace App\Services\Reportes;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Rango de fechas de un informe (inclusivo en los dos extremos).
 *
 * Los módulos guardan lo que pasa de dos formas: eventos con fecha
 * (accidentes, capacitaciones…) y planes por AÑO + MESES (plan de trabajo,
 * programas, lecturas de indicadores). Por eso además de las fechas da los
 * meses del periodo agrupados por año.
 */
final class Periodo
{
    public function __construct(
        public readonly CarbonImmutable $desde,
        public readonly CarbonImmutable $hasta,
    ) {}

    /** Por defecto, del 1 de enero a hoy. */
    public static function desde(?string $desde, ?string $hasta): self
    {
        $h = $hasta ? CarbonImmutable::parse($hasta) : CarbonImmutable::today();
        $d = $desde ? CarbonImmutable::parse($desde) : $h->startOfYear();

        return new self($d->startOfDay(), $h->startOfDay());
    }

    /** Filtra una consulta por una columna de fecha dentro del periodo. */
    public function filtrar(Builder $query, string $columna): Builder
    {
        return $query->whereBetween($columna, [$this->desde->toDateString(), $this->hasta->toDateString()]);
    }

    /**
     * Meses del periodo por año: [2026 => [1, 2, …, 9]].
     *
     * @return array<int, list<int>>
     */
    public function meses(): array
    {
        $meses = [];
        foreach (CarbonPeriod::create($this->desde->startOfMonth(), '1 month', $this->hasta->startOfMonth()) as $mes) {
            $meses[$mes->year][] = $mes->month;
        }

        return $meses;
    }

    public function etiqueta(): string
    {
        return $this->larga($this->desde).' al '.$this->larga($this->hasta);
    }

    public function larga(CarbonImmutable $fecha): string
    {
        return $fecha->locale('es')->translatedFormat('j \d\e F \d\e Y');
    }
}
