<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ausentismo laboral — hojas «Ausentismo» de los dos libros de CMK.
 *
 * Es el módulo que desatasca tres indicadores legales: sin días de ausencia por
 * causa médica no se puede calcular AUS-CM, y sin los días perdidos por
 * accidente de trabajo no se puede calcular el índice de severidad. Hasta ahora
 * esas cifras había que teclearlas a mano en la lectura del indicador.
 *
 * Los días se guardan CALCULADOS y no solo derivados de las fechas, porque el
 * ausentismo se mide en días hábiles perdidos y hay incapacidades que caen en
 * fin de semana o que la empresa cuenta distinto. El modelo propone el número a
 * partir de las fechas, pero el consultor puede corregirlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->unsignedSmallInteger('dias');

            /**
             * Clasificación del origen. Solo las tres primeras son «causa
             * médica» y entran en AUS-CM; y solo `accidente_trabajo` suma a los
             * días perdidos del índice de severidad.
             *
             * enfermedad_general | accidente_trabajo | enfermedad_laboral |
             * accidente_comun | licencia_maternidad | licencia_luto |
             * permiso | otro
             */
            $table->string('tipo', 25);

            $table->string('diagnostico')->nullable();
            $table->string('cie10', 10)->nullable();
            $table->string('entidad', 120)->nullable();       // EPS / ARL que la expide
            $table->string('incapacidad_numero', 60)->nullable();
            $table->boolean('prorroga')->default(false);

            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'fecha_inicio']);
            $table->index(['tenant_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absences');
    }
};
