<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulos contratados por la empresa cliente (feedback de CMK): la
 * plataforma muestra/permite solo lo que el cliente contrató.
 * null = todos los módulos habilitados (compatibilidad con clientes existentes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('modulos')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('modulos');
        });
    }
};
