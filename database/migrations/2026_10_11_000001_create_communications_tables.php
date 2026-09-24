<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M19 — Comunicaciones (ISO 7.4 de las tres normas, Dec. 1072 art.
 * 2.2.4.6.14, PESV paso 24).
 *
 * - `communication_plan`: la matriz de comunicaciones de la empresa: qué se
 *   comunica, cuándo, a quién, cómo y quién lo hace. Es un documento: se
 *   planea y se mantiene.
 * - `communication_logs`: el registro de lo que efectivamente se comunicó o
 *   se recibió, sobre todo lo externo (autoridades, ARL, clientes,
 *   comunidad), con la respuesta cuando la pide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('tipo', 8);                    // interna / externa
            $table->string('que');                        // qué se comunica
            $table->string('cuando');                     // frecuencia u ocasión
            $table->string('a_quien');
            $table->string('como');                       // medio
            $table->string('responsable');
            $table->json('sistemas');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('tipo', 8);                    // interna / externa
            $table->string('direccion', 8);               // entrante / saliente
            $table->string('parte_interesada');           // ARL, MinTrabajo, cliente, comunidad…
            $table->string('asunto');
            $table->string('medio')->nullable();
            $table->string('responsable')->nullable();
            $table->text('detalle')->nullable();
            $table->boolean('requiere_respuesta')->default(false);
            $table->date('fecha_limite_respuesta')->nullable();
            $table->date('fecha_respuesta')->nullable();
            $table->text('respuesta')->nullable();
            $table->foreignId('communication_plan_id')->nullable()->constrained('communication_plan')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
        Schema::dropIfExists('communication_plan');
    }
};
