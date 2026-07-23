<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor genérico de formatos (Tier 4).
 *
 * En vez de un módulo por cada hoja del SGI (inspecciones, actas, listas de
 * chequeo…), un único motor: `form_formats` es el catálogo GLOBAL de formatos
 * con su esquema (secciones + campos) en JSON, y `form_records` son los
 * registros diligenciados por cada empresa cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Catálogo global de formatos (definición: secciones + campos en JSON).
        Schema::create('form_formats', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();       // FT-INS-EXT, FT-ACTA-COPASST, ...
            $table->string('nombre');
            $table->string('categoria', 20);              // SST / HSEQ / PESV
            $table->string('grupo', 30);                  // inspeccion / acta / lista / general
            $table->text('descripcion')->nullable();
            $table->json('schema');                       // { secciones: [ { titulo, campos:[...] } ] }
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Registros diligenciados por empresa cliente.
        Schema::create('form_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('form_format_id')->nullable()->constrained('form_formats')->nullOnDelete();

            $table->string('codigo', 40);                 // copiado del formato al crear
            $table->string('titulo');
            $table->string('categoria', 20);
            $table->string('grupo', 30);
            $table->json('schema');                       // SNAPSHOT del esquema al crear el registro
            $table->json('data')->nullable();             // valores diligenciados por campo
            $table->string('estado', 15)->default('borrador'); // borrador / completado
            $table->date('fecha')->nullable();
            $table->string('responsable')->nullable();
            $table->string('generado_por')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'grupo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_records');
        Schema::dropIfExists('form_formats');
    }
};
