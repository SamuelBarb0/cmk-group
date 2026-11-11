import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Anillo0312 } from './anillo-0312';
import { PorArea } from './por-area';
import { QueHacerHoy, tareasDelCliente } from './que-hacer-hoy';
import { SaludSistema } from './salud-sistema';
import { type Alerta, banda0312, type DashboardProps, porcentajeDeTexto, TONO_PUNTO } from './tipos';

/** El azul marino de CMK, fijo en ambos temas (el `--primary` del tema oscuro es claro). */
export const MARINO = 'bg-[#16243F] text-white dark:bg-[hsl(220,42%,13%)] dark:ring-1 dark:ring-white/10';

/** Tono de una cifra sobre el marino: el mismo código del resto del panel (rojo = acción, ámbar = pronto). */
const CIFRA_TONO = { rojo: 'text-red-300', ambar: 'text-amber-300' } as const;

/** Una cifra del encabezado. En cero queda blanca: no hay nada que atender. */
export function Cifra({ n, singular, plural, tono }: { n: number; singular: string; plural: string; tono: keyof typeof CIFRA_TONO }) {
    return (
        <span className="inline-flex items-baseline gap-1.5 whitespace-nowrap">
            {n > 0 && <span className={cn('size-2 self-center rounded-full', TONO_PUNTO[tono])} aria-hidden />}
            <span className={cn('font-brand text-2xl font-semibold tabular-nums', n > 0 ? CIFRA_TONO[tono] : 'text-white')}>{n}</span>
            <span className="text-sm text-white/75">{n === 1 ? singular : plural}</span>
        </span>
    );
}

/**
 * Encabezado del cliente: quién es, una frase con lo que importa y el
 * puntaje 0312 como anillo. Es lo que el gerente se lleva en la cabeza.
 */
function Encabezado({ props, vencidos, semana, fuera }: { props: DashboardProps; vencidos: number; semana: number; fuera: number | null }) {
    const diagnostico = props.cumplimiento?.find((c) => c.clave === 'diagnostico');
    const puntaje = porcentajeDeTexto(diagnostico?.valor);
    const banda = puntaje === null ? null : banda0312(puntaje);
    const todoAlDia = vencidos === 0 && semana === 0 && !fuera;

    return (
        <section className={cn('rounded-2xl px-5 py-6 sm:px-8 sm:py-7', MARINO)}>
            <div className="flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
                <div className="min-w-0">
                    <h1 className="font-brand text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                        {props.tenant?.name ?? 'Mi empresa'}
                    </h1>
                    <p className="mt-1 text-sm text-white/65">
                        {[props.tenant?.nit && `NIT ${props.tenant.nit}`, props.tenant?.city].filter(Boolean).join(' · ')}
                    </p>

                    <div className="mt-5 flex flex-wrap items-baseline gap-x-6 gap-y-2">
                        {todoAlDia ? (
                            <span className="text-base text-white/90">Todo al día: sin vencimientos ni indicadores fuera de meta.</span>
                        ) : (
                            <>
                                <Cifra n={vencidos} singular="vencido" plural="vencidos" tono="rojo" />
                                <Cifra n={semana} singular="vence esta semana" plural="vencen esta semana" tono="ambar" />
                                {fuera !== null && (
                                    <Cifra n={fuera} singular="indicador fuera de meta" plural="indicadores fuera de meta" tono="rojo" />
                                )}
                            </>
                        )}
                    </div>
                </div>

                {diagnostico && (
                    <Link
                        href={diagnostico.url ?? '/diagnostico'}
                        className="group flex shrink-0 items-center gap-4 rounded-xl p-2 transition-colors duration-150 hover:bg-white/5 focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:outline-none md:pr-4"
                    >
                        <Anillo0312 puntaje={puntaje} />
                        <span className="max-w-44">
                            <span className="block text-sm font-medium text-white">Estándares mínimos</span>
                            {banda ? (
                                <span className="mt-1 inline-flex items-center gap-1.5 text-sm text-white/85">
                                    <span className={cn('size-2 rounded-full', TONO_PUNTO[banda.tono])} aria-hidden />
                                    {banda.texto}
                                </span>
                            ) : (
                                <span className="mt-1 block text-sm text-white/75">Sin autoevaluación todavía</span>
                            )}
                            <span className="mt-2 flex items-center gap-1 text-xs text-white/60 group-hover:text-white/85">
                                {banda ? 'Ver la autoevaluación' : 'Hacer la autoevaluación'} <ArrowRight className="size-3" />
                            </span>
                        </span>
                    </Link>
                )}
            </div>
        </section>
    );
}

export function VistaCliente({ props }: { props: DashboardProps }) {
    const agenda = props.agenda ?? [];
    const alertas = (props.alertas ?? []) as Alerta[];
    const tareas = tareasDelCliente(agenda, alertas);
    const kpis = props.kpis ?? [];
    const filas = props.indicadores?.filas ?? [];
    const minimos = new Set(kpis.map((k) => k.codigo));
    const otros = filas.filter((f) => !minimos.has(f.codigo));
    const hayIndicadores = (props.indicadores?.total ?? 0) > 0;

    return (
        <>
            <Encabezado
                props={props}
                vencidos={agenda.filter((i) => i.estado === 'vencido').length}
                semana={agenda.filter((i) => i.estado === 'por_vencer' && i.dias <= 7).length}
                fuera={hayIndicadores ? filas.filter((f) => f.cumple === false).length : null}
            />

            <div className="grid items-start gap-6 lg:grid-cols-12">
                <div className="min-w-0 lg:col-span-7">
                    <QueHacerHoy tareas={tareas} />
                </div>
                <div className="min-w-0 lg:col-span-5">
                    <SaludSistema
                        cumplimiento={props.cumplimiento ?? []}
                        kpis={kpis}
                        otros={hayIndicadores ? { medidos: otros.length, fuera: otros.filter((o) => o.cumple === false).length } : null}
                    />
                </div>
            </div>

            <PorArea modulos={props.modulos ?? []} />
        </>
    );
}
