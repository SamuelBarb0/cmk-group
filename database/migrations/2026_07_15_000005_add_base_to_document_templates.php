<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documento MODELO base para las plantillas.
 *
 * En vez de generar desde cero, la IA rellena/adapta este contenido base
 * (los documentos reales de CMK con marcadores XXXX / NOMBRE DE LA EMPRESA).
 * `archivo` guarda la ruta del .docx original (para export con formato futuro).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->longText('contenido_base')->nullable()->after('descripcion');
            $table->string('archivo')->nullable()->after('contenido_base'); // storage/app/plantillas-base/CODIGO.docx
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn(['contenido_base', 'archivo']);
        });
    }
};
