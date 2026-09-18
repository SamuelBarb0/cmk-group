<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporte de actos y condiciones inseguras — hoja «2.8.1 Reporte de act y cond»
 * y «5.4 REPORTE AyC» de los libros de CMK.
 *
 * Es el canal por el que CUALQUIER trabajador levanta la mano, no solo el
 * profesional de SST. De ahí que el reportante sea texto libre y no un
 * `employee_id` obligatorio: en varias empresas el reporte se recibe en papel o
 * de alguien que no está en la nómina del sistema (un contratista, una visita),
 * y exigir el vínculo haría que esos reportes simplemente no se registraran.
 *
 * Alimenta el indicador RED-AC: intervenidas sobre reportadas. Por eso el estado
 * distingue `reportado` de `intervenido`, que son el denominador y el numerador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->date('fecha');
            $table->string('reportado_por');
            // Opcional: si el reportante está en la nómina se vincula, y así se
            // puede saber quién reporta y quién no.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('area')->nullable();
            $table->string('lugar')->nullable();

            // acto = lo que hace una persona | condicion = cómo está el entorno.
            $table->string('tipo', 12);
            $table->text('descripcion');
            $table->string('clasificacion_peligro', 60)->nullable();  // misma taxonomía GTC 45 del IPERC

            // Qué tan grave sería si pasara: bajo / medio / alto / critico.
            $table->string('severidad', 10)->default('medio');

            $table->text('accion_inmediata')->nullable();

            // reportado -> intervenido -> cerrado. `intervenido` es lo que cuenta
            // para RED-AC; `cerrado` es que además se verificó.
            $table->string('estado', 12)->default('reportado');
            $table->date('fecha_intervencion')->nullable();
            $table->string('responsable_intervencion')->nullable();

            // Cuando el reporte da origen a una acción formal, se enlaza en vez
            // de repetir el seguimiento aquí.
            $table->foreignId('acpm_action_id')->nullable()->constrained('acpm_actions')->nullOnDelete();

            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'estado']);
            $table->index(['tenant_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_reports');
    }
};
