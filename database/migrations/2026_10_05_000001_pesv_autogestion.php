<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PESV · paso 20: reporte de autogestión anual (Res. 40595, literales a–l y
 * Tabla 10). Casi todo el reporte sale de los datos del módulo; aquí se guarda
 * solo lo que la plataforma no puede saber (correo del líder, cargo y correo
 * de los auditores, metas y programas propuestos para el año siguiente,
 * análisis) y la constancia de que se radicó ante la entidad verificadora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesv_autogestiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('anio');            // año reportado (corte al 31 de diciembre)
            $table->string('lider_email')->nullable();
            $table->json('auditores')->nullable();          // [{nombre, cargo, email}]
            $table->text('objetivos_siguiente')->nullable();
            $table->text('programas_siguiente')->nullable();
            $table->text('analisis')->nullable();
            $table->string('entidad_verificadora', 30)->nullable();
            $table->date('reportado_at')->nullable();
            $table->string('radicado')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesv_autogestiones');
    }
};
