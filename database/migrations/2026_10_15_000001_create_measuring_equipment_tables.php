<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M09 — Control de los equipos de seguimiento y medición (ISO 9001 7.1.5,
 * ISO 45001 y 14001 9.1.1; SIG-35): sonómetros, luxómetros, alcoholímetros,
 * balanzas, termómetros, multímetros, medidores de gases…
 *
 * No va dentro de Mantenimiento: a un equipo de medición no se le pregunta
 * cuándo se engrasó sino si mide bien, contra qué patrón y con qué error; y
 * si sale fuera de tolerancia, qué pasa con lo que se midió con él antes
 * (9001 7.1.5.2).
 *
 * - `measuring_equipment`: la hoja de vida (magnitud, rango, resolución,
 *   error máximo permitido, calibración o verificación y cada cuánto).
 * - `equipment_calibrations`: cada calibración o verificación, con quién la
 *   hizo, si el laboratorio está acreditado ante ONAC, el certificado, el
 *   error encontrado y el veredicto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measuring_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('codigo', 40);
            $table->string('nombre');
            $table->string('marca')->nullable();
            $table->string('modelo')->nullable();
            $table->string('serie')->nullable();
            $table->string('magnitud', 60)->nullable();          // nivel sonoro, iluminancia, masa…
            $table->string('unidad', 20)->nullable();            // dB, lux, kg, °C…
            $table->string('rango')->nullable();
            $table->string('resolucion', 40)->nullable();
            // Lo que se le tolera al equipo para el uso que se le da: el
            // veredicto de cada calibración se compara contra esto.
            $table->string('error_maximo', 40)->nullable();
            $table->string('uso')->nullable();                   // para qué se usa
            $table->string('ubicacion')->nullable();
            $table->string('responsable')->nullable();
            $table->string('control', 12)->default('calibracion'); // calibracion / verificacion
            $table->unsignedSmallInteger('frecuencia_meses')->default(12);
            $table->string('estado', 16)->default('en_uso');     // en_uso / fuera_servicio / baja
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'codigo']);
        });

        Schema::create('equipment_calibrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('measuring_equipment_id')->constrained('measuring_equipment')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('tipo', 12);                           // calibracion / verificacion
            $table->string('realizado_por')->nullable();          // laboratorio o persona
            $table->boolean('acreditado_onac')->default(false);
            $table->string('certificado', 60)->nullable();
            $table->string('error_encontrado', 60)->nullable();
            $table->string('incertidumbre', 60)->nullable();
            $table->string('resultado', 12);                      // conforme / no_conforme
            // Si no es conforme: qué pasó con las mediciones hechas desde la
            // última calibración buena (9001 7.1.5.2).
            $table->text('impacto_mediciones')->nullable();
            $table->string('archivo')->nullable();
            $table->string('archivo_nombre')->nullable();
            $table->foreignId('acpm_action_id')->nullable()->constrained('acpm_actions')->nullOnDelete();
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_calibrations');
        Schema::dropIfExists('measuring_equipment');
    }
};
