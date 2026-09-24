<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Documento del catálogo de referencia del SIG (GLOBAL): los 227 documentos
 * del Anexo A del informe, con el módulo, los sistemas que evidencia y el
 * código que tenía en el listado maestro FT-SST-034.
 *
 * No es un documento de ninguna empresa: es el molde con el que cada empresa
 * arma su listado maestro.
 */
class DocumentCatalogEntry extends Model
{
    protected $table = 'document_catalog';

    protected $fillable = ['modulo', 'tipo', 'nombre', 'sistemas', 'condicional', 'codigo_referencia', 'proceso', 'orden'];

    protected function casts(): array
    {
        return ['sistemas' => 'array', 'condicional' => 'boolean'];
    }

    /** Los 20 módulos funcionales del informe. */
    public const MODULOS = [
        'M01' => 'Control documental',
        'M02' => 'Contexto y partes interesadas',
        'M03' => 'Requisitos legales',
        'M04' => 'Gestión de riesgos',
        'M05' => 'Objetivos e indicadores',
        'M06' => 'Plan de trabajo anual',
        'M07' => 'Talento: cargos, competencias y formación',
        'M08' => 'Comités y participación',
        'M09' => 'Operación SST',
        'M10' => 'Flota y conductores',
        'M11' => 'Emergencias',
        'M12' => 'Proveedores y contratistas',
        'M13' => 'Incidentes y siniestros',
        'M14' => 'Auditorías',
        'M15' => 'Acciones correctivas y mejora (ACPM)',
        'M16' => 'Revisión por la dirección',
        'M17' => 'Calidad: cliente y servicio',
        'M18' => 'Gestión ambiental',
        'M19' => 'Comunicaciones',
        'M20' => 'Gestión del cambio',
    ];
}
