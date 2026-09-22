<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accidentes e incidentes de trabajo, CON su investigación.
 *
 * Un solo módulo y no dos. En los libros de CMK esto está repartido entre
 * «Accidentalidad» (el registro estadístico), «3.2.1 For-inv-acc» (el formato de
 * investigación), «Tabla SCAT» y «MATRIZ CAUSA RAIZ» — pero todas hablan del
 * MISMO evento. Separarlos en dos tablas obligaría a registrar el accidente dos
 * veces y a mantenerlos sincronizados, que es exactamente el problema que hoy
 * tienen con los Excel.
 *
 * Alimenta los índices de frecuencia, severidad y la proporción de mortalidad.
 * Los días perdidos NO se guardan aquí: viven en `absences` con tipo
 * `accidente_trabajo`, enlazados por `absence_id`. Tener el mismo número en dos
 * sitios es garantizar que un día no coincidan.
 *
 * El bot 1 de WhatsApp ya redacta esta investigación. Este módulo es el destino
 * natural de lo que ese bot produce, que hoy se queda en el chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_accidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('codigo', 20);                 // AT-2026-001, por cliente

            // ------------------------------------------------ el evento
            // incidente | accidente | casi_accidente. El incidente no dejó
            // lesión pero se investiga igual: es el que avisa antes del grave.
            $table->string('clase', 15)->default('accidente');
            $table->date('fecha');
            $table->time('hora')->nullable();
            $table->string('lugar')->nullable();
            $table->string('area')->nullable();
            $table->text('descripcion');

            // Taxonomía de la Res. 1401/2007 y del formato de CMK.
            $table->string('tipo_lesion', 80)->nullable();
            $table->string('parte_cuerpo', 80)->nullable();
            $table->string('mecanismo', 120)->nullable();      // golpeado por, caída, atrapamiento…
            $table->string('agente', 120)->nullable();         // máquina, herramienta, superficie…

            // Marca el evento mortal: es el numerador de la proporción de
            // letalidad y no se puede inferir de nada más.
            $table->boolean('mortal')->default(false);
            $table->boolean('grave')->default(false);
            $table->boolean('reportado_arl')->default(false);
            $table->date('fecha_reporte_arl')->nullable();

            // Los días perdidos viven en `absences`. Aquí solo el enlace.
            $table->foreignId('absence_id')->nullable()->constrained('absences')->nullOnDelete();

            // ------------------------------------------------ la investigación
            $table->boolean('investigado')->default(false);
            $table->date('fecha_investigacion')->nullable();
            $table->string('equipo_investigador')->nullable();

            // Causas inmediatas y básicas, según el modelo de la tabla SCAT que
            // usa CMK. Son listas, por eso json y no texto: la caracterización y
            // el análisis de causa raíz las agrupan.
            $table->json('causas_inmediatas')->nullable();     // actos y condiciones subestándar
            $table->json('causas_basicas')->nullable();        // factores personales y del trabajo
            $table->text('causa_raiz')->nullable();

            $table->text('leccion_aprendida')->nullable();
            $table->text('observaciones')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'codigo']);
            $table->index(['tenant_id', 'fecha']);
            $table->index(['tenant_id', 'clase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_accidents');
    }
};
