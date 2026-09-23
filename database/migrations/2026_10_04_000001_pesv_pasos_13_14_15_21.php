<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PESV · pasos 13, 14, 15 y 21:
 *  - pesv_siniestros: nivel de pérdida (1-4, matriz de CMK y Tabla 10),
 *    tipo de desplazamiento (el paso 21 pide separar laborales de los no
 *    laborales), costos directos e indirectos ($SV) e investigación (paso 13).
 *  - pesv_km_periodos: kilómetros recorridos por la flota por trimestre, el
 *    denominador de la TSV.
 *  - pesv_internal_roads: vías internas y su cronograma de mantenimiento (paso 14).
 *  - pesv_routes.plan: planificación del desplazamiento por ruta (paso 15, RE-SST-69).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pesv_siniestros', function (Blueprint $table) {
            $table->string('tipo_desplazamiento', 12)->default('laboral')->after('tipo');
            $table->unsignedTinyInteger('nivel_perdida')->nullable()->after('gravedad');
            $table->decimal('costo_directo', 14, 2)->nullable()->after('costo');
            $table->decimal('costo_indirecto', 14, 2)->nullable()->after('costo_directo');
            $table->date('fecha_investigacion')->nullable()->after('investigado');
            $table->text('equipo_investigador')->nullable()->after('fecha_investigacion');
            $table->text('causas_inmediatas')->nullable()->after('equipo_investigador');
            $table->text('causas_basicas')->nullable()->after('causas_inmediatas');
            $table->text('leccion_aprendida')->nullable()->after('causas_basicas');
            $table->boolean('leccion_divulgada')->default(false)->after('leccion_aprendida');
        });
        // El costo que había era uno solo: pasa a directo.
        DB::table('pesv_siniestros')->whereNotNull('costo')->update(['costo_directo' => DB::raw('costo')]);

        Schema::create('pesv_km_periodos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('trimestre');   // 1..4
            $table->decimal('km', 14, 1);
            $table->timestamps();
            $table->unique(['tenant_id', 'anio', 'trimestre']);
        });

        Schema::create('pesv_internal_roads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->text('riesgos_criticos')->nullable();
            $table->decimal('km', 8, 2)->nullable();
            $table->unsignedInteger('tiempo_min')->nullable();
            $table->boolean('frecuente')->default(true);
            $table->unsignedInteger('veces_mes')->nullable();
            $table->text('plan_accion')->nullable();
            $table->unsignedSmallInteger('anio_cronograma')->nullable();
            $table->json('cronograma')->nullable();     // [{actividad, costo, programados:[m], ejecutados:[m]}]
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        Schema::table('pesv_routes', function (Blueprint $table) {
            $table->json('plan')->nullable()->after('controles');
        });
    }

    public function down(): void
    {
        Schema::table('pesv_routes', fn (Blueprint $t) => $t->dropColumn('plan'));
        Schema::dropIfExists('pesv_internal_roads');
        Schema::dropIfExists('pesv_km_periodos');
        Schema::table('pesv_siniestros', fn (Blueprint $t) => $t->dropColumn([
            'tipo_desplazamiento', 'nivel_perdida', 'costo_directo', 'costo_indirecto', 'fecha_investigacion',
            'equipo_investigador', 'causas_inmediatas', 'causas_basicas', 'leccion_aprendida', 'leccion_divulgada',
        ]));
    }
};
