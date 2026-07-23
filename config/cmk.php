<?php

/*
|--------------------------------------------------------------------------
| Datos corporativos e identidad de CMK GROUP S.A.S.
|--------------------------------------------------------------------------
| Fuente: "RESPUESTA A INSUMOS SOFTWARE CMK" (tono y datos de contacto).
| Se muestran en la plataforma y en los reportes PDF que genera el sistema.
*/

return [

    'company' => [
        'name'        => 'CMK GROUP',
        'legal_name'  => 'CMK GROUP S.A.S.',
        'nit'         => '901.959.302-4',
        'email'       => 'profesional02.cmk@gmail.com',
        'phones'      => ['+57 310 333 40 06', '+57 317 886 38 27'],
        'domain'      => 'cmkgroup.com',
        'addresses'   => [
            [
                'label'   => 'Valledupar',
                'line'    => 'Carrera 19A2 # 4-77, Sector Rincón de Rosales',
                'city'    => 'Valledupar, Cesar',
                'zip'     => '200005',
            ],
            [
                'label'   => 'Barranquilla',
                'line'    => 'Carrera 72 # 91 A - 100, Torre 6, Oficina 823, Barrio Villa Carolina',
                'city'    => 'Barranquilla, Atlántico',
                'zip'     => '080001',
            ],
        ],
    ],

    /*
    | Paleta de marca extraída del logo (azul marino + gris).
    | Se referencia también en resources/css/app.css.
    */
    'brand' => [
        'navy'      => '#16243F', // azul marino principal (letras CMK)
        'navy_deep' => '#0F1B30',
        'gray'      => '#6E7277', // gris del isotipo
        'gray_soft' => '#9AA0A6',
        'paper'     => '#F5F4F1', // fondo tipo papel del logo
    ],

    /*
    | Roles del sistema. Alineados con la Guía de Requerimientos + el rol
    | "Auditor" solicitado por CMK en la respuesta a insumos.
    | scope: cmk = personal de la consultora | client = usuario del cliente final
    */
    'roles' => [
        'consultor_admin' => [
            'label'  => 'Consultor administrador',
            'scope'  => 'cmk',
            'desc'   => 'Control total: gestiona clientes, usuarios, módulos, configuración y ve el dashboard maestro consolidado.',
        ],
        'consultor_operativo' => [
            'label'  => 'Consultor operativo',
            'scope'  => 'cmk',
            'desc'   => 'Ejecuta la consultoría: carga información, hallazgos y reportes de los clientes asignados.',
        ],
        'cliente_admin' => [
            'label'  => 'Cliente administrador',
            'scope'  => 'client',
            'desc'   => 'Responsable por parte del cliente. Gestiona la información y los usuarios de su empresa.',
        ],
        'cliente_usuario' => [
            'label'  => 'Cliente usuario',
            'scope'  => 'client',
            'desc'   => 'Usuario operativo del cliente. Consulta y diligencia información con permisos limitados.',
        ],
        'inspector' => [
            'label'  => 'Inspector (app móvil)',
            'scope'  => 'client',
            'desc'   => 'Inspecciones en campo desde la PWA: checklists, fotos con GPS, firmas e incidentes (offline).',
        ],
        'auditor' => [
            'label'  => 'Auditor',
            'scope'  => 'client',
            'desc'   => 'Solo consulta y evidencia documentos, indicadores e información auditable del cliente. Sin edición.',
        ],
    ],

    /*
    | Módulos técnicos del sector (Alcance A2 de la propuesta).
    */
    'modules' => [
        'sst'  => ['label' => 'SST',  'desc' => 'Matriz de peligros, plan anual, indicadores y accidentes.'],
        'hseq' => ['label' => 'HSEQ', 'desc' => 'Gestión ambiental, calidad y auditorías.'],
        'pesv' => ['label' => 'PESV', 'desc' => 'Gestión vial, conductores, vehículos y capacitaciones.'],
    ],

    /*
    | Módulos CONTRATABLES por empresa cliente: lo que CMK vende por contrato.
    | La clave coincide con la ruta/ítem del sidebar; tenants.modulos guarda
    | las claves contratadas (null = todos). Organización y Empleados son base
    | y siempre están habilitados (alimentan al resto).
    */
    'modulos_contratables' => [
        'diagnostico'   => 'Diagnóstico SG-SST (Res. 0312)',
        'iperc'         => 'Matriz IPERC (GTC 45)',
        'plan-trabajo'  => 'Plan de Trabajo Anual',
        'indicadores'   => 'Indicadores',
        'documentos'    => 'Documentos de la empresa',
        'documentos-ia' => 'Documentos IA',
        'sst'           => 'SST',
        'hseq'          => 'HSEQ',
        'pesv'          => 'PESV',
        'inspecciones'  => 'Inspecciones',
        'reportes'      => 'Reportes',
        'auditoria'     => 'Auditoría',
    ],
];
