<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M17 — Calidad (ISO 9001): los registros operativos que faltaban del ciclo
 * con el cliente.
 *
 * - `customer_requests`: PQRS (peticiones, quejas, reclamos, sugerencias y
 *   felicitaciones), la comunicación con el cliente de 8.2.1, con su plazo y
 *   su respuesta (SIG-49).
 * - `nonconforming_outputs`: control de las salidas no conformes (8.7): qué
 *   salió mal, qué se hizo con ello y quién lo verificó o lo autorizó
 *   (SIG-50).
 * - `satisfaction_surveys`: la percepción del cliente (9.1.2), con criterios
 *   fijos de 1 a 5 para que el índice se pueda comparar entre periodos
 *   (SIG-56).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('radicado', 20);
            $table->date('fecha');
            $table->string('tipo', 12);                  // peticion / queja / reclamo / sugerencia / felicitacion
            $table->string('cliente');
            $table->string('contacto')->nullable();
            $table->string('canal', 15)->nullable();     // correo / telefono / presencial / web / whatsapp / escrito
            $table->text('descripcion');
            $table->foreignId('process_id')->nullable()->constrained('processes')->nullOnDelete();
            $table->string('responsable')->nullable();
            $table->date('fecha_limite');
            $table->text('respuesta')->nullable();
            $table->date('fecha_respuesta')->nullable();
            $table->boolean('procede')->nullable();      // ¿la queja o el reclamo tenía razón?
            $table->string('estado', 12)->default('abierta'); // abierta / en_tramite / cerrada
            $table->foreignId('acpm_action_id')->nullable()->constrained('acpm_actions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'radicado']);
            $table->index(['tenant_id', 'fecha']);
        });

        Schema::create('nonconforming_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('codigo', 20);
            $table->date('fecha');
            $table->string('producto');                  // producto o servicio afectado
            $table->foreignId('process_id')->nullable()->constrained('processes')->nullOnDelete();
            $table->string('detectado_en', 12);          // recepcion / proceso / final / cliente
            $table->text('descripcion');
            $table->string('cantidad', 60)->nullable();
            $table->string('tratamiento', 14);           // correccion / segregacion / devolucion / concesion / reproceso / desecho
            $table->text('detalle_tratamiento')->nullable();
            // Una concesión (aceptar el producto como está) exige quién la autorizó
            // y, si aplica, el aval del cliente (8.7.2 d).
            $table->string('autorizado_por')->nullable();
            $table->string('verificado_por')->nullable();
            $table->date('fecha_verificacion')->nullable();
            $table->string('estado', 10)->default('abierta'); // abierta / cerrada
            $table->foreignId('acpm_action_id')->nullable()->constrained('acpm_actions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'codigo']);
            $table->index(['tenant_id', 'fecha']);
        });

        Schema::create('satisfaction_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('cliente');
            $table->string('producto')->nullable();
            $table->unsignedTinyInteger('calidad');
            $table->unsignedTinyInteger('oportunidad');
            $table->unsignedTinyInteger('atencion');
            $table->unsignedTinyInteger('cumplimiento');
            $table->unsignedTinyInteger('recomendaria');
            $table->text('comentario')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('satisfaction_surveys');
        Schema::dropIfExists('nonconforming_outputs');
        Schema::dropIfExists('customer_requests');
    }
};
