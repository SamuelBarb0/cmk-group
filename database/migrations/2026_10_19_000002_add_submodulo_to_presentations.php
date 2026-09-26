<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La presentación puede ir de una parte del módulo del mapa: una pantalla
 * («epp») o una parte de ella («epp.matriz»). Nulo = el módulo completo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->string('submodulo', 60)->nullable()->after('modulo');
        });
    }

    public function down(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->dropColumn('submodulo');
        });
    }
};
