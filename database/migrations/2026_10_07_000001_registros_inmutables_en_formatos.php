<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un registro completado no se edita: se anula y se crea otro (informe de
 * estructura documental, sección 7, «Inmutabilidad»).
 *
 * Hasta ahora un registro completado se podía devolver a borrador y cambiar,
 * sin dejar rastro. Aquí se agregan el consecutivo del registro
 * (FT-INS-EXT-2026-0015: código del formato + año + número), quién y cuándo lo
 * completó, y la anulación con su motivo y el registro que lo reemplaza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_records', function (Blueprint $table) {
            $table->string('consecutivo', 60)->nullable()->after('codigo');
            $table->timestamp('completado_at')->nullable()->after('estado');
            $table->string('completado_por')->nullable()->after('completado_at');
            $table->timestamp('anulado_at')->nullable()->after('completado_por');
            $table->string('anulado_por')->nullable()->after('anulado_at');
            $table->text('motivo_anulacion')->nullable()->after('anulado_por');
            $table->foreignId('reemplaza_id')->nullable()->after('motivo_anulacion')
                ->constrained('form_records')->nullOnDelete();
            $table->unique(['tenant_id', 'consecutivo']);
        });

        // Los completados que ya existen reciben su consecutivo en el orden en
        // que se crearon. La fecha de completado real no se guardaba; la mejor
        // aproximación es la última modificación.
        $contadores = [];
        DB::table('form_records')->where('estado', 'completado')->orderBy('id')->get()
            ->each(function ($r) use (&$contadores) {
                $anio = $r->fecha ? substr((string) $r->fecha, 0, 4) : substr((string) $r->created_at, 0, 4);
                $clave = $r->tenant_id.'|'.$r->codigo.'|'.$anio;
                $contadores[$clave] = ($contadores[$clave] ?? 0) + 1;

                DB::table('form_records')->where('id', $r->id)->update([
                    'consecutivo' => $r->codigo.'-'.$anio.'-'.str_pad((string) $contadores[$clave], 4, '0', STR_PAD_LEFT),
                    'completado_at' => $r->updated_at,
                    'completado_por' => $r->generado_por,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('form_records', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'consecutivo']);
            $table->dropConstrainedForeignId('reemplaza_id');
            $table->dropColumn(['consecutivo', 'completado_at', 'completado_por', 'anulado_at', 'anulado_por', 'motivo_anulacion']);
        });
    }
};
