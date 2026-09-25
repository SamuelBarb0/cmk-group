<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M04 — lo que faltaba de la gestión de riesgos (informe SIG, etapa 6):
 *
 * - `risk_opportunities`: riesgos y oportunidades de los procesos (ISO 45001
 *   6.1.1, ISO 9001 6.1, ISO 14001 6.1.1; SIG-21). Salen sobre todo de la
 *   DOFA del contexto (M02): debilidades y amenazas son riesgos, fortalezas y
 *   oportunidades son oportunidades. Probabilidad × impacto en una matriz
 *   5×5, tratamiento, acciones y evaluación de su eficacia (9001 6.1.2 b).
 * - `environmental_aspects`: aspectos e impactos ambientales con perspectiva
 *   de ciclo de vida y condiciones normales, anormales y de emergencia (ISO
 *   14001 6.1.2; SIG-20), con su significancia.
 *
 * La matriz de peligros (GTC 45) y la de riesgos viales ya existen en IPERC
 * y en el PESV; esto no las duplica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('process_id')->nullable()->constrained('processes')->nullOnDelete();
            $table->foreignId('context_issue_id')->nullable()->constrained('context_issues')->nullOnDelete();
            $table->string('tipo', 12);                        // riesgo / oportunidad
            $table->text('descripcion');
            $table->text('causa')->nullable();
            $table->text('efecto')->nullable();
            $table->unsignedTinyInteger('probabilidad');      // 1–5
            $table->unsignedTinyInteger('impacto');           // 1–5
            $table->string('tratamiento', 12);
            $table->text('acciones')->nullable();
            $table->string('responsable')->nullable();
            $table->date('fecha_limite')->nullable();
            $table->foreignId('acpm_action_id')->nullable()->constrained('acpm_actions')->nullOnDelete();
            $table->string('estado', 15)->default('abierto');  // abierto / en_tratamiento / cerrado
            $table->text('eficacia')->nullable();
            $table->date('evaluado_at')->nullable();
            $table->json('sistemas');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'tipo']);
        });

        Schema::create('environmental_aspects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('process_id')->nullable()->constrained('processes')->nullOnDelete();
            $table->string('actividad');
            $table->string('aspecto');
            $table->string('impacto');
            $table->string('tipo_impacto', 8)->default('negativo');   // negativo / positivo
            $table->string('condicion', 12)->default('normal');       // normal / anormal / emergencia
            $table->string('etapa', 16);                              // etapa del ciclo de vida
            $table->unsignedTinyInteger('frecuencia');                // 1–5
            $table->unsignedTinyInteger('severidad');                 // 1–5
            // Cualquiera de las dos lo vuelve significativo sin importar el puntaje.
            $table->boolean('requisito_legal')->default(false);
            $table->boolean('preocupa_partes')->default(false);
            $table->text('controles')->nullable();
            $table->foreignId('acpm_action_id')->nullable()->constrained('acpm_actions')->nullOnDelete();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environmental_aspects');
        Schema::dropIfExists('risk_opportunities');
    }
};
