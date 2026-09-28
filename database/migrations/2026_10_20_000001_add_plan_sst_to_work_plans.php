<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El Plan de Trabajo tenía solo el plan del SGI por cláusulas ISO (hoja «6.2
 * PLAN DE TRABAJO SGI»). Faltaba el Plan de Trabajo Anual SST-PESV (hoja «2.4.1
 * Plan de trabajo», estándar 2.4.1 de la Res. 0312): ~88 actividades por ciclo
 * PHVA, con frecuencia y responsable.
 *
 * - work_plan_activities.plan: a qué plan pertenece la actividad (sst | sgi).
 * - work_plan_activities.frecuencia / responsable_sugerido: de la hoja 2.4.1.
 * - work_plans.tipo: cada empresa lleva un plan de cada tipo por año, con sus
 *   propias firmas, selección de actividades y cumplimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_plan_activities', function (Blueprint $table) {
            $table->string('plan', 8)->default('sgi')->after('id');
            $table->string('frecuencia', 80)->nullable()->after('soporte');
            $table->string('responsable_sugerido')->nullable()->after('frecuencia');
            $table->index('plan');
        });

        Schema::table('work_plans', function (Blueprint $table) {
            $table->string('tipo', 8)->default('sgi')->after('anio');
            // El índice nuevo se crea ANTES de soltar el viejo: en MySQL la
            // llave foránea de tenant_id se apoya en él y no deja soltarlo solo.
            $table->unique(['tenant_id', 'anio', 'tipo']);
        });

        Schema::table('work_plans', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::table('work_plans', function (Blueprint $table) {
            $table->unique(['tenant_id', 'anio']);
        });

        Schema::table('work_plans', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'anio', 'tipo']);
            $table->dropColumn('tipo');
        });

        Schema::table('work_plan_activities', function (Blueprint $table) {
            $table->dropIndex(['plan']);
            $table->dropColumn(['plan', 'frecuencia', 'responsable_sugerido']);
        });
    }
};
