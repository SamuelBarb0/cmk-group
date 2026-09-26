<?php

namespace App\Services\Calidad;

use App\Models\CustomerRequest;
use App\Models\DocumentCatalogEntry;
use App\Models\NonconformingOutput;
use App\Models\SatisfactionSurvey;
use App\Services\ControlDocumental\CatalogoModulo as C;
use Illuminate\Support\Carbon;

/**
 * Arma el texto de los documentos del M17 que salen de los registros de
 * calidad, para mandarlos al control documental como borrador:
 *
 *   pqrs_prc  → PRC «Procedimiento de atención de PQRS»                (SIG-49)
 *   pqrs_ft   → FT  «Registro y respuesta de PQRS»                      (SIG-49)
 *   snc_prc   → PRC «Procedimiento de control de salidas no conformes»  (SIG-50)
 *   snc_ft    → FT  «Registro de salidas no conformes»                  (SIG-50)
 *   encuesta  → FT  «Encuesta de satisfacción del cliente»              (SIG-56)
 *
 * Los formatos llevan los registros de los últimos 12 meses.
 */
class DocumentosCalidad
{
    /** documento => [tipo, fragmento del nombre en el catálogo, fragmentos del título del requisito] */
    public const DOCUMENTOS = [
        'pqrs_prc' => ['PRC', 'atención de PQRS', ['Requisitos del cliente']],
        'pqrs_ft' => ['FT', 'respuesta de PQRS', ['Requisitos del cliente']],
        'snc_prc' => ['PRC', 'salidas no conformes', ['salidas no conformes']],
        'snc_ft' => ['FT', 'Registro de salidas no conformes', ['salidas no conformes']],
        'encuesta' => ['FT', 'satisfacción del cliente', ['satisfacción del cliente']],
    ];

    public function entrada(string $documento): ?DocumentCatalogEntry
    {
        [$tipo, $fragmento] = self::DOCUMENTOS[$documento];

        return C::entrada('M17', $tipo, $fragmento);
    }

    /** @return list<string> */
    public function claves(string $documento): array
    {
        return C::claves('M17', self::DOCUMENTOS[$documento][2]);
    }

    public function contenido(string $documento): string
    {
        return match ($documento) {
            'pqrs_prc' => $this->pqrsProcedimiento(),
            'pqrs_ft' => $this->pqrsRegistro(),
            'snc_prc' => $this->sncProcedimiento(),
            'snc_ft' => $this->sncRegistro(),
            default => $this->encuesta(),
        };
    }

    private function desde(): string
    {
        return Carbon::today()->subYear()->toDateString();
    }

    private function pqrsProcedimiento(): string
    {
        $tipos = collect(CustomerRequest::TIPOS)->map(fn ($l, $k) => "- **{$l}**")->implode("\n");

        return "## Objetivo\n\nRecibir, registrar, tramitar y responder las peticiones, quejas, reclamos, sugerencias y felicitaciones de los clientes y partes interesadas, y usar lo que dicen para mejorar (ISO 9001 8.2.1 y 9.1.2).\n\n"
            ."## Alcance\n\nAplica a toda comunicación de un cliente que exprese una solicitud, una inconformidad o una opinión sobre los productos y servicios, recibida por cualquier canal.\n\n"
            ."## Tipos\n\n{$tipos}\n\n"
            ."## Desarrollo\n\n"
            ."1. **Recepción y radicación.** Toda PQRS se registra el mismo día con un radicado consecutivo, la fecha, el cliente, el canal y la descripción.\n"
            .'2. **Plazo.** Se responde dentro de los '.CustomerRequest::PLAZO_DIAS_HABILES." días hábiles siguientes a la radicación (Ley 1755 de 2015, art. 14 y 32; Ley 1480 de 2011, art. 58, para reclamos de consumo). Si no es posible, se informa al cliente antes del vencimiento el motivo y la nueva fecha.\n"
            ."3. **Asignación.** Se asigna al responsable del proceso involucrado, que analiza el caso y propone la respuesta.\n"
            ."4. **Respuesta.** Se responde por escrito, de fondo y de manera clara, y se registra la fecha y el contenido de la respuesta.\n"
            ."5. **Análisis.** En las quejas y los reclamos se determina si proceden. Los que proceden y revelan una falla del sistema generan una acción correctiva en ACPM.\n"
            ."6. **Seguimiento.** Se mide el número de PQRS por tipo, el porcentaje respondido a tiempo y el tiempo promedio de respuesta, y se presentan en la revisión por la dirección.\n";
    }

    private function pqrsRegistro(): string
    {
        $filas = CustomerRequest::query()->with('process:id,sigla')->where('fecha', '>=', $this->desde())->orderBy('fecha')->orderBy('id')->get();
        $md = "## Registro de PQRS (últimos 12 meses)\n\n";
        if ($filas->isEmpty()) {
            return $md."Sin PQRS registradas.\n";
        }

        $md .= "| Radicado | Fecha | Tipo | Cliente | Descripción | Responsable | Límite | Respuesta | Fecha resp. | ¿A tiempo? | ¿Procede? |\n|---|---|---|---|---|---|---|---|---|---|---|\n";
        foreach ($filas as $r) {
            $md .= "| {$r->radicado} | {$r->fecha->toDateString()} | ".CustomerRequest::TIPOS[$r->tipo]
                .' | '.C::celda($r->cliente).' | '.C::celda($r->descripcion).' | '.C::celda($r->responsable)
                .' | '.$r->fecha_limite->toDateString().' | '.C::celda($r->respuesta)
                .' | '.($r->fecha_respuesta?->toDateString() ?? '—')
                .' | '.($r->a_tiempo === null ? ($r->vencida ? '**Vencida**' : 'Pendiente') : ($r->a_tiempo ? 'Sí' : 'No'))
                .' | '.($r->procede === null ? '—' : ($r->procede ? 'Sí' : 'No'))." |\n";
        }

        $respondidas = $filas->whereNotNull('fecha_respuesta');

        return $md."\nRespondidas a tiempo: {$respondidas->where('a_tiempo', true)->count()} de {$respondidas->count()}."
            .($respondidas->isNotEmpty() ? ' Tiempo promedio de respuesta: '.round((float) $respondidas->avg('dias_respuesta'), 1).' días.' : '')."\n";
    }

    private function sncProcedimiento(): string
    {
        $tratamientos = collect(NonconformingOutput::TRATAMIENTOS)->map(fn ($l) => "- {$l}")->implode("\n");

        return "## Objetivo\n\nIdentificar y controlar las salidas (productos y servicios) que no cumplen sus requisitos, para prevenir su uso o entrega no intencionada, y tratarlas de forma que se asegure la conformidad (ISO 9001 8.7).\n\n"
            ."## Alcance\n\nAplica a los insumos recibidos, los productos en proceso, los productos terminados y los servicios prestados, incluida la no conformidad detectada por el cliente después de la entrega.\n\n"
            ."## Desarrollo\n\n"
            ."1. **Identificación.** Quien detecte la salida no conforme la identifica y la separa físicamente cuando es posible, para evitar su uso o entrega.\n"
            ."2. **Registro.** Se registra con un código consecutivo, la fecha, el producto o servicio, dónde se detectó, la descripción y la cantidad afectada.\n"
            ."3. **Tratamiento.** El responsable del proceso decide el tratamiento:\n\n{$tratamientos}\n\n"
            ."4. **Concesión.** Aceptar un producto no conforme requiere la autorización de quien tiene la autoridad para ello y, cuando aplica, del cliente; se registra quién la autorizó.\n"
            ."5. **Verificación.** Después de una corrección o un reproceso se verifica de nuevo la conformidad con los requisitos antes de liberar; se registra quién verificó y cuándo.\n"
            ."6. **Acción correctiva.** Si la no conformidad es repetitiva o de impacto, se abre una acción correctiva en ACPM para eliminar su causa.\n"
            ."7. **Información documentada.** Se conserva el registro con la descripción, las acciones tomadas, las concesiones obtenidas y la autoridad que decidió (8.7.2).\n";
    }

    private function sncRegistro(): string
    {
        $filas = NonconformingOutput::query()->where('fecha', '>=', $this->desde())->orderBy('fecha')->orderBy('id')->get();
        $md = "## Registro de salidas no conformes (últimos 12 meses)\n\n";
        if ($filas->isEmpty()) {
            return $md."Sin salidas no conformes registradas.\n";
        }

        $md .= "| Código | Fecha | Producto o servicio | Detectado en | Descripción | Cantidad | Tratamiento | Autorizado por | Verificado por | Estado |\n|---|---|---|---|---|---|---|---|---|---|\n";
        foreach ($filas as $s) {
            $md .= "| {$s->codigo} | {$s->fecha->toDateString()} | ".C::celda($s->producto)
                .' | '.NonconformingOutput::DETECCION[$s->detectado_en].' | '.C::celda($s->descripcion).' | '.C::celda($s->cantidad)
                .' | '.NonconformingOutput::TRATAMIENTOS[$s->tratamiento].($s->detalle_tratamiento ? ': '.C::celda($s->detalle_tratamiento) : '')
                .' | '.C::celda($s->autorizado_por)
                .' | '.C::celda(trim(($s->verificado_por ?? '').($s->fecha_verificacion ? " ({$s->fecha_verificacion->toDateString()})" : '')))
                .' | '.NonconformingOutput::ESTADOS[$s->estado]." |\n";
        }

        return $md;
    }

    private function encuesta(): string
    {
        $md = "## Instrucciones\n\nCalifique de 1 a 5 cada aspecto, donde 1 es «muy insatisfecho» y 5 es «muy satisfecho». Sus respuestas nos ayudan a mejorar.\n\n";
        $md .= "| Cliente | Fecha | Producto o servicio |\n|---|---|---|\n|  |  |  |\n\n";
        $md .= "| Aspecto | 1 | 2 | 3 | 4 | 5 |\n|---|---|---|---|---|---|\n";
        foreach (SatisfactionSurvey::CRITERIOS as $etiqueta) {
            $md .= "| {$etiqueta} |  |  |  |  |  |\n";
        }
        $md .= "\n**Comentarios y sugerencias:**\n\n\n";

        $encuestas = SatisfactionSurvey::query()->where('fecha', '>=', $this->desde())->get();
        $md .= "## Resultados (últimos 12 meses)\n\n";
        if ($encuestas->isEmpty()) {
            return $md."Sin encuestas registradas.\n";
        }

        $r = SatisfactionSurvey::resumen($encuestas);
        $md .= "Encuestas: {$r['n']}. Índice de satisfacción: **{$r['indice']} %** (suma de las calificaciones sobre el máximo posible).\n\n| Aspecto | Promedio (1 a 5) |\n|---|---|\n";
        foreach (SatisfactionSurvey::CRITERIOS as $c => $etiqueta) {
            $md .= "| {$etiqueta} | {$r['criterios'][$c]} |\n";
        }

        return $md;
    }
}
