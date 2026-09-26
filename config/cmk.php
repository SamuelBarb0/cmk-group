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
        'name' => 'CMK GROUP',
        'legal_name' => 'CMK GROUP S.A.S.',
        'nit' => '901.959.302-4',
        'email' => 'profesional02.cmk@gmail.com',
        'phones' => ['+57 310 333 40 06', '+57 317 886 38 27'],
        'domain' => 'cmkgroup.com',
        'addresses' => [
            [
                'label' => 'Valledupar',
                'line' => 'Carrera 19A2 # 4-77, Sector Rincón de Rosales',
                'city' => 'Valledupar, Cesar',
                'zip' => '200005',
            ],
            [
                'label' => 'Barranquilla',
                'line' => 'Carrera 72 # 91 A - 100, Torre 6, Oficina 823, Barrio Villa Carolina',
                'city' => 'Barranquilla, Atlántico',
                'zip' => '080001',
            ],
        ],
    ],

    /*
    | Paleta de marca extraída del logo (azul marino + gris).
    | Se referencia también en resources/css/app.css.
    */
    'brand' => [
        'navy' => '#16243F', // azul marino principal (letras CMK)
        'navy_deep' => '#0F1B30',
        'gray' => '#6E7277', // gris del isotipo
        'gray_soft' => '#9AA0A6',
        'paper' => '#F5F4F1', // fondo tipo papel del logo
    ],

    /*
    | Roles del sistema. Alineados con la Guía de Requerimientos + el rol
    | "Auditor" solicitado por CMK en la respuesta a insumos.
    | scope: cmk = personal de la consultora | client = usuario del cliente final
    */
    'roles' => [
        'consultor_admin' => [
            'label' => 'Consultor administrador',
            'scope' => 'cmk',
            'desc' => 'Control total: gestiona clientes, usuarios, módulos, configuración y ve el dashboard maestro consolidado.',
        ],
        'consultor_operativo' => [
            'label' => 'Consultor operativo',
            'scope' => 'cmk',
            'desc' => 'Ejecuta la consultoría: carga información, hallazgos y reportes de los clientes asignados.',
        ],
        'cliente_admin' => [
            'label' => 'Cliente administrador',
            'scope' => 'client',
            'desc' => 'Responsable por parte del cliente. Gestiona la información y los usuarios de su empresa.',
        ],
        'cliente_usuario' => [
            'label' => 'Cliente usuario',
            'scope' => 'client',
            'desc' => 'Usuario operativo del cliente. Consulta y diligencia información con permisos limitados.',
        ],
        'inspector' => [
            'label' => 'Inspector (app móvil)',
            'scope' => 'client',
            'desc' => 'Inspecciones en campo desde la PWA: checklists, fotos con GPS, firmas e incidentes (offline).',
        ],
        'auditor' => [
            'label' => 'Auditor',
            'scope' => 'client',
            'desc' => 'Solo consulta y evidencia documentos, indicadores e información auditable del cliente. Sin edición.',
        ],
    ],

    /*
    | Módulos CONTRATABLES por empresa cliente: lo que CMK vende por contrato.
    | La clave coincide con la ruta/ítem del sidebar; tenants.modulos guarda
    | las claves contratadas (null = todos). Organización y Empleados son base
    | y siempre están habilitados (alimentan al resto).
    */
    'modulos_contratables' => [
        'diagnostico' => 'Diagnóstico SG-SST (Res. 0312)',
        'pesv' => 'PESV (Res. 40595)',
        'iperc' => 'Matriz IPERC (GTC 45)',
        'plan-trabajo' => 'Plan de Trabajo Anual',
        'indicadores' => 'Indicadores',
        'capacitaciones' => 'Capacitaciones',
        'control-documental' => 'Control documental (listado maestro, versiones y normas del SIG)',
        'documentos' => 'Documentos de la empresa',
        'documentos-ia' => 'Documentos IA',
        'inspecciones' => 'Formatos (inspecciones y actas)',
        'reportes' => 'Reportes',
        'auditoria' => 'Auditoría',

        // Seguimiento de lo que ocurre y de lo que hay que hacer con ello.
        'requisitos-legales' => 'Matriz de requisitos legales',
        'acpm' => 'ACPM (acciones correctivas y de mejora)',
        'reportes-ac' => 'Reportes de actos y condiciones inseguras',
        'accidentes' => 'Accidentalidad e investigación (Res. 1401)',
        'ausentismo' => 'Ausentismo laboral',
        'comites' => 'Comités (COPASST y convivencia laboral)',
        'epp' => 'EPP (matriz por cargo y entregas)',
        'emergencias' => 'Plan de emergencias y brigada',
        'salud-ocupacional' => 'Salud ocupacional (profesiograma y exámenes médicos)',
        'programas' => 'Programas de gestión (PVE, alcohol, fatiga, seguridad vial, ambiental)',
        'mantenimiento' => 'Mantenimiento de activos (máquinas, equipos, instalaciones y vehículos)',
        'contratistas' => 'Contratistas y proveedores (selección, requisitos SST y evaluación)',
        'importar' => 'Importación asistida por IA (Excel del cliente a los módulos)',
        'gestion-cambio' => 'Gestión del cambio',
        'revision-direccion' => 'Revisión por la dirección',
        'comunicaciones' => 'Comunicaciones (matriz y registro)',
        'contexto' => 'Contexto de la organización (DOFA / PESTEL, partes interesadas, alcance y procesos)',
        'riesgos-oportunidades' => 'Riesgos y oportunidades de los procesos',
        'aspectos-ambientales' => 'Aspectos e impactos ambientales (ISO 14001)',
        'cargos' => 'Perfiles de cargo y matriz de competencias',
        'equipos-medicion' => 'Equipos de medición (calibración y verificación)',
        'calidad' => 'Calidad ISO 9001 (PQRS, salidas no conformes y satisfacción del cliente)',
        'ambiental' => 'Gestión ambiental ISO 14001 (residuos y RESPEL, consumos, productos químicos)',
    ],

    /*
    | Partes de un módulo que se contratan por separado. Cada parte lista los
    | nombres de ruta que la componen (comodines de Str::is): el middleware
    | `module` bloquea la ruta si la empresa tiene el módulo pero no esa parte.
    | tenants.submodulos guarda {modulo: [partes]}; un módulo ausente = todas
    | sus partes. Lo que no está en ninguna parte (el índice, lo común del
    | módulo) va con el módulo. Una parte sin rutas se controla en su
    | controlador (comités: las dos van por las mismas rutas).
    */
    'submodulos' => [
        'contexto' => [
            'alcance' => ['nombre' => 'Alcance y perfil de la organización', 'rutas' => ['contexto.perfil', 'contexto.revisado']],
            'dofa' => ['nombre' => 'DOFA y PESTEL', 'rutas' => ['contexto.cuestiones.*']],
            'partes' => ['nombre' => 'Partes interesadas', 'rutas' => ['contexto.partes.*']],
            'procesos' => ['nombre' => 'Mapa y caracterización de procesos', 'rutas' => ['contexto.procesos.*']],
        ],
        'pesv' => [
            'conductores' => ['nombre' => 'Conductores: requisitos y pruebas', 'rutas' => ['pesv.conductores.*', 'pesv.pruebas.*']],
            'vehiculos' => ['nombre' => 'Vehículos y su hoja de vida', 'rutas' => ['pesv.vehiculos.*']],
            'rutas' => ['nombre' => 'Rutas y planes de viaje', 'rutas' => ['pesv.rutas.*']],
            'siniestros' => ['nombre' => 'Siniestros viales y análisis estadístico', 'rutas' => ['pesv.siniestros.*', 'pesv.estadistica.*']],
            'vias' => ['nombre' => 'Vías internas', 'rutas' => ['pesv.vias.*']],
            'encuesta' => ['nombre' => 'Encuesta de movilidad', 'rutas' => ['pesv.encuesta.*']],
            'riesgos' => ['nombre' => 'Matriz de riesgos viales', 'rutas' => ['pesv.riesgos.*']],
            'documentos' => ['nombre' => 'Semáforo de documentos', 'rutas' => ['pesv.documentos.*']],
            'infracciones' => ['nombre' => 'Infracciones de tránsito', 'rutas' => ['pesv.infracciones.*']],
            'autogestion' => ['nombre' => 'Reporte de autogestión', 'rutas' => ['pesv.autogestion.*']],
        ],
        'comites' => [
            'copasst' => ['nombre' => 'COPASST (o vigía)', 'rutas' => []],
            'cocolab' => ['nombre' => 'Comité de convivencia laboral', 'rutas' => []],
        ],
        'epp' => [
            'matriz' => ['nombre' => 'Matriz de EPP por cargo', 'rutas' => ['epp.matriz.*']],
            'entregas' => ['nombre' => 'Entregas y reposiciones', 'rutas' => ['epp.entregas.*']],
        ],
        'emergencias' => [
            'brigada' => ['nombre' => 'Brigada de emergencias', 'rutas' => ['emergencias.brigada.*']],
            'simulacros' => ['nombre' => 'Simulacros', 'rutas' => ['emergencias.simulacros.*']],
            'equipos' => ['nombre' => 'Equipos de emergencia', 'rutas' => ['emergencias.equipos.*']],
            'directorio' => ['nombre' => 'Directorio de emergencias', 'rutas' => ['emergencias.directorio.*']],
        ],
        'salud-ocupacional' => [
            'examenes' => ['nombre' => 'Exámenes médicos ocupacionales', 'rutas' => ['salud-ocupacional.examenes.*']],
            'profesiograma' => ['nombre' => 'Profesiograma', 'rutas' => ['salud-ocupacional.perfiles.*']],
        ],
        'comunicaciones' => [
            'matriz' => ['nombre' => 'Matriz de comunicaciones', 'rutas' => ['comunicaciones.matriz.*', 'comunicaciones.base']],
            'registro' => ['nombre' => 'Registro de comunicaciones', 'rutas' => ['comunicaciones.registro.*']],
        ],
        'calidad' => [
            'pqrs' => ['nombre' => 'PQRS', 'rutas' => ['calidad.pqrs.*']],
            'salidas' => ['nombre' => 'Salidas no conformes', 'rutas' => ['calidad.salidas.*']],
            'satisfaccion' => ['nombre' => 'Satisfacción del cliente', 'rutas' => ['calidad.encuestas.*']],
        ],
        'ambiental' => [
            'residuos' => ['nombre' => 'Residuos y RESPEL', 'rutas' => ['ambiental.residuos.*']],
            'consumos' => ['nombre' => 'Consumos de agua y energía', 'rutas' => ['ambiental.lecturas.*']],
            'quimicos' => ['nombre' => 'Productos químicos', 'rutas' => ['ambiental.quimicos.*']],
        ],
    ],

    /*
    | Módulos de la plataforma que NO salen del mapa documental: herramientas
    | que CMK vende aparte. En la ficha del cliente se marcan a mano.
    */
    'herramientas' => ['documentos', 'documentos-ia', 'reportes', 'importar'],

    /*
    | Qué documentos del mapa documental del SIG (catálogo M01–M20) encienden
    | cada pantalla y cada parte de pantalla. Cuando CMK elige para un cliente
    | los documentos que necesita, las pantallas y partes se deducen de aquí:
    | una pantalla se ve si el cliente tiene al menos uno de sus documentos.
    | Cada regla es [módulo del mapa, [fragmentos del nombre]]; '*' = todo el
    | módulo. Los fragmentos se buscan sin distinguir mayúsculas.
    */
    'alcance_documental' => [
        'pantallas' => [
            'control-documental' => [['M01', ['*']]],
            'contexto' => [['M02', ['*']]],
            'requisitos-legales' => [['M03', ['*']]],
            'iperc' => [['M04', ['peligros', 'medidas de prevención']]],
            'reportes-ac' => [['M04', ['actos y condiciones']]],
            'riesgos-oportunidades' => [['M04', ['riesgos y oportunidades']]],
            'aspectos-ambientales' => [['M04', ['aspectos e impactos']]],
            'indicadores' => [['M05', ['indicador', 'objetivos']]],
            'diagnostico' => [['M05', ['evaluación inicial', 'autoevaluación']]],
            'ausentismo' => [['M05', ['ausentismo']]],
            'plan-trabajo' => [['M06', ['*']]],
            'cargos' => [['M07', ['responsabilidades', 'funciones', 'competencia', 'conocimiento', 'reglamento interno', 'listado de trabajadores']]],
            'capacitaciones' => [['M07', ['capacitación', 'inducción', 'curso de 50']]],
            'comites' => [['M08', ['copasst', 'vigía', 'convivencia', 'conflicto', 'compromisos', 'acoso', 'participación', 'responsable del sg-sst', 'buzón']]],
            'pesv' => [
                ['M10', ['*']],
                ['M08', ['seguridad vial', 'líder del pesv']],
                ['M04', ['riesgos viales']],
                ['M05', ['autogestión del pesv']],
                ['M11', ['emergencias viales']],
                ['M13', ['siniestro']],
            ],
            'epp' => [['M09', ['protección personal', 'epp']]],
            'salud-ocupacional' => [['M09', ['médic', 'profesiograma', 'paraclínicos', 'condiciones de salud', 'sociodemográfico', 'restricciones']]],
            'inspecciones' => [
                ['M09', ['inspección', 'inspecciones', 'permiso de trabajo', 'permisos de trabajo', 'análisis de trabajo seguro', 'visitantes', 'tarea segura', 'reglamento de higiene', 'trabajo seguro en alturas']],
                ['M19', ['acta de reunión', 'registro de asistencia']],
            ],
            'programas' => [['M09', ['programa de']], ['M10', ['programa de']], ['M18', ['programa de']]],
            'mantenimiento' => [['M09', ['mantenimiento']], ['M10', ['mantenimiento']]],
            'equipos-medicion' => [['M09', ['equipos de seguimiento', 'calibración']]],
            'emergencias' => [['M11', ['*']]],
            'contratistas' => [['M12', ['*']]],
            'accidentes' => [['M13', ['accidente', 'incidentes', 'furat', 'accidentalidad']]],
            'auditoria' => [['M14', ['*']]],
            'acpm' => [['M15', ['*']]],
            'revision-direccion' => [['M16', ['*']]],
            'calidad' => [['M17', ['*']]],
            'ambiental' => [['M18', ['*']], ['M13', ['incidente ambiental']]],
            'comunicaciones' => [['M19', ['*']]],
            'gestion-cambio' => [['M20', ['*']]],
        ],
        // Partes de pantalla (config('cmk.submodulos')). Una pantalla encendida
        // sin ninguna parte que coincida se queda con todas sus partes.
        'partes' => [
            'contexto' => [
                'alcance' => [['M02', ['alcance']]],
                'dofa' => [['M02', ['dofa', 'análisis de contexto']]],
                'partes' => [['M02', ['partes interesadas']]],
                'procesos' => [['M02', ['mapa de procesos', 'caracterización']]],
            ],
            'pesv' => [
                'conductores' => [['M10', ['conductor', 'licencia', 'alcoholimetría', 'prueba teórica']]],
                'vehiculos' => [['M10', ['vehículo', 'preoperacional', 'flota', 'mantenimiento vehicular']]],
                'rutas' => [['M10', ['desplazamientos', 'rutas', 'jornada']]],
                'siniestros' => [['M13', ['siniestro']]],
                'vias' => [['M10', ['vías internas']]],
                'encuesta' => [['M10', ['planificación de desplazamientos']]],
                'riesgos' => [['M04', ['riesgos viales']]],
                'documentos' => [['M10', ['hoja de vida']]],
                'infracciones' => [['M10', ['comparendos']]],
                'autogestion' => [['M05', ['autogestión del pesv']]],
            ],
            'comites' => [
                'copasst' => [['M08', ['copasst', 'vigía']]],
                'cocolab' => [['M08', ['convivencia', 'conflicto', 'compromisos', 'acoso']]],
            ],
            'epp' => [
                'matriz' => [['M09', ['matriz de elementos']]],
                'entregas' => [['M09', ['entrega de epp']]],
            ],
            'emergencias' => [
                'brigada' => [['M11', ['brigad']]],
                'simulacros' => [['M11', ['simulacro']]],
                'equipos' => [['M11', ['extintores', 'botiquines', 'contraincendios', 'derrames']]],
                'directorio' => [['M11', ['plan de preparación', 'información personal']]],
            ],
            'salud-ocupacional' => [
                'examenes' => [['M09', ['exámenes médicos', 'examen médico', 'paraclínicos', 'restricciones']]],
                'profesiograma' => [['M09', ['profesiograma']]],
            ],
            'comunicaciones' => [
                'matriz' => [['M19', ['matriz de comunicaciones', 'procedimiento de comunicación']]],
                'registro' => [['M19', ['registro', 'acta de reunión']]],
            ],
            'calidad' => [
                'pqrs' => [['M17', ['pqrs']]],
                'salidas' => [['M17', ['salidas no conformes']]],
                'satisfaccion' => [['M17', ['satisfacción']]],
            ],
            'ambiental' => [
                'residuos' => [['M18', ['residuos', 'respel', 'disposición final']]],
                'consumos' => [['M18', ['agua', 'energía']]],
                'quimicos' => [['M18', ['químic', 'datos de seguridad']]],
            ],
        ],
    ],
];
