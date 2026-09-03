<?php

namespace App\Services\Ai;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Services\AiService;
use App\Support\TenantContext;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cerebro del asistente conversacional de CMK.
 *
 * Ejecuta el bucle de tool use contra Claude: la IA pide una herramienta
 * (consultar el IPERC, crear un documento…), aquí se ejecuta, se le devuelve
 * el resultado y se vuelve a llamar hasta que la IA responde en texto.
 *
 * Todo va en STREAMING: cada trozo de texto se empuja al navegador en cuanto
 * llega, mediante el callback $emit que entrega el controlador.
 *
 * Los turnos se guardan con los bloques de contenido intactos (incluidos los
 * `thinking` con su signature) porque la API exige recibirlos de vuelta sin
 * modificar para continuar una conversación con razonamiento y herramientas.
 */
class Assistant
{
    public function __construct(
        private readonly AiService $ai,
        private readonly AssistantToolbox $toolbox,
        private readonly TenantContext $context,
    ) {}

    /**
     * Procesa un mensaje del usuario y emite los eventos del stream.
     *
     * @param  callable(string,array<string,mixed>):void  $emit
     */
    public function responder(AssistantConversation $conversacion, string $mensajeUsuario, bool $puedeEscribir, callable $emit): void
    {
        $mensajes = $this->historial($conversacion);

        // El contenido se guarda SIEMPRE como array de bloques (nunca cadena
        // suelta) para que historial, persistencia y render usen una sola forma.
        $mensajes[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => $mensajeUsuario]]];
        $this->guardar($conversacion, 'user', [['type' => 'text', 'text' => $mensajeUsuario]]);

        if (blank($conversacion->titulo)) {
            $conversacion->update(['titulo' => Str::limit($mensajeUsuario, 60)]);
        }

        $tools = $this->toolbox->definiciones($puedeEscribir);
        $maxTurnos = (int) config('ai.chat.max_turnos', 10);

        for ($turno = 1; $turno <= $maxTurnos; $turno++) {
            if ($turno > 1) {
                // Cada vuelta es un mensaje distinto del asistente: se avisa al
                // widget para que abra una burbuja nueva y no pegue el texto
                // de este turno al final del anterior.
                $emit('turno', []);
            }

            $respuesta = $this->ai->stream(
                $this->payload($mensajes, $tools, $puedeEscribir),
                fn (array $evento) => $this->reenviar($evento, $emit),
            );

            $bloques = $respuesta['content'];

            if ($bloques !== []) {
                $mensajes[] = ['role' => 'assistant', 'content' => $bloques];
                $this->guardar($conversacion, 'assistant', $bloques);
            }

            if ($respuesta['stop_reason'] === 'refusal') {
                throw new RuntimeException('Claude declinó responder a esa solicitud. Reformúlala o consulta con el equipo.');
            }

            if ($respuesta['stop_reason'] !== 'tool_use') {
                if ($respuesta['stop_reason'] === 'max_tokens') {
                    $emit('aviso', ['mensaje' => 'La respuesta se cortó por longitud. Pídele que continúe.']);
                }

                return;
            }

            // Todos los tool_result de una tanda van en UN SOLO mensaje de
            // usuario: separarlos le enseña al modelo a dejar de paralelizar.
            $resultados = [];

            foreach ($bloques as $bloque) {
                if (($bloque['type'] ?? '') !== 'tool_use') {
                    continue;
                }

                $emit('herramienta', ['nombre' => $bloque['name'], 'estado' => 'inicio']);

                $salida = $this->toolbox->ejecutar($bloque['name'], $bloque['input'] ?? [], $puedeEscribir);

                $emit('herramienta', [
                    'nombre' => $bloque['name'],
                    'estado' => 'fin',
                    'error' => str_starts_with($salida, 'ERROR'),
                ]);

                $resultados[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $bloque['id'],
                    'content' => $salida,
                    'is_error' => str_starts_with($salida, 'ERROR'),
                ];
            }

            if ($resultados === []) {
                return; // stop_reason tool_use sin bloques: nada que devolver.
            }

            $mensajes[] = ['role' => 'user', 'content' => $resultados];
            $this->guardar($conversacion, 'user', $resultados);
        }

        $emit('aviso', ['mensaje' => 'El asistente alcanzó el límite de pasos para esta pregunta. Divídela en partes más pequeñas.']);
    }

    /**
     * Cuerpo de la petición a la Messages API.
     *
     * El prompt de sistema va marcado para caché: es lo más pesado y estable
     * de cada petición, y así las vueltas del bucle de herramientas no lo
     * vuelven a cobrar completo.
     *
     * @param  array<int,array<string,mixed>>  $mensajes
     * @param  array<int,array<string,mixed>>  $tools
     * @return array<string,mixed>
     */
    private function payload(array $mensajes, array $tools, bool $puedeEscribir): array
    {
        return [
            'model' => config('ai.anthropic.model'),
            'max_tokens' => (int) config('ai.chat.max_tokens', 32000),
            'system' => [[
                'type' => 'text',
                'text' => $this->systemPrompt($puedeEscribir),
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'thinking' => ['type' => 'adaptive', 'display' => 'summarized'],
            'output_config' => ['effort' => config('ai.chat.effort', 'high')],
            'tools' => $tools,
            'messages' => $mensajes,
        ];
    }

    /** Rol, contexto y reglas del asistente. */
    private function systemPrompt(bool $puedeEscribir): string
    {
        $tenant = $this->context->get();

        $empresa = $tenant !== null
            ? "La empresa cliente activa es «{$tenant->name}»"
                .($tenant->nit ? " (NIT {$tenant->nit})" : '')
                .'. Todo lo que consultes o generes es para esa empresa.'
            : 'AHORA MISMO NO HAY EMPRESA CLIENTE ACTIVA. No puedes consultar datos ni generar documentos: '
                .'lo primero que debes hacer es pedirle al usuario que seleccione un cliente en el selector superior.';

        $escritura = $puedeEscribir
            ? 'Puedes crear y actualizar documentos. Todo documento que crees nace en estado BORRADOR: '
                .'el consultor lo revisa, edita y aprueba desde el módulo Documentos IA. Nunca digas que un documento quedó aprobado.'
            : 'Este usuario NO tiene permiso para crear ni modificar documentos: solo puedes consultar información y explicar.';

        return <<<PROMPT
        Eres el asistente de {$this->consultora()}, una consultora colombiana de SST, PESV y HSEQ.
        Trabajas dentro de la plataforma de CMK y hablas con un consultor o con personal de la empresa cliente.

        {$empresa}

        TU TRABAJO PRINCIPAL ES GENERAR DOCUMENTOS DEL SISTEMA DE GESTIÓN.
        Antes de redactar cualquier documento:
        1. Consulta `contexto_organizacion` para tener los datos reales de la empresa.
        2. Mira `listar_plantillas` y usa la plantilla del catálogo que corresponda, en lugar de inventar una estructura.
        3. Usa las herramientas de lectura que aporten datos reales al documento (IPERC para matrices de riesgo,
           empleados para coberturas y actas, indicadores y plan de trabajo para informes de gestión,
           diagnóstico para planes de mejora). Un documento con cifras reales vale mucho más que uno genérico.

        {$escritura}

        NORMATIVA: Decreto 1072 de 2015, Resolución 0312 de 2019, Resolución 1401 de 2007,
        Resolución 40595 de 2022 (PESV) e ISO 9001/14001/45001. Cita la norma cuando sustente algo.

        REGLAS:
        - Escribe SIEMPRE en español de Colombia, formal pero directo. Eso incluye las frases cortas
          que digas antes de usar una herramienta: nunca respondas en inglés, ni siquiera un preámbulo.
        - Los documentos van en Markdown con encabezados.
        - NUNCA inventes datos que no te dieron las herramientas: escribe [PENDIENTE] y dile al usuario qué falta.
        - Si la plantilla tiene documento modelo base, se rellena de forma exacta y automática: no lo reescribas,
          es el texto oficial de cumplimiento de CMK.
        - Antes de crear un documento largo, confirma en una línea qué vas a generar. Después de crearlo,
          di el título y que quedó como borrador en Documentos IA.
        - Si te piden algo fuera de SST/PESV/HSEQ y documentos, responde breve y reencamina.
        - No prometas acciones que no puedas hacer con tus herramientas.
        PROMPT;
    }

    private function consultora(): string
    {
        return (string) config('cmk.company.legal_name', 'CMK GROUP S.A.S.');
    }

    /**
     * Traduce los eventos SSE de Anthropic a los eventos que entiende el widget.
     *
     * @param  array<string,mixed>  $evento
     * @param  callable(string,array<string,mixed>):void  $emit
     */
    private function reenviar(array $evento, callable $emit): void
    {
        if (($evento['type'] ?? '') !== 'content_block_delta') {
            return;
        }

        $delta = $evento['delta'] ?? [];

        match ($delta['type'] ?? '') {
            'text_delta' => $emit('texto', ['delta' => $delta['text']]),
            'thinking_delta' => $emit('pensando', ['delta' => $delta['thinking']]),
            default => null,
        };
    }

    /**
     * Historial que se reenvía en cada petición, recortado a los últimos N
     * turnos SIN romper una pareja tool_use / tool_result (la API rechaza un
     * historial que empiece con resultados de herramienta huérfanos).
     *
     * @return array<int,array<string,mixed>>
     */
    private function historial(AssistantConversation $conversacion): array
    {
        $limite = (int) config('ai.chat.historial', 40);

        // reorder() y no latest(): la relación messages() ya trae orderBy('id')
        // ascendente, y un segundo ORDER BY no reemplaza al primero. Con latest()
        // la consulta seguía saliendo ascendente, así que el limit se quedaba con
        // los 40 mensajes MÁS VIEJOS y el reverse() de abajo devolvía la
        // conversación al revés: cada tool_result llegaba antes que su tool_use y
        // la API respondía 400 «unexpected tool_use_id found in tool_result blocks».
        $mensajes = $conversacion->messages()
            ->reorder('id', 'desc')
            ->limit($limite)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (AssistantMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        // Descarta desde el principio hasta el primer mensaje de usuario que
        // sea texto real (no un tool_result que colgaría de un turno perdido).
        while ($mensajes !== [] && ! $this->esInicioValido($mensajes[0])) {
            array_shift($mensajes);
        }

        return $mensajes;
    }

    /** @param  array<string,mixed>  $mensaje */
    private function esInicioValido(array $mensaje): bool
    {
        if ($mensaje['role'] !== 'user') {
            return false;
        }

        return ! collect($mensaje['content'])->contains(fn ($b) => ($b['type'] ?? '') === 'tool_result');
    }

    /**
     * Persiste un turno con sus bloques intactos.
     *
     * @param  array<int,array<string,mixed>>  $content
     */
    private function guardar(AssistantConversation $conversacion, string $role, array $content): void
    {
        $conversacion->messages()->create([
            'role' => $role,
            'content' => $content,
        ]);
    }
}
