<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback de CMK al Plan de Trabajo Anual: metas, objetivos, recursos,
 * selección de actividades aplicables y firma digital del representante
 * legal y del responsable del SG-SST.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_plans', function (Blueprint $table) {
            $table->text('metas')->nullable()->after('responsable');
            $table->text('objetivos')->nullable()->after('metas');
            $table->text('recursos')->nullable()->after('objetivos');

            // IDs de actividades del catálogo que APLICAN a este plan (null = todas).
            $table->json('actividades_seleccionadas')->nullable()->after('recursos');

            // Firma digital: quién, con qué cédula y cuándo (sello del sistema).
            $table->string('firma_rep_nombre')->nullable()->after('actividades_seleccionadas');
            $table->string('firma_rep_cc', 30)->nullable()->after('firma_rep_nombre');
            $table->timestamp('firma_rep_at')->nullable()->after('firma_rep_cc');
            $table->string('firma_resp_nombre')->nullable()->after('firma_rep_at');
            $table->string('firma_resp_cc', 30)->nullable()->after('firma_resp_nombre');
            $table->timestamp('firma_resp_at')->nullable()->after('firma_resp_cc');
        });
    }

    public function down(): void
    {
        Schema::table('work_plans', function (Blueprint $table) {
            $table->dropColumn([
                'metas', 'objetivos', 'recursos', 'actividades_seleccionadas',
                'firma_rep_nombre', 'firma_rep_cc', 'firma_rep_at',
                'firma_resp_nombre', 'firma_resp_cc', 'firma_resp_at',
            ]);
        });
    }
};
