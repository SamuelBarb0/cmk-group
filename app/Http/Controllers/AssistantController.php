<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Services\Ai\Assistant;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Asistente conversacional de CMK (widget flotante).
 *
 * Un hilo por usuario y empresa cliente activa: al cambiar de cliente el
 * consultor entra a una conversación distinta, así no se mezcla el contexto
 * de dos empresas en el mismo chat.
 *
 * La respuesta va en Server-Sent Events para que el texto aparezca a medida
 * que Claude lo escribe. Las acciones sobre documentos las hace el propio
 * modelo con herramientas (ver AssistantToolbox) y siempre dejan el documento
 * en borrador; crear/actualizar exige el permiso documents.manage.
 */
class AssistantController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    /** Historial del hilo actual, ya aplanado para pintarlo en el widget. */
    public function history(Request $request): JsonResponse
    {
        $conversacion = $this->conversacion($request, crear: false);

        return response()->json([
            'cliente' => $this->context->get()?->name,
            'puede_escribir' => (bool) $request->user()?->can('documents.manage'),
            'mensajes' => $conversacion === null
                ? []
                : $conversacion->messages->map(fn (AssistantMessage $m) => $this->paraVista($m))->filter()->values(),
        ]);
    }

    /** Envía un mensaje y devuelve la respuesta del asistente en streaming. */
    public function stream(Request $request, Assistant $assistant): StreamedResponse
    {
        $data = $request->validate([
            'mensaje' => ['required', 'string', 'max:8000'],
        ]);

        $conversacion = $this->conversacion($request, crear: true);
        $puedeEscribir = (bool) $request->user()?->can('documents.manage');

        // La sesión se cierra ya: el stream puede durar minutos y no debe
        // bloquear las demás peticiones del mismo usuario.
        $request->session()->save();

        return response()->stream(function () use ($assistant, $conversacion, $data, $puedeEscribir) {
            // Generar un documento largo pasa de un minuto con holgura. Sin esto
            // PHP corta la petición al llegar a max_execution_time (60 s en XAMPP)
            // con un fatal, el evento 'fin' no llega nunca y el widget se queda
            // colgado en «Creando el documento…» aunque el documento ya esté hecho.
            set_time_limit(0);

            $emitir = function (string $evento, array $carga): void {
                echo 'event: '.$evento."\n";
                echo 'data: '.json_encode($carga, JSON_UNESCAPED_UNICODE)."\n\n";

                if (ob_get_level() > 0) {
                    @ob_flush();
                }

                flush();
            };

            // Relleno inicial: algunos proxies no entregan nada hasta juntar
            // unos cuantos KB, y el chat parecería congelado.
            echo ':'.str_repeat(' ', 2048)."\n\n";
            flush();

            try {
                $assistant->responder($conversacion, $data['mensaje'], $puedeEscribir, $emitir);
            } catch (Throwable $e) {
                report($e);
                $emitir('error', ['mensaje' => $e->getMessage()]);
            }

            $emitir('fin', []);
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            // Desactiva el buffering de nginx/LiteSpeed delante de PHP-FPM.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** Borra el hilo actual y empieza de cero. */
    public function clear(Request $request): JsonResponse
    {
        $this->conversacion($request, crear: false)?->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Hilo del usuario para la empresa activa (uno por combinación).
     */
    private function conversacion(Request $request, bool $crear): ?AssistantConversation
    {
        $claves = [
            'user_id' => $request->user()->id,
            'tenant_id' => $this->context->id(),
        ];

        $query = AssistantConversation::withoutTenantScope()
            ->where('user_id', $claves['user_id'])
            ->where('tenant_id', $claves['tenant_id'])
            ->with('messages');

        if ($crear) {
            return $query->first() ?? AssistantConversation::create($claves);
        }

        return $query->first();
    }

    /**
     * Aplana un turno guardado a lo que pinta el widget.
     * Devuelve null para los turnos que no se muestran (resultados de herramienta).
     *
     * @return array<string,mixed>|null
     */
    private function paraVista(AssistantMessage $mensaje): ?array
    {
        $bloques = collect($mensaje->content);

        if ($bloques->contains(fn ($b) => ($b['type'] ?? '') === 'tool_result')) {
            return null;
        }

        $texto = $bloques->where('type', 'text')->pluck('text')->implode('');
        $herramientas = $bloques->where('type', 'tool_use')->pluck('name')->values()->all();

        if ($texto === '' && $herramientas === []) {
            return null;
        }

        return [
            'id' => $mensaje->id,
            'role' => $mensaje->role,
            'texto' => $texto,
            'herramientas' => $herramientas,
        ];
    }
}
