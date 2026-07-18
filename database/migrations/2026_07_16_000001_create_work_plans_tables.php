<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de Trabajo Anual del SGI (cronograma por cláusulas ISO 4→10).
 *
 * - work_plan_activities: catálogo GLOBAL de actividades estándar del SGI
 *   (fuente: hoja «6.2 PLAN DE TRABAJO SGI» de la herramienta modelo de CMK).
 * - work_plans: un plan por empresa cliente y año (segregado por tenant).
 * - work_plan_items: programación/ejecución mensual de cada actividad en el plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Catálogo global de actividades (compartido por todos los clientes).
        Schema::create('work_plan_activities', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 12);              // 4.1, 5.2, 8.2E, ...
            $table->string('fase');                    // 4. Contexto / 5. Liderazgo / ...
            $table->string('nombre');                  // Título de la actividad
            $table->json('normas');                    // ["9001","14001","45001"]
            $table->text('soporte')->nullable();       // Evidencias/documentos sugeridos
            $table->unsignedSmallInteger('orden');
            $table->timestamps();

            $table->unique('codigo');
        });

        Schema::create('work_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->string('responsable')->nullable();
            $table->decimal('cumplimiento', 5, 2)->default(0); // 0..100
            $table->timestamps();

            $table->unique(['tenant_id', 'anio']);
        });

        Schema::create('work_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_plan_id')->constrained('work_plans')->cascadeOnDelete();
            $table->foreignId('work_plan_activity_id')->constrained('work_plan_activities')->cascadeOnDelete();
            $table->json('meses_programados')->nullable();  // [1..12]
            $table->json('meses_ejecutados')->nullable();   // [1..12]
            $table->string('responsable')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique(['work_plan_id', 'work_plan_activity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_plan_items');
        Schema::dropIfExists('work_plans');
        Schema::dropIfExists('work_plan_activities');
    }
};
