<?php

use App\Models\PesvCriterion;
use App\Models\PesvPlan;
use Database\Seeders\PesvCriteriaSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lista de verificación oficial del PESV (Tabla 16 de la Res. 40595):
 *  - pesv_criteria: las 60 preguntas (catálogo global).
 *  - pesv_plan_criteria: la respuesta de cada empresa.
 *  - pesv_evidences: los archivos que soportan cada respuesta.
 *
 * Desde aquí el estado de un paso SALE de sus preguntas. Para no perder lo que
 * ya se había marcado a mano, un paso en cumple / no cumple / no aplica pasa
 * ese estado a todas sus preguntas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesv_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pesv_step_id')->constrained('pesv_steps')->cascadeOnDelete();
            $table->string('codigo', 10)->unique();   // 1.1, 18.5…
            $table->text('pregunta');
            $table->json('niveles');
            $table->unsignedSmallInteger('orden');
            $table->timestamps();
        });

        Schema::create('pesv_plan_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pesv_plan_id')->constrained('pesv_plans')->cascadeOnDelete();
            $table->foreignId('pesv_criterion_id')->constrained('pesv_criteria')->cascadeOnDelete();
            $table->string('estado', 15)->default('no_verificado');
            $table->text('observaciones')->nullable();
            $table->date('verificado_at')->nullable();
            $table->string('verificado_por')->nullable();
            $table->timestamps();
            $table->unique(['pesv_plan_id', 'pesv_criterion_id']);
        });

        Schema::create('pesv_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('pesv_plan_id')->constrained('pesv_plans')->cascadeOnDelete();
            $table->foreignId('pesv_criterion_id')->constrained('pesv_criteria')->cascadeOnDelete();
            $table->string('archivo');
            $table->string('nombre');
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('subido_por')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'pesv_criterion_id']);
        });

        // Sin los 24 pasos (base nueva) no hay a qué colgar las preguntas: las
        // carga el seeder normal después.
        if (DB::table('pesv_steps')->count() === 0) {
            return;
        }
        (new PesvCriteriaSeeder)->run();

        $porPaso = PesvCriterion::all()->groupBy('pesv_step_id');
        $hoy = now()->toDateString();
        foreach (DB::table('pesv_plan_steps')->whereIn('estado', ['cumple', 'no_cumple', 'no_aplica'])->get() as $ps) {
            foreach ($porPaso->get($ps->pesv_step_id, []) as $c) {
                DB::table('pesv_plan_criteria')->insert([
                    'pesv_plan_id' => $ps->pesv_plan_id,
                    'pesv_criterion_id' => $c->id,
                    'estado' => $ps->estado,
                    'observaciones' => 'Traído del estado que tenía el paso antes de la lista de verificación.',
                    'verificado_at' => $hoy,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (PesvPlan::withoutTenantScope()->get() as $plan) {
            $plan->recalcular();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pesv_evidences');
        Schema::dropIfExists('pesv_plan_criteria');
        Schema::dropIfExists('pesv_criteria');
    }
};
