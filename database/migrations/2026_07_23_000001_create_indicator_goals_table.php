<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta propia de un cliente para un indicador preset (global). Si no existe
 * fila, aplica la meta legal por defecto del indicador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicator_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('indicator_id')->constrained()->cascadeOnDelete();
            $table->decimal('meta', 10, 2);
            $table->timestamps();

            $table->unique(['tenant_id', 'indicator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicator_goals');
    }
};
