<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de Capacitaciones del SGI.
 *
 * `training_topics` = biblioteca GLOBAL de temas (las presentaciones modelo de
 * CMK, descargables). `trainings` = capacitaciones programadas/dictadas por cada
 * empresa cliente, con su `training_attendees` (registro de asistencia).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Biblioteca global de temas de capacitación.
        Schema::create('training_topics', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('titulo');
            $table->string('categoria', 20);              // SST / PESV / HSEQ
            $table->text('descripcion')->nullable();
            $table->string('archivo')->nullable();        // ruta a la presentación (.pptx/.ppt)
            $table->unsignedSmallInteger('duracion_sugerida')->nullable(); // minutos
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Capacitaciones por empresa cliente.
        Schema::create('trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('training_topic_id')->nullable()->constrained('training_topics')->nullOnDelete();

            $table->string('titulo');
            $table->string('categoria', 20);
            $table->date('fecha')->nullable();
            $table->string('instructor')->nullable();
            $table->string('modalidad', 20)->default('presencial'); // presencial / virtual
            $table->unsignedSmallInteger('duracion_minutos')->nullable();
            $table->string('lugar')->nullable();
            $table->text('objetivo')->nullable();
            $table->string('estado', 15)->default('programada');    // programada / realizada
            $table->text('observaciones')->nullable();
            $table->string('creado_por')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'fecha']);
        });

        // Registro de asistencia (asistentes por capacitación).
        Schema::create('training_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_id')->constrained('trainings')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('nombres');                    // snapshot (empleado o manual)
            $table->string('numero_documento')->nullable();
            $table->string('cargo')->nullable();
            $table->boolean('asistio')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_attendees');
        Schema::dropIfExists('trainings');
        Schema::dropIfExists('training_topics');
    }
};
