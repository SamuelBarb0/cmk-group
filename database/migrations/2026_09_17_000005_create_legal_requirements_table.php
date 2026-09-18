<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matriz de requisitos legales — anexo «2.8.1 Matriz de requisitos legales.xlsx»
 * y hoja «6.1 M. REQUIS. LEGALES» del libro SGI.
 *
 * Alimenta el indicador CUMP-LEG: requisitos cumplidos sobre requisitos
 * aplicables. Por eso `aplica` y `cumplimiento` son columnas y no texto libre:
 * son el denominador y el numerador de ese indicador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('norma');                      // Decreto 1072 de 2015
            $table->unsignedSmallInteger('anio')->nullable();
            $table->string('articulo', 120)->nullable();   // Art. 2.2.4.6.8
            $table->string('tema', 160)->nullable();       // SG-SST, alturas, PESV…
            $table->string('entidad', 120)->nullable();    // MinTrabajo, MinTransporte…
            $table->text('requisito');                     // qué exige, en palabras

            // Un requisito que NO aplica sale del denominador de CUMP-LEG, pero
            // se conserva en la matriz con su justificación: en una auditoría hay
            // que poder mostrar POR QUÉ no aplica, no simplemente omitirlo.
            $table->boolean('aplica')->default(true);
            $table->text('justificacion_no_aplica')->nullable();

            // cumple / parcial / no_cumple. Solo `cumple` entra al numerador.
            $table->string('cumplimiento', 12)->default('no_cumple');
            $table->text('forma_cumplimiento')->nullable();   // cómo se cumple
            $table->text('evidencia')->nullable();            // dónde está el soporte

            $table->string('responsable')->nullable();
            $table->date('fecha_verificacion')->nullable();
            $table->text('observaciones')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'aplica', 'cumplimiento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_requirements');
    }
};
