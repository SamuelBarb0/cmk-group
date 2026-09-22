<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EPP: catálogo, matriz por cargo y entrega firmada — hojas «4.2.6 MATRIZ EPP»
 * y «4.2.6 Entrega de EPP» del libro de CMK.
 *
 * Cierra el bucle que abrió el IPERC. Ahí ya se registra el EPP como último
 * escalón de la jerarquía de controles, pero era texto libre: no había forma de
 * saber qué EPP le toca a cada cargo, ni si se entregó.
 *
 * La entrega con firma es lo que de verdad pide una auditoría: sin el registro
 * firmado, para efectos legales el EPP no se entregó.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de elementos. Por cliente, porque cada empresa compra lo suyo.
        Schema::create('ppe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('nombre');
            // Las siete familias que agrupa la matriz de CMK: cabeza, visual,
            // respiratoria, auditiva, manos, pies, cuerpo.
            $table->string('categoria', 20);
            $table->string('norma')->nullable();          // NTC 1523, ANSI Z89.1…
            $table->text('uso')->nullable();              // contra qué protege
            // Texto y no un número de días: el Excel lo expresa en rangos
            // («Entre 2 a 3 meses») y convertirlo a un número inventaría una
            // precisión que el dato no tiene.
            $table->string('vida_util', 60)->nullable();
            $table->text('criterio_reposicion')->nullable();

            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'categoria']);
        });

        // La matriz: qué EPP le corresponde a cada cargo.
        Schema::create('ppe_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('ppe_item_id')->constrained('ppe_items')->cascadeOnDelete();

            // El cargo va como texto y no como relación: la matriz se define por
            // CARGO, no por persona, y los cargos de esta empresa viven hoy como
            // una columna de texto en `employees`.
            $table->string('cargo');
            $table->string('area')->nullable();

            // requerido | segun_necesidad — la R y la S de la matriz de CMK.
            $table->string('requerimiento', 20)->default('requerido');
            $table->text('observaciones')->nullable();

            $table->timestamps();

            // Un elemento no se le asigna dos veces al mismo cargo.
            $table->unique(['tenant_id', 'cargo', 'ppe_item_id'], 'ppe_cargo_item_unico');
        });

        // La entrega, con su constancia.
        Schema::create('ppe_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('ppe_item_id')->constrained('ppe_items')->cascadeOnDelete();

            $table->date('fecha_entrega');
            $table->unsignedSmallInteger('cantidad')->default(1);
            $table->string('talla', 20)->nullable();
            $table->string('entregado_por')->nullable();

            // La firma es lo que convierte el registro en evidencia. Se guarda
            // el nombre de quien recibe y la fecha en que firmó; sin esto, para
            // una auditoría la entrega no ocurrió.
            $table->string('recibido_por')->nullable();
            $table->date('fecha_firma')->nullable();

            // reposicion = cambia uno gastado; dotacion = entrega inicial.
            $table->string('motivo', 15)->default('dotacion');
            $table->text('observaciones')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'fecha_entrega']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppe_deliveries');
        Schema::dropIfExists('ppe_assignments');
        Schema::dropIfExists('ppe_items');
    }
};
