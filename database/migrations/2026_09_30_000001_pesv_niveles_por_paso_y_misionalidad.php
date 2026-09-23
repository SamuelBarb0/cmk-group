<?php

use App\Models\PesvPlan;
use App\Models\PesvStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PESV conforme al anexo de la Res. 40595:
 *  - pesv_steps.niveles: en qué niveles es exigible cada paso (tablas 2, 6, 9
 *    y 12). Antes los 24 contaban para todos.
 *  - pesv_plans.misionalidad: 1 transporte / 2 otra actividad (Tabla 1). Con
 *    el tamaño de la flota define el nivel.
 *
 * Y recalcula el avance de los planes existentes: la fórmula vieja dividía por
 * los pasos GUARDADOS, no por los que aplican, y daba 100 % con uno solo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pesv_steps', function (Blueprint $table) {
            $table->json('niveles')->nullable()->after('descripcion');
        });
        Schema::table('pesv_plans', function (Blueprint $table) {
            $table->unsignedTinyInteger('misionalidad')->nullable()->after('nivel');
        });

        foreach (DB::table('pesv_steps')->get(['id', 'numero']) as $paso) {
            DB::table('pesv_steps')->where('id', $paso->id)
                ->update(['niveles' => json_encode(PesvStep::nivelesDe((int) $paso->numero))]);
        }

        foreach (PesvPlan::withoutTenantScope()->get() as $plan) {
            $plan->recalcular();
        }
    }

    public function down(): void
    {
        Schema::table('pesv_plans', fn (Blueprint $t) => $t->dropColumn('misionalidad'));
        Schema::table('pesv_steps', fn (Blueprint $t) => $t->dropColumn('niveles'));
    }
};
