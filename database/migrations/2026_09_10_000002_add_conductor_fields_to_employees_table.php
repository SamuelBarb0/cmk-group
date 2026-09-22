<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha de conductor sobre el empleado ya existente.
 *
 * El PESV (Res. 40595, Paso 5) exige caracterizar a los colaboradores que
 * conducen. En vez de duplicar la lista de personas en una tabla aparte, se
 * marca `es_conductor` sobre el empleado y se le cuelgan los datos de licencia
 * y exámenes. Los conductores que NO son empleados (terceros) se registran en
 * `pesv_contractors`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('es_conductor')->default(false)->after('nivel_riesgo');
            $table->string('licencia_numero', 30)->nullable()->after('es_conductor');
            // A1, A2, B1, B2, B3, C1, C2, C3 (Ley 769 / Res. 20223040040595)
            $table->string('licencia_categoria', 10)->nullable()->after('licencia_numero');
            $table->date('licencia_vence')->nullable()->after('licencia_categoria');
            $table->date('examen_psicosensometrico_vence')->nullable()->after('licencia_vence');
            $table->date('curso_manejo_defensivo')->nullable()->after('examen_psicosensometrico_vence');
            $table->text('observaciones_conductor')->nullable()->after('curso_manejo_defensivo');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'es_conductor',
                'licencia_numero',
                'licencia_categoria',
                'licencia_vence',
                'examen_psicosensometrico_vence',
                'curso_manejo_defensivo',
                'observaciones_conductor',
            ]);
        });
    }
};
