<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matriz IPERC — Identificación de Peligros, Evaluación y Valoración de Riesgos
 * (metodología GTC 45). Cada fila es un peligro identificado por proceso/tarea,
 * con la valoración del riesgo calculada (NP = ND×NE, NR = NP×NC). Por tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iperc_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Contexto
            $table->string('proceso');
            $table->string('zona')->nullable();          // Zona / lugar
            $table->string('actividad');
            $table->string('tarea')->nullable();
            $table->boolean('rutinaria')->default(true);

            // Peligro
            $table->string('clasificacion');             // Físico/Químico/Biológico/...
            $table->string('peligro');                   // Descripción del peligro
            $table->text('efectos')->nullable();         // Efectos posibles

            // Controles existentes
            $table->string('control_fuente')->nullable();
            $table->string('control_medio')->nullable();
            $table->string('control_individuo')->nullable();

            // Valoración del riesgo (GTC 45)
            $table->unsignedTinyInteger('nd');           // Nivel de Deficiencia: 0,2,6,10
            $table->unsignedTinyInteger('ne');           // Nivel de Exposición: 1,2,3,4
            $table->unsignedTinyInteger('nc');           // Nivel de Consecuencia: 10,25,60,100
            $table->unsignedSmallInteger('np');          // NP = ND × NE
            $table->unsignedSmallInteger('nr');          // NR = NP × NC
            $table->string('nivel_riesgo', 4);           // I / II / III / IV
            $table->string('aceptabilidad', 40);         // No Aceptable / Aceptable con control / Aceptable

            // Intervención
            $table->text('medidas')->nullable();         // Medidas de intervención
            $table->unsignedSmallInteger('expuestos')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iperc_rows');
    }
};
