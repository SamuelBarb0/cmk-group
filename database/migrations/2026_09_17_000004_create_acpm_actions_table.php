<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACPM — Acciones Correctivas, Preventivas y de Mejora. Hoja «10 ACPM» /
 * «10.1 ACCIONES DE MEJORA» de los libros de CMK.
 *
 * Va PRIMERO de los módulos nuevos a propósito: es el registro que alimentan
 * todos los demás. Una investigación de accidente, un reporte de acto inseguro,
 * un hallazgo de auditoría y una inspección terminan todos en lo mismo — «hay
 * que hacer algo, alguien responde, para tal fecha». Sin un registro común, cada
 * módulo llevaría su propia lista de tareas y nadie podría responder «cuántas
 * acciones tengo abiertas», que es justo el indicador GEST-PA.
 *
 * El origen se guarda como texto libre + un id suelto, NO como relación
 * polimórfica: las fuentes son de módulos que todavía no existen todos, y una
 * morphTo obligaría a tener el modelo creado para poder registrar la acción.
 * `origen_tipo` es una etiqueta ('accidente', 'reporte', 'auditoria',
 * 'inspeccion', 'iperc', 'manual') y `origen_id` el registro cuando lo hay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acpm_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('codigo', 20);                 // ACPM-2026-001, por cliente
            $table->string('tipo', 12);                   // correctiva / preventiva / mejora
            $table->string('origen_tipo', 20)->default('manual');
            $table->unsignedBigInteger('origen_id')->nullable();

            $table->text('hallazgo');                     // qué se encontró
            $table->text('causa')->nullable();            // análisis de causa
            $table->text('accion');                       // qué se va a hacer

            $table->string('responsable');
            $table->date('fecha_deteccion');
            $table->date('fecha_limite');
            $table->date('fecha_cierre')->nullable();

            $table->string('estado', 12)->default('abierta'); // abierta / en_proceso / cerrada

            // La eficacia se verifica DESPUÉS de cerrar: cerrar una acción no
            // prueba que el problema no vuelva. null = todavía sin verificar.
            $table->boolean('eficaz')->nullable();
            $table->text('verificacion')->nullable();
            $table->date('fecha_verificacion')->nullable();

            $table->text('observaciones')->nullable();
            $table->timestamps();

            // El código es único DENTRO de cada cliente, no globalmente: dos
            // empresas distintas pueden tener su ACPM-2026-001.
            $table->unique(['tenant_id', 'codigo']);
            $table->index(['tenant_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acpm_actions');
    }
};
