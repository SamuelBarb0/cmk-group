<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M16 — Revisión por la dirección (ISO 9.3 de las tres normas, Dec. 1072
 * art. 2.2.4.6.31, Res. 0312 est. 6.1.3).
 *
 * Una revisión tiene:
 * - ENTRADAS: los datos del periodo que la norma obliga a revisar. No se
 *   digitan: se recopilan de los módulos (las mismas secciones del informe de
 *   gestión) y se congelan en `datos`, porque el acta tiene que mostrar las
 *   cifras que la gerencia vio ese día, no las de hoy. Sobre cada entrada la
 *   dirección deja su análisis (`analisis`).
 * - CONCLUSIONES sobre la conveniencia, adecuación y eficacia del sistema.
 * - SALIDAS: decisiones con responsable y fecha. Su seguimiento sigue vivo
 *   después de cerrar la revisión y es la primera entrada de la siguiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('codigo', 20);                 // RXD-2026-001, por empresa
            $table->date('periodo_desde');
            $table->date('periodo_hasta');
            $table->date('fecha_reunion')->nullable();
            $table->json('sistemas');                     // normas que cubre la revisión
            $table->text('participantes')->nullable();
            $table->json('datos')->nullable();            // snapshot de las secciones, por clave
            $table->timestamp('datos_at')->nullable();
            $table->json('analisis')->nullable();         // texto de la dirección, por entrada
            $table->json('conclusiones_sistema')->nullable(); // conveniente / adecuado / eficaz
            $table->text('conclusiones')->nullable();
            $table->string('estado', 10)->default('borrador'); // borrador / cerrada
            $table->timestamp('cerrada_at')->nullable();
            $table->string('cerrada_por')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'codigo']);
        });

        Schema::create('management_review_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('management_review_id')->constrained('management_reviews')->cascadeOnDelete();
            $table->string('tipo', 15);                   // mejora / cambio / recursos / otro
            $table->text('descripcion');
            $table->string('responsable')->nullable();
            $table->date('fecha_limite')->nullable();
            $table->string('estado', 12)->default('pendiente'); // pendiente / en_proceso / cumplida / cancelada
            $table->text('seguimiento')->nullable();
            $table->foreignId('acpm_action_id')->nullable()->constrained('acpm_actions')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_review_decisions');
        Schema::dropIfExists('management_reviews');
    }
};
