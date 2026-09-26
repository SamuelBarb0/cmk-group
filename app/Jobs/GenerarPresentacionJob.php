<?php

namespace App\Jobs;

use App\Models\DocumentCatalogEntry;
use App\Models\Presentation;
use App\Models\Tenant;
use App\Models\TenantDocument;
use App\Models\User;
use App\Services\Ai\ContextoCliente;
use App\Services\AiService;
use App\Services\Presentaciones\ConstructorPptx;
use App\Services\Reportes\Periodo;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Genera una presentación: arma el contexto completo del cliente, le pide a
 * Claude las diapositivas como datos (herramienta forzada, no prosa) y las
 * dibuja en un .pptx con la marca de CMK. El archivo queda también en el
 * repositorio de documentos de la empresa.
 */
class GenerarPresentacionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 360;

    public int $tries = 1;

    public function __construct(public int $presentationId) {}

    public function handle(AiService $ai, ContextoCliente $contexto, ConstructorPptx $pptx, TenantContext $tenantContext): void
    {
        // En cola no hay TenantContext: se busca sin el scope y se pone a mano.
        $p = Presentation::withoutTenantScope()->find($this->presentationId);
        if (! $p || $p->estado !== 'generando') {
            return;
        }
        $tenant = Tenant::findOrFail($p->tenant_id);
        $tenantContext->set($tenant);
        $user = User::find($p->user_id) ?? throw new \RuntimeException('El usuario que pidió la presentación ya no existe.');

        $periodo = Periodo::desde($p->desde->toDateString(), $p->hasta->toDateString());
        $texto = $contexto->texto($tenant, $user, $p->modulo, $periodo, $p->submodulo);

        // Una presentación larga puede tardar más que el timeout general de la API.
        config(['ai.anthropic.timeout' => max((int) config('ai.anthropic.timeout'), 300)]);
        $diapositivas = $ai->herramienta($this->system(), $this->prompt($p, $texto), $this->herramienta($p->diapositivas), 16000, 'medium');

        $ruta = "tenants/{$tenant->id}/presentaciones/".Str::slug(Str::limit($diapositivas['titulo'], 60, '')).'-'.$p->id.'.pptx';
        Storage::disk('local')->makeDirectory(dirname($ruta));
        $pptx->guardar($diapositivas, $tenant->name, Storage::disk('local')->path($ruta));

        $p->forceFill([
            'titulo' => Str::limit($diapositivas['titulo'], 250),
            'contenido' => $diapositivas,
            'archivo' => $ruta,
            'estado' => 'lista',
            'error' => null,
        ])->save();

        $doc = new TenantDocument([
            'nombre' => $p->titulo.'.pptx',
            'categoria' => 'Presentaciones',
            'origen' => 'export',
            'path' => $ruta,
            'size' => Storage::disk('local')->size($ruta),
            'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'subido_por' => $user->name,
        ]);
        $doc->tenant_id = $tenant->id;
        $doc->save();
    }

    public function failed(?Throwable $e): void
    {
        Presentation::withoutTenantScope()->whereKey($this->presentationId)
            ->update(['estado' => 'error', 'error' => Str::limit($e?->getMessage() ?? 'Error desconocido', 1000)]);
    }

    private function system(): string
    {
        return 'Eres consultor senior de CMK GROUP en sistemas de gestión (SG-SST del Decreto 1072 de 2015 y la Resolución 0312 de 2019, PESV de la Resolución 40595 de 2022, ISO 45001, ISO 9001 e ISO 14001) en Colombia. '
            .'Preparas presentaciones para las empresas cliente con sus datos reales. Reglas: '
            .'1) Usa SOLO las cifras y hechos del contexto; nunca inventes números, nombres, fechas ni resultados. Si un dato falta, dilo como un pendiente o una brecha, no lo supongas. '
            .'2) Escribe en español de Colombia, claro y concreto; frases cortas en las diapositivas y el detalle en las notas del orador. '
            .'3) Cita normas solo cuando aporten y sin inventar numerales. '
            .'4) Cada diapositiva tiene una idea; máximo 6 viñetas; las cifras van en diapositivas de tipo cifras y las listas con columnas en tipo tabla.';
    }

    private function prompt(Presentation $p, string $contexto): string
    {
        [$proposito, $guia] = Presentation::PROPOSITOS[$p->proposito];
        $tema = $p->modulo ? "el módulo {$p->modulo} · ".(DocumentCatalogEntry::MODULOS[$p->modulo] ?? $p->modulo) : 'el sistema de gestión completo';
        if ($p->modulo && $p->submodulo) {
            $tema = 'la parte «'.(ContextoCliente::submodulosDe($p->modulo)[$p->submodulo] ?? $p->submodulo)."» de {$tema}; no hables de las demás partes del módulo";
        }

        return "Prepara una presentación sobre {$tema} para la empresa del contexto.\n\n"
            ."Propósito: {$proposito}. {$guia}\n"
            ."Extensión: {$p->diapositivas} diapositivas de contenido (sin contar la portada), incluidas las de sección y la de cierre.\n"
            .(filled($p->instrucciones) ? "Instrucciones de quien la pide: {$p->instrucciones}\n" : '')
            ."\n# Contexto del cliente\n\n{$contexto}";
    }

    /** @return array<string, mixed> */
    private function herramienta(int $n): array
    {
        return [
            'name' => 'presentacion',
            'description' => 'Entrega la presentación completa como diapositivas estructuradas.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'titulo' => ['type' => 'string', 'description' => 'Título de la presentación (portada).'],
                    'subtitulo' => ['type' => 'string'],
                    'diapositivas' => [
                        'type' => 'array',
                        'description' => "Exactamente {$n} diapositivas de contenido.",
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'tipo' => ['type' => 'string', 'enum' => ['seccion', 'vinetas', 'cifras', 'tabla', 'cierre']],
                                'titulo' => ['type' => 'string'],
                                'puntos' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Viñetas (máximo 6).'],
                                'cifras' => [
                                    'type' => 'array',
                                    'description' => 'Para tipo cifras: de 1 a 4 datos del contexto.',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => ['valor' => ['type' => 'string'], 'etiqueta' => ['type' => 'string']],
                                        'required' => ['valor', 'etiqueta'],
                                    ],
                                ],
                                'tabla' => [
                                    'type' => 'object',
                                    'description' => 'Para tipo tabla: hasta 6 columnas y 8 filas.',
                                    'properties' => [
                                        'columnas' => ['type' => 'array', 'items' => ['type' => 'string']],
                                        'filas' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]],
                                    ],
                                    'required' => ['columnas', 'filas'],
                                ],
                                'notas' => ['type' => 'string', 'description' => 'Notas del orador: qué decir y de dónde sale cada dato.'],
                            ],
                            'required' => ['tipo', 'titulo'],
                        ],
                    ],
                ],
                'required' => ['titulo', 'diapositivas'],
            ],
        ];
    }
}
