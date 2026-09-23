<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PESV · pasos 5 y 6:
 *  - pesv_mobility_surveys: respuestas a la encuesta de movilidad (RE-SST-36),
 *    diligenciada por el trabajador desde un enlace público o por el consultor.
 *  - pesv_plans: token del enlace de la encuesta y análisis del diagnóstico.
 *  - pesv_road_risks: matriz de riesgos viales (RE-SST-45).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pesv_plans', function (Blueprint $table) {
            $table->string('encuesta_token', 48)->nullable()->unique()->after('avance');
            $table->boolean('encuesta_activa')->default(false)->after('encuesta_token');
            $table->text('diagnostico_analisis')->nullable()->after('encuesta_activa');
        });

        Schema::create('pesv_mobility_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('fecha');
            $table->string('nombre');
            $table->string('documento', 30);
            $table->json('respuestas');
            $table->string('origen', 12)->default('enlace');   // enlace / consultor
            $table->timestamps();
            $table->index(['tenant_id', 'documento']);
        });

        Schema::create('pesv_road_risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('desempeno', 12);                   // humano / vehiculo / via / ambiente
            $table->string('factor');
            $table->string('perfil')->nullable();
            $table->string('cargo')->nullable();
            $table->string('rol_via', 15)->nullable();
            $table->string('tipo_vehiculo')->nullable();
            $table->unsignedTinyInteger('exposicion');          // 1..3
            $table->unsignedTinyInteger('probabilidad');        // 1..3
            $table->unsignedTinyInteger('valor');               // exposición × probabilidad
            $table->string('nivel', 10);                        // bajo / moderado / critico
            $table->string('accion', 12)->nullable();
            $table->json('controles')->nullable();
            $table->json('lineas')->nullable();
            $table->boolean('eficaz')->nullable();
            $table->date('fecha_identificacion');
            $table->date('fecha_cierre')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'nivel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesv_road_risks');
        Schema::dropIfExists('pesv_mobility_surveys');
        Schema::table('pesv_plans', fn (Blueprint $t) => $t->dropColumn(['encuesta_token', 'encuesta_activa', 'diagnostico_analisis']));
    }
};
