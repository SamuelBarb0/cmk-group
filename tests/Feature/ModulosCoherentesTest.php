<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Coherencia entre las tres listas de módulos que tiene la plataforma.
 *
 * Hay tres sitios que hablan de «módulos» y nada los ataba:
 *   1. El middleware `module:<slug>` de cada ruta.
 *   2. El catálogo `cmk.modulos_contratables`, que es lo que CMK le vende al
 *      cliente y lo que se marca en la ficha de Clientes.
 *   3. La barra lateral, que oculta lo que el cliente no contrató.
 *
 * Si un módulo tiene ruta pero NO está en el catálogo, pasa algo que no se ve
 * en las pruebas normales: funciona con los clientes que tienen `modulos = null`
 * (todos habilitados) y es **imposible de contratar** para cualquier otro,
 * porque la ficha de Clientes ni siquiera lo lista. Pasó con los siete módulos
 * de las etapas 3 y 4.
 */
class ModulosCoherentesTest extends TestCase
{
    /** @return list<string> Slugs usados en el middleware `module:` de las rutas. */
    private function slugsEnRutas(): array
    {
        $slugs = [];

        foreach (Route::getRoutes() as $ruta) {
            foreach ($ruta->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'module:')) {
                    $slugs[] = substr($middleware, strlen('module:'));
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    public function test_todo_modulo_con_ruta_se_puede_contratar(): void
    {
        $catalogo = array_keys(config('cmk.modulos_contratables'));

        $huerfanos = array_diff($this->slugsEnRutas(), $catalogo);

        $this->assertSame([], array_values($huerfanos),
            'Estos módulos tienen ruta pero no están en cmk.modulos_contratables, '.
            'así que ningún cliente con módulos restringidos podría usarlos: '.implode(', ', $huerfanos));
    }

    public function test_todo_modulo_contratable_tiene_a_donde_llevar(): void
    {
        $catalogo = array_keys(config('cmk.modulos_contratables'));

        $sinRuta = array_diff($catalogo, $this->slugsEnRutas());

        $this->assertSame([], array_values($sinRuta),
            'Estos módulos se pueden contratar pero ninguna ruta los usa, '.
            'así que el cliente pagaría por algo que no existe: '.implode(', ', $sinRuta));
    }

    public function test_el_menu_solo_apunta_a_modulos_del_catalogo(): void
    {
        $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));
        preg_match_all("/module: '([a-z0-9-]+)'/", $sidebar, $m);

        $huerfanos = array_diff(array_unique($m[1]), array_keys(config('cmk.modulos_contratables')));

        $this->assertSame([], array_values($huerfanos),
            'El menú referencia módulos que no están en el catálogo: '.implode(', ', $huerfanos));
    }

    /**
     * Cada ítem del menú tiene que llevar a una ruta que exista. Un enlace a una
     * URI sin ruta le da un 404 al consultor delante del cliente.
     */
    public function test_ningun_item_del_menu_lleva_a_una_ruta_inexistente(): void
    {
        $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));
        preg_match_all("/url: '\/([a-z0-9-]+)'/", $sidebar, $m);

        $rutasGet = [];
        foreach (Route::getRoutes() as $ruta) {
            if (in_array('GET', $ruta->methods(), true)) {
                $rutasGet[] = $ruta->uri();
            }
        }

        $rotos = array_diff(array_unique($m[1]), $rutasGet);

        $this->assertSame([], array_values($rotos),
            'El menú apunta a URIs sin ruta: '.implode(', ', $rotos));
    }
}
