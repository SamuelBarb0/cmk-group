<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repositorio documental por empresa: archivos que QUEDAN guardados para el
 * cliente — exports .docx de Documentos IA y archivos subidos (versiones
 * firmadas, evidencias, soportes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('nombre');
            $table->string('categoria')->nullable();
            // export = archivado automáticamente al exportar un Documento IA | upload = subido a mano.
            $table->string('origen', 10)->default('upload');
            $table->foreignId('generated_document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime')->nullable();
            $table->string('subido_por')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'origen']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_documents');
    }
};
