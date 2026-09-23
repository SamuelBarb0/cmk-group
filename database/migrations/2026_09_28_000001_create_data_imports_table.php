<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importación asistida: un archivo de Excel del cliente cargado a un módulo.
 *
 * Guarda el mapeo (propuesto por la IA y corregible a mano) y, una vez
 * aplicada, los ids que creó: así se puede DESHACER una importación entera
 * sin tocar lo que ya estaba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('destino', 30);                   // clave en Importacion\Destinos
            $table->string('archivo');                       // ruta en el disco local
            $table->string('nombre_original');
            $table->json('hojas');                           // [{nombre, filas}]
            $table->string('hoja')->nullable();
            // subido / mapeando / listo / error / aplicado / deshecho
            $table->string('estado', 12)->default('subido');
            $table->json('mapeo')->nullable();
            $table->boolean('mapeo_editado')->default(false);
            $table->text('error')->nullable();
            $table->json('resultado')->nullable();           // {creados: [ids], validas, errores, duplicadas}
            $table->timestamp('aplicado_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_imports');
    }
};
