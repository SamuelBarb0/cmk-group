<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Parte interesada con sus necesidades y expectativas (ISO 4.2). Segregada
 * por tenant. `es_requisito` marca las necesidades que la empresa adopta como
 * requisito u obligación de cumplimiento: esas pasan a la matriz legal o a los
 * requisitos del cliente, no se quedan en esta matriz.
 */
class InterestedParty extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'nombre', 'tipo', 'categoria', 'necesidades', 'expectativas', 'es_requisito',
        'influencia', 'interes', 'como_se_atiende', 'cambio_climatico', 'sistemas', 'orden',
    ];

    protected function casts(): array
    {
        return ['sistemas' => 'array', 'es_requisito' => 'boolean', 'cambio_climatico' => 'boolean'];
    }

    public const TIPOS = ['interna', 'externa'];

    public const CATEGORIAS = [
        'direccion' => 'Alta dirección y socios',
        'trabajadores' => 'Trabajadores',
        'representantes' => 'Comités y representantes (COPASST, convivencia, seguridad vial)',
        'contratistas' => 'Contratistas y subcontratistas',
        'clientes' => 'Clientes',
        'proveedores' => 'Proveedores',
        'autoridades' => 'Autoridades y entes de control',
        'seguridad_social' => 'ARL, EPS y seguridad social',
        'comunidad' => 'Comunidad y vecinos',
        'otros' => 'Otros',
    ];

    public const NIVELES = ['alta', 'media', 'baja'];

    public const NIVELES_INTERES = ['alto', 'medio', 'bajo'];

    /**
     * Estrategia de relación según influencia e interés (matriz de poder e
     * interés): la que más pesa manda cómo se atiende a la parte.
     */
    public function estrategia(): string
    {
        $influye = $this->influencia === 'alta';
        $leImporta = $this->interes === 'alto';

        return match (true) {
            $influye && $leImporta => 'Gestionar de cerca',
            $influye => 'Mantener satisfecha',
            $leImporta => 'Mantener informada',
            default => 'Monitorear',
        };
    }

    /**
     * Partes interesadas típicas para arrancar. Punto de partida genérico: cada
     * empresa borra las que no le aplican, ajusta necesidades y agrega las
     * suyas. Las obligaciones legales se confirman contra su matriz legal.
     *
     * [nombre, tipo, categoria, necesidades, expectativas, es_requisito, influencia, interes, como_se_atiende, sistemas]
     */
    public const BASE = [
        ['Alta dirección y socios', 'interna', 'direccion', 'Cumplimiento legal, sostenibilidad del negocio y control de los riesgos.', 'Resultados medibles del sistema y reducción de costos por accidentes e incidentes.', false, 'alta', 'alto', 'Revisión por la dirección e informes periódicos.', ['sst', 'pesv', 'iso45001', 'iso9001', 'iso14001']],
        ['Trabajadores', 'interna', 'trabajadores', 'Condiciones de trabajo seguras y saludables, capacitación, elementos de protección y pago de la seguridad social.', 'Participar en las decisiones de SST y un buen clima laboral.', true, 'media', 'alto', 'Inducción, capacitaciones, COPASST y buzón de sugerencias.', ['sst', 'pesv', 'iso45001']],
        ['COPASST o vigía y Comité de Convivencia Laboral', 'interna', 'representantes', 'Tiempo, recursos y respaldo para sesionar y hacer seguimiento.', 'Que sus recomendaciones se atiendan.', true, 'media', 'alto', 'Reuniones periódicas y actas con seguimiento.', ['sst', 'iso45001']],
        ['Contratistas y subcontratistas', 'externa', 'contratistas', 'Conocer los requisitos de SST, seguridad vial y ambientales para trabajar en la empresa.', 'Reglas claras y pagos oportunos.', true, 'media', 'medio', 'Manual de contratistas, inducción y evaluación periódica.', ['sst', 'pesv', 'iso45001', 'iso14001']],
        ['Clientes', 'externa', 'clientes', 'Productos o servicios conformes, a tiempo y al precio pactado.', 'Buena atención y respuesta rápida a sus solicitudes.', true, 'alta', 'alto', 'Revisión de requisitos, atención de PQRS y encuesta de satisfacción.', ['iso9001']],
        ['Proveedores', 'externa', 'proveedores', 'Especificaciones claras de compra y pagos oportunos.', 'Relación comercial estable.', false, 'media', 'medio', 'Órdenes de compra y evaluación de proveedores.', ['iso45001', 'iso9001', 'iso14001']],
        ['Ministerio del Trabajo', 'externa', 'autoridades', 'Cumplimiento del SG-SST (Dec. 1072 de 2015 y Res. 0312 de 2019).', 'Reportes e información a tiempo.', true, 'alta', 'medio', 'Autoevaluación de estándares mínimos y atención de requerimientos.', ['sst', 'iso45001']],
        ['Superintendencia de Transporte', 'externa', 'autoridades', 'Implementación y reporte del PESV (Res. 40595 de 2022).', 'Reporte anual de autogestión en las fechas fijadas.', true, 'alta', 'medio', 'Reporte de autogestión y atención de requerimientos.', ['pesv']],
        ['Autoridad ambiental', 'externa', 'autoridades', 'Cumplimiento de permisos, gestión de residuos y vertimientos que apliquen.', 'Reportes e información a tiempo.', true, 'alta', 'medio', 'Matriz legal ambiental y atención de visitas.', ['iso14001']],
        ['ARL', 'externa', 'seguridad_social', 'Reporte oportuno de accidentes y enfermedades laborales.', 'Participación en la asesoría y los programas de prevención.', true, 'media', 'medio', 'Reportes (FURAT/FUREL) y plan de trabajo con la ARL.', ['sst', 'iso45001']],
        ['Comunidad y vecinos', 'externa', 'comunidad', 'Que la operación no afecte su seguridad ni el ambiente.', 'Canales para quejas y respuesta a ellas.', false, 'baja', 'medio', 'Atención de quejas y comunicación de emergencias.', ['sst', 'pesv', 'iso45001', 'iso14001']],
    ];
}
