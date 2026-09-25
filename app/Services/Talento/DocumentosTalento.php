<?php

namespace App\Services\Talento;

use App\Models\DocumentCatalogEntry;
use App\Models\JobPosition;
use App\Models\JobPositionRequirement;
use App\Services\ControlDocumental\CatalogoModulo as C;
use Illuminate\Database\Eloquent\Collection;

/**
 * Arma el texto de los documentos del M07 que salen de los perfiles de cargo,
 * para mandarlos al control documental como borrador:
 *
 *   perfiles          → MAN «Manual de funciones y perfiles de cargo»   (SIG-11, SIG-29)
 *   competencias      → MTZ «Matriz de competencias por cargo»          (SIG-29)
 *   responsabilidades → MAN «Manual de responsabilidades en SST»        (SIG-11)
 */
class DocumentosTalento
{
    /** documento => [tipo, fragmento del nombre en el catálogo, fragmentos del título del requisito] */
    public const DOCUMENTOS = [
        'perfiles' => ['MAN', 'funciones y perfiles', ['Roles, responsabilidades', 'Perfiles de cargo']],
        'competencias' => ['MTZ', 'competencias por cargo', ['Perfiles de cargo']],
        'responsabilidades' => ['MAN', 'responsabilidades en SST', ['Roles, responsabilidades']],
    ];

    public function __construct(private readonly MatrizCompetencias $matriz) {}

    public function entrada(string $documento): ?DocumentCatalogEntry
    {
        [$tipo, $fragmento] = self::DOCUMENTOS[$documento];

        return C::entrada('M07', $tipo, $fragmento);
    }

    /** @return list<string> */
    public function claves(string $documento): array
    {
        return C::claves('M07', self::DOCUMENTOS[$documento][2]);
    }

    public function contenido(string $documento): string
    {
        return match ($documento) {
            'perfiles' => $this->perfiles(),
            'competencias' => $this->competencias(),
            default => $this->responsabilidades(),
        };
    }

    /** @return Collection<int, JobPosition> */
    private function cargos()
    {
        return JobPosition::query()->with(['process:id,sigla,nombre', 'requirements.topic:id,titulo'])
            ->orderBy('orden')->orderBy('nombre')->get();
    }

    private function perfiles(): string
    {
        $md = "## Objetivo\n\nDefinir para cada cargo su propósito, funciones, responsabilidades y autoridad dentro del sistema integrado de gestión, y los requisitos de educación, formación, experiencia y habilidades que aseguran su competencia (ISO 45001, 9001 y 14001 numerales 5.3 y 7.2; Decreto 1072 de 2015, art. 2.2.4.6.8 y 2.2.4.6.11).\n\n";
        $md .= "## Alcance\n\nAplica a todos los cargos de la organización. Cada trabajador conoce el perfil de su cargo desde la inducción.\n\n";

        $cargos = $this->cargos();
        if ($cargos->isEmpty()) {
            return $md."Sin cargos definidos.\n";
        }

        foreach ($cargos as $i => $c) {
            $md .= '## '.($i + 1).'. '.$c->nombre."\n\n";
            $md .= "| Campo | Detalle |\n|---|---|\n";
            $md .= '| Proceso | '.($c->process ? "{$c->process->sigla} — {$c->process->nombre}" : '—')." |\n";
            $md .= '| Reporta a | '.C::celda($c->reporta_a)." |\n";
            $md .= '| Objetivo del cargo | '.C::celda($c->objetivo)." |\n\n";

            foreach (['funciones' => 'Funciones', 'responsabilidades_sig' => 'Responsabilidades en el SIG (SST, calidad, ambiente y seguridad vial)', 'autoridad' => 'Autoridad'] as $campo => $titulo) {
                $md .= "**{$titulo}**\n\n".$this->lista($c->{$campo})."\n";
            }

            $md .= "**Requisitos del cargo**\n\n";
            if ($c->requirements->isEmpty()) {
                $md .= "Sin requisitos definidos.\n\n";

                continue;
            }
            $md .= "| Tipo | Requisito |\n|---|---|\n";
            foreach ($c->requirements as $r) {
                $md .= '| '.JobPositionRequirement::TIPOS[$r->tipo].' | '.C::celda($r->descripcion)
                    .($r->topic ? ' (tema de capacitación: '.C::celda($r->topic->titulo).')' : '')." |\n";
            }
            $md .= "\n";
        }

        return $md;
    }

    private function competencias(): string
    {
        $md = "## Objetivo\n\nDeterminar la competencia necesaria de cada cargo y verificar que las personas que lo ocupan la tienen, con base en su educación, formación o experiencia, y tomar acciones para cerrar las brechas (ISO 45001, 9001 y 14001 numeral 7.2; Decreto 1072 de 2015, art. 2.2.4.6.11; PESV paso 10).\n\n";
        $md .= "## Criterio\n\nUn requisito de formación asociado a un tema de capacitación se cumple con una capacitación realizada, aprobada y vigente de ese tema. Los demás requisitos se evalúan con evidencia (títulos, certificados, certificación laboral u observación). Las brechas de formación alimentan el programa de capacitación.\n\n";

        $cargos = $this->cargos();
        if ($cargos->isEmpty()) {
            return $md."Sin cargos definidos.\n";
        }

        $md .= "## Requisitos por cargo\n\n| Cargo | Educación | Formación | Experiencia | Habilidades |\n|---|---|---|---|---|\n";
        foreach ($cargos as $c) {
            $md .= '| '.C::celda($c->nombre);
            foreach (array_keys(JobPositionRequirement::TIPOS) as $tipo) {
                $items = $c->requirements->where('tipo', $tipo)->pluck('descripcion');
                $md .= ' | '.($items->isEmpty() ? '—' : $items->map(fn ($d) => C::celda($d))->implode('; '));
            }
            $md .= " |\n";
        }

        $md .= "\n## Evaluación de los trabajadores\n\n";
        foreach ($cargos as $c) {
            $datos = $this->matriz->deCargo($c);
            if ($datos['empleados']->isEmpty() || $datos['requisitos']->isEmpty()) {
                continue;
            }
            $md .= "### {$c->nombre}\n\n| Trabajador | ".$datos['requisitos']->map(fn ($r) => C::celda($r->descripcion))->implode(' | ')." |\n";
            $md .= '|---|'.str_repeat('---|', $datos['requisitos']->count())."\n";
            foreach ($datos['empleados'] as $e) {
                $md .= '| '.C::celda(trim("{$e->nombres} {$e->apellidos}"));
                foreach ($datos['requisitos'] as $r) {
                    $md .= ' | '.MatrizCompetencias::ESTADOS[$datos['celdas']["{$e->id}-{$r->id}"]['estado']];
                }
                $md .= " |\n";
            }
            $md .= "\n";
        }

        $brechas = $this->matriz->resumen()['brechas'];
        $md .= "## Necesidades de formación\n\n";
        if ($brechas === []) {
            return $md."Sin brechas de formación a la fecha.\n";
        }
        $md .= "| Cargo | Requisito | Personas |\n|---|---|---|\n";
        foreach ($brechas as $b) {
            $md .= '| '.C::celda($b['cargo']).' | '.C::celda($b['requisito']).' | '.collect($b['personas'])->pluck('nombre')->map(fn ($n) => C::celda($n))->implode(', ')." |\n";
        }

        return $md;
    }

    private function responsabilidades(): string
    {
        $md = "## Objetivo\n\nAsignar, documentar y comunicar las responsabilidades específicas en seguridad y salud en el trabajo a todos los niveles de la organización, incluida la alta dirección, y la autoridad para cumplirlas (Decreto 1072 de 2015, art. 2.2.4.6.8 y 2.2.4.6.10; Resolución 0312 de 2019, estándar 1.1.2; ISO 45001 5.3).\n\n";
        $md .= "## Responsabilidades comunes a todos los trabajadores\n\n"
            ."- Procurar el cuidado integral de su salud.\n"
            ."- Suministrar información clara, veraz y completa sobre su estado de salud.\n"
            ."- Cumplir las normas, reglamentos e instrucciones del SG-SST.\n"
            ."- Informar oportunamente los peligros y riesgos latentes en su sitio de trabajo.\n"
            ."- Participar en las actividades de capacitación en SST definidas en el plan de capacitación.\n"
            ."- Participar y contribuir al cumplimiento de los objetivos del SG-SST.\n\n";

        $cargos = $this->cargos()->filter(fn (JobPosition $c) => filled($c->responsabilidades_sig) || filled($c->autoridad));
        $md .= "## Responsabilidades por cargo\n\n";
        if ($cargos->isEmpty()) {
            return $md."Sin responsabilidades definidas por cargo.\n";
        }

        $md .= "| Cargo | Responsabilidades | Autoridad |\n|---|---|---|\n";
        foreach ($cargos as $c) {
            $md .= '| '.C::celda($c->nombre).' | '.C::celda($c->responsabilidades_sig).' | '.C::celda($c->autoridad)." |\n";
        }

        return $md."\nLa rendición de cuentas se hace al menos una vez al año, con los resultados del cargo frente a estas responsabilidades.\n";
    }

    /** Texto de varias líneas como lista de viñetas. */
    private function lista(?string $texto): string
    {
        $lineas = collect(preg_split('/\R/u', (string) $texto))
            ->map(fn ($l) => trim(ltrim(trim($l), '-*•·')))->filter();

        return $lineas->isEmpty() ? "Sin definir.\n" : $lineas->map(fn ($l) => "- {$l}")->implode("\n")."\n";
    }
}
