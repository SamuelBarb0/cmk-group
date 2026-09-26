<?php

namespace App\Services\Clientes;

use App\Models\DocumentCatalogEntry;
use Illuminate\Support\Collection;

/**
 * Lo que un cliente contrató, dicho con el mapa documental del SIG: CMK elige
 * los documentos del catálogo (M01–M20, con el código del listado maestro) y
 * de ahí se deducen las pantallas y las partes de pantalla que la empresa ve
 * (config('cmk.alcance_documental')). Las herramientas que no salen del mapa
 * (config('cmk.herramientas')) se eligen aparte.
 *
 * Lo deducido se guarda en `tenants.modulos` y `tenants.submodulos`, que es
 * lo que ya revisan el middleware `module`, el menú y las pantallas.
 */
class AlcanceDocumental
{
    /** @var array{pantallas: array<string, list<int>>, partes: array<string, array<string, list<int>>>}|null */
    private ?array $reglas = null;

    /**
     * Ids del catálogo que enciende cada pantalla y cada parte.
     *
     * @return array{pantallas: array<string, list<int>>, partes: array<string, array<string, list<int>>>}
     */
    public function reglas(): array
    {
        if ($this->reglas !== null) {
            return $this->reglas;
        }

        $catalogo = DocumentCatalogEntry::query()->get(['id', 'modulo', 'nombre']);
        $ids = fn (array $reglas) => $this->idsQueCumplen($catalogo, $reglas);
        $config = config('cmk.alcance_documental');

        return $this->reglas = [
            'pantallas' => collect($config['pantallas'])->map($ids)->all(),
            'partes' => collect($config['partes'])->map(fn (array $partes) => collect($partes)->map($ids)->all())->all(),
        ];
    }

    /**
     * Pantallas y partes que corresponden a una selección de documentos.
     * Si se eligió todo el catálogo y todas las herramientas, todo queda en
     * null (= todo, incluido lo que se agregue después).
     *
     * @param  list<int>  $documentos  ids del catálogo
     * @param  list<string>  $herramientas
     * @return array{documentos_sig: ?list<int>, modulos: ?list<string>, submodulos: ?array<string, list<string>>}
     */
    public function deducir(array $documentos, array $herramientas): array
    {
        $documentos = array_values(array_unique(array_map('intval', $documentos)));
        sort($documentos);
        $elegidos = array_flip($documentos);
        $reglas = $this->reglas();
        $todasHerramientas = config('cmk.herramientas');

        if (count($documentos) === DocumentCatalogEntry::query()->count() && ! array_diff($todasHerramientas, $herramientas)) {
            return ['documentos_sig' => null, 'modulos' => null, 'submodulos' => null];
        }

        $tiene = fn (array $ids) => (bool) array_intersect_key(array_flip($ids), $elegidos);

        $modulos = array_values(array_intersect($todasHerramientas, $herramientas));
        foreach ($reglas['pantallas'] as $pantalla => $ids) {
            if ($tiene($ids)) {
                $modulos[] = $pantalla;
            }
        }

        $submodulos = [];
        foreach ($reglas['partes'] as $pantalla => $partes) {
            if (! in_array($pantalla, $modulos, true)) {
                continue;
            }
            $conDocs = array_keys(array_filter($partes, $tiene));
            // Encendida por un documento general, sin ninguno de sus partes: todas.
            if ($conDocs !== [] && count($conDocs) < count($partes)) {
                $submodulos[$pantalla] = $conDocs;
            }
        }

        return [
            'documentos_sig' => $documentos,
            'modulos' => $modulos,
            'submodulos' => $submodulos === [] ? null : $submodulos,
        ];
    }

    /**
     * El catálogo agrupado por módulo del mapa, para la ficha del cliente.
     *
     * @return list<array{modulo: string, nombre: string, documentos: list<array{id: int, tipo: string, nombre: string, codigo: ?string, condicional: bool}>}>
     */
    public function catalogo(): array
    {
        return DocumentCatalogEntry::query()->orderBy('modulo')->orderBy('orden')->orderBy('id')
            ->get(['id', 'modulo', 'tipo', 'nombre', 'codigo_referencia', 'condicional'])
            ->groupBy('modulo')
            ->map(fn (Collection $docs, string $m) => [
                'modulo' => $m,
                'nombre' => DocumentCatalogEntry::MODULOS[$m] ?? $m,
                'documentos' => $docs->map(fn (DocumentCatalogEntry $d) => [
                    'id' => $d->id, 'tipo' => $d->tipo, 'nombre' => $d->nombre,
                    'codigo' => $d->codigo_referencia, 'condicional' => (bool) $d->condicional,
                ])->values()->all(),
            ])->values()->all();
    }

    /**
     * @param  Collection<int, DocumentCatalogEntry>  $catalogo
     * @param  list<array{0: string, 1: list<string>}>  $reglas
     * @return list<int>
     */
    private function idsQueCumplen(Collection $catalogo, array $reglas): array
    {
        return $catalogo->filter(function (DocumentCatalogEntry $d) use ($reglas) {
            foreach ($reglas as [$modulo, $fragmentos]) {
                if ($d->modulo !== $modulo) {
                    continue;
                }
                foreach ($fragmentos as $f) {
                    if ($f === '*' || str_contains(mb_strtolower($d->nombre), mb_strtolower($f))) {
                        return true;
                    }
                }
            }

            return false;
        })->pluck('id')->values()->all();
    }
}
