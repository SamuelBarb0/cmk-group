<?php

namespace App\Services\Calibracion;

use App\Models\DocumentCatalogEntry;
use App\Models\MeasuringEquipment;
use App\Services\ControlDocumental\CatalogoModulo as C;
use Illuminate\Database\Eloquent\Collection;

/**
 * Arma el texto de los dos documentos del M09 que salen de los equipos de
 * medición, para mandarlos al control documental como borrador (SIG-35):
 *
 *   procedimiento → PRC «Procedimiento de control de equipos de seguimiento y medición»
 *   hojas         → FT  «Hoja de vida y calibración de equipos de medición»
 */
class DocumentosCalibracion
{
    /** documento => [tipo, fragmento del nombre en el catálogo] */
    public const DOCUMENTOS = [
        'procedimiento' => ['PRC', 'equipos de seguimiento y medición'],
        'hojas' => ['FT', 'calibración de equipos'],
    ];

    public const SITUACIONES = [
        'vigente' => 'Vigente',
        'por_vencer' => 'Por vencer',
        'vencido' => 'Vencido',
        'sin_calibrar' => 'Sin calibrar',
        'no_conforme' => 'No conforme',
        'fuera_servicio' => 'Fuera de servicio',
        'baja' => 'Dado de baja',
    ];

    public function entrada(string $documento): ?DocumentCatalogEntry
    {
        [$tipo, $fragmento] = self::DOCUMENTOS[$documento];

        return C::entrada('M09', $tipo, $fragmento);
    }

    /** @return list<string> */
    public function claves(): array
    {
        return C::claves('M09', ['calibración de equipos']);
    }

    public function contenido(string $documento): string
    {
        $equipos = MeasuringEquipment::query()->with('calibrations')->orderBy('codigo')->get();

        return $documento === 'procedimiento' ? $this->procedimiento($equipos) : $this->hojas($equipos);
    }

    /** @param  Collection<int, MeasuringEquipment>  $equipos */
    private function procedimiento(Collection $equipos): string
    {
        $md = "## Objetivo\n\nAsegurar que los equipos usados para el seguimiento y la medición son adecuados para su uso y dan resultados válidos y confiables, mediante su identificación, calibración o verificación a intervalos definidos, su protección y la evaluación de las mediciones hechas cuando un equipo resulta no conforme (ISO 9001 7.1.5; ISO 45001 y 14001 9.1.1).\n\n";
        $md .= "## Alcance\n\nAplica a todos los equipos de la organización cuyas mediciones sirven para demostrar la conformidad de productos y servicios, el control de los peligros y riesgos o el desempeño ambiental (por ejemplo: sonómetros, luxómetros, medidores de gases, alcoholímetros, balanzas y termómetros).\n\n";
        $md .= "## Definiciones\n\n"
            ."- **Calibración:** comparación del equipo contra un patrón trazable a patrones nacionales o internacionales, hecha por un laboratorio preferiblemente acreditado ante el ONAC; da el error del equipo y su incertidumbre.\n"
            ."- **Verificación:** comprobación interna, contra un patrón o material de referencia, de que el equipo sigue dentro del error máximo permitido.\n"
            ."- **Error máximo permitido:** la diferencia máxima que se tolera entre lo que indica el equipo y el valor de referencia, según el uso que se le da.\n\n";
        $md .= "## Desarrollo\n\n"
            ."1. **Identificación.** Cada equipo tiene un código único, marcado en el equipo, y una hoja de vida con su magnitud, rango, resolución, error máximo permitido, ubicación y responsable.\n"
            .'2. **Programación.** Para cada equipo se define si se calibra o se verifica y cada cuánto. La fecha de la próxima calibración se calcula desde la última y se revisa cada mes; se alerta con '.MeasuringEquipment::AVISO_DIAS." días de anticipación.\n"
            ."3. **Ejecución.** La calibración la hace un laboratorio competente, preferiblemente acreditado ante el ONAC. Se conserva el certificado como información documentada.\n"
            ."4. **Evaluación del resultado.** El error encontrado se compara con el error máximo permitido. Si está dentro, el equipo es conforme y sigue en uso. Si no, es no conforme.\n"
            ."5. **Equipo no conforme.** Se retira de uso y se identifica como fuera de servicio; se determina si la validez de las mediciones hechas desde la última calibración conforme se vio afectada, se toman las acciones necesarias y se registra una acción correctiva. El equipo vuelve al uso solo tras una calibración conforme.\n"
            ."6. **Protección.** Los equipos se guardan y transportan de forma que no se dañen ni se desajusten, y se protegen de ajustes que invaliden su calibración.\n\n";
        $md .= "## Inventario de equipos\n\n";

        if ($equipos->isEmpty()) {
            return $md."Sin equipos registrados.\n";
        }

        $md .= "| Código | Equipo | Magnitud y rango | Error máx. permitido | Control | Frecuencia | Última | Próxima | Estado |\n|---|---|---|---|---|---|---|---|---|\n";
        foreach ($equipos as $e) {
            $s = $e->situacion();
            $md .= '| '.C::celda($e->codigo)
                .' | '.C::celda(trim($e->nombre.' '.($e->marca ?? '').' '.($e->modelo ?? '')))
                .' | '.C::celda(trim(($e->magnitud ?? '').($e->rango ? " · {$e->rango}" : '').($e->unidad ? " {$e->unidad}" : '')))
                .' | '.C::celda($e->error_maximo)
                .' | '.MeasuringEquipment::CONTROLES[$e->control]
                .' | '.$e->frecuencia_meses.' meses'
                .' | '.($s['ultima'] ?? '—')
                .' | '.($s['proxima'] ?? '—')
                .' | '.self::SITUACIONES[$s['estado']]." |\n";
        }

        return $md;
    }

    /** @param  Collection<int, MeasuringEquipment>  $equipos */
    private function hojas(Collection $equipos): string
    {
        if ($equipos->isEmpty()) {
            return "Sin equipos registrados.\n";
        }

        $md = '';
        foreach ($equipos as $e) {
            $md .= "## {$e->codigo} · {$e->nombre}\n\n| Campo | Detalle |\n|---|---|\n";
            foreach ([
                'Marca / modelo / serie' => implode(' / ', array_map(fn ($v) => $v ?: '—', [$e->marca, $e->modelo, $e->serie])),
                'Magnitud' => trim(($e->magnitud ?? '').($e->unidad ? " ({$e->unidad})" : '')),
                'Rango' => $e->rango,
                'Resolución' => $e->resolucion,
                'Error máximo permitido' => $e->error_maximo,
                'Uso' => $e->uso,
                'Ubicación' => $e->ubicacion,
                'Responsable' => $e->responsable,
                'Control' => MeasuringEquipment::CONTROLES[$e->control].' cada '.$e->frecuencia_meses.' meses',
                'Estado' => self::SITUACIONES[$e->situacion()['estado']],
            ] as $campo => $valor) {
                $md .= "| {$campo} | ".C::celda($valor)." |\n";
            }

            $md .= "\n**Historial de calibraciones y verificaciones**\n\n";
            if ($e->calibrations->isEmpty()) {
                $md .= "Sin registros.\n\n";

                continue;
            }
            $md .= "| Fecha | Tipo | Realizado por | Certificado | Error encontrado | Incertidumbre | Resultado |\n|---|---|---|---|---|---|---|\n";
            foreach ($e->calibrations as $c) {
                $md .= '| '.$c->fecha->toDateString()
                    .' | '.MeasuringEquipment::CONTROLES[$c->tipo]
                    .' | '.C::celda($c->realizado_por).($c->acreditado_onac ? ' (acreditado ONAC)' : '')
                    .' | '.C::celda($c->certificado)
                    .' | '.C::celda($c->error_encontrado)
                    .' | '.C::celda($c->incertidumbre)
                    .' | '.($c->resultado === 'conforme' ? 'Conforme' : '**No conforme**'.(filled($c->impacto_mediciones) ? ': '.C::celda($c->impacto_mediciones) : ''))
                    ." |\n";
            }
            $md .= "\n";
        }

        return $md;
    }
}
