<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firmas digitalizadas del cliente (imagen) para estamparlas en los documentos
 * que se exportan: la del representante legal y la del responsable del SG-SST.
 * El logo ya tenía columna (logo_path); las tres viven en el disco privado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('firma_rep_path')->nullable()->after('logo_path');
            $table->string('firma_resp_path')->nullable()->after('firma_rep_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['firma_rep_path', 'firma_resp_path']);
        });
    }
};
