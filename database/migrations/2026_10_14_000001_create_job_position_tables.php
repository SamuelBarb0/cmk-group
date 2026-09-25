<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M07 — Talento humano: lo que faltaba para la competencia (ISO 45001/9001/
 * 14001 7.2, Dec. 1072 2.2.4.6.11, PESV paso 10; SIG-29) y los roles y
 * responsabilidades del SIG (5.3, Dec. 1072 2.2.4.6.8; SIG-11):
 *
 * - `job_positions`: el perfil de cada cargo (objetivo, funciones,
 *   responsabilidades en el SIG y autoridad). El empleado sigue guardando su
 *   cargo como texto (`employees.cargo`), igual que el profesiograma y la
 *   matriz de EPP; se enlazan por el nombre, sin mayúsculas ni espacios.
 * - `job_position_requirements`: lo que exige el cargo en educación,
 *   formación, experiencia y habilidades. Un requisito de formación puede
 *   apuntar a un tema del catálogo de capacitaciones: entonces se cumple solo
 *   con una capacitación realizada, eficaz y vigente de ese tema.
 * - `competency_assessments`: la evaluación a mano de un trabajador contra un
 *   requisito (lo que no se puede deducir de las capacitaciones: el título,
 *   la experiencia, una habilidad observada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nombre');
            $table->foreignId('process_id')->nullable()->constrained('processes')->nullOnDelete();
            $table->string('reporta_a')->nullable();
            $table->text('objetivo')->nullable();
            $table->text('funciones')->nullable();
            $table->text('responsabilidades_sig')->nullable();
            $table->text('autoridad')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'nombre']);
        });

        Schema::create('job_position_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('job_position_id')->constrained('job_positions')->cascadeOnDelete();
            $table->string('tipo', 12);                  // educacion / formacion / experiencia / habilidad
            $table->string('descripcion', 500);
            $table->foreignId('training_topic_id')->nullable()->constrained('training_topics')->nullOnDelete();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('competency_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('job_position_requirement_id')->constrained('job_position_requirements')->cascadeOnDelete();
            $table->boolean('cumple');
            $table->string('evidencia', 500)->nullable();
            $table->date('evaluado_at');
            $table->string('evaluado_por')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'job_position_requirement_id'], 'competency_employee_requirement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_assessments');
        Schema::dropIfExists('job_position_requirements');
        Schema::dropIfExists('job_positions');
    }
};
