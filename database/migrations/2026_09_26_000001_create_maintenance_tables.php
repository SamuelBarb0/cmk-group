<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mantenimiento de activos: máquinas, equipos, herramientas, instalaciones y
 * vehículos (PASO 17 del PESV y estándar 4.2.5 de la Res. 0312).
 *
 * Sale de «17. PROGRAMA DE MTTO», «17. SEGUIMIENTO MTO» y «17. HOJA DE VIDA
 * VEH.» (PASO 17) y de «7.1 MANTENIMIENTO DE ACTIVOS» (libro SGI). El
 * cronograma PHVA del programa de mantenimiento vive en Programas de gestión
 * (PR-SST-06); aquí va lo que son DATOS: qué activos hay, qué se les hace y
 * cada cuánto, y el registro de lo que se hizo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // vehiculo / maquina / equipo / herramienta / locativo / tecnologia / otro
            $table->string('tipo', 20);
            $table->string('nombre');
            $table->string('codigo', 40)->nullable();          // placa o código interno
            $table->string('marca')->nullable();
            $table->string('modelo')->nullable();
            $table->string('serie')->nullable();
            $table->string('ubicacion')->nullable();
            // El vehículo ya existe en la caracterización del PESV: se enlaza,
            // no se vuelve a escribir.
            $table->foreignId('pesv_vehicle_id')->nullable()->constrained('pesv_vehicles')->nullOnDelete();
            // Para los planes por uso: km (vehículos) u horas (máquinas).
            $table->string('unidad_lectura', 5)->nullable();
            $table->unsignedInteger('lectura_actual')->nullable();
            $table->date('fecha_ingreso')->nullable();
            $table->string('responsable')->nullable();
            $table->boolean('activo')->default(true);
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'pesv_vehicle_id']);
        });

        // Qué se le hace al activo y cada cuánto. Sin tenant propio: cuelga
        // del activo y se guarda siempre a través de él.
        Schema::create('maintenance_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_asset_id')->constrained('maintenance_assets')->cascadeOnDelete();
            $table->string('actividad', 500);
            $table->unsignedInteger('frecuencia_valor')->nullable();
            $table->string('frecuencia_unidad', 5)->nullable();  // dias / km / horas
            $table->string('responsable')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        // «17. SEGUIMIENTO MTO» y el formato de mantenimiento de activos.
        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('maintenance_asset_id')->constrained('maintenance_assets')->cascadeOnDelete();
            // Nulo = mantenimiento fuera del plan (un correctivo, casi siempre).
            $table->foreignId('maintenance_plan_item_id')->nullable()->constrained('maintenance_plan_items')->nullOnDelete();
            $table->date('fecha');
            $table->string('tipo', 12);                          // preventivo / correctivo
            $table->text('descripcion');
            $table->unsignedInteger('lectura')->nullable();      // km u horas al momento
            $table->string('realizado_por')->nullable();         // proveedor o persona
            $table->boolean('proveedor_idoneo')->nullable();     // «idoneidad del proveedor»
            $table->string('factura', 60)->nullable();
            $table->decimal('valor', 14, 2)->nullable();
            $table->string('verificado_por')->nullable();
            $table->text('hallazgos')->nullable();
            $table->string('estado', 10)->default('cerrada');    // abierta / cerrada
            $table->timestamps();

            $table->index(['tenant_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_records');
        Schema::dropIfExists('maintenance_plan_items');
        Schema::dropIfExists('maintenance_assets');
    }
};
