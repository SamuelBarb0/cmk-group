<?php

namespace Database\Seeders;

use App\Models\TrainingTopic;
use Illuminate\Database\Seeder;

/**
 * Biblioteca inicial de temas de capacitación (las 14 presentaciones modelo de
 * CMK). Los archivos viven en storage/app/private/capacitaciones/{codigo}.pptx.
 * Si el archivo no está en disco, el tema igual aparece (sin botón de descarga).
 */
class TrainingTopicsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->temas() as $t) {
            TrainingTopic::updateOrCreate(['codigo' => $t['codigo']], $t);
        }
    }

    private function temas(): array
    {
        $base = 'capacitaciones/';

        return [
            ['codigo' => 'CAP-COPASST', 'titulo' => 'Capacitación COPASST', 'categoria' => 'SST', 'orden' => 10,
                'duracion_sugerida' => 60, 'archivo' => $base.'copasst.pptx',
                'descripcion' => 'Conformación, funciones y responsabilidades del Comité Paritario de Seguridad y Salud en el Trabajo.'],
            ['codigo' => 'CAP-COMITE-COPASST', 'titulo' => 'Comité COPASST', 'categoria' => 'SST', 'orden' => 20,
                'duracion_sugerida' => 60, 'archivo' => $base.'comite-copasst.pptx',
                'descripcion' => 'Funcionamiento del comité COPASST: reuniones, actas y seguimiento.'],
            ['codigo' => 'CAP-CONVIVENCIA', 'titulo' => 'Comité de Convivencia Laboral', 'categoria' => 'SST', 'orden' => 30,
                'duracion_sugerida' => 60, 'archivo' => $base.'comite-convivencia.pptx',
                'descripcion' => 'Prevención del acoso laboral y funciones del Comité de Convivencia Laboral.'],
            ['codigo' => 'CAP-5S', 'titulo' => 'Metodología 5S', 'categoria' => 'SST', 'orden' => 40,
                'duracion_sugerida' => 45, 'archivo' => $base.'cinco-s.pptx',
                'descripcion' => 'Orden, clasificación y limpieza en el puesto de trabajo (Seiri, Seiton, Seiso, Seiketsu, Shitsuke).'],
            ['codigo' => 'CAP-EPP', 'titulo' => 'Elementos de Protección Personal (EPP)', 'categoria' => 'SST', 'orden' => 50,
                'duracion_sugerida' => 45, 'archivo' => $base.'epp.pptx',
                'descripcion' => 'Uso, cuidado y reposición correcta de los elementos de protección personal según el riesgo.'],
            ['codigo' => 'CAP-RIESGOS', 'titulo' => 'Riesgos Asociados al Trabajo', 'categoria' => 'SST', 'orden' => 60,
                'duracion_sugerida' => 60, 'archivo' => $base.'riesgos-trabajo.pptx',
                'descripcion' => 'Identificación de peligros y prevención de los riesgos presentes en las actividades laborales.'],
            ['codigo' => 'CAP-CARGAS', 'titulo' => 'Manejo de Cargas e Higiene Postural', 'categoria' => 'SST', 'orden' => 70,
                'duracion_sugerida' => 45, 'archivo' => $base.'manejo-cargas.ppt',
                'descripcion' => 'Técnicas seguras de levantamiento de cargas y prevención del riesgo biomecánico.'],
            ['codigo' => 'CAP-PRIMEROS-AUX', 'titulo' => 'Primeros Auxilios', 'categoria' => 'SST', 'orden' => 80,
                'duracion_sugerida' => 90, 'archivo' => $base.'primeros-auxilios.pptx',
                'descripcion' => 'Atención inicial de emergencias: valoración, RCP básico y actuación ante lesiones.'],
            ['codigo' => 'CAP-INV-ACC', 'titulo' => 'Investigación de Accidentes', 'categoria' => 'SST', 'orden' => 90,
                'duracion_sugerida' => 60, 'archivo' => $base.'investigacion-accidentes.pptx',
                'descripcion' => 'Metodología de investigación de accidentes e incidentes de trabajo y acciones correctivas.'],
            ['codigo' => 'CAP-FATIGA', 'titulo' => 'Fatiga y Sueño', 'categoria' => 'SST', 'orden' => 100,
                'duracion_sugerida' => 45, 'archivo' => $base.'fatiga-sueno.pptx',
                'descripcion' => 'Prevención de la fatiga laboral, higiene del sueño y su impacto en la seguridad.'],
            ['codigo' => 'CAP-INDICADORES', 'titulo' => 'Indicadores de Gestión', 'categoria' => 'SST', 'orden' => 110,
                'duracion_sugerida' => 45, 'archivo' => $base.'indicadores-gestion.pptx',
                'descripcion' => 'Interpretación de los indicadores del SG-SST (frecuencia, severidad, ausentismo, cobertura).'],
            ['codigo' => 'CAP-SEG-VIAL', 'titulo' => 'Comité de Seguridad Vial', 'categoria' => 'PESV', 'orden' => 120,
                'duracion_sugerida' => 60, 'archivo' => $base.'comite-seguridad-vial.pptx',
                'descripcion' => 'Conformación y funciones del comité de seguridad vial dentro del PESV.'],
            ['codigo' => 'CAP-SENAL-VIAL', 'titulo' => 'Seguridad y Señalización Vial', 'categoria' => 'PESV', 'orden' => 130,
                'duracion_sugerida' => 60, 'archivo' => $base.'senalizacion-vial.pptx',
                'descripcion' => 'Normas de tránsito, señalización y conducción segura para actores viales.'],
            ['codigo' => 'CAP-PONS', 'titulo' => 'Presentación PONS (Plan de Seguridad Vial)', 'categoria' => 'PESV', 'orden' => 140,
                'duracion_sugerida' => 60, 'archivo' => $base.'pons.pptx',
                'descripcion' => 'Lineamientos del Plan Estratégico de Seguridad Vial y su implementación.'],
        ];
    }
}
