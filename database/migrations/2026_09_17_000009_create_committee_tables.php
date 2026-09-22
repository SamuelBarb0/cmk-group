<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comités: COPASST (Res. 2013 de 1986 / Dec. 1072) y Comité de Convivencia
 * Laboral (Res. 652 y 1356 de 2012).
 *
 * Se construye porque dos indicadores YA SEMBRADOS —CUMP-COPASST y
 * CUMP-COCOLAB— no tienen de dónde sacar su cifra: ambos son «actividades
 * ejecutadas sobre actividades programadas», y esas actividades viven en la
 * hoja «1.1.7 Seguimiento C» del libro de CMK, que hoy nadie puede diligenciar
 * en la plataforma.
 *
 * Tres tablas y no una, porque son tres cosas con vidas distintas: el comité
 * (se conforma y vence), sus miembros (rotan) y su plan de trabajo anual (es lo
 * que se mide).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('committees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('tipo', 10);                   // copasst / cocolab
            $table->unsignedSmallInteger('periodo');      // año de inicio de la vigencia
            $table->date('fecha_conformacion');
            // La vigencia legal es de dos años en los dos comités.
            $table->date('fecha_vencimiento')->nullable();

            // El número de trabajadores decide cuántos miembros exige la norma.
            $table->unsignedInteger('numero_trabajadores')->nullable();
            $table->string('acta_conformacion', 60)->nullable();
            $table->text('observaciones')->nullable();

            $table->timestamps();

            // Un solo comité de cada tipo por periodo y empresa.
            $table->unique(['tenant_id', 'tipo', 'periodo']);
        });

        Schema::create('committee_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_id')->constrained('committees')->cascadeOnDelete();
            // Opcional igual que en los reportes: hay miembros que entran por
            // designación del empleador y no siempre están en la nómina cargada.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('nombres');
            $table->string('numero_documento', 40)->nullable();
            $table->string('cargo')->nullable();

            // presidente / secretario / principal / suplente
            $table->string('rol', 15);
            // empleador / trabajadores: la norma exige paridad entre las dos partes.
            $table->string('representa', 15);
            // Solo para los representantes de los trabajadores, que se eligen por voto.
            $table->unsignedInteger('votos')->nullable();

            $table->timestamps();
        });

        Schema::create('committee_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_id')->constrained('committees')->cascadeOnDelete();

            $table->string('descripcion');
            $table->unsignedSmallInteger('orden')->default(0);

            // Programada y ejecutada por separado: son el denominador y el
            // numerador del indicador. Una actividad que se ejecuta sin estar
            // programada suma arriba pero no abajo, y eso es correcto.
            $table->boolean('programada')->default(true);
            $table->boolean('ejecutada')->default(false);
            $table->date('fecha_ejecucion')->nullable();
            $table->text('evidencia')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committee_activities');
        Schema::dropIfExists('committee_members');
        Schema::dropIfExists('committees');
    }
};
