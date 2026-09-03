<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite que las plantillas se suban desde la plataforma, sin seeder.
 *
 * `document_templates` nació como catálogo GLOBAL de CMK. Ahora una plantilla
 * puede además ser PRIVADA de una empresa cliente:
 *   - tenant_id NULL  -> catálogo de CMK, la ven todos los clientes.
 *   - tenant_id lleno -> formato propio de esa empresa, solo lo ve ella.
 *
 * `codigo` deja de ser único a secas: dos clientes distintos pueden llamar
 * «POL-SGI» a su propia versión sin pisarse, y ninguno pisa a la de CMK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained('tenants')->cascadeOnDelete();
            $table->string('subido_por')->nullable()->after('orden');
            $table->timestamp('subida_at')->nullable()->after('subido_por');
        });

        // El índice único de `codigo` pasa a ser por tenant. SQLite no sabe
        // soltar un índice por nombre dentro de un Blueprint, así que se hace
        // con la API de Schema, que sí traduce a cada motor.
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropUnique('document_templates_codigo_unique');
            $table->unique(['tenant_id', 'codigo'], 'document_templates_tenant_codigo_unique');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropUnique('document_templates_tenant_codigo_unique');
            $table->unique('codigo', 'document_templates_codigo_unique');
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['subido_por', 'subida_at']);
        });
    }
};
