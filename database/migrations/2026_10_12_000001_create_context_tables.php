<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M02 — Contexto de la organización (capítulo 4 de ISO 45001, 9001 y 14001;
 * Dec. 1072 art. 2.2.4.6.1 para el alcance; PESV paso 5 para el nivel).
 *
 * - `context_profiles`: una ficha por empresa con el alcance del sistema
 *   integrado (4.3), las exclusiones justificadas y la decisión sobre si el
 *   cambio climático es una cuestión pertinente (enmienda de 2024 a 4.1).
 * - `context_issues`: las cuestiones internas y externas (4.1), clasificadas
 *   en DOFA y, las externas, en PESTEL. Es la matriz MTZ-DIR-001.
 * - `interested_parties`: partes interesadas con sus necesidades y
 *   expectativas y cuáles se vuelven requisitos (4.2). Es la MTZ-DIR-002.
 * - Columnas nuevas en `processes`: la caracterización de cada proceso del
 *   mapa (4.4): objetivo, líder, proveedores, entradas, actividades PHVA,
 *   salidas, clientes, recursos, indicadores y riesgos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('context_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->text('alcance')->nullable();
            $table->text('sedes')->nullable();
            $table->text('productos_servicios')->nullable();
            $table->json('exclusiones')->nullable();          // [{requisito, justificacion}]
            $table->boolean('cambio_climatico')->nullable();  // null = aún sin decidir
            $table->text('cambio_climatico_justificacion')->nullable();
            $table->date('revisado_at')->nullable();
            $table->string('revisado_por')->nullable();
            $table->timestamps();
        });

        Schema::create('context_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('origen', 8);                      // interno / externo
            $table->string('dofa', 12);                       // fortaleza / debilidad / oportunidad / amenaza
            $table->string('pestel', 12)->nullable();         // solo externas
            $table->text('descripcion');
            $table->string('impacto', 5)->default('medio');   // alto / medio / bajo
            $table->boolean('cambio_climatico')->default(false);
            $table->text('tratamiento')->nullable();
            $table->json('sistemas');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('interested_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('tipo', 8);                        // interna / externa
            $table->string('categoria', 30);
            $table->text('necesidades');
            $table->text('expectativas')->nullable();
            // Necesidades que la empresa adopta como requisito u obligación de
            // cumplimiento (ISO 45001/14001 4.2 c, 14001 6.1.3).
            $table->boolean('es_requisito')->default(false);
            $table->string('influencia', 5)->default('media'); // alta / media / baja
            $table->string('interes', 5)->default('medio');    // alto / medio / bajo
            $table->text('como_se_atiende')->nullable();
            $table->boolean('cambio_climatico')->default(false);
            $table->json('sistemas');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::table('processes', function (Blueprint $table) {
            $table->text('objetivo')->nullable()->after('tipo');
            $table->string('lider')->nullable()->after('objetivo');
            $table->json('caracterizacion')->nullable()->after('lider');
        });
    }

    public function down(): void
    {
        Schema::table('processes', function (Blueprint $table) {
            $table->dropColumn(['objetivo', 'lider', 'caracterizacion']);
        });
        Schema::dropIfExists('interested_parties');
        Schema::dropIfExists('context_issues');
        Schema::dropIfExists('context_profiles');
    }
};
