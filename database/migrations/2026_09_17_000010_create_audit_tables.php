<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditorías internas del sistema de gestión — hojas «6.1.2 Cronograma de
 * auditorias», «Plan de auditoria» e «Informe de Auditoria» del libro de CMK.
 *
 * Cierra dos cosas que quedaron colgando:
 *  · La barra lateral tenía una entrada `/auditoria` que NO llevaba a ninguna
 *    ruta: un enlace muerto desde antes de esta etapa.
 *  · ACPM ya aceptaba `origen_tipo = 'auditoria'` pero no había módulo que
 *    generara esas acciones. Ahora un hallazgo se enlaza con su acción.
 *
 * La lista de chequeo y las actas de apertura y cierre NO se modelan aquí: ya
 * existen como formatos del motor (FT-LCH-AUD, FT-ACTA-AUD-APER,
 * FT-ACTA-AUD-CIERRE) y duplicarlas sería tener dos sitios donde diligenciar lo
 * mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('codigo', 20);                 // AUD-2026-001, por cliente
            // interna / externa / contratistas / terceros — los cuatro tipos
            // que lista el cronograma de CMK.
            $table->string('tipo', 15)->default('interna');

            $table->string('objetivo');
            $table->text('alcance')->nullable();
            $table->text('criterios')->nullable();        // normas contra las que se audita
            $table->string('procesos')->nullable();       // procesos o áreas auditadas

            $table->date('fecha_programada');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();

            $table->string('auditor_lider')->nullable();
            $table->string('equipo_auditor')->nullable();

            // programada -> en_curso -> cerrada
            $table->string('estado', 12)->default('programada');
            $table->text('conclusiones')->nullable();
            $table->text('observaciones')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'codigo']);
            $table->index(['tenant_id', 'estado']);
        });

        Schema::create('audit_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained('audits')->cascadeOnDelete();

            /**
             * no_conformidad_mayor | no_conformidad_menor | observacion |
             * oportunidad | fortaleza
             *
             * La fortaleza también se registra: un informe que solo lista fallos
             * hace que el auditado deje de colaborar, y las actas de cierre de
             * CMK abren justamente por las fortalezas.
             */
            $table->string('tipo', 25);
            $table->string('proceso')->nullable();
            $table->string('requisito')->nullable();      // cláusula o artículo incumplido
            $table->text('descripcion');
            $table->text('evidencia')->nullable();

            // Un hallazgo que exige acción se enlaza con su ACPM en vez de
            // llevar aquí su propio seguimiento.
            $table->foreignId('acpm_action_id')->nullable()->constrained('acpm_actions')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_findings');
        Schema::dropIfExists('audits');
    }
};
