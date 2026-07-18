<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de Indicadores del SGI.
 *
 * - indicators: catálogo de indicadores. tenant_id NULL = preset legal global
 *   (Res. 0312 / Decreto 1072); con tenant_id = indicador propio del cliente.
 *   Fórmula genérica: valor = (numerador / denominador) × constante.
 * - indicator_readings: lectura mensual (numerador/denominador) por cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('codigo', 20);
            $table->string('nombre');
            $table->string('categoria', 20)->default('SST');     // SST / HSEQ / PESV / Proceso
            $table->string('numerador_label');
            $table->string('denominador_label');
            $table->unsignedInteger('constante')->default(100);   // 100, 1000, 100000, 240000
            $table->string('unidad', 10)->default('%');           // % / tasa
            $table->string('sentido', 4)->default('desc');        // asc (mayor mejor) / desc (menor mejor)
            $table->decimal('meta', 10, 2)->default(0);
            $table->boolean('es_legal')->default(false);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('indicator_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('indicator_id')->constrained('indicators')->cascadeOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');                   // 1..12
            $table->decimal('numerador', 12, 2)->default(0);
            $table->decimal('denominador', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'indicator_id', 'anio', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicator_readings');
        Schema::dropIfExists('indicators');
    }
};
