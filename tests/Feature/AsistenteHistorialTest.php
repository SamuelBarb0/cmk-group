<?php

namespace Tests\Feature;

use App\Models\AssistantConversation;
use App\Models\User;
use App\Services\Ai\Assistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El historial que se reenvía a Claude tiene que salir en orden cronológico y
 * con cada tool_result justo detrás del tool_use que lo pidió. Si se invierte,
 * la Messages API responde 400 «unexpected tool_use_id found in tool_result
 * blocks», que es lo que pasaba al escribir un segundo mensaje en una
 * conversación donde la IA ya había usado herramientas.
 */
class AsistenteHistorialTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<int,array{0:string,1:array<int,array<string,mixed>>}>  $turnos */
    private function conversacionCon(array $turnos): AssistantConversation
    {
        $conversacion = AssistantConversation::create([
            'user_id' => User::factory()->create()->id,
            'titulo' => 'Prueba',
        ]);

        foreach ($turnos as [$role, $content]) {
            $conversacion->messages()->create(['role' => $role, 'content' => $content]);
        }

        return $conversacion;
    }

    /** @return array<int,array<string,mixed>> */
    private function historial(AssistantConversation $conversacion): array
    {
        $metodo = new ReflectionMethod(Assistant::class, 'historial');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(Assistant::class), $conversacion);
    }

    private function texto(string $t): array
    {
        return [['type' => 'text', 'text' => $t]];
    }

    private function usoHerramienta(string $id): array
    {
        return [['type' => 'tool_use', 'id' => $id, 'name' => 'contexto_organizacion', 'input' => []]];
    }

    private function resultadoHerramienta(string $id): array
    {
        return [['type' => 'tool_result', 'tool_use_id' => $id, 'content' => 'datos', 'is_error' => false]];
    }

    public function test_el_historial_sale_en_orden_cronologico(): void
    {
        $conversacion = $this->conversacionCon([
            ['user', $this->texto('primera pregunta')],
            ['assistant', $this->usoHerramienta('toolu_uno')],
            ['user', $this->resultadoHerramienta('toolu_uno')],
            ['assistant', $this->texto('primera respuesta')],
            ['user', $this->texto('segunda pregunta')],
        ]);

        $historial = $this->historial($conversacion);

        $this->assertSame(
            ['primera pregunta', 'tool_use:toolu_uno', 'tool_result:toolu_uno', 'primera respuesta', 'segunda pregunta'],
            array_map($this->resumir(...), $historial),
        );
    }

    public function test_cada_tool_result_va_detras_de_su_tool_use(): void
    {
        $conversacion = $this->conversacionCon([
            ['user', $this->texto('pregunta')],
            ['assistant', $this->usoHerramienta('toolu_uno')],
            ['user', $this->resultadoHerramienta('toolu_uno')],
            ['assistant', $this->usoHerramienta('toolu_dos')],
            ['user', $this->resultadoHerramienta('toolu_dos')],
            ['assistant', $this->texto('respuesta')],
        ]);

        $historial = $this->historial($conversacion);

        foreach ($historial as $i => $mensaje) {
            foreach ($mensaje['content'] as $bloque) {
                if (($bloque['type'] ?? '') !== 'tool_result') {
                    continue;
                }

                $anterior = $historial[$i - 1]['content'] ?? [];
                $ids = array_column(array_filter($anterior, fn ($b) => ($b['type'] ?? '') === 'tool_use'), 'id');

                $this->assertContains(
                    $bloque['tool_use_id'],
                    $ids,
                    "El tool_result {$bloque['tool_use_id']} no tiene su tool_use en el mensaje anterior.",
                );
            }
        }
    }

    public function test_el_recorte_conserva_los_mensajes_mas_recientes(): void
    {
        config()->set('ai.chat.historial', 3);

        $conversacion = $this->conversacionCon([
            ['user', $this->texto('vieja')],
            ['assistant', $this->texto('vieja respuesta')],
            ['user', $this->texto('reciente')],
            ['assistant', $this->texto('reciente respuesta')],
            ['user', $this->texto('ultima')],
        ]);

        $resumen = array_map($this->resumir(...), $this->historial($conversacion));

        $this->assertSame(['reciente', 'reciente respuesta', 'ultima'], $resumen);
    }

    public function test_descarta_los_tool_result_que_quedan_huerfanos_al_recortar(): void
    {
        config()->set('ai.chat.historial', 3);

        $conversacion = $this->conversacionCon([
            ['user', $this->texto('pregunta')],
            ['assistant', $this->usoHerramienta('toolu_uno')],
            ['user', $this->resultadoHerramienta('toolu_uno')],
            ['assistant', $this->texto('respuesta')],
            ['user', $this->texto('siguiente pregunta')],
        ]);

        $historial = $this->historial($conversacion);

        // La ventana empieza en el tool_result, que sin su tool_use es inválido:
        // se descarta hasta el primer mensaje de usuario con texto real.
        $this->assertSame(['siguiente pregunta'], array_map($this->resumir(...), $historial));
    }

    /** @param  array<string,mixed>  $mensaje */
    private function resumir(array $mensaje): string
    {
        $bloque = $mensaje['content'][0];

        return match ($bloque['type']) {
            'tool_use' => 'tool_use:'.$bloque['id'],
            'tool_result' => 'tool_result:'.$bloque['tool_use_id'],
            default => $bloque['text'],
        };
    }
}
