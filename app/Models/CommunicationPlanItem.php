<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la matriz de comunicaciones de una empresa. Segregada por tenant.
 */
class CommunicationPlanItem extends Model
{
    use BelongsToTenant;

    protected $table = 'communication_plan';

    protected $fillable = ['tipo', 'que', 'cuando', 'a_quien', 'como', 'responsable', 'sistemas', 'orden'];

    protected function casts(): array
    {
        return ['sistemas' => 'array'];
    }

    public const TIPOS = ['interna', 'externa'];

    /**
     * Matriz base para arrancar. Es un punto de partida genérico: cada
     * empresa ajusta responsables, medios y frecuencias, y agrega lo propio.
     * Los plazos legales de reporte se confirman contra la matriz legal de
     * la empresa antes de darlos por fijos.
     */
    public const BASE = [
        ['interna', 'Política integrada y objetivos del sistema', 'Al publicarse o cambiar, y en la inducción', 'Todos los trabajadores y contratistas', 'Inducción, carteleras y correo', 'Responsable del SIG', ['sst', 'pesv', 'iso45001', 'iso9001', 'iso14001']],
        ['interna', 'Peligros, riesgos y controles del cargo', 'En la inducción y cuando se actualice la matriz de peligros', 'Trabajadores expuestos', 'Inducción, charlas y fichas de cargo', 'Responsable del SG-SST', ['sst', 'iso45001']],
        ['interna', 'Resultados de indicadores y avance del plan de trabajo', 'Trimestral', 'Alta dirección y COPASST o vigía', 'Informe y reunión', 'Responsable del SG-SST', ['sst', 'pesv', 'iso45001', 'iso9001', 'iso14001']],
        ['interna', 'Rendición de cuentas del sistema', 'Anual', 'Todos los niveles de la empresa', 'Reunión y acta', 'Alta dirección', ['sst', 'pesv', 'iso45001']],
        ['interna', 'Actas y recomendaciones del COPASST o vigía', 'Mensual', 'Alta dirección', 'Acta de reunión', 'Presidente del COPASST o vigía', ['sst', 'iso45001']],
        ['interna', 'Cambios que afectan la seguridad y salud (gestión del cambio)', 'Antes de implementar el cambio', 'Trabajadores afectados y COPASST', 'Reunión y correo', 'Líder del proceso que cambia', ['sst', 'pesv', 'iso45001', 'iso9001', 'iso14001']],
        ['interna', 'Plan de emergencias, rutas de evacuación y simulacros', 'En la inducción y antes de cada simulacro', 'Trabajadores, brigada y visitantes', 'Inducción, señalización y carteleras', 'Coordinador de emergencias', ['sst', 'iso45001', 'iso14001']],
        ['interna', 'Reportes de actos y condiciones inseguras, quejas y sugerencias', 'Permanente', 'Responsable del SG-SST', 'Formato de reporte y buzón', 'Cualquier trabajador', ['sst', 'pesv', 'iso45001']],
        ['interna', 'Normas de seguridad vial, jornadas y pausas de conducción', 'En la inducción y al asignar un vehículo', 'Conductores y colaboradores que se desplazan', 'Inducción y capacitación', 'Líder del PESV', ['pesv']],
        ['externa', 'Reporte de accidentes de trabajo y enfermedades laborales', 'Dentro del plazo legal después del evento', 'ARL y EPS del trabajador', 'Formulario FURAT / FUREL', 'Responsable del SG-SST', ['sst', 'iso45001']],
        ['externa', 'Reporte anual de autogestión del PESV', 'Anual, según el calendario de la Supertransporte', 'Superintendencia de Transporte', 'Plataforma de la Supertransporte', 'Líder del PESV', ['pesv']],
        ['externa', 'Requisitos de SST, seguridad vial y ambientales para contratistas', 'Al contratar y en cada renovación', 'Contratistas y proveedores', 'Contrato, correo e inducción', 'Compras y responsable del SG-SST', ['sst', 'pesv', 'iso45001', 'iso14001']],
        ['externa', 'Requerimientos de autoridades, clientes y comunidad', 'Cuando se reciben', 'Parte interesada que consulta', 'El medio por el que llegó', 'Alta dirección o responsable asignado', ['sst', 'pesv', 'iso45001', 'iso9001', 'iso14001']],
    ];
}
