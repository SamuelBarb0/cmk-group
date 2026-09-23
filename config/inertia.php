<?php

/*
| Solo se sobrescribe lo que difiere del paquete. Las paginas de este
| proyecto viven en resources/js/pages (minuscula) y el default de Inertia es
| js/Pages: en Windows da igual, pero en el runner Linux del CI los tests que
| comprueban ->component() fallaban con «page component file does not exist».
|
| OJO: mergeConfigFrom mezcla solo el primer nivel, asi que el bloque
| `testing` tiene que ir completo.
*/

$paginas = [resource_path('js/pages')];
$extensiones = ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'];

return [

    'page_paths' => $paginas,

    'page_extensions' => $extensiones,

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => $paginas,
        'page_extensions' => $extensiones,
    ],

];
