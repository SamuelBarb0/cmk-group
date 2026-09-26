<?php

namespace App\Services\Ai;

use App\Models\ControlledDocument;
use App\Models\DocumentCatalogEntry;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Clientes\AlcanceDocumental;
use App\Services\Reportes\InformeGestion;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;
use Illuminate\Support\Carbon;

/**
 * Todo lo que la IA necesita saber de un cliente para trabajar sobre él, en
 * texto: los datos de la organización, las cifras reales de los módulos (las
 * mismas secciones del informe de gestión, así que respeta lo contratado y
 * los permisos de quien pide) y el estado de sus documentos en el control
 * documental.
 *
 * Se acota con una selección de PIEZAS de cualquier módulo del mapa
 * documental: un módulo completo («M11»), una pantalla de un módulo
 * («M09:epp») o una parte de pantalla («M09:epp.matriz»). Sin piezas, es el
 * sistema completo.
 *
 * Espera el TenantContext de la empresa ya puesto (en cola, lo pone el job).
 */
class ContextoCliente
{
    public function __construct(
        private readonly InformeGestion $informe,
        private readonly AlcanceDocumental $alcance,
    ) {}

    /** @param  list<string>  $seleccion  piezas («M11», «M09:epp», «M09:epp.matriz») */
    public function texto(Tenant $tenant, User $user, array $seleccion, Periodo $periodo): string
    {
        $partes = [$this->organizacion($tenant)];
        if ($seleccion !== []) {
            $partes[] = "## Alcance de la presentación\n\nSolo estas piezas del sistema (no hables de las demás):\n\n"
                .collect($seleccion)->map(fn (string $p) => '- '.self::nombrePieza($p))->implode("\n");
        }
        $partes[] = $this->cifras($user, $seleccion, $periodo);
        $partes[] = $this->documentos($seleccion);

        return implode("\n\n", array_filter($partes));
    }

    /**
     * Partes de un módulo del mapa para acotar una presentación: sus pantallas
     * («epp») y, de cada una, las partes cuyos documentos son de ese módulo
     * («epp.matriz»).
     *
     * @return array<string, string> clave => nombre
     */
    public static function submodulosDe(string $modulo): array
    {
        $opciones = [];
        foreach (self::pantallasDe($modulo) as $pantalla) {
            $nombre = explode(' (', (string) config("cmk.modulos_contratables.{$pantalla}", $pantalla))[0];
            $opciones[$pantalla] = $nombre;
            foreach (config("cmk.alcance_documental.partes.{$pantalla}", []) as $parte => $reglas) {
                if (in_array($modulo, array_column($reglas, 0), true)) {
                    $opciones["{$pantalla}.{$parte}"] = $nombre.' · '.config("cmk.submodulos.{$pantalla}.{$parte}.nombre", $parte);
                }
            }
        }

        return $opciones;
    }

    /** Claves de las pantallas de la plataforma que pertenecen a un módulo del mapa. */
    public static function pantallasDe(string $modulo): array
    {
        return collect(config('cmk.alcance_documental.pantallas'))
            ->filter(fn (array $reglas) => in_array($modulo, array_column($reglas, 0), true))
            ->keys()->all();
    }

    /** ¿«M11», «M09:epp» o «M09:epp.matriz» existe en el mapa? */
    public static function piezaValida(string $pieza): bool
    {
        [$modulo, $sub] = array_pad(explode(':', $pieza, 2), 2, null);

        return isset(DocumentCatalogEntry::MODULOS[$modulo]) && ($sub === null || isset(self::submodulosDe($modulo)[$sub]));
    }

    /** «M09 · Operación SST › EPP · Matriz de EPP por cargo». */
    public static function nombrePieza(string $pieza): string
    {
        [$modulo, $sub] = array_pad(explode(':', $pieza, 2), 2, null);
        $nombre = "{$modulo} · ".(DocumentCatalogEntry::MODULOS[$modulo] ?? $modulo);

        return $sub ? $nombre.' › '.(self::submodulosDe($modulo)[$sub] ?? $sub) : $nombre;
    }

    private function organizacion(Tenant $t): string
    {
        $campos = [
            'Nombre' => $t->name,
            'Razón social' => $t->legal_name,
            'NIT' => $t->nit,
            'Ciudad' => $t->city,
            'Actividad económica' => $t->actividad_economica,
            'CIIU' => $t->codigo_ciiu,
            'Sector' => $t->sector,
            'Tamaño' => $t->tamano_empresa,
            'Nivel de riesgo (ARL)' => $t->nivel_riesgo,
            'ARL' => $t->arl,
            'Trabajadores registrados' => $t->num_trabajadores,
            'Trabajadores activos en la plataforma' => Employee::withoutTenantScope()->where('tenant_id', $t->id)->where('is_active', true)->count(),
            'Representante legal' => $t->representante_legal,
            'Responsable del SG-SST' => $t->responsable_sgsst,
        ];
        $lineas = collect($campos)->map(fn ($v, $k) => "- {$k}: ".(filled($v) ? $v : 'sin dato'))->implode("\n");
        $consultora = config('cmk.company.name');

        return "## La organización\n\n{$lineas}\n- Consultora que la acompaña: {$consultora}\n- Fecha de hoy: ".Carbon::today()->toDateString();
    }

    /**
     * Pantallas de la plataforma que cubre la selección (null = todas). Las
     * secciones del informe son por pantalla: una parte usa la de su pantalla.
     *
     * @param  list<string>  $seleccion
     * @return list<string>|null
     */
    private function pantallas(array $seleccion): ?array
    {
        if ($seleccion === []) {
            return null;
        }

        return collect($seleccion)->flatMap(function (string $pieza) {
            [$modulo, $sub] = array_pad(explode(':', $pieza, 2), 2, null);

            return $sub ? [explode('.', $sub)[0]] : self::pantallasDe($modulo);
        })->unique()->values()->all();
    }

    /** @param  list<string>  $seleccion */
    private function cifras(User $user, array $seleccion, Periodo $periodo): string
    {
        $pantallas = $this->pantallas($seleccion);
        $claves = collect($this->informe->disponibles($user))
            ->filter(fn (Seccion $s) => $pantallas === null || in_array($s->modulo(), $pantallas, true))
            ->map(fn (Seccion $s) => $s->clave())->values()->all();

        if ($claves === []) {
            return "## Cifras del periodo\n\nLa plataforma no tiene registros de lo elegido para la empresa (o no está contratado).";
        }

        $datos = $this->informe->generar($user, $periodo, $claves);
        $md = "## Cifras del periodo {$datos['periodo']['etiqueta']} (datos reales de la plataforma)\n";
        if ($datos['atencion'] !== []) {
            $md .= "\n### Puntos de atención\n\n".collect($datos['atencion'])->map(fn ($a) => "- {$a['seccion']}: {$a['texto']}")->implode("\n")."\n";
        }
        foreach ($datos['secciones'] as $s) {
            $md .= "\n### {$s['titulo']}\n\n";
            foreach ($s['cifras'] as $c) {
                $md .= "- {$c['etiqueta']}: {$c['valor']}".($c['alerta'] ? " (alerta: {$c['alerta']})" : '')."\n";
            }
            foreach ($s['tablas'] ?? [] as $t) {
                if ($t['filas'] === []) {
                    continue;
                }
                $md .= "\n{$t['titulo']}:\n| ".implode(' | ', $t['columnas'])." |\n|".str_repeat('---|', count($t['columnas']))."\n";
                foreach ($t['filas'] as $f) {
                    $md .= '| '.implode(' | ', $f)." |\n";
                }
            }
        }

        return $md;
    }

    /**
     * Ids del catálogo del mapa que cubre la selección (null = todos): un
     * módulo, todos sus documentos; una pantalla o una parte, los documentos
     * de ese módulo que la alimentan.
     *
     * @param  list<string>  $seleccion
     * @return list<int>|null
     */
    private function idsCatalogo(array $seleccion): ?array
    {
        if ($seleccion === []) {
            return null;
        }
        $reglas = $this->alcance->reglas();
        $porModulo = DocumentCatalogEntry::query()->get(['id', 'modulo'])->groupBy('modulo')->map->pluck('id');

        return collect($seleccion)->flatMap(function (string $pieza) use ($reglas, $porModulo) {
            [$modulo, $sub] = array_pad(explode(':', $pieza, 2), 2, null);
            $delModulo = $porModulo->get($modulo, collect())->all();
            if ($sub === null) {
                return $delModulo;
            }
            [$pantalla, $parte] = array_pad(explode('.', $sub, 2), 2, null);
            $ids = $parte ? ($reglas['partes'][$pantalla][$parte] ?? []) : ($reglas['pantallas'][$pantalla] ?? []);

            return array_intersect($ids, $delModulo);
        })->unique()->values()->all();
    }

    /** @param  list<string>  $seleccion */
    private function documentos(array $seleccion): string
    {
        $ids = $this->idsCatalogo($seleccion);
        $docs = ControlledDocument::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('document_catalog_id', $ids))
            ->orderBy('codigo')->get(['id', 'codigo', 'titulo', 'estado', 'version_vigente', 'document_catalog_id']);
        if ($docs->isEmpty()) {
            return "## Documentos en el control documental\n\nTodavía no hay documentos".($ids !== null ? ' de lo elegido' : '').' en el listado maestro.';
        }

        $porEstado = $docs->countBy('estado')->map(fn ($n, $e) => "{$n} ".str_replace('_', ' ', $e))->implode(', ');
        $lista = $docs->take(60)->map(fn ($d) => "- {$d->codigo} {$d->titulo} ({$d->estado}".($d->version_vigente ? ", v{$d->version_vigente}" : '').')')->implode("\n");

        return "## Documentos en el control documental\n\n{$docs->count()} documentos: {$porEstado}.\n\n{$lista}";
    }
}
