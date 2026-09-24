<?php

namespace Database\Seeders;

use App\Models\DocumentCatalogEntry;
use App\Models\DocumentTemplate;
use App\Models\Norm;
use App\Models\NormRequirement;
use Illuminate\Database\Seeder;

/**
 * Normas, requisitos y catálogo documental del SIG.
 *
 * Los datos salen del «Mapa Documental SIG» (Cristian Contreras, 23-sep-2026),
 * exportados a `data/sig-catalogo.json`: 62 requisitos con 180 referencias
 * exactas repartidas en 5 normas, y 227 documentos en 20 módulos. Son los
 * mismos números del informe; si cambian, cambió el JSON.
 *
 * Idempotente: se puede volver a correr para actualizar textos sin duplicar
 * ni romper los vínculos que ya hicieron las empresas.
 */
class SigCatalogSeeder extends Seeder
{
    /** Claves cortas del mapa → claves de `norms`. */
    private const CLAVES = ['sst' => 'sst', 'pesv' => 'pesv', 'i45' => 'iso45001', 'i9' => 'iso9001', 'i14' => 'iso14001'];

    private const NORMAS = [
        'sst' => ['nombre' => 'SG-SST', 'edicion' => 'Dec. 1072/2015 · Res. 0312/2019',
            'descripcion' => 'Decreto 1072 de 2015 (Libro 2, Parte 2, Título 4, Cap. 6) y estándares mínimos de la Resolución 0312 de 2019.'],
        'pesv' => ['nombre' => 'PESV', 'edicion' => 'Res. 40595/2022',
            'descripcion' => 'Plan Estratégico de Seguridad Vial, Resolución 40595 de 2022 del Ministerio de Transporte (24 pasos).'],
        'iso45001' => ['nombre' => 'ISO 45001', 'edicion' => '2018 + Enm. 1:2024',
            'descripcion' => 'Sistemas de gestión de la seguridad y salud en el trabajo. La enmienda de 2024 incorpora el cambio climático en 4.1 y 4.2.'],
        'iso9001' => ['nombre' => 'ISO 9001', 'edicion' => '2026',
            'descripcion' => 'Sistemas de gestión de la calidad, edición publicada el 16 de septiembre de 2026.'],
        'iso14001' => ['nombre' => 'ISO 14001', 'edicion' => '2026',
            'descripcion' => 'Sistemas de gestión ambiental, edición publicada el 15 de abril de 2026.'],
    ];

    /**
     * Plantilla de Documentos IA → documento del catálogo que redacta
     * (módulo, nombre). Las que no tienen equivalente en el catálogo (política
     * de seguridad vial aparte, fatiga, infractores y monitoreo vehicular) se
     * quedan sin enlace y, al enviarse al control documental, nacen como
     * documento propio.
     */
    private const PLANTILLAS = [
        'POL-SGI' => ['M01', 'Política integrada del SIG'],
        'MAN-SGSST' => ['M01', 'Manual del Sistema Integrado de Gestión'],
        'MAN-CONTROL-DOC' => ['M01', 'Procedimiento de control de documentos y registros'],
        'PR-REQ-LEG' => ['M03', 'Procedimiento para la identificación de requisitos legales'],
        'PR-IPERC' => ['M04', 'Procedimiento para la identificación de peligros y valoración de riesgos'],
        'FT-AUTOGESTION-PESV' => ['M05', 'Reporte anual de autogestión del PESV'],
        'MAN-FUNCIONES' => ['M07', 'Manual de funciones y perfiles de cargo'],
        'PR-PARTICIPACION' => ['M08', 'Procedimiento de comunicación, participación y consulta'],
        'PR-EMO' => ['M09', 'Procedimiento para la realización de exámenes médicos ocupacionales'],
        'FT-RECOM-MED' => ['M09', 'Reporte de restricciones médicas laborales'],
        'PR-IDONEIDAD' => ['M10', 'Procedimiento de selección y evaluación de conductores'],
        'PR-PLAN-VIAJES' => ['M10', 'Procedimiento de planificación de desplazamientos laborales'],
        'PL-EMERGENCIAS' => ['M11', 'Plan de preparación, prevención y respuesta ante emergencias'],
        'PR-COMPRAS' => ['M12', 'Procedimiento de adquisiciones y compras'],
        'PR-TERCEROS' => ['M12', 'Procedimiento de selección, evaluación y reevaluación de proveedores'],
        'PR-INV-ACC' => ['M13', 'Procedimiento para la investigación de incidentes, accidentes y enfermedades laborales'],
        'PR-INV-SINIESTROS' => ['M13', 'Procedimiento de reporte e investigación de siniestros viales'],
        'PR-AUDITORIA' => ['M14', 'Procedimiento de auditorías internas'],
        'PR-REV-DIRECCION' => ['M16', 'Procedimiento de revisión por la alta dirección'],
        'FT-ACTA-REV-DIR' => ['M16', 'Acta de revisión por la alta dirección'],
        'FT-RENDICION' => ['M16', 'Rendición de cuentas'],
        'PR-GESTION-CAMBIO' => ['M20', 'Procedimiento de gestión del cambio'],
    ];

    public function run(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/data/sig-catalogo.json'), true, flags: JSON_THROW_ON_ERROR);

        $normas = [];
        $orden = 0;
        foreach (self::NORMAS as $clave => $n) {
            $normas[$clave] = Norm::updateOrCreate(
                ['clave' => $clave, 'edicion' => $n['edicion']],
                ['nombre' => $n['nombre'], 'descripcion' => $n['descripcion'], 'vigente' => true, 'orden' => ++$orden],
            );
        }

        foreach ($data['requisitos'] as $i => $r) {
            $claveComun = sprintf('SIG-%02d', $i + 1);

            foreach ($r['referencias'] as $corta => $referencia) {
                NormRequirement::updateOrCreate(
                    ['norm_id' => $normas[self::CLAVES[$corta]]->id, 'clave_comun' => $claveComun],
                    [
                        'etapa' => $r['etapa'],
                        'referencia' => $referencia,
                        'titulo' => $r['titulo'],
                        'evidencia' => $r['evidencia'],
                        'modulo' => $r['modulo'],
                        'nota' => $r['nota'],
                        'orden' => $i + 1,
                    ],
                );
            }
        }

        foreach ($data['documentos'] as $i => $d) {
            DocumentCatalogEntry::updateOrCreate(
                ['modulo' => $d['modulo'], 'nombre' => $d['nombre']],
                [
                    'tipo' => $d['tipo'],
                    'sistemas' => array_map(fn ($s) => self::CLAVES[$s], $d['sistemas']),
                    'condicional' => $d['condicional'],
                    'codigo_referencia' => $d['codigo_referencia'],
                    'proceso' => $data['modulo_proceso'][$d['modulo']],
                    'orden' => $i + 1,
                ],
            );
        }

        // Solo las plantillas globales de CMK: las que sube cada empresa no
        // tienen un equivalente fijo en el catálogo.
        foreach (self::PLANTILLAS as $codigo => [$modulo, $nombre]) {
            $entrada = DocumentCatalogEntry::where('modulo', $modulo)->where('nombre', $nombre)->firstOrFail();
            DocumentTemplate::whereNull('tenant_id')->where('codigo', $codigo)->update(['document_catalog_id' => $entrada->id]);
        }
    }
}
