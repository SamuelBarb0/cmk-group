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
 * documental. Se puede acotar a un módulo del mapa documental (M01–M20) y,
 * dentro de él, a una pantalla («epp») o a una parte de ella («epp.matriz»).
 *
 * Espera el TenantContext de la empresa ya puesto (en cola, lo pone el job).
 */
class ContextoCliente
{
    public function __construct(
        private readonly InformeGestion $informe,
        private readonly AlcanceDocumental $alcance,
    ) {}

    public function texto(Tenant $tenant, User $user, ?string $modulo, Periodo $periodo, ?string $submodulo = null): string
    {
        $partes = [$this->organizacion($tenant)];
        if ($modulo) {
            $partes[] = "## Módulo del sistema\n\n{$modulo} · ".(DocumentCatalogEntry::MODULOS[$modulo] ?? $modulo)
                .($submodulo ? "\n\nLa presentación es SOLO de esta parte del módulo: ".(self::submodulosDe($modulo)[$submodulo] ?? $submodulo).'.' : '');
        }
        $partes[] = $this->cifras($user, $modulo, $periodo, $submodulo);
        $partes[] = $this->documentos($modulo, $submodulo);

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

    private function cifras(User $user, ?string $modulo, Periodo $periodo, ?string $submodulo): string
    {
        // Las secciones del informe son por pantalla: una parte usa la de su pantalla.
        $pantallas = $submodulo ? [explode('.', $submodulo)[0]] : ($modulo ? self::pantallasDe($modulo) : null);
        $claves = collect($this->informe->disponibles($user))
            ->filter(fn (Seccion $s) => $pantallas === null || in_array($s->modulo(), $pantallas, true))
            ->map(fn (Seccion $s) => $s->clave())->values()->all();

        if ($claves === []) {
            return "## Cifras del periodo\n\nLa plataforma no tiene registros de este módulo para la empresa (o no está contratado).";
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

    private function documentos(?string $modulo, ?string $submodulo): string
    {
        // Con una parte elegida, solo los documentos del mapa que la alimentan.
        $ids = null;
        if ($submodulo) {
            [$pantalla, $parte] = array_pad(explode('.', $submodulo, 2), 2, null);
            $reglas = $this->alcance->reglas();
            $ids = $parte ? ($reglas['partes'][$pantalla][$parte] ?? []) : ($reglas['pantallas'][$pantalla] ?? []);
        }

        $docs = ControlledDocument::query()->with('catalogEntry:id,modulo')
            ->when($modulo, fn ($q) => $q->whereHas('catalogEntry', fn ($c) => $c->where('modulo', $modulo)))
            ->when($ids !== null, fn ($q) => $q->whereIn('document_catalog_id', $ids))
            ->orderBy('codigo')->get(['id', 'codigo', 'titulo', 'estado', 'version_vigente', 'document_catalog_id']);
        if ($docs->isEmpty()) {
            return "## Documentos en el control documental\n\nTodavía no hay documentos".($modulo ? ' de este módulo' : '').' en el listado maestro.';
        }

        $porEstado = $docs->countBy('estado')->map(fn ($n, $e) => "{$n} ".str_replace('_', ' ', $e))->implode(', ');
        $lista = $docs->take(60)->map(fn ($d) => "- {$d->codigo} {$d->titulo} ({$d->estado}".($d->version_vigente ? ", v{$d->version_vigente}" : '').')')->implode("\n");

        return "## Documentos en el control documental\n\n{$docs->count()} documentos: {$porEstado}.\n\n{$lista}";
    }
}
