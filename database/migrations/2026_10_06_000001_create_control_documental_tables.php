<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M01 — Control documental y catálogo de normas/requisitos del SIG.
 *
 * Sigue el modelo del «Informe inicial · Estructura documental del SIG»
 * (Cristian Contreras, 23-sep-2026):
 *
 *   NORMA ──< REQUISITO >──< DOCUMENTO >── PROCESO
 *                               └──< VERSIÓN ──< LECTURA
 *
 * - `norms` y `norm_requirements` son catálogos GLOBALES. Un requisito
 *   pertenece a una sola norma (y a una edición de ella), pero el mismo
 *   requisito común (p. ej. la política integrada) aparece una vez por norma
 *   con la misma `clave_comun`, que es lo que permite decir «esto se construye
 *   una sola vez» sin perder la referencia exacta de cada norma.
 * - `document_catalog` es el catálogo de referencia de 227 documentos del
 *   informe, del que cada empresa arma su listado maestro.
 * - `processes` y `controlled_documents` son POR EMPRESA: el código de un
 *   documento lleva el proceso dueño, y cada empresa tiene su mapa de procesos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('norms', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 20);                  // sst, pesv, iso45001, iso9001, iso14001
            $table->string('nombre');                     // ISO 9001
            $table->string('edicion', 60);                // 2026, 2018 + Enmienda 1:2024…
            $table->text('descripcion')->nullable();
            // Durante una transición conviven dos ediciones de la misma norma;
            // solo una es la vigente para requisitos nuevos.
            $table->boolean('vigente')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
            $table->unique(['clave', 'edicion']);
        });

        Schema::create('norm_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('norm_id')->constrained('norms')->cascadeOnDelete();
            $table->string('clave_comun', 20);            // SIG-01…SIG-62, igual en todas las normas
            $table->string('etapa', 2);                   // 0, 4, 5 … 10 (Estructura Armonizada)
            $table->string('referencia');                 // «5.2», «Paso 3», «Res.0312 2.1.1»
            $table->string('titulo');
            $table->string('evidencia', 10);              // documento / registro / ambos
            $table->string('modulo', 3);                  // M01…M20
            $table->text('nota')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
            $table->unique(['norm_id', 'clave_comun']);
            $table->index('clave_comun');
        });

        Schema::create('document_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('modulo', 3);
            $table->string('tipo', 3);                    // MAN, PLT, REG, PRC, PRG, PLA, MTZ, INS, FT
            $table->string('nombre');
            $table->json('sistemas');                     // ["sst","pesv",…]
            $table->boolean('condicional')->default(false);
            $table->string('codigo_referencia', 20)->nullable(); // código en el listado FT-SST-034
            $table->string('proceso', 3);                 // sigla del proceso dueño sugerido
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('sigla', 3);
            $table->string('nombre');
            $table->string('tipo', 12);                   // estrategico / misional / apoyo / evaluacion
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'sigla']);
        });

        Schema::create('controlled_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('codigo', 20);                 // PRC-SST-004, lo genera el sistema
            $table->string('tipo', 3);
            $table->unsignedTinyInteger('nivel');         // 1 estratégico … 4 evidencia
            // restrict: un proceso con documentos no se puede borrar, porque
            // el código de esos documentos lleva su sigla.
            $table->foreignId('process_id')->constrained('processes')->restrictOnDelete();
            $table->string('titulo');
            $table->json('sistemas');
            $table->boolean('condicional')->default(false);
            $table->foreignId('document_catalog_id')->nullable()->constrained('document_catalog')->nullOnDelete();
            // borrador / en_revision / en_aprobacion / vigente / obsoleto.
            // Se deriva de las versiones (ControlledDocument::sincronizarEstado).
            $table->string('estado', 15)->default('borrador');
            $table->unsignedInteger('version_vigente')->nullable();
            $table->unsignedSmallInteger('frecuencia_revision_meses');
            $table->date('proxima_revision')->nullable();
            $table->unsignedSmallInteger('retencion_anios')->nullable();
            $table->string('disposicion_final', 20)->nullable(); // conservar / eliminar / digitalizar
            $table->string('ubicacion')->nullable();
            $table->string('codigo_historico', 30)->nullable();
            $table->timestamps();
            // SoftDeletes y unique sobre el código: un documento borrado sigue
            // ocupando su número, así el consecutivo nunca se reutiliza.
            $table->softDeletes();
            $table->unique(['tenant_id', 'codigo']);
            $table->index(['tenant_id', 'estado']);
        });

        Schema::create('controlled_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('controlled_document_id')->constrained('controlled_documents')->cascadeOnDelete();
            $table->unsignedInteger('version');
            // borrador / en_revision / en_aprobacion / vigente / obsoleta
            $table->string('estado', 15)->default('borrador');
            $table->longText('contenido')->nullable();
            $table->string('archivo')->nullable();
            $table->string('archivo_nombre')->nullable();
            $table->text('descripcion_cambio')->nullable();
            $table->text('observaciones')->nullable();    // última devolución o rechazo

            $table->foreignId('elaboro_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('elaboro_nombre')->nullable();
            $table->timestamp('enviado_revision_at')->nullable();
            $table->foreignId('reviso_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviso_nombre')->nullable();
            $table->timestamp('revisado_at')->nullable();
            $table->foreignId('aprobo_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('aprobo_nombre')->nullable();
            $table->timestamp('aprobado_at')->nullable();

            $table->timestamp('obsoleto_at')->nullable();
            $table->text('motivo_obsoleto')->nullable();
            $table->timestamps();
            $table->unique(['controlled_document_id', 'version'], 'cdv_documento_version_unique');
        });

        Schema::create('controlled_document_requirement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('controlled_document_id')->constrained('controlled_documents')->cascadeOnDelete();
            $table->foreignId('norm_requirement_id')->constrained('norm_requirements')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['controlled_document_id', 'norm_requirement_id'], 'cdr_documento_requisito_unique');
        });

        Schema::create('controlled_document_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('controlled_document_version_id')->constrained('controlled_document_versions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('user_nombre');
            $table->timestamp('leido_at');
            $table->timestamps();
            $table->unique(['controlled_document_version_id', 'user_id'], 'cdl_version_usuario_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controlled_document_reads');
        Schema::dropIfExists('controlled_document_requirement');
        Schema::dropIfExists('controlled_document_versions');
        Schema::dropIfExists('controlled_documents');
        Schema::dropIfExists('processes');
        Schema::dropIfExists('document_catalog');
        Schema::dropIfExists('norm_requirements');
        Schema::dropIfExists('norms');
    }
};
