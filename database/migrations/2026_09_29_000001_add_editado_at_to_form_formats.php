<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los formatos ahora se editan desde la plataforma. FormFormatsSeeder hace
 * updateOrCreate por código, y se corre en los despliegues: sin esta marca,
 * el siguiente despliegue borraría en silencio lo que CMK cambió a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_formats', function (Blueprint $table) {
            $table->timestamp('editado_at')->nullable()->after('activo');
            $table->string('editado_por')->nullable()->after('editado_at');
        });
    }

    public function down(): void
    {
        Schema::table('form_formats', function (Blueprint $table) {
            $table->dropColumn(['editado_at', 'editado_por']);
        });
    }
};
