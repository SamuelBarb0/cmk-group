<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programas de gestión (estándar 4.2.1 de la Res. 0312 y pasos 8 y 16 del
 * PESV): PVE osteomuscular y de estilos de vida, alcohol y SPA, fatiga,
 * velocidad, distracción, actores viales y gestión ambiental.
 *
 * En los Excel de CMK cada programa es una hoja con la MISMA forma: objetivo,
 * alcance, 2 a 5 indicadores y un cronograma PHVA con P/E por mes. Por eso es
 * un solo módulo con catálogo, y no un módulo por programa.
 *
 * Los programas que ya tienen casa propia NO están aquí: capacitaciones,
 * inspecciones (Formatos), emergencias y mantenimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Catálogo GLOBAL, sembrado desde los Excel. Las actividades y los
        // indicadores van en JSON porque solo se leen al adoptar el programa.
        Schema::create('management_programs', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre');
            $table->string('categoria', 20);                  // pve / sst / pesv / ambiental
            $table->text('objetivo')->nullable();
            $table->text('alcance')->nullable();
            $table->string('recursos')->nullable();
            // Formato del motor genérico con el que se ejecuta el programa
            // (FT-LCH-FATIGA para fatiga, FT-INS-AMB para el ambiental).
            $table->string('formato_codigo', 30)->nullable();
            $table->json('actividades');
            $table->json('indicadores');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        // El programa adoptado por una empresa para un año. Guarda COPIA de
        // los textos del catálogo: el consultor los adapta a la empresa (el
        // propio Excel dice «es una guía, la cual debe adaptar»), y editar el
        // catálogo no debe cambiar lo que ya se firmó en un cliente.
        Schema::create('program_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Nulo = programa propio de la empresa, fuera del catálogo.
            $table->foreignId('management_program_id')->nullable()->constrained('management_programs')->nullOnDelete();
            $table->unsignedSmallInteger('anio');

            $table->string('codigo', 30);
            $table->string('nombre');
            $table->string('categoria', 20);
            $table->text('objetivo')->nullable();
            $table->text('alcance')->nullable();
            $table->string('recursos')->nullable();
            $table->string('formato_codigo', 30)->nullable();
            $table->string('responsable')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'codigo', 'anio']);
        });

        Schema::create('program_plan_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_plan_id')->constrained('program_plans')->cascadeOnDelete();
            $table->string('fase', 10);                       // planear / hacer / verificar / actuar
            $table->string('nombre', 500);
            $table->string('responsable')->nullable();
            $table->decimal('presupuesto', 15, 2)->nullable();
            $table->json('meses_programados')->nullable();    // [1..12]
            $table->json('meses_ejecutados')->nullable();     // [1..12]
            $table->text('observaciones')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        // Un indicador del programa. El de cumplimiento es `automatico`: sale
        // del cronograma y no se digita. Los demás guardan numerador y
        // denominador por periodo en `lecturas` ({"1": {"numerador": …}}); son
        // como mucho 4 periodos al año, no justifican otra tabla.
        Schema::create('program_plan_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_plan_id')->constrained('program_plans')->cascadeOnDelete();
            $table->string('clave', 30);
            $table->string('nombre');
            $table->string('numerador_label');
            $table->string('denominador_label');
            $table->decimal('constante', 12, 2)->default(100);
            $table->decimal('meta', 8, 2)->nullable();
            $table->string('meta_texto')->nullable();          // la meta tal como la escribe el Excel
            $table->string('sentido', 4)->default('asc');      // asc: más es mejor / desc: menos es mejor
            $table->string('frecuencia', 12)->default('semestral'); // trimestral / semestral / anual
            $table->boolean('automatico')->default(false);
            $table->json('lecturas')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_plan_indicators');
        Schema::dropIfExists('program_plan_activities');
        Schema::dropIfExists('program_plans');
        Schema::dropIfExists('management_programs');
    }
};
