import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Anillo0312 } from './anillo-0312';
import { QueHacerHoy, tareasDeAgenda } from './que-hacer-hoy';
import { Estado } from './salud-sistema';
import { type ClienteFila, type DashboardProps, type ItemAgenda, ruta } from './tipos';
import { Cifra, MARINO } from './vista-cliente';

function Cliente({ c, onAbrir }: { c: ClienteFila; onAbrir: () => void }) {
    const alDia = c.vencido === 0 && c.por_vencer === 0 && !c.fuera_meta;
    return (
        <li>
            <button
                type="button"
                onClick={onAbrir}
                className="group hover:bg-muted/60 focus-visible:ring-ring flex w-full items-center gap-3 rounded-lg px-3 py-3 text-left transition-colors duration-150 focus-visible:ring-2 focus-visible:outline-none"
            >
                <Anillo0312 puntaje={c.estandares} tamano={44} sobre="sobreClaro" />
                <span className="min-w-0 flex-1">
                    <span className="block truncate font-medium">{c.name}</span>
                    <span className="text-muted-foreground block truncate text-xs">
                        {[c.city, c.nit ? `NIT ${c.nit}` : null, `${c.users_count} usuarios`].filter(Boolean).join(' · ')}
                    </span>
                    <span className="mt-1.5 flex flex-wrap gap-x-3 gap-y-1">
                        {c.vencido > 0 && <Estado tono="rojo" texto={`${c.vencido} vencido${c.vencido === 1 ? '' : 's'}`} />}
                        {c.por_vencer > 0 && <Estado tono="ambar" texto={`${c.por_vencer} por vencer`} />}
                        {!!c.fuera_meta && <Estado tono="rojo" texto={`${c.fuera_meta} indicador${c.fuera_meta === 1 ? '' : 'es'} fuera de meta`} />}
                        {alDia && <Estado tono="verde" texto="Al día" />}
                    </span>
                </span>
                <span className="text-primary hidden items-center gap-1 text-sm font-medium opacity-70 transition-opacity group-hover:opacity-100 sm:inline-flex">
                    Abrir <ArrowRight className="size-3.5" />
                </span>
            </button>
        </li>
    );
}

/**
 * Vista consolidada del consultor: el portafolio en una frase, los clientes
 * ordenados por urgencia y todo lo vencido de todos en una sola lista.
 */
export function VistaConsultor({ props }: { props: DashboardProps }) {
    const clientes = props.clientes ?? [];
    const items = (props.alertas ?? []) as ItemAgenda[];
    const dias = props.agenda_dias ?? { por_vencer: 30, horizonte: 60 };
    const trabajar = (id: number, ir?: string) => router.post(route('clientes.select', id), ir ? { ir } : {});

    const conVencidos = clientes.filter((c) => c.vencido > 0).length;
    const totalVencidos = clientes.reduce((s, c) => s + c.vencido, 0);
    const fuera = clientes.reduce((s, c) => s + (c.fuera_meta ?? 0), 0);

    return (
        <>
            <section className={cn('rounded-2xl px-5 py-6 sm:px-8 sm:py-7', MARINO)}>
                <h1 className="font-brand text-2xl font-semibold tracking-tight sm:text-3xl">Panel de consultoría</h1>
                <p className="mt-1 text-sm text-white/65">
                    {props.stats.clients_active ?? 0} de {props.stats.clients ?? 0} clientes activos · {props.stats.client_users ?? 0} usuarios
                    cliente · {props.stats.cmk_users ?? 0} en el equipo CMK
                </p>
                <div className="mt-5 flex flex-wrap items-baseline gap-x-6 gap-y-2">
                    {totalVencidos === 0 && fuera === 0 ? (
                        <span className="text-base text-white/90">Todos los clientes al día.</span>
                    ) : (
                        <>
                            <Cifra n={conVencidos} singular="cliente con vencidos" plural="clientes con vencidos" tono="rojo" />
                            <Cifra n={totalVencidos} singular="vencimiento en total" plural="vencimientos en total" tono="rojo" />
                            <Cifra n={fuera} singular="indicador fuera de meta" plural="indicadores fuera de meta" tono="rojo" />
                        </>
                    )}
                </div>
            </section>

            <div className="grid items-start gap-6 lg:grid-cols-12">
                <section aria-labelledby="clientes" className="bg-card min-w-0 rounded-2xl border p-4 sm:p-5 lg:col-span-5">
                    <div className="mb-3 flex items-baseline justify-between gap-3 px-1">
                        <h2 id="clientes" className="font-brand text-lg font-semibold tracking-tight">
                            Clientes
                        </h2>
                        <span className="text-muted-foreground text-sm">Los más urgentes primero</span>
                    </div>
                    {clientes.length === 0 ? (
                        <div className="flex flex-col items-start gap-3 px-1 py-4 text-sm">
                            <p className="text-muted-foreground">
                                Todavía no hay clientes activos. Crea el primero para empezar a trabajar su sistema.
                            </p>
                            <Button size="sm" onClick={() => router.visit('/clientes')}>
                                Ir a Clientes
                            </Button>
                        </div>
                    ) : (
                        <ul className="space-y-0.5">
                            {clientes.map((c) => (
                                <Cliente key={c.id} c={c} onAbrir={() => trabajar(c.id, '/dashboard')} />
                            ))}
                        </ul>
                    )}
                </section>

                <div className="min-w-0 lg:col-span-7">
                    <QueHacerHoy
                        titulo="Qué hacer hoy en todos los clientes"
                        descripcion={`Lo vencido y lo que vence en ${dias.por_vencer} días. Al abrir una fila se trabaja ese cliente y se va directo al registro.`}
                        tareas={tareasDeAgenda(items)}
                        onAbrir={(t) => t.tenant && t.url && trabajar(t.tenant.id, ruta(t.url))}
                    />
                </div>
            </div>
        </>
    );
}
