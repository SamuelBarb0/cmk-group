<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Información de la Organización (contexto SGI) sobre la empresa cliente.
 *
 * Campos que alimentan el diagnóstico de estándares mínimos, el PESV y la
 * generación de documentos con IA (actividad, nivel de riesgo, responsable
 * del SG-SST, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('actividad_economica')->nullable()->after('address'); // Descripción
            $table->string('codigo_ciiu', 10)->nullable()->after('actividad_economica');
            $table->string('sector')->nullable()->after('codigo_ciiu');           // Económico
            $table->string('nivel_riesgo', 3)->nullable()->after('sector');        // I..V (ARL)
            $table->string('arl')->nullable()->after('nivel_riesgo');
            $table->string('tamano_empresa', 20)->nullable()->after('arl');        // Micro/Pequeña/Mediana/Grande
            $table->unsignedInteger('num_trabajadores')->nullable()->after('tamano_empresa');
            $table->string('representante_legal')->nullable()->after('num_trabajadores');
            $table->string('responsable_sgsst')->nullable()->after('representante_legal');
            $table->string('licencia_sgsst')->nullable()->after('responsable_sgsst'); // N° licencia SST
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'actividad_economica',
                'codigo_ciiu',
                'sector',
                'nivel_riesgo',
                'arl',
                'tamano_empresa',
                'num_trabajadores',
                'representante_legal',
                'responsable_sgsst',
                'licencia_sgsst',
            ]);
        });
    }
};
