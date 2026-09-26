<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partes contratadas de cada módulo, por empresa: {modulo: [partes]}. El
 * catálogo está en config('cmk.submodulos'). Nulo, o un módulo ausente,
 * significa todas sus partes: las empresas que ya existen no pierden nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('submodulos')->nullable()->after('modulos');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('submodulos');
        });
    }
};
