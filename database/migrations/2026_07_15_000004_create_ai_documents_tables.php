<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generación de documentos SGI con IA.
 *
 * - document_templates: catálogo GLOBAL de plantillas. Cada plantilla es
 *   TRANSVERSAL: sirve a varias normas (campo `normas`), así un mismo
 *   documento (p. ej. Política Integrada) cubre SST+PESV+HSEQ a la vez.
 * - generated_documents: documento generado/editado por empresa cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();       // POL-SGI, PR-IPERC...
            $table->string('nombre');
            $table->string('tipo', 40);                    // Política/Procedimiento/Manual/Plan...
            $table->string('categoria', 20);               // SGI/SST/PESV/HSEQ
            $table->json('normas');                        // ["ISO 45001","Res 0312",...] (transversalidad)
            $table->text('descripcion')->nullable();
            $table->text('prompt');                        // Instrucción/estructura para la IA
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('document_template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->string('titulo');
            $table->longText('contenido');                 // Markdown generado/editado
            $table->string('estado', 20)->default('borrador'); // borrador/en_revision/aprobado
            $table->unsignedInteger('version')->default(1);
            $table->string('generado_por')->nullable();    // Nombre del consultor
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
        Schema::dropIfExists('document_templates');
    }
};
