<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de prevención, preparación y respuesta ante emergencias (estándares
 * 5.1.1 y 5.1.2 de la Res. 0312): brigada, simulacros, equipos y directorio.
 *
 * Sale de las 13 hojas de emergencias de los Excel de CMK («5.1.1 …» en el
 * libro SST-PESV y «8.2 …» en el SGI). Lo que ya tenía casa NO se repite aquí:
 * la lista de chequeo del simulacro y las inspecciones de extintores y
 * botiquín son formatos del motor genérico, y el documento del plan es una
 * plantilla (PL-EMERGENCIAS). Aquí va lo que son REGISTROS vivos.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Hojas «Formulario Brigada» y «Estructura brigada»: una fila por
        // persona, con su rol en el organigrama de la brigada.
        Schema::create('brigade_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Opcional: hay brigadistas contratistas que no están en la nómina.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('nombres');
            $table->string('numero_documento', 40)->nullable();
            $table->string('cargo')->nullable();
            $table->string('telefono', 50)->nullable();

            // coordinador_emergencias / jefe_brigada / lider_* / seguridad_fisica /
            // enlace_logistica / brigadista
            $table->string('rol', 30)->default('brigadista');
            // primeros_auxilios / evacuacion / contra_incendios
            $table->string('especialidad', 20)->nullable();
            $table->date('fecha_inscripcion')->nullable();

            // Datos que el MEDEVAC necesita tener a mano en una emergencia.
            $table->string('grupo_sanguineo', 5)->nullable();
            $table->string('eps')->nullable();
            $table->string('arl')->nullable();
            $table->string('limitaciones_fisicas')->nullable();   // null = ninguna
            $table->boolean('usa_anteojos')->default(false);
            $table->string('contacto_emergencia_nombre')->nullable();
            $table->string('contacto_emergencia_telefono', 50)->nullable();

            // El formato lo marca como obligatorio para todo brigadista.
            $table->boolean('curso_primer_respondiente')->default(false);
            $table->date('fecha_curso')->nullable();

            $table->boolean('activo')->default(true);
            $table->text('observaciones')->nullable();
            $table->timestamps();

            // La misma persona de la nómina no se inscribe dos veces.
            $table->unique(['tenant_id', 'employee_id']);
        });

        // «Programa de emergencias»: sus tres indicadores salen de aquí.
        Schema::create('emergency_drills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->date('fecha');
            $table->string('tipo', 10);                     // interno / externo
            $table->string('escenario');                    // sismo, incendio, evacuación…
            $table->string('estado', 12)->default('programado'); // programado / realizado
            $table->string('sede')->nullable();
            $table->string('entidades_apoyo')->nullable();  // bomberos, defensa civil… (externos)

            // «Lista de chequeo de simulacro», cabecera.
            $table->time('hora_inicio')->nullable();
            $table->time('hora_fin')->nullable();
            // Hasta que sale la última persona del edificio.
            $table->unsignedInteger('tiempo_evacuacion_segundos')->nullable();
            $table->unsignedInteger('evacuados')->nullable();

            // Indicador 3: participación sobre convocados.
            $table->unsignedInteger('convocados')->nullable();
            $table->unsignedInteger('participantes')->nullable();

            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'fecha']);
        });

        // Indicador 2: recomendaciones implementadas sobre generadas. Van como
        // filas y no como dos contadores para que se pueda perseguir cada una.
        Schema::create('emergency_drill_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emergency_drill_id')->constrained('emergency_drills')->cascadeOnDelete();

            $table->string('descripcion', 500);
            $table->string('responsable')->nullable();
            $table->date('fecha_limite')->nullable();
            $table->boolean('implementada')->default(false);
            $table->date('fecha_implementacion')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);

            $table->timestamps();
        });

        // «Inventario general de equipos y elementos de emergencias».
        Schema::create('emergency_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // «Vehículo / puesto de control» en el Excel: dónde está el equipo.
            $table->string('ubicacion');
            $table->string('ciudad', 120)->nullable();
            $table->string('direccion')->nullable();

            $table->string('elemento');
            $table->unsignedInteger('cantidad')->default(1);
            $table->string('ubicacion_exacta')->nullable();
            // primeros_auxilios / contra_incendios / evacuacion
            $table->string('tipo', 20);
            $table->string('estado', 10)->default('bueno'); // bueno / regular / malo

            $table->date('fecha_revision')->nullable();
            // No está en el Excel y es lo que más se escapa: la recarga del
            // extintor y los insumos del botiquín caducan.
            $table->date('fecha_vencimiento')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });

        // Directorio del MEDEVAC: responsables internos, entidades de apoyo y
        // prestadores de salud.
        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('tipo', 20);                     // interno / entidad_apoyo / prestador_salud
            $table->string('nombre');
            // Cargo (interno), «llamar en caso de» (entidad) o servicio (prestador).
            $table->string('detalle')->nullable();
            $table->string('telefono', 60)->nullable();
            $table->string('telefono_alterno', 60)->nullable();
            $table->string('direccion')->nullable();
            $table->string('ciudad', 120)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_contacts');
        Schema::dropIfExists('emergency_equipment');
        Schema::dropIfExists('emergency_drill_recommendations');
        Schema::dropIfExists('emergency_drills');
        Schema::dropIfExists('brigade_members');
    }
};
