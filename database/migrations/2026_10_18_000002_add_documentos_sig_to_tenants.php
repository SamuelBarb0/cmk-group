<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos del mapa documental del SIG (ids del catálogo M01–M20) que CMK
 * contrató para la empresa. De aquí se deducen `modulos` y `submodulos`
 * (App\Services\Clientes\AlcanceDocumental). Nulo = todo el catálogo: las
 * empresas que ya existen no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('documentos_sig')->nullable()->after('submodulos');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('documentos_sig');
        });
    }
};
