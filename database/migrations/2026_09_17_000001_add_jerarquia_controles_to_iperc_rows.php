<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completa la matriz IPERC con las columnas que trae la hoja «4.1.2 MATRIZ
 * IPERC» del libro de CMK y que aquí faltaban.
 *
 * La importante es la JERARQUÍA DE CONTROLES. La GTC 45 exige que las medidas
 * de intervención se propongan en orden —eliminación, sustitución, controles de
 * ingeniería, controles administrativos y señalización, y por último EPP— y el
 * Excel les da una columna a cada una. Aquí vivían todas fundidas en el campo
 * de texto `medidas`, y mientras estén fundidas no se puede verificar la regla
 * que CMK aplica a mano: que no se proponga EPP como control único si hay una
 * medida de orden superior viable.
 *
 * `medidas` NO se borra: las filas ya cargadas tienen su texto ahí y perderlo
 * sería destruir trabajo del consultor. Pasa a ser el campo de notas y lo
 * estructurado vive en las cinco columnas nuevas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iperc_rows', function (Blueprint $table) {
            // Peor consecuencia creíble del peligro, que es distinta del nivel
            // de consecuencia: el NC es el número, esto es la descripción.
            $table->string('peor_consecuencia')->nullable()->after('efectos');

            // Por qué se elige ese control: requisito legal, norma interna,
            // criterio del profesional. El Excel lo pide y sostiene la decisión
            // ante una auditoría.
            $table->text('criterio_controles')->nullable()->after('aceptabilidad');

            // Jerarquía de controles, en el orden de la GTC 45.
            $table->text('med_eliminacion')->nullable()->after('criterio_controles');
            $table->text('med_sustitucion')->nullable()->after('med_eliminacion');
            $table->text('med_ingenieria')->nullable()->after('med_sustitucion');
            $table->text('med_administrativos')->nullable()->after('med_ingenieria');
            $table->text('med_epp')->nullable()->after('med_administrativos');
        });
    }

    public function down(): void
    {
        Schema::table('iperc_rows', function (Blueprint $table) {
            $table->dropColumn([
                'peor_consecuencia',
                'criterio_controles',
                'med_eliminacion',
                'med_sustitucion',
                'med_ingenieria',
                'med_administrativos',
                'med_epp',
            ]);
        });
    }
};
