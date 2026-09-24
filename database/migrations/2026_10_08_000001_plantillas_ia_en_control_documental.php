<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une Documentos IA con el control documental.
 *
 * Las plantillas de IA tienen códigos propios (POL-SGI, PR-IPERC…) que no
 * siguen la codificación del SIG. En vez de recodificarlas, cada plantilla
 * apunta al documento del catálogo que redacta: así, al enviar un borrador de
 * IA al control documental, cae en el documento correcto del listado maestro
 * (PLT-GSI-001, PRC-GSI-003…) y recorre allí revisión y aprobación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->foreignId('document_catalog_id')->nullable()->after('tenant_id')
                ->constrained('document_catalog')->nullOnDelete();
        });

        // De qué redacción de IA salió el texto de cada versión (trazabilidad).
        Schema::table('controlled_document_versions', function (Blueprint $table) {
            $table->foreignId('generated_document_id')->nullable()->after('archivo_nombre')
                ->constrained('generated_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('controlled_document_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('generated_document_id');
        });
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_catalog_id');
        });
    }
};
