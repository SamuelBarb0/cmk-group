<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los temas de capacitación y su material ahora se cargan desde la
 * plataforma. TrainingTopicsSeeder hace updateOrCreate por código (incluida
 * la ruta del archivo): sin esta marca, el siguiente despliegue devolvería el
 * tema a la presentación vieja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_topics', function (Blueprint $table) {
            $table->timestamp('editado_at')->nullable()->after('activo');
            $table->string('editado_por')->nullable()->after('editado_at');
        });
    }

    public function down(): void
    {
        Schema::table('training_topics', function (Blueprint $table) {
            $table->dropColumn(['editado_at', 'editado_por']);
        });
    }
};
