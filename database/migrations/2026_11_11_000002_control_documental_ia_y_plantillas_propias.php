<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - controlled_documents.codigo_pesv: el documento del listado maestro que
 *   respalda un documento del PESV (POL-PESV, PR-FATIGA…), para que el paso lo
 *   encuentre aunque no venga del catálogo ni de Documentos IA.
 * - controlled_document_versions.ia_estado / ia_error: la IA está
 *   actualizando el borrador (en cola) o falló.
 * - document_templates.reemplaza_id: plantilla propia de una empresa que
 *   ocupa el lugar de una de CMK solo para esa empresa.
 * - document_templates.estado_proceso / error_proceso: un PDF subido se pasa
 *   a texto en segundo plano; mientras tanto no se puede generar con él.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controlled_documents', function (Blueprint $table) {
            $table->string('codigo_pesv', 30)->nullable()->after('codigo_historico');
        });

        Schema::table('controlled_document_versions', function (Blueprint $table) {
            $table->string('ia_estado', 20)->nullable()->after('generated_document_id');
            $table->text('ia_error')->nullable()->after('ia_estado');
        });

        Schema::table('document_templates', function (Blueprint $table) {
            $table->foreignId('reemplaza_id')->nullable()->after('tenant_id')->constrained('document_templates')->nullOnDelete();
            $table->string('estado_proceso', 20)->nullable()->after('archivo');
            $table->text('error_proceso')->nullable()->after('estado_proceso');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reemplaza_id');
            $table->dropColumn(['estado_proceso', 'error_proceso']);
        });

        Schema::table('controlled_document_versions', function (Blueprint $table) {
            $table->dropColumn(['ia_estado', 'ia_error']);
        });

        Schema::table('controlled_documents', function (Blueprint $table) {
            $table->dropColumn('codigo_pesv');
        });
    }
};
