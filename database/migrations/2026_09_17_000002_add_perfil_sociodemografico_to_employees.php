<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil sociodemográfico del trabajador — hoja «3.1.1 Encuesta Perfil Sociod»
 * del libro de CMK. Es la encuesta que alimenta el análisis de condiciones de
 * salud y, con él, buena parte del diagnóstico del SG-SST.
 *
 * Solo se agregan las preguntas que NO se pueden responder con lo que ya sabe
 * la ficha del empleado. De las 16 preguntas de la encuesta, cuatro ya están
 * resueltas y duplicarlas sería crear dos fuentes de verdad para el mismo dato:
 *
 *   1. Edad                    -> se calcula de `fecha_nacimiento`
 *   3. Género                  -> `genero`
 *   9. Antigüedad en la empresa-> se calcula de `fecha_ingreso`
 *  11. Tipo de contratación    -> `tipo_contrato`
 *
 * La 15 no es una pregunta de salud: es el CONSENTIMIENTO INFORMADO de la Ley
 * 1581 de 2012. Sin él la encuesta no se puede tabular, porque son datos
 * sensibles. Por eso se guarda con su fecha y no como una casilla más.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Las respuestas se guardan como el TEXTO de la opción elegida y no
            // como un código: así la tabulación sale legible y el consultor
            // puede añadir una opción sin migrar la base.
            $table->string('estado_civil', 40)->nullable()->after('genero');
            $table->string('personas_a_cargo', 30)->nullable()->after('estado_civil');
            $table->string('escolaridad', 40)->nullable()->after('personas_a_cargo');
            $table->string('tenencia_vivienda', 40)->nullable()->after('escolaridad');
            $table->string('uso_tiempo_libre', 60)->nullable()->after('tenencia_vivienda');
            $table->string('ingresos_smlv', 40)->nullable()->after('uso_tiempo_libre');
            $table->string('antiguedad_cargo', 30)->nullable()->after('ingresos_smlv');

            // Pregunta 12: es de selección MÚLTIPLE (puede haber participado en
            // varias), por eso va en json y no en una columna de texto.
            $table->json('actividades_salud')->nullable()->after('antiguedad_cargo');

            // Hábitos. El booleano y la frecuencia van separados porque «fuma»
            // se tabula como porcentaje y «cuánto fuma» no.
            $table->boolean('consume_alcohol')->nullable()->after('actividades_salud');
            $table->string('alcohol_frecuencia', 20)->nullable()->after('consume_alcohol');
            $table->boolean('fuma')->nullable()->after('alcohol_frecuencia');
            $table->string('fuma_promedio_dia', 30)->nullable()->after('fuma');
            $table->boolean('practica_deporte')->nullable()->after('fuma_promedio_dia');
            $table->string('deporte_frecuencia', 20)->nullable()->after('practica_deporte');

            // Consentimiento informado (Ley 1581 de 2012).
            $table->boolean('consentimiento_datos')->default(false)->after('deporte_frecuencia');
            $table->date('consentimiento_fecha')->nullable()->after('consentimiento_datos');

            // Cuándo se diligenció. La encuesta se repite cada año, y sin esta
            // marca no hay forma de saber si el perfil está vencido.
            $table->timestamp('perfil_actualizado_at')->nullable()->after('consentimiento_fecha');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'estado_civil', 'personas_a_cargo', 'escolaridad', 'tenencia_vivienda',
                'uso_tiempo_libre', 'ingresos_smlv', 'antiguedad_cargo', 'actividades_salud',
                'consume_alcohol', 'alcohol_frecuencia', 'fuma', 'fuma_promedio_dia',
                'practica_deporte', 'deporte_frecuencia',
                'consentimiento_datos', 'consentimiento_fecha', 'perfil_actualizado_at',
            ]);
        });
    }
};
