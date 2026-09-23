<?php

namespace App\Services\Reportes;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Una sección del informe de gestión: la parte de UN módulo.
 *
 * Cada sección produce la misma forma (cifras, tablas y notas) y de ahí salen
 * la vista previa en pantalla, el Word y el PDF. Ninguna sabe cómo se pinta.
 *
 * Una cifra puede llevar ALERTA: el texto que va a «Puntos de atención» al
 * principio del informe. Es lo que el gerente lee primero.
 */
abstract class Seccion
{
    /** Filas máximas por tabla: lo demás está en la exportación a Excel. */
    protected const MAX_FILAS = 25;

    /** @var list<array{etiqueta: string, valor: string, alerta: ?string}> */
    private array $cifras = [];

    /** @var list<array<string, mixed>> */
    private array $tablas = [];

    /** @var list<string> */
    private array $notas = [];

    abstract public function clave(): string;

    abstract public function titulo(): string;

    /** Módulo contratable del que sale (null = base, siempre disponible). */
    abstract public function modulo(): ?string;

    /** Permiso que hace falta para ver esos datos en su propia pantalla. */
    abstract public function permiso(): string;

    abstract protected function construir(Periodo $periodo): void;

    /** @return array{clave: string, titulo: string, cifras: list<array>, tablas: list<array>, notas: list<string>} */
    public function generar(Periodo $periodo): array
    {
        $this->cifras = $this->tablas = $this->notas = [];
        $this->construir($periodo);

        return [
            'clave' => $this->clave(),
            'titulo' => $this->titulo(),
            'cifras' => $this->cifras,
            'tablas' => $this->tablas,
            'notas' => $this->notas,
        ];
    }

    protected function cifra(string $etiqueta, mixed $valor, ?string $alerta = null): void
    {
        $this->cifras[] = ['etiqueta' => $etiqueta, 'valor' => $this->texto($valor), 'alerta' => $alerta];
    }

    /**
     * @param  list<string>  $columnas
     * @param  iterable<array<int, mixed>>  $filas
     */
    protected function tabla(string $titulo, array $columnas, iterable $filas, string $vacio): void
    {
        $filas = collect($filas)->values();
        $this->tablas[] = [
            'titulo' => $titulo,
            'columnas' => $columnas,
            'filas' => $filas->take(static::MAX_FILAS)->map(fn ($f) => array_map(fn ($v) => $this->texto($v), array_values($f)))->all(),
            'omitidas' => max(0, $filas->count() - static::MAX_FILAS),
            'vacio' => $vacio,
        ];
    }

    protected function nota(string $texto): void
    {
        $this->notas[] = $texto;
    }

    // --- Formato común --------------------------------------------------------

    protected static function pct(float|int|null $parte, float|int|null $total): ?float
    {
        return $total ? round($parte / $total * 100, 1) : null;
    }

    protected static function porcentaje(?float $valor): string
    {
        return $valor === null ? '—' : self::numero($valor, 1).' %';
    }

    protected static function numero(float|int|null $n, int $decimales = 0): string
    {
        if ($n === null) {
            return '—';
        }
        // Sin decimales de relleno: 12,0 % se lee peor que 12 %.
        $dec = $decimales > 0 && fmod((float) $n, 1.0) !== 0.0 ? $decimales : 0;

        return number_format((float) $n, $dec, ',', '.');
    }

    protected static function fecha(?CarbonInterface $fecha): string
    {
        return $fecha ? $fecha->format('d/m/Y') : '—';
    }

    protected static function corto(?string $texto, int $max = 90): string
    {
        return $texto === null || $texto === '' ? '—' : Str::limit(preg_replace('/\s+/', ' ', $texto), $max);
    }

    /**
     * Claves guardadas sin tilde que el informe no puede mostrar tal cual: va
     * a la gerencia del cliente, y «Critico» o «Auditoria» se leen como error.
     */
    private const ETIQUETAS = [
        'critico' => 'Crítico', 'periodico' => 'Periódico', 'post_incapacidad' => 'Post incapacidad',
        'auditoria' => 'Auditoría', 'inspeccion' => 'Inspección', 'condicion' => 'Condición',
        'basico' => 'Básico', 'estandar' => 'Estándar', 'accidente_comun' => 'Accidente común',
        'iperc' => 'IPERC', 'pendiente_aprobacion' => 'Pendiente de aprobación', 'solo_danos' => 'Solo daños',
        'dotacion' => 'Dotación', 'reposicion' => 'Reposición', 'maquina' => 'Máquina', 'tecnologia' => 'Tecnología',
        'electrico' => 'Eléctrico', 'mecanico' => 'Mecánico', 'quimico' => 'Químico', 'fisico' => 'Físico',
        'biologico' => 'Biológico', 'psicosocial' => 'Psicosocial', 'biomecanico' => 'Biomecánico',
        'fisicoquimico' => 'Fisicoquímico', 'publico' => 'Público', 'transito' => 'Tránsito',
    ];

    /** Valores de lista guardados como clave («no_cumple») a texto («No cumple»). */
    protected static function etiqueta(?string $clave): string
    {
        return $clave ? (self::ETIQUETAS[$clave] ?? Str::ucfirst(str_replace('_', ' ', $clave))) : '—';
    }

    private function texto(mixed $v): string
    {
        return match (true) {
            $v === null => '—',
            is_bool($v) => $v ? 'Sí' : 'No',
            is_int($v), is_float($v) => self::numero($v, 1),
            $v instanceof CarbonInterface => self::fecha($v),
            default => (string) $v,
        };
    }
}
