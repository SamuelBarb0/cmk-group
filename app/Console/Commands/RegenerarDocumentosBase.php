<?php

namespace App\Console\Commands;

use App\Models\GeneratedDocument;
use App\Services\DocumentFiller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Vuelve a rellenar los documentos que nacieron del contenido base VIEJO.
 *
 * POR QUÉ EXISTE: hasta el 4-sep los `.txt` de plantillas-base eran texto
 * plano (ver plantillas:reimportar). Los documentos generados antes de eso
 * guardaron ese texto plano en `contenido`: la descarga en Word ya sale bien
 * (usa el .docx modelo), pero EN PANTALLA se ven como un muro de letras.
 *
 * Qué se regenera, y qué no:
 *  - Solo plantillas con modelo base: su contenido sale de un relleno
 *    determinista (DocumentFiller). Lo redactado por la IA no se toca: no hay
 *    forma de regenerarlo sin volver a redactarlo.
 *  - Solo versión 1: editar sube la versión. Un documento editado NUNCA se toca.
 *  - Solo los guardados como TEXTO PLANO (sin encabezados, tablas ni viñetas),
 *    que es el síntoma del bug. Uno que ya tiene estructura se deja como está:
 *    los generados antes del 16-jul los redactó la IA sobre el modelo, y
 *    regenerarlos los cambiaría por el relleno mecánico.
 *  - Nunca los aprobados, aunque sean v1: se listan para que el consultor
 *    decida. Un documento aprobado no cambia sin que nadie lo vea.
 *  - La fecha de emisión se conserva: se rellena con la fecha en que se
 *    creó el documento, no con la de hoy.
 *
 * Por defecto NO escribe: muestra lo que haría. Con --aplicar guarda un
 * respaldo JSON del contenido anterior antes de tocar nada.
 */
class RegenerarDocumentosBase extends Command
{
    protected $signature = 'documentos:regenerar-base
                            {--aplicar : Escribe los cambios (sin esto solo muestra lo que haría)}
                            {--id=* : Solo estos documentos}';

    protected $description = 'Regenera el contenido de los documentos v1 nacidos del contenido base viejo';

    public function handle(DocumentFiller $filler): int
    {
        $docs = GeneratedDocument::withoutTenantScope()
            ->with(['template', 'tenant'])
            ->when($this->option('id'), fn ($q, $ids) => $q->whereKey($ids))
            ->orderBy('id')
            ->get();

        $cambios = [];
        $filas = [];
        foreach ($docs as $d) {
            [$accion, $nuevo] = $this->evaluar($d, $filler);
            $filas[] = [$d->id, $d->tenant?->name ?? '—', mb_strimwidth($d->titulo, 0, 40, '…'), "v{$d->version} {$d->estado}", $accion, $this->perfil($d->contenido), $nuevo ? $this->perfil($nuevo) : '—'];
            if ($nuevo !== null && $accion === 'regenerar') {
                $cambios[$d->id] = ['anterior' => $d->contenido, 'nuevo' => $nuevo];
            }
        }

        $this->table(['Id', 'Empresa', 'Documento', 'Versión', 'Acción', 'Hoy', 'Después'], $filas);
        $this->line('Perfil: líneas / encabezados / filas de tabla / viñetas.');

        if ($cambios === []) {
            $this->info('No hay documentos que regenerar.');

            return self::SUCCESS;
        }

        if (! $this->option('aplicar')) {
            $this->warn(count($cambios).' documento(s) se regenerarían. Nada se escribió: vuelve a correr con --aplicar.');

            return self::SUCCESS;
        }

        $respaldo = 'respaldos/documentos-antes-de-regenerar-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($respaldo, json_encode(
            collect($cambios)->map(fn ($c) => $c['anterior'])->all(),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));
        $this->line("Respaldo del contenido anterior: storage/app/private/{$respaldo}");

        DB::transaction(function () use ($cambios): void {
            foreach ($cambios as $id => $c) {
                // La versión NO sube: el documento no cambió de contenido para el
                // consultor, se corrigió cómo quedó guardado.
                GeneratedDocument::withoutTenantScope()->whereKey($id)->update(['contenido' => $c['nuevo']]);
            }
        });
        $this->info(count($cambios).' documento(s) regenerado(s).');

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: ?string} acción y contenido nuevo */
    private function evaluar(GeneratedDocument $d, DocumentFiller $filler): array
    {
        $t = $d->template;
        if (! $t || ! $t->tieneBase()) {
            return ['omitir: redactado por IA', null];
        }
        if ($d->version !== 1) {
            return ['omitir: editado', null];
        }
        if (! $d->tenant) {
            return ['omitir: sin empresa', null];
        }
        // Solo el síntoma del bug: guardado como texto plano. Un documento que
        // ya tiene estructura no se toca, venga de donde venga (los de antes del
        // 16-jul los redactó la IA sobre el modelo y NO son lo que da el relleno).
        if (! $this->esPlano($d->contenido)) {
            return ['omitir: ya tiene formato', null];
        }

        $nuevo = $filler->fill($t->contenido_base, $d->tenant, $d->created_at);
        if ($this->esPlano($nuevo)) {
            return ['omitir: el modelo tampoco tiene formato', null];
        }
        if ($d->estado === 'aprobado') {
            return ['REVISAR: aprobado', $nuevo];
        }

        return ['regenerar', $nuevo];
    }

    /** Sin un solo encabezado, fila de tabla ni viñeta: el texto plano del bug. */
    private function esPlano(?string $texto): bool
    {
        return ! preg_match('/^(#{1,6}\s|\s*\||\s*[-*+]\s|\s*\d+[.)]\s)/m', (string) $texto);
    }

    /** «líneas / encabezados / filas de tabla / viñetas»: dice de un vistazo si es texto plano. */
    private function perfil(?string $texto): string
    {
        $lineas = preg_split('/\R/', (string) $texto);

        return count($lineas).' / '
            .count(preg_grep('/^#{1,6}\s/', $lineas)).' / '
            .count(preg_grep('/^\s*\|/', $lineas)).' / '
            .count(preg_grep('/^\s*([-*+]|\d+[.)])\s/', $lineas));
    }
}
