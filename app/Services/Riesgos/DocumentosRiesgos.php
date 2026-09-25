<?php

namespace App\Services\Riesgos;

use App\Models\DocumentCatalogEntry;
use App\Models\EnvironmentalAspect;
use App\Models\RiskOpportunity;
use App\Services\ControlDocumental\CatalogoModulo as C;

/**
 * Arma el texto de las dos matrices del M04 que faltaban, para mandarlas al
 * control documental como borrador:
 *
 *   riesgos   → MTZ «Matriz de riesgos y oportunidades por proceso»  (SIG-21)
 *   aspectos  → MTZ «Matriz de aspectos e impactos ambientales»      (SIG-20)
 */
class DocumentosRiesgos
{
    /** documento => [fragmento del nombre en el catálogo, fragmentos del título del requisito] */
    public const DOCUMENTOS = [
        'riesgos' => ['riesgos y oportunidades por proceso', ['riesgos y oportunidades']],
        'aspectos' => ['aspectos e impactos', ['aspectos e impactos']],
    ];

    public function entrada(string $documento): ?DocumentCatalogEntry
    {
        return C::entrada('M04', 'MTZ', self::DOCUMENTOS[$documento][0]);
    }

    /** @return list<string> */
    public function claves(string $documento): array
    {
        return C::claves('M04', self::DOCUMENTOS[$documento][1]);
    }

    public function contenido(string $documento): string
    {
        return $documento === 'riesgos' ? $this->riesgos() : $this->aspectos();
    }

    private function riesgos(): string
    {
        $filas = RiskOpportunity::query()->with('process:id,sigla,nombre')->orderBy('orden')->orderBy('id')->get();

        $md = "## Objetivo\n\nDeterminar los riesgos y oportunidades de los procesos que es necesario abordar para asegurar que el sistema integrado logre sus resultados previstos, prevenir o reducir efectos no deseados y lograr la mejora continua (ISO 45001 6.1.1, ISO 9001 6.1, ISO 14001 6.1.1).\n\n";
        $md .= "## Metodología\n\nCada riesgo u oportunidad se valora con probabilidad (1 a 5) por impacto (1 a 5). Nivel: bajo (1 a 4), medio (5 a 9), alto (10 a 16) y crítico (20 a 25). En las oportunidades el impacto es el beneficio si se aprovecha. Los riesgos altos y críticos se tratan con acciones, responsable y fecha, y se evalúa la eficacia de lo hecho.\n\n";

        foreach (['riesgo' => 'Riesgos', 'oportunidad' => 'Oportunidades'] as $tipo => $titulo) {
            $grupo = $filas->where('tipo', $tipo)->sortByDesc('valor');
            $md .= "## {$titulo}\n\n";
            if ($grupo->isEmpty()) {
                $md .= "Sin registros.\n\n";

                continue;
            }
            $md .= "| Proceso | Descripción | Causa / efecto | P × I | Nivel | Tratamiento | Acciones y responsable | Eficacia |\n|---|---|---|---|---|---|---|---|\n";
            foreach ($grupo as $r) {
                $causa = trim(implode(' → ', array_filter([C::celda($r->causa) === '—' ? null : C::celda($r->causa), C::celda($r->efecto) === '—' ? null : C::celda($r->efecto)])));
                $md .= '| '.($r->process ? $r->process->sigla : 'General')
                    .' | '.C::celda($r->descripcion)
                    .' | '.($causa !== '' ? $causa : '—')
                    .' | '.$r->probabilidad.' × '.$r->impacto.' = '.$r->valor
                    .' | '.ucfirst($r->nivel === 'critico' ? 'crítico' : $r->nivel)
                    .' | '.ucfirst($r->tratamiento)
                    .' | '.C::celda(trim(($r->acciones ?? '').($r->responsable ? " ({$r->responsable})" : '')))
                    .' | '.C::celda($r->eficacia)." |\n";
            }
            $md .= "\n";
        }

        return $md;
    }

    private function aspectos(): string
    {
        $filas = EnvironmentalAspect::query()->with('process:id,sigla')->orderBy('orden')->orderBy('id')->get();

        $md = "## Objetivo\n\nIdentificar los aspectos ambientales de las actividades, productos y servicios que la organización puede controlar o influir, y sus impactos, desde una perspectiva de ciclo de vida y en condiciones normales, anormales y de emergencia, y determinar cuáles son significativos (ISO 14001 6.1.2).\n\n";
        $md .= '## Criterio de significancia'."\n\nValor = frecuencia (1 a 5) por severidad (1 a 5). Un aspecto negativo es significativo si su valor es ".EnvironmentalAspect::UMBRAL.' o más, si tiene un requisito legal asociado, si preocupa a las partes interesadas, o si es de emergencia con severidad '.EnvironmentalAspect::SEVERIDAD_EMERGENCIA." o más (una emergencia es poco frecuente por definición, así que pesa su severidad).\n\n";

        if ($filas->isEmpty()) {
            return $md."Sin aspectos registrados.\n";
        }

        $md .= "| Proceso | Actividad | Aspecto | Impacto | Condición | Ciclo de vida | F × S | Legal | ¿Significativo? | Controles |\n|---|---|---|---|---|---|---|---|---|---|\n";
        foreach ($filas as $a) {
            $md .= '| '.($a->process ? $a->process->sigla : '—')
                .' | '.C::celda($a->actividad)
                .' | '.C::celda($a->aspecto)
                .' | '.C::celda($a->impacto).($a->tipo_impacto === 'positivo' ? ' (positivo)' : '')
                .' | '.ucfirst($a->condicion)
                .' | '.(EnvironmentalAspect::ETAPAS[$a->etapa] ?? $a->etapa)
                .' | '.$a->frecuencia.' × '.$a->severidad.' = '.$a->valor
                .' | '.($a->requisito_legal ? 'Sí' : 'No')
                .' | '.($a->significativo ? '**Sí**' : 'No')
                .' | '.C::celda($a->controles)." |\n";
        }

        $n = $filas->where('significativo', true)->count();

        return $md."\nAspectos significativos: {$n} de {$filas->count()}. Los significativos se consideran al establecer los objetivos, los controles operacionales y la preparación ante emergencias.\n";
    }
}
