<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestión del cambio (estándar 2.11.1 de la Res. 0312, ISO 45001 8.1.3 y el
 * «PASO 18 PROCEDIMIENTO DE GESTIÓN DEL CAMBIO» de CMK).
 *
 * Sale de «2.11.1 Matriz gestión del cambio» (libro SST-PESV) y de «6.3
 * AUTORIZAC. CAMBIOS» + «6.3 MATRIZ DE SEGUIM. A CAMBIOS» (libro SGI). La
 * solicitud del SGI es la ficha completa; las matrices son su resumen, así que
 * aquí es UNA tabla y la matriz es la lista.
 *
 * La regla que manda, del procedimiento: «Ninguna gestión de proceso de cambio
 * se puede realizar si no es aprobada y revisada por la Gerencia y el
 * encargado del SG SST - PESV». Por eso hay dos aprobaciones separadas, y el
 * estado se DEDUCE de ellas en el modelo en vez de guardarse suelto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // 1. Información general.
            $table->date('fecha_solicitud');
            $table->string('solicitante');
            $table->string('cargo_solicitante')->nullable();
            $table->string('area');                              // proceso donde se genera
            $table->string('procesos_involucrados')->nullable();
            // infraestructura / requisito_legal / proceso / personal / contratista / pesv / sistema_gestion / otro
            $table->string('tipo', 20);
            $table->string('tipo_otro')->nullable();
            $table->string('condicion', 12)->default('fijo');    // temporal / fijo / ciclico / emergencia
            $table->text('descripcion');
            $table->date('fecha_limite')->nullable();            // el «tiempo estimado» del formato

            // 2. Análisis por elemento (las 6M del formato). JSON porque es una
            // rejilla fija de seis filas que se lee y se guarda entera.
            $table->json('analisis')->nullable();
            // 4. Costos de inversión.
            $table->decimal('costo_presupuestado', 15, 2)->nullable();
            $table->decimal('costo_ejecutado', 15, 2)->nullable();

            // Lo que el procedimiento exige revisar antes de cerrar.
            $table->boolean('requiere_actualizar_iperc')->default(false);
            $table->date('iperc_actualizada_at')->nullable();
            $table->boolean('requiere_capacitacion')->default(false);

            // 5. Aprobación: las DOS son obligatorias.
            $table->string('aprobacion_gerencia_nombre')->nullable();
            $table->date('aprobacion_gerencia_fecha')->nullable();
            $table->string('aprobacion_sst_nombre')->nullable();
            $table->date('aprobacion_sst_fecha')->nullable();
            $table->date('rechazado_at')->nullable();
            $table->text('motivo_rechazo')->nullable();

            // 8. Evaluación de cierre.
            $table->date('cierre_fecha')->nullable();
            $table->boolean('cierre_implementado')->nullable();  // ¿se implementaron las actividades?
            $table->boolean('cierre_a_tiempo')->nullable();      // ¿en el tiempo establecido?
            $table->boolean('cierre_eficaz')->nullable();        // ¿fue eficaz?
            $table->text('cierre_justificacion')->nullable();

            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'fecha_solicitud']);
        });

        // 7. Seguimiento a la implementación: el plan de acción, fila a fila.
        Schema::create('change_request_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_request_id')->constrained('change_requests')->cascadeOnDelete();

            $table->string('descripcion', 500);
            $table->string('responsable')->nullable();
            $table->date('fecha_compromiso')->nullable();
            $table->boolean('ejecutada')->default(false);
            $table->date('fecha_ejecucion')->nullable();
            $table->text('observacion')->nullable();              // observación / hallazgo
            $table->unsignedSmallInteger('orden')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_request_actions');
        Schema::dropIfExists('change_requests');
    }
};
