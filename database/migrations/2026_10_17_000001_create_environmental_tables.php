<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M18 — Gestión ambiental (ISO 14001): los registros operativos que faltaban.
 * La matriz de aspectos e impactos ya está en el M04 y el cronograma del
 * programa ambiental en Programas de gestión; aquí van los DATOS con que se
 * miden:
 *
 * - `waste_records`: cada entrega de residuos a un gestor, con su tipo, peso,
 *   disposición y certificado (SIG-51). Con los peligrosos se calcula la
 *   categoría de generador RESPEL.
 * - `resource_readings`: consumo mensual de agua, energía, gas y
 *   combustible (SIG-51), para ver la tendencia contra el año anterior.
 * - `chemical_products`: inventario de productos químicos con sus peligros
 *   SGA y la fecha de su hoja de datos de seguridad (SIG-26).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('tipo', 12);                       // aprovechable / organico / ordinario / peligroso / raee / especial
            $table->string('corriente')->nullable();          // qué residuo: luminarias, aceite usado, cartón…
            $table->decimal('cantidad_kg', 10, 2);
            $table->string('gestor')->nullable();
            $table->string('licencia_gestor', 120)->nullable(); // licencia o permiso ambiental del gestor
            $table->string('disposicion', 16)->nullable();    // aprovechamiento / tratamiento / relleno / incineracion / celda_seguridad / posconsumo
            $table->string('certificado', 60)->nullable();
            $table->string('archivo')->nullable();
            $table->string('archivo_nombre')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'fecha']);
        });

        Schema::create('resource_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('periodo');                          // primer día del mes
            $table->string('recurso', 12);                    // agua / energia / gas / combustible
            $table->decimal('cantidad', 12, 2);
            $table->decimal('costo', 14, 2)->nullable();
            $table->unsignedInteger('trabajadores')->nullable(); // para el consumo por persona
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'periodo', 'recurso']);
        });

        Schema::create('chemical_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('proveedor')->nullable();
            $table->string('uso')->nullable();
            $table->string('cantidad', 60)->nullable();       // lo que se almacena: «2 galones»
            $table->string('ubicacion')->nullable();
            $table->json('peligros');                         // pictogramas SGA: GHS01…GHS09
            $table->date('hds_fecha')->nullable();            // fecha de revisión de la HDS; nulo = no se tiene
            $table->string('epp')->nullable();
            $table->boolean('activo')->default(true);
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chemical_products');
        Schema::dropIfExists('resource_readings');
        Schema::dropIfExists('waste_records');
    }
};
