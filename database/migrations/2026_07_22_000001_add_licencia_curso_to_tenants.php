<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requisitos del responsable del SG-SST (feedback de CMK):
 * fecha de expiración de la licencia SST y curso de 50/20 horas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->date('licencia_sgsst_vence')->nullable()->after('licencia_sgsst');
            // Curso virtual obligatorio del responsable: 50 horas o actualización de 20 horas.
            $table->string('curso_sst_horas', 2)->nullable()->after('licencia_sgsst_vence');
            $table->date('curso_sst_fecha')->nullable()->after('curso_sst_horas');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['licencia_sgsst_vence', 'curso_sst_horas', 'curso_sst_fecha']);
        });
    }
};
