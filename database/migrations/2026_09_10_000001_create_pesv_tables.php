<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan Estratégico de Seguridad Vial (Resolución 40595 de 2022).
 *
 * La norma organiza el PESV en 4 fases y 24 pasos:
 *   Fase 1 Planificación (1-8) · Fase 2 Implementación (9-19)
 *   Fase 3 Seguimiento (20-22)  · Fase 4 Mejora continua (23-24)
 *
 * - pesv_steps: catálogo GLOBAL de los 24 pasos (igual que sst_standards).
 * - pesv_plans: un plan por empresa cliente (segregado por tenant).
 * - pesv_plan_steps: estado y evidencia de cada paso dentro del plan.
 *
 * El resto de tablas son la caracterización del Paso 5 (Diagnóstico) y los
 * insumos que los pasos posteriores necesitan: sedes, vehículos, contratistas,
 * rutas y siniestros viales.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Catálogo global de los 24 pasos (compartido por todos los clientes).
        Schema::create('pesv_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('numero')->unique();   // 1..24
            $table->unsignedTinyInteger('fase');               // 1..4
            $table->string('fase_nombre', 60);                 // Planificación, ...
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->unsignedSmallInteger('orden');
            $table->timestamps();
        });

        Schema::create('pesv_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // básico / estándar / avanzado. Lo fija el consultor: la norma lo
            // deriva de la misionalidad y el tamaño de la flota, y ese criterio
            // se sugiere en pantalla pero NO se impone desde el código.
            $table->string('nivel', 20)->default('basico');
            $table->unsignedSmallInteger('periodo_inicio')->nullable();
            $table->unsignedSmallInteger('periodo_fin')->nullable();
            $table->string('lider_nombre')->nullable();
            $table->string('lider_cargo')->nullable();
            $table->string('lider_documento', 30)->nullable();
            $table->date('lider_designacion_fecha')->nullable();
            $table->decimal('avance', 5, 2)->default(0);       // 0..100
            $table->timestamps();

            $table->unique('tenant_id');
        });

        Schema::create('pesv_plan_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pesv_plan_id')->constrained('pesv_plans')->cascadeOnDelete();
            $table->foreignId('pesv_step_id')->constrained('pesv_steps')->cascadeOnDelete();
            // pendiente / en_proceso / cumple / no_cumple / no_aplica
            $table->string('estado', 15)->default('pendiente');
            $table->text('observaciones')->nullable();
            $table->string('responsable')->nullable();
            $table->date('fecha_cumplimiento')->nullable();
            $table->timestamps();

            $table->unique(['pesv_plan_id', 'pesv_step_id']);
        });

        // ---- Caracterización (Paso 5) -------------------------------------

        Schema::create('pesv_sedes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('direccion')->nullable();
            $table->string('ciudad', 120)->nullable();
            $table->string('departamento', 120)->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('responsable')->nullable();
            $table->unsignedInteger('num_trabajadores')->nullable();
            $table->boolean('es_principal')->default(false);
            $table->timestamps();
        });

        Schema::create('pesv_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('placa', 10);
            // automovil / camioneta / campero / camion / bus / motocicleta / maquinaria / otro
            $table->string('tipo', 30);
            $table->string('marca', 60)->nullable();
            $table->string('linea', 60)->nullable();
            $table->unsignedSmallInteger('modelo')->nullable();     // año
            // propio / arrendado / contratista / colaborador / leasing
            $table->string('propiedad', 20)->default('propio');
            $table->string('propietario')->nullable();
            $table->date('soat_vence')->nullable();
            $table->date('tecnomecanica_vence')->nullable();
            $table->date('poliza_vence')->nullable();
            $table->unsignedInteger('kilometraje')->nullable();
            $table->date('ultimo_mantenimiento')->nullable();
            $table->date('proximo_mantenimiento')->nullable();
            $table->text('observaciones')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'placa']);
        });

        Schema::create('pesv_contractors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('nit', 30)->nullable();
            // contratista / subcontratista / tercero / proveedor / propietario_vehiculo
            $table->string('tipo', 30)->default('contratista');
            $table->string('actividad')->nullable();
            $table->string('contacto_nombre')->nullable();
            $table->string('contacto_telefono', 50)->nullable();
            $table->string('contacto_email')->nullable();
            $table->unsignedInteger('num_conductores')->nullable();
            $table->unsignedInteger('num_vehiculos')->nullable();
            $table->boolean('tiene_pesv')->default(false);
            $table->date('evaluado_at')->nullable();
            $table->unsignedTinyInteger('calificacion')->nullable();  // 0..100
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pesv_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('origen')->nullable();
            $table->string('destino')->nullable();
            $table->string('tipo_via', 20)->nullable();      // urbana / rural / nacional / mixta
            $table->decimal('distancia_km', 8, 2)->nullable();
            $table->unsignedInteger('duracion_min')->nullable();
            $table->string('frecuencia', 20)->nullable();    // diaria / semanal / mensual / ocasional
            $table->string('horario', 120)->nullable();
            $table->text('peligros')->nullable();
            $table->text('controles')->nullable();
            $table->string('nivel_riesgo', 20)->nullable();  // bajo / medio / alto / critico
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ---- Comité de Seguridad Vial (Paso 2) ----------------------------

        Schema::create('pesv_committee_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pesv_plan_id')->constrained('pesv_plans')->cascadeOnDelete();
            // Si el integrante es un empleado ya registrado se enlaza; si no,
            // se guarda a mano (puede ser un contratista o un externo).
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('nombre');
            $table->string('documento', 30)->nullable();
            $table->string('cargo')->nullable();
            $table->string('rol_comite', 30)->default('integrante'); // presidente/secretario/integrante
            $table->boolean('es_representante_direccion')->default(false);
            $table->timestamps();
        });

        // ---- Siniestros viales (Pasos 13 y 21) ----------------------------

        Schema::create('pesv_siniestros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('fecha');
            $table->time('hora')->nullable();
            $table->string('lugar')->nullable();
            // choque / atropello / volcamiento / caida_ocupante / incendio / otro
            $table->string('tipo', 30);
            // solo_danos / con_heridos / fatal
            $table->string('gravedad', 20)->default('solo_danos');
            $table->foreignId('pesv_vehicle_id')->nullable()->constrained('pesv_vehicles')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('descripcion')->nullable();
            $table->text('causa_probable')->nullable();
            $table->unsignedSmallInteger('lesionados')->default(0);
            $table->unsignedSmallInteger('fallecidos')->default(0);
            $table->unsignedInteger('dias_incapacidad')->nullable();
            $table->decimal('costo', 15, 2)->nullable();
            $table->boolean('investigado')->default(false);
            $table->text('acciones')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesv_siniestros');
        Schema::dropIfExists('pesv_committee_members');
        Schema::dropIfExists('pesv_routes');
        Schema::dropIfExists('pesv_contractors');
        Schema::dropIfExists('pesv_vehicles');
        Schema::dropIfExists('pesv_sedes');
        Schema::dropIfExists('pesv_plan_steps');
        Schema::dropIfExists('pesv_plans');
        Schema::dropIfExists('pesv_steps');
    }
};
