<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empleados (trabajadores) de cada empresa cliente.
 *
 * Es el registro base del SGI: de aquí dependen profesiograma, exámenes
 * médicos, entrega de EPP, capacitaciones, competencias, etc.
 * Segregado por tenant_id (una nómina por cliente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Identificación
            $table->string('nombres');
            $table->string('apellidos');
            $table->string('tipo_documento', 5)->default('CC'); // CC, CE, TI, PA, PEP
            $table->string('numero_documento', 30);

            // Datos personales
            $table->date('fecha_nacimiento')->nullable();
            $table->string('genero', 20)->nullable();          // Masculino/Femenino/Otro
            $table->string('grupo_sanguineo', 5)->nullable();  // O+, A-, ...
            $table->string('telefono', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('direccion')->nullable();
            $table->string('ciudad', 120)->nullable();

            // Datos laborales
            $table->string('cargo')->nullable();
            $table->string('area')->nullable();
            $table->string('sede')->nullable();
            $table->date('fecha_ingreso')->nullable();
            $table->string('tipo_contrato', 40)->nullable();   // Indefinido/Fijo/Obra/Prestación
            $table->decimal('salario', 15, 2)->nullable();

            // Seguridad social
            $table->string('eps')->nullable();
            $table->string('afp')->nullable();                 // Fondo de pensiones
            $table->string('arl')->nullable();
            $table->string('nivel_riesgo', 3)->nullable();     // I..V (clase de riesgo ARL)

            $table->boolean('is_active')->default(true);       // Activo / retirado
            $table->timestamps();

            // Un documento único por cliente.
            $table->unique(['tenant_id', 'numero_documento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
