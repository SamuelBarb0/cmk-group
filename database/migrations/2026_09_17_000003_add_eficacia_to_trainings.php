<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eficacia y vigencia de las capacitaciones.
 *
 * Hasta ahora una capacitación solo registraba QUIÉN ASISTIÓ. Con eso se pueden
 * calcular cobertura y cumplimiento, pero no la EFICACIA, que el dashboard de
 * CMK lleva como IND3: «número de evaluaciones eficaces / número de personas
 * evaluadas». Sin la nota por asistente ese indicador no se puede calcular, solo
 * teclear a mano.
 *
 * La nota sale de la hoja «11. PRUEBA DE CONOCIMIENTO», que tiene casilla de
 * calificación pero NO dice cuál es la nota mínima para aprobar. Por eso
 * `nota_minima` es configurable por capacitación en vez de una constante
 * escondida en el código: el umbral lo pone CMK, no nosotros. El 70 es un valor
 * por defecto, no una regla.
 *
 * `vigencia_meses` es lo que pide la hoja «4.2.1 SEGUIMIENTO A CAPACITAC.», que
 * lleva por trabajador la fecha y el VENCIMIENTO de cada tema: hay formaciones
 * —alturas, manejo defensivo— que caducan y hay que repetir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->boolean('evalua_eficacia')->default(false)->after('estado');
            $table->unsignedTinyInteger('nota_minima')->default(70)->after('evalua_eficacia');
            // null = no caduca.
            $table->unsignedSmallInteger('vigencia_meses')->nullable()->after('nota_minima');
        });

        Schema::table('training_attendees', function (Blueprint $table) {
            // 0 a 100. Null = asistió pero todavía no se le evaluó, que no es lo
            // mismo que sacar cero: el denominador de IND3 son las personas
            // EVALUADAS, no las asistentes.
            $table->decimal('nota', 5, 2)->nullable()->after('asistio');
            // Se deriva de la nota y del umbral al guardar. Se materializa en vez
            // de calcularse al vuelo para poder agregar por SQL sin arrastrar la
            // capacitación en cada consulta del indicador.
            $table->boolean('eficaz')->nullable()->after('nota');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn(['evalua_eficacia', 'nota_minima', 'vigencia_meses']);
        });

        Schema::table('training_attendees', function (Blueprint $table) {
            $table->dropColumn(['nota', 'eficaz']);
        });
    }
};
