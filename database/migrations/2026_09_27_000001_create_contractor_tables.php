<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contratistas y proveedores fuera del PESV (estándar 2.10.1 de la Res. 0312
 * e ISO 8.4): documentos, selección, requisitos SST y evaluación.
 *
 * El registro de contratistas YA existía como `pesv_contractors` y es
 * genérico (contratista, proveedor, tercero…): se amplía en vez de crear una
 * tabla paralela, para que el PESV y este módulo vean a la misma empresa.
 * El nombre de la tabla se queda como está: renombrarla tocaría el PESV
 * entero por una cuestión de nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        // «8.4 HOJA VIDA PROVEEDOR» y «8.4 LISTA DE PROVEEDORES».
        Schema::table('pesv_contractors', function (Blueprint $table) {
            $table->string('persona', 10)->nullable()->after('tipo');     // juridica / natural
            $table->string('direccion')->nullable()->after('actividad');
            $table->string('ciudad', 100)->nullable()->after('direccion');
            $table->string('representante_legal')->nullable()->after('ciudad');
            $table->string('supervisor')->nullable()->after('representante_legal');
            $table->date('fecha_ingreso')->nullable()->after('supervisor');
            $table->text('observaciones')->nullable()->after('calificacion');
        });

        // Sección 3 de «8.4 SELEC PROVEED»: RE (recibido) / NE (no entregado) / N/A.
        // Sin tenant ni rutas propias: se guardan a través del contratista.
        Schema::create('contractor_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pesv_contractor_id')->constrained('pesv_contractors')->cascadeOnDelete();
            $table->string('tipo', 30);
            $table->string('nombre')->nullable();                  // para «otro»
            $table->string('estado', 15)->default('no_entregado'); // recibido / no_entregado / no_aplica
            $table->date('fecha_expedicion')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->string('observacion', 500)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        // Una fila por formulario diligenciado. Guarda COPIA de la estructura
        // (como los registros de Formatos): si CMK cambia un criterio, las
        // evaluaciones viejas siguen calificadas con lo que se preguntó.
        Schema::create('contractor_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('pesv_contractor_id')->constrained('pesv_contractors')->cascadeOnDelete();
            $table->string('formato', 30);                         // clave en EvaluacionesContratistas
            $table->string('uso', 20);                             // seleccion / evaluacion / requisitos_sst
            $table->date('fecha');
            $table->string('evaluador')->nullable();
            $table->string('evaluador_cargo')->nullable();
            $table->json('estructura');
            $table->json('respuestas');
            $table->decimal('puntaje', 8, 2);
            $table->decimal('porcentaje', 5, 1);
            $table->string('resultado', 15);
            $table->unsignedSmallInteger('incumplimientos')->default(0);
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_evaluations');
        Schema::dropIfExists('contractor_documents');
        Schema::table('pesv_contractors', function (Blueprint $table) {
            $table->dropColumn(['persona', 'direccion', 'ciudad', 'representante_legal', 'supervisor', 'fecha_ingreso', 'observaciones']);
        });
    }
};
