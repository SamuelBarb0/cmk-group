<?php

namespace App\Http\Controllers;

use App\Models\Committee;
use App\Models\Employee;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * COPASST y Comité de Convivencia Laboral del cliente activo.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class CommitteeController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('comites/index', [
                'needsClient' => true,
                'comites' => [],
                'empleados' => [],
                'stats' => ['total' => 0, 'vencidos' => 0, 'sin_paridad' => 0, 'cumplimiento' => 0],
                'catalogos' => $this->catalogos(),
            ]);
        }

        $comites = Committee::query()
            ->whereIn('tipo', $this->tiposContratados())
            ->with(['members', 'activities'])
            ->orderByDesc('periodo')
            ->orderBy('tipo')
            ->get();

        // Cumplimiento consolidado de los dos comités: es la misma cuenta que
        // hacen los indicadores CUMP-COPASST y CUMP-COCOLAB.
        $programadas = $comites->flatMap->activities->where('programada', true);
        $ejecutadas = $comites->flatMap->activities->where('ejecutada', true);

        return Inertia::render('comites/index', [
            'needsClient' => false,
            'comites' => $comites,
            'empleados' => Employee::query()->where('is_active', true)
                ->orderBy('apellidos')->get(['id', 'nombres', 'apellidos', 'cargo', 'numero_documento']),
            'stats' => [
                'total' => $comites->count(),
                'vencidos' => $comites->where('vencido', true)->count(),
                // Comités que no cumplen la paridad que exige la norma.
                'sin_paridad' => $comites->where('composicion_correcta', false)->count(),
                'cumplimiento' => $programadas->count() > 0
                    ? (int) round($ejecutadas->count() / $programadas->count() * 100)
                    : 0,
            ],
            'catalogos' => $this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de conformar un comité.']);
        }

        // Se valida TODO antes de tocar la base: si los integrantes o el plan
        // vienen mal, no se puede quedar creado el comite sin ellos.
        $datos = $this->validated($request);
        $detalle = $this->validatedDetalle($request);

        DB::transaction(function () use ($datos, $detalle): void {
            $comite = Committee::create($datos);
            $this->reemplazarDetalle($comite, $detalle);
        });

        return back()->with('success', 'Comité conformado.');
    }

    public function update(Request $request, Committee $comite): RedirectResponse
    {
        $this->exigirContratado($comite);
        $datos = $this->validated($request, $comite);
        $detalle = $this->validatedDetalle($request);

        DB::transaction(function () use ($comite, $datos, $detalle): void {
            $comite->update($datos);
            $this->reemplazarDetalle($comite, $detalle);
        });

        return back()->with('success', 'Comité actualizado.');
    }

    public function destroy(Committee $comite): RedirectResponse
    {
        $this->exigirContratado($comite);
        $comite->delete();

        return back()->with('success', 'Comité eliminado.');
    }

    /**
     * Reemplaza miembros y actividades en bloque.
     *
     * Se borra y se recrea, como hace TrainingController con sus asistentes: la
     * pantalla manda la lista completa y llevar un diff por id complicaría el
     * formulario sin ganar nada, porque estas filas no las referencia nadie.
     */
    private function validatedDetalle(Request $request): array
    {
        return $request->validate([
            'members' => ['nullable', 'array'],
            'members.*.employee_id' => ['nullable', 'integer'],
            'members.*.nombres' => ['required', 'string', 'max:255'],
            'members.*.numero_documento' => ['nullable', 'string', 'max:40'],
            'members.*.cargo' => ['nullable', 'string', 'max:255'],
            'members.*.rol' => ['required', Rule::in(\App\Models\CommitteeMember::ROLES)],
            'members.*.representa' => ['required', Rule::in(\App\Models\CommitteeMember::REPRESENTA)],
            'members.*.votos' => ['nullable', 'integer', 'min:0'],

            'activities' => ['nullable', 'array'],
            'activities.*.descripcion' => ['required', 'string', 'max:255'],
            'activities.*.programada' => ['boolean'],
            'activities.*.ejecutada' => ['boolean'],
            'activities.*.evidencia' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /** @param  array<string, mixed>  $detalle */
    private function reemplazarDetalle(Committee $comite, array $detalle): void
    {
        $comite->members()->delete();
        foreach ($detalle['members'] ?? [] as $m) {
            $comite->members()->create($m);
        }

        $comite->activities()->delete();
        foreach (array_values($detalle['activities'] ?? []) as $i => $a) {
            $comite->activities()->create($a + ['orden' => $i + 1]);
        }
    }

    /**
     * Comités que la empresa contrató: los dos van por las mismas rutas, así
     * que la parte del módulo (config('cmk.submodulos.comites')) se controla aquí.
     *
     * @return list<string>
     */
    private function tiposContratados(): array
    {
        $tenant = $this->context->has() ? $this->context->get() : null;

        return array_values(array_filter(Committee::TIPOS, fn (string $t) => ! $tenant || $tenant->submoduloHabilitado('comites', $t)));
    }

    private function exigirContratado(Committee $comite): void
    {
        abort_unless(in_array($comite->tipo, $this->tiposContratados(), true), 403, 'La empresa no tiene contratado este comité.');
    }

    private function catalogos(): array
    {
        return [
            'tipos' => $this->tiposContratados(),
            'roles' => \App\Models\CommitteeMember::ROLES,
            'representa' => \App\Models\CommitteeMember::REPRESENTA,
        ];
    }

    private function validated(Request $request, ?Committee $comite = null): array
    {
        return $request->validate([
            'tipo' => ['required', Rule::in($this->tiposContratados())],
            'periodo' => [
                'required', 'integer', 'min:2000', 'max:2100',
                // Un solo comité de cada tipo por periodo: dos COPASST del
                // mismo año en la misma empresa no existen.
                Rule::unique('committees', 'periodo')
                    ->where('tenant_id', $this->context->id())
                    ->where('tipo', $request->input('tipo'))
                    ->ignore($comite?->id),
            ],
            'fecha_conformacion' => ['required', 'date'],
            'fecha_vencimiento' => ['nullable', 'date', 'after:fecha_conformacion'],
            'numero_trabajadores' => ['nullable', 'integer', 'min:1'],
            'acta_conformacion' => ['nullable', 'string', 'max:60'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
