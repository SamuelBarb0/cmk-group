<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditorías contra la tabla de requisitos (informe de estructura documental,
 * sección 8.3):
 *
 * - Al planear la auditoría se eligen las normas que cubre (`sistemas`).
 * - La lista de verificación sale de los requisitos de esas normas: si solo se
 *   audita el PESV, solo salen los del PESV. Se evalúa una vez por requisito
 *   común (`clave_comun`), no una vez por norma: la política integrada se
 *   revisa una sola vez y el resultado cuenta en las cinco.
 * - Cada hallazgo se vincula a los requisitos que incumple, con la referencia
 *   exacta de cada norma.
 * - El informe se desglosa por norma con su porcentaje de cumplimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            // null = auditoría anterior a este cambio, sin lista de verificación.
            $table->json('sistemas')->nullable()->after('criterios');
        });

        // Solo se guardan las respuestas: la lista en sí se arma desde el
        // alcance. Así, cambiar las normas de la auditoría no borra lo que ya
        // se evaluó.
        Schema::create('audit_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained('audits')->cascadeOnDelete();
            $table->string('clave_comun', 20);
            $table->string('resultado', 15);              // conforme / no_conforme / observacion / no_aplica
            $table->text('evidencia')->nullable();
            $table->timestamps();
            $table->unique(['audit_id', 'clave_comun']);
        });

        Schema::create('audit_finding_requirement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_finding_id')->constrained('audit_findings')->cascadeOnDelete();
            $table->foreignId('norm_requirement_id')->constrained('norm_requirements')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['audit_finding_id', 'norm_requirement_id'], 'afr_hallazgo_requisito_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_finding_requirement');
        Schema::dropIfExists('audit_checks');
        Schema::table('audits', function (Blueprint $table) {
            $table->dropColumn('sistemas');
        });
    }
};
