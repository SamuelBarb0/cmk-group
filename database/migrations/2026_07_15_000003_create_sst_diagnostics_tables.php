<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnóstico de Estándares Mínimos del SG-SST (Resolución 0312 de 2019).
 *
 * - sst_standards: catálogo GLOBAL de los 60 estándares (no por tenant).
 * - sst_diagnostics: una evaluación por empresa cliente (segregada por tenant).
 * - sst_diagnostic_items: respuesta por estándar dentro de una evaluación.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Catálogo global de estándares (compartido por todos los clientes).
        Schema::create('sst_standards', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 12)->unique();   // 1.1.1, 4.2.6, ...
            $table->string('ciclo', 20);               // I. Planear / II. Hacer / III. Verificar / IV. Actuar
            $table->string('grupo');                   // Recursos, Gestión de la Salud, ...
            $table->unsignedTinyInteger('peso_grupo'); // % del grupo (10, 15, 20, 30, ...)
            $table->text('item');                      // Descripción del estándar
            $table->decimal('valor', 5, 2);            // Valor individual (suma = 100)
            $table->unsignedSmallInteger('orden');
            $table->timestamps();
        });

        Schema::create('sst_diagnostics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('fecha')->nullable();
            $table->string('evaluador')->nullable();
            $table->decimal('puntaje', 5, 2)->default(0);   // 0..100
            $table->string('clasificacion', 40)->nullable(); // Crítico / Moderadamente aceptable / Aceptable
            $table->timestamps();
        });

        Schema::create('sst_diagnostic_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sst_diagnostic_id')->constrained('sst_diagnostics')->cascadeOnDelete();
            $table->foreignId('sst_standard_id')->constrained('sst_standards')->cascadeOnDelete();
            $table->string('estado', 15)->default('pendiente'); // cumple / no_cumple / no_aplica / pendiente
            $table->text('justificacion')->nullable();
            $table->timestamps();

            $table->unique(['sst_diagnostic_id', 'sst_standard_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sst_diagnostic_items');
        Schema::dropIfExists('sst_diagnostics');
        Schema::dropIfExists('sst_standards');
    }
};
