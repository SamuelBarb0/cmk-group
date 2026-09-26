<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que abarca una presentación: varias piezas de cualquier módulo del mapa.
 * Cada pieza es un módulo completo («M11»), una pantalla de un módulo
 * («M09:epp») o una parte de pantalla («M09:epp.matriz»). Vacío o nulo = todo
 * el sistema. `modulo` queda con el módulo cuando todas las piezas son de uno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->json('seleccion')->nullable()->after('modulo');
        });
    }

    public function down(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->dropColumn('seleccion');
        });
    }
};
