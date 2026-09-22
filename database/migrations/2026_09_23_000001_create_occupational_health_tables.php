<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salud ocupacional: profesiograma por cargo y seguimiento de exámenes
 * médicos ocupacionales (Res. 2346 de 2007 y estándar 3.1.4 de la Res. 0312).
 *
 * Sale de las hojas «3.1.3 Profesiograma» y «3.1.4 Seguimiento E.O» del libro
 * SST-PESV (en el SGI: «8.1.2 SEGUIMIENTO EXAMENES MED.»).
 *
 * DATOS SENSIBLES: aquí NO se guarda diagnóstico ni historia clínica. La
 * historia la custodia la IPS (Res. 2346, art. 16); al empleador solo le llega
 * el CONCEPTO de aptitud y las recomendaciones, y es lo único que se registra.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Una fila por cargo. Los exámenes van en JSON porque el catálogo es
        // una matriz ancha (16 exámenes × ingreso/periódico/retiro) que se lee
        // y se guarda siempre entera.
        Schema::create('occupational_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('cargo');
            $table->text('factores_riesgo')->nullable();
            $table->string('pve')->nullable();                 // programas de vigilancia que aplican
            // {codigo_examen: ['ingreso' => bool, 'periodico' => bool, 'retiro' => bool]}
            $table->json('examenes')->nullable();
            $table->string('otros_examenes')->nullable();
            // El periódico casi siempre es anual; conductores o alturas pueden ir distinto.
            $table->unsignedSmallInteger('periodicidad_meses')->default(12);

            // La nota del Excel: el profesiograma lo firma un profesional con
            // licencia en salud ocupacional.
            $table->string('revisado_por')->nullable();
            $table->string('licencia_so', 60)->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'cargo']);
        });

        Schema::create('medical_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Obligatorio, a diferencia de brigada o comités: un examen
            // ocupacional siempre es de alguien de la nómina.
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->date('fecha');
            // ingreso / periodico / retiro / post_incapacidad / reintegro (Res. 2346, art. 3)
            $table->string('tipo', 20);
            $table->string('ips')->nullable();
            $table->json('examenes_realizados')->nullable();   // códigos del catálogo

            // apto / apto_con_restricciones / no_apto / aplazado
            $table->string('concepto', 25);
            $table->text('restricciones')->nullable();
            $table->text('recomendaciones_personales')->nullable();
            $table->text('recomendaciones_sst')->nullable();
            $table->text('recomendaciones_medicas')->nullable();

            // «CARTA» en el Excel: la carta de recomendaciones entregada y firmada.
            $table->boolean('carta_entregada')->default(false);
            $table->date('fecha_carta')->nullable();

            $table->string('pve')->nullable();
            $table->text('plan_accion')->nullable();
            $table->text('seguimiento')->nullable();

            // Se calcula al guardar desde la periodicidad del cargo, y se puede
            // corregir a mano (el médico a veces pide control antes).
            $table->date('proximo_examen')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_exams');
        Schema::dropIfExists('occupational_profiles');
    }
};
