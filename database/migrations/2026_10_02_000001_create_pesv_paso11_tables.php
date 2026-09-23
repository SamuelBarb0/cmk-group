<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PESV · Paso 11 (responsabilidad y comportamiento seguro), según las hojas
 * de CMK:
 *  - pesv_driver_checks: requisitos del operador (RE-SST-51), uno por conductor.
 *  - pesv_vehicle_checks: requisitos del vehículo (RE-SST-50), uno por vehículo.
 *  - pesv_driver_tests: pruebas de idoneidad (teórica, práctica, psicosensométrica).
 *  - pesv_infractions: seguimiento de comparendos (RE-SST-52). El reporte de
 *    autogestión pide el número de infracciones por código.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesv_driver_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('fecha')->nullable();
            $table->string('placa_asignada', 10)->nullable();
            $table->json('respuestas')->nullable();
            $table->string('resultado', 15)->default('pendiente');   // cumple / no_cumple / pendiente
            $table->string('verificado_por')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'employee_id']);
        });

        Schema::create('pesv_vehicle_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('pesv_vehicle_id')->constrained('pesv_vehicles')->cascadeOnDelete();
            $table->date('fecha')->nullable();
            $table->json('respuestas')->nullable();
            $table->string('resultado', 15)->default('pendiente');
            $table->string('verificado_por')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'pesv_vehicle_id']);
        });

        Schema::create('pesv_driver_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('tipo', 20);                       // teorica / practica / psicosensometrica
            $table->date('fecha');
            $table->decimal('puntaje', 5, 1)->nullable();     // % en teórica y práctica
            $table->string('resultado', 10);                  // apto / no_apto
            $table->date('vigente_hasta')->nullable();
            $table->string('evaluador')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'employee_id']);
        });

        Schema::create('pesv_infractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('pesv_vehicle_id')->nullable()->constrained('pesv_vehicles')->nullOnDelete();
            $table->date('fecha');
            $table->string('codigo', 20);                     // código de la infracción (Ley 769)
            $table->string('descripcion')->nullable();
            $table->decimal('valor', 12, 2)->nullable();
            $table->string('estado', 20)->default('pendiente');
            $table->boolean('registrada_simit')->default(true);
            $table->text('acciones')->nullable();             // reinducción, compromiso, curso pedagógico…
            $table->timestamps();
            $table->index(['tenant_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesv_infractions');
        Schema::dropIfExists('pesv_driver_tests');
        Schema::dropIfExists('pesv_vehicle_checks');
        Schema::dropIfExists('pesv_driver_checks');
    }
};
