<?php

namespace App\Services\Contexto;

use App\Models\ContextIssue;
use App\Models\ContextProfile;
use App\Models\DocumentCatalogEntry;
use App\Models\InterestedParty;
use App\Models\NormRequirement;
use App\Models\Process;
use Illuminate\Support\Collection;

/**
 * Arma, desde los datos del módulo de contexto (M02), el texto de los tres
 * documentos del catálogo que ese módulo evidencia, para mandarlos al control
 * documental como borrador:
 *
 *   dofa     → MTZ «Matriz DOFA / PESTEL (incluye cambio climático)»   (4.1)
 *   partes   → MTZ «Matriz de partes interesadas…»                      (4.2)
 *   alcance  → MAN «Documento de alcance y mapa de procesos»           (4.3, 4.4)
 *
 * El módulo es donde se trabaja; el documento es la foto aprobada que ve el
 * auditor. Por eso se manda a propósito, no se sincroniza solo.
 */
class DocumentosContexto
{
    /** documento => [tipo del catálogo, fragmento del nombre, requisitos que evidencia (por su título)] */
    public const DOCUMENTOS = [
        'dofa' => ['MTZ', 'DOFA', ['contexto interno y externo']],
        'partes' => ['MTZ', 'partes interesadas', ['partes interesadas']],
        'alcance' => ['MAN', 'alcance y mapa de procesos', ['Alcance del sistema', 'Mapa de procesos']],
    ];

    public function entrada(string $documento): ?DocumentCatalogEntry
    {
        [$tipo, $nombre] = self::DOCUMENTOS[$documento];

        return DocumentCatalogEntry::query()
            ->where('modulo', 'M02')
            ->where('tipo', $tipo)
            ->where('nombre', 'like', "%{$nombre}%")
            ->first();
    }

    /**
     * Claves comunes (SIG-xx) de los requisitos del M02 que evidencia el
     * documento. Se buscan por título y no por número: la numeración depende
     * del orden del catálogo.
     *
     * @return list<string>
     */
    public function clavesRequisitos(string $documento): array
    {
        $titulos = self::DOCUMENTOS[$documento][2];

        return NormRequirement::query()
            ->where('modulo', 'M02')
            ->where(function ($q) use ($titulos) {
                foreach ($titulos as $t) {
                    $q->orWhere('titulo', 'like', "%{$t}%");
                }
            })
            ->distinct()
            ->pluck('clave_comun')
            ->values()
            ->all();
    }

    public function contenido(string $documento): string
    {
        return match ($documento) {
            'dofa' => $this->dofa(),
            'partes' => $this->partes(),
            'alcance' => $this->alcance(),
        };
    }

    private function dofa(): string
    {
        $perfil = ContextProfile::query()->first();
        $cuestiones = ContextIssue::query()->orderBy('orden')->orderBy('id')->get();

        $md = "## Objetivo\n\nIdentificar las cuestiones internas y externas pertinentes para el propósito de la organización y que afectan su capacidad para lograr los resultados previstos del sistema integrado de gestión (ISO 45001, ISO 9001 e ISO 14001, numeral 4.1).\n\n";

        $md .= "## Cambio climático\n\n".$this->climaTexto($perfil)."\n\n";

        foreach (['fortaleza' => 'Fortalezas', 'debilidad' => 'Debilidades', 'oportunidad' => 'Oportunidades', 'amenaza' => 'Amenazas'] as $clave => $titulo) {
            $grupo = $cuestiones->where('dofa', $clave);
            $md .= "## {$titulo}\n\n";
            if ($grupo->isEmpty()) {
                $md .= "Sin cuestiones registradas.\n\n";

                continue;
            }
            $externa = in_array($clave, ContextIssue::DOFA['externo'], true);
            $md .= $externa
                ? "| Cuestión | PESTEL | Impacto | Tratamiento |\n|---|---|---|---|\n"
                : "| Cuestión | Impacto | Tratamiento |\n|---|---|---|\n";
            foreach ($grupo as $c) {
                $cuestion = self::celda($c->descripcion).($c->cambio_climatico ? ' (cambio climático)' : '');
                $md .= $externa
                    ? "| {$cuestion} | ".self::pestel($c->pestel).' | '.ucfirst($c->impacto).' | '.self::celda($c->tratamiento)." |\n"
                    : "| {$cuestion} | ".ucfirst($c->impacto).' | '.self::celda($c->tratamiento)." |\n";
            }
            $md .= "\n";
        }

        return $md.$this->pieRevision($perfil);
    }

    private function partes(): string
    {
        $perfil = ContextProfile::query()->first();
        $partes = InterestedParty::query()->orderBy('orden')->orderBy('id')->get();

        $md = "## Objetivo\n\nDeterminar las partes interesadas pertinentes para el sistema integrado de gestión, sus necesidades y expectativas, y cuáles de ellas se convierten en requisitos legales u otros requisitos (ISO 45001, ISO 9001 e ISO 14001, numeral 4.2).\n\n";

        if ($partes->isEmpty()) {
            return $md."Sin partes interesadas registradas.\n\n".$this->pieRevision($perfil);
        }

        $md .= "| Parte interesada | Tipo | Necesidades | Expectativas | ¿Requisito? | Influencia / interés | Cómo se atiende |\n|---|---|---|---|---|---|---|\n";
        foreach ($partes as $p) {
            $md .= '| '.self::celda($p->nombre).($p->cambio_climatico ? ' (cambio climático)' : '')
                .' | '.ucfirst($p->tipo)
                .' | '.self::celda($p->necesidades)
                .' | '.self::celda($p->expectativas)
                .' | '.($p->es_requisito ? 'Sí' : 'No')
                .' | '.ucfirst($p->influencia).' / '.ucfirst($p->interes).' — '.$p->estrategia()
                .' | '.self::celda($p->como_se_atiende)." |\n";
        }

        $requisitos = $partes->where('es_requisito', true);
        if ($requisitos->isNotEmpty()) {
            $md .= "\nLas necesidades marcadas como requisito se incorporan a la matriz de requisitos legales y otros requisitos, o a los requisitos del cliente, según corresponda.\n";
        }

        return $md."\n".$this->pieRevision($perfil);
    }

    private function alcance(): string
    {
        $perfil = ContextProfile::query()->first();
        $procesos = Process::query()->orderBy('orden')->orderBy('id')->get();

        $md = "## Alcance del sistema integrado de gestión\n\n".(filled($perfil?->alcance) ? trim($perfil->alcance) : 'Pendiente por definir.')."\n\n";

        if (filled($perfil?->productos_servicios)) {
            $md .= '**Productos y servicios:** '.trim($perfil->productos_servicios)."\n\n";
        }
        if (filled($perfil?->sedes)) {
            $md .= '**Sedes y centros de trabajo incluidos:** '.trim($perfil->sedes)."\n\n";
        }

        $md .= "El sistema de gestión de seguridad y salud en el trabajo cubre a todos los trabajadores, sin importar su forma de contratación, y a los contratistas y subcontratistas (Decreto 1072 de 2015, art. 2.2.4.6.1).\n\n";

        $exclusiones = collect($perfil?->exclusiones ?? [])->filter(fn ($e) => filled($e['requisito'] ?? null));
        $md .= "## Requisitos no aplicables\n\n";
        if ($exclusiones->isEmpty()) {
            $md .= "Todos los requisitos de las normas del sistema aplican a la organización.\n\n";
        } else {
            $md .= "| Requisito | Justificación |\n|---|---|\n";
            foreach ($exclusiones as $e) {
                $md .= '| '.self::celda($e['requisito']).' | '.self::celda($e['justificacion'] ?? '')." |\n";
            }
            $md .= "\n";
        }

        $md .= "## Cambio climático\n\n".$this->climaTexto($perfil)."\n\n";

        $md .= "## Mapa de procesos\n\n";
        if ($procesos->isEmpty()) {
            $md .= "Pendiente por definir.\n\n";
        } else {
            $md .= "| Tipo | Sigla | Proceso | Líder | Objetivo |\n|---|---|---|---|---|\n";
            foreach ($procesos->sortBy(fn ($p) => array_search($p->tipo, Process::TIPOS, true)) as $p) {
                $md .= '| '.self::tipoProceso($p->tipo).' | '.$p->sigla.' | '.self::celda($p->nombre).' | '.self::celda($p->lider).' | '.self::celda($p->objetivo)." |\n";
            }
            $md .= "\n";
        }

        $caracterizados = $procesos->filter(fn (Process $p) => filled($p->objetivo) || filled($p->caracterizacion));
        if ($caracterizados->isNotEmpty()) {
            $md .= "## Caracterización de los procesos\n\n";
            foreach ($caracterizados as $p) {
                $md .= "### {$p->sigla} · {$p->nombre}\n\n";
                if (filled($p->objetivo)) {
                    $md .= '**Objetivo:** '.trim($p->objetivo)."\n\n";
                }
                if (filled($p->lider)) {
                    $md .= '**Líder:** '.trim($p->lider)."\n\n";
                }
                $c = $p->caracterizacion ?? [];
                $md .= "| Aspecto | Descripción |\n|---|---|\n";
                foreach (Process::CARACTERIZACION as $clave => $etiqueta) {
                    if (filled($c[$clave] ?? null)) {
                        $md .= "| {$etiqueta} | ".self::celda($c[$clave])." |\n";
                    }
                }
                $md .= "\n";
            }
        }

        return $md.$this->pieRevision($perfil);
    }

    private function climaTexto(?ContextProfile $perfil): string
    {
        $just = filled($perfil?->cambio_climatico_justificacion) ? ' '.trim($perfil->cambio_climatico_justificacion) : '';

        return match ($perfil?->cambio_climatico) {
            true => 'La organización determinó que el cambio climático es una cuestión pertinente para su sistema de gestión.'.$just,
            false => 'La organización determinó que el cambio climático no es una cuestión pertinente para su sistema de gestión.'.$just,
            default => 'Pendiente: la organización debe determinar si el cambio climático es una cuestión pertinente (ISO 45001:2018/Amd 1:2024; ISO 9001:2026 e ISO 14001:2026, numeral 4.1).',
        };
    }

    private function pieRevision(?ContextProfile $perfil): string
    {
        if (! $perfil?->revisado_at) {
            return '';
        }

        return '_Última revisión del contexto: '.$perfil->revisado_at->format('Y-m-d').($perfil->revisado_por ? " por {$perfil->revisado_por}" : '').'._'."\n";
    }

    /** Un texto dentro de una celda de tabla: sin saltos de línea ni barras. */
    private static function celda(?string $texto): string
    {
        $t = trim((string) $texto);

        return $t === '' ? '—' : str_replace(['|', "\r\n", "\n", "\r"], ['/', ' ', ' ', ' '], $t);
    }

    private static function pestel(?string $p): string
    {
        return [
            'politico' => 'Político', 'economico' => 'Económico', 'social' => 'Social',
            'tecnologico' => 'Tecnológico', 'ambiental' => 'Ambiental', 'legal' => 'Legal',
        ][$p] ?? '—';
    }

    private static function tipoProceso(string $tipo): string
    {
        return ['estrategico' => 'Estratégico', 'misional' => 'Misional', 'apoyo' => 'Apoyo', 'evaluacion' => 'Evaluación'][$tipo] ?? $tipo;
    }

    /**
     * Cuánto le falta al módulo, para la pantalla y el informe.
     *
     * @return array{dofa: bool, clima: bool, partes: bool, alcance: bool, procesos: bool}
     */
    public static function completitud(?ContextProfile $perfil, Collection $cuestiones, Collection $partes, Collection $procesos): array
    {
        $cuadrantes = $cuestiones->pluck('dofa')->unique();

        return [
            // Las cuatro caras de la DOFA con al menos una cuestión.
            'dofa' => collect(['fortaleza', 'debilidad', 'oportunidad', 'amenaza'])->every(fn ($c) => $cuadrantes->contains($c)),
            'clima' => $perfil?->cambio_climatico !== null && filled($perfil?->cambio_climatico_justificacion),
            'partes' => $partes->isNotEmpty(),
            'alcance' => filled($perfil?->alcance),
            'procesos' => $procesos->isNotEmpty() && $procesos->every(fn (Process $p) => $p->caracterizado()),
        ];
    }
}
