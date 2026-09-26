<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presentaciones (.pptx) que la IA arma con el contexto completo del cliente:
 * los datos de la organización y las cifras reales de los módulos (el mismo
 * informe de gestión), de un módulo del mapa documental (M01–M20) o de todo
 * el sistema. Se generan en cola; `contenido` guarda las diapositivas que
 * devolvió la IA y `archivo` el .pptx armado con la marca de CMK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presentations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('titulo')->nullable();
            $table->string('modulo', 3)->nullable();          // M01–M20; nulo = todo el sistema
            $table->string('proposito', 20);                   // gerencia / trabajadores / capacitacion / auditoria / otro
            $table->text('instrucciones')->nullable();
            $table->unsignedTinyInteger('diapositivas');
            $table->date('desde');                             // periodo de las cifras
            $table->date('hasta');
            $table->string('estado', 10)->default('generando'); // generando / lista / error
            $table->json('contenido')->nullable();
            $table->string('archivo')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentations');
    }
};
