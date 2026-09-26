<?php

namespace App\Services\Reportes;

use App\Models\User;
use App\Services\Reportes\Secciones as S;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Informe de gestión del SG-SST de la empresa activa para un periodo.
 *
 * Arma las secciones de los módulos que la empresa tiene CONTRATADOS y que el
 * usuario puede VER (si no puede abrir Accidentalidad, tampoco la ve en el
 * informe), y reúne al principio los «puntos de atención»: las alertas de
 * todas las secciones, para que la gerencia lea primero lo que exige acción.
 *
 * Produce datos, no documento: la pantalla, el Word y el PDF pintan lo mismo.
 */
class InformeGestion
{
    /** Orden del informe: del sistema en conjunto a cada frente de gestión. */
    public const SECCIONES = [
        S\Empresa::class,
        S\Diagnostico::class,
        S\Contexto::class,
        S\PlanTrabajo::class,
        S\Indicadores::class,
        S\Accidentes::class,
        S\Ausentismo::class,
        S\ActosCondiciones::class,
        S\Acpm::class,
        S\Calidad::class,
        S\Capacitaciones::class,
        S\Cargos::class,
        S\Inspecciones::class,
        S\SaludOcupacional::class,
        S\RequisitosLegales::class,
        S\Iperc::class,
        S\RiesgosOportunidades::class,
        S\AspectosAmbientales::class,
        S\Emergencias::class,
        S\Comites::class,
        S\Comunicaciones::class,
        S\Epp::class,
        S\Mantenimiento::class,
        S\EquiposMedicion::class,
        S\Contratistas::class,
        S\GestionCambio::class,
        S\Programas::class,
        S\Auditoria::class,
        S\Pesv::class,
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Secciones que ESTE usuario puede incluir para la empresa activa.
     *
     * @return list<Seccion>
     */
    public function disponibles(User $user): array
    {
        $tenant = $this->context->get();

        return collect(self::SECCIONES)
            ->map(fn (string $clase) => app($clase))
            ->filter(fn (Seccion $s) => ($s->modulo() === null || $tenant?->moduloHabilitado($s->modulo()))
                && $user->can($s->permiso()))
            ->values()->all();
    }

    /**
     * @param  list<string>|null  $claves  secciones pedidas (null = todas las disponibles)
     * @return array<string, mixed>
     */
    public function generar(User $user, Periodo $periodo, ?array $claves = null): array
    {
        $tenant = $this->context->get();
        $secciones = collect($this->disponibles($user))
            ->filter(fn (Seccion $s) => $claves === null || in_array($s->clave(), $claves, true))
            ->map(fn (Seccion $s) => $s->generar($periodo))
            ->values();

        $atencion = $secciones->flatMap(fn (array $s) => collect($s['cifras'])
            ->pluck('alerta')->filter()->map(fn (string $a) => ['seccion' => $s['titulo'], 'texto' => $a]))
            ->values();

        return [
            'empresa' => [
                'nombre' => $tenant->name,
                'razon_social' => $tenant->legal_name ?: $tenant->name,
                'nit' => $tenant->nit,
                'ciudad' => $tenant->city,
            ],
            'periodo' => [
                'desde' => $periodo->desde->toDateString(),
                'hasta' => $periodo->hasta->toDateString(),
                'etiqueta' => $periodo->etiqueta(),
            ],
            'generado' => [
                'fecha' => $periodo->larga(Carbon::now()->toImmutable()),
                'por' => $user->name,
            ],
            'atencion' => $atencion->all(),
            'secciones' => $secciones->all(),
        ];
    }
}
