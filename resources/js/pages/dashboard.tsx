import { CodigoSig, useCodigosSig } from '@/components/codigo-sig';
import { StatCard } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    CalendarCheck2,
    CalendarClock,
    CalendarX2,
    CircleAlert,
    LayoutGrid,
    ShieldCheck,
    UserCircle2,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

type Estado = 'vencido' | 'por_vencer' | 'proximo';

interface ItemAgenda {
    modulo: string;
    modulo_titulo: string;
    tipo: string;
    titulo: string;
    detalle: string | null;
    fecha: string;
    dias: number;
    estado: Estado;
    url: string;
    tenant?: { id: number; name: string };
}

interface Resumen {
    vencido: number;
    por_vencer: number;
    proximo: number;
}

interface ModuloTarjeta {
    clave: string;
    modulo: string;
    titulo: string;
    url: string | null;
    cifras: { etiqueta: string; valor: string }[];
    alertas: string[];
    vencidos: number;
    por_vencer: number;
}

interface ClienteFila extends Resumen {
    id: number;
    name: string;
    city: string | null;
    nit: string | null;
    users_count: number;
}

interface DashboardProps {
    view: 'master' | 'client';
    stats: Record<string, number>;
    tenant?: { id: number; name: string; nit: string | null; city: string | null } | null;
    agenda?: ItemAgenda[];
    agenda_resumen?: Resumen;
    agenda_dias?: { por_vencer: number; horizonte: number };
    modulos?: ModuloTarjeta[];
    clientes?: ClienteFila[];
    alertas?: ItemAgenda[];
}

// Los mismos colores que ya usan los módulos (equipos de medición, PESV…).
const ESTADO_CLASE: Record<Estado, string> = {
    vencido: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    por_vencer: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    proximo: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
};

const ESTADO_BORDE: Record<Estado, string> = {
    vencido: 'border-l-red-500',
    por_vencer: 'border-l-amber-500',
    proximo: 'border-l-sky-500',
};

function plazo(dias: number): string {
    if (dias < -1) return `Vencido hace ${Math.abs(dias)} d`;
    if (dias === -1) return 'Venció ayer';
    if (dias === 0) return 'Vence hoy';
    if (dias === 1) return 'Vence mañana';
    return `En ${dias} días`;
}

const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

/** «15 ago 2026»: cabe en una línea, a diferencia de «15 de ago de 2026». */
function fechaCorta(iso: string) {
    const [a, m, d] = iso.split('-').map(Number);
    return `${d} ${MESES[m - 1]} ${a}`;
}

/**
 * Solo el código PRINCIPAL del módulo en cada fila: el PESV cruza seis
 * códigos del mapa y en una lista larga eso es ruido. La tarjeta del módulo
 * sí los muestra todos.
 */
function CodigoPrincipal({ modulo }: { modulo: string }) {
    const [codigo] = useCodigosSig(modulo);
    if (!codigo) return null;
    return <span className="bg-primary/10 text-primary rounded px-1.5 py-0.5 font-mono text-[11px] font-semibold">{codigo}</span>;
}

/** Ruta interna de una URL absoluta: la agenda consolidada navega selecciona-y-va. */
function ruta(url: string) {
    try {
        const u = new URL(url, window.location.origin);
        return u.pathname + u.search;
    } catch {
        return url;
    }
}

function FilaAgenda({ item, onAbrir }: { item: ItemAgenda; onAbrir?: () => void }) {
    const contenido = (
        <>
            <div className="w-32 shrink-0 text-sm">
                <div className="font-medium whitespace-nowrap tabular-nums">{fechaCorta(item.fecha)}</div>
                <div className={cn('mt-0.5 inline-block rounded px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap', ESTADO_CLASE[item.estado])}>
                    {plazo(item.dias)}
                </div>
            </div>
            <div className="min-w-0 flex-1">
                <div className="text-muted-foreground flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                    <CodigoPrincipal modulo={item.modulo} />
                    <span>{item.tenant ? `${item.tenant.name} · ` : ''}{item.modulo_titulo}</span>
                    <span aria-hidden>·</span>
                    <span className="font-medium">{item.tipo}</span>
                </div>
                <div className="mt-0.5 truncate font-medium">{item.titulo}</div>
                {item.detalle && <div className="text-muted-foreground truncate text-sm">{item.detalle}</div>}
            </div>
            <ArrowRight className="text-muted-foreground size-4 shrink-0 opacity-0 transition group-hover:opacity-100" />
        </>
    );
    const clase = cn('group hover:bg-muted/50 flex w-full items-start gap-4 border-l-4 px-4 py-3 text-left transition', ESTADO_BORDE[item.estado]);

    return onAbrir ? (
        <button type="button" onClick={onAbrir} className={clase}>
            {contenido}
        </button>
    ) : (
        <Link href={item.url} className={clase}>
            {contenido}
        </Link>
    );
}

const GRUPOS: { clave: string; titulo: string; incluye: (i: ItemAgenda) => boolean }[] = [
    { clave: 'vencido', titulo: 'Vencido', incluye: (i) => i.dias < 0 },
    { clave: 'semana', titulo: 'Esta semana', incluye: (i) => i.dias >= 0 && i.dias <= 7 },
    { clave: 'mes', titulo: 'Próximos 30 días', incluye: (i) => i.dias > 7 && i.dias <= 30 },
    { clave: 'despues', titulo: 'Más adelante', incluye: (i) => i.dias > 30 },
];

const VISIBLES = 8;

function Agenda({ items, dias }: { items: ItemAgenda[]; dias: { por_vencer: number; horizonte: number } }) {
    const [estado, setEstado] = useState<string>('todo');
    const [modulo, setModulo] = useState<string>('');
    const [abiertos, setAbiertos] = useState<Record<string, boolean>>({});

    const modulos = useMemo(
        () => Array.from(new Map(items.map((i) => [i.modulo, i.modulo_titulo])).entries()).sort((a, b) => a[1].localeCompare(b[1], 'es')),
        [items],
    );
    const filtrados = items.filter((i) => (estado === 'todo' || i.estado === estado) && (modulo === '' || i.modulo === modulo));

    return (
        <Card className="gap-0 py-0">
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b py-4">
                <div>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <CalendarClock className="size-4" /> Agenda de vencimientos
                    </CardTitle>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Lo vencido y lo que vence en los próximos {dias.horizonte} días. Clic en una fila para abrirla y actualizarla.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <ToggleGroup type="single" size="sm" variant="outline" value={estado} onValueChange={(v) => setEstado(v || 'todo')}>
                        <ToggleGroupItem value="todo">Todo</ToggleGroupItem>
                        <ToggleGroupItem value="vencido">Vencido</ToggleGroupItem>
                        <ToggleGroupItem value="por_vencer">Por vencer</ToggleGroupItem>
                        <ToggleGroupItem value="proximo">Próximo</ToggleGroupItem>
                    </ToggleGroup>
                    {modulos.length > 1 && (
                        <select
                            value={modulo}
                            onChange={(e) => setModulo(e.target.value)}
                            className="border-input bg-background h-8 rounded-md border px-2 text-sm"
                            aria-label="Filtrar por módulo"
                        >
                            <option value="">Todos los módulos</option>
                            {modulos.map(([clave, titulo]) => (
                                <option key={clave} value={clave}>
                                    {titulo}
                                </option>
                            ))}
                        </select>
                    )}
                </div>
            </CardHeader>
            <CardContent className="p-0">
                {filtrados.length === 0 ? (
                    <div className="text-muted-foreground flex items-center gap-3 px-5 py-8 text-sm">
                        <CalendarCheck2 className="size-5 text-emerald-600" />
                        {items.length === 0
                            ? 'Nada vencido ni por vencer en los módulos contratados. Las fechas aparecen aquí en cuanto se registran en cada módulo.'
                            : 'Nada con este filtro.'}
                    </div>
                ) : (
                    GRUPOS.map((g) => {
                        const del = filtrados.filter(g.incluye);
                        if (del.length === 0) return null;
                        const todos = abiertos[g.clave] ?? false;
                        return (
                            <div key={g.clave}>
                                <div className="bg-muted/40 text-muted-foreground flex items-center justify-between border-y px-4 py-1.5 text-xs font-semibold tracking-wide uppercase first:border-t-0">
                                    <span>{g.titulo}</span>
                                    <span className="tabular-nums">{del.length}</span>
                                </div>
                                <div className="divide-border divide-y">
                                    {(todos ? del : del.slice(0, VISIBLES)).map((i, n) => (
                                        <FilaAgenda key={`${g.clave}-${n}-${i.url}-${i.titulo}`} item={i} />
                                    ))}
                                </div>
                                {del.length > VISIBLES && (
                                    <button
                                        type="button"
                                        onClick={() => setAbiertos((a) => ({ ...a, [g.clave]: !todos }))}
                                        className="text-primary w-full border-t px-4 py-2 text-left text-sm font-medium hover:underline"
                                    >
                                        {todos ? 'Ver menos' : `Ver ${del.length - VISIBLES} más`}
                                    </button>
                                )}
                            </div>
                        );
                    })
                )}
            </CardContent>
        </Card>
    );
}

function TarjetaModulo({ m }: { m: ModuloTarjeta }) {
    const cuerpo = (
        <Card className={cn('h-full gap-3 py-4 transition', m.url && 'hover:border-primary/40 hover:shadow-sm')}>
            <CardHeader className="px-4">
                <div className="flex items-start justify-between gap-2">
                    <CardTitle className="text-sm leading-snug">{m.titulo}</CardTitle>
                    <CodigoSig modulo={m.modulo} className="shrink-0" />
                </div>
                {(m.vencidos > 0 || m.por_vencer > 0) && (
                    <div className="flex flex-wrap gap-1.5">
                        {m.vencidos > 0 && <Badge className={cn('font-medium', ESTADO_CLASE.vencido)}>{m.vencidos} vencido{m.vencidos === 1 ? '' : 's'}</Badge>}
                        {m.por_vencer > 0 && <Badge className={cn('font-medium', ESTADO_CLASE.por_vencer)}>{m.por_vencer} por vencer</Badge>}
                    </div>
                )}
            </CardHeader>
            <CardContent className="space-y-3 px-4">
                {m.cifras.length > 0 ? (
                    <dl className="grid grid-cols-2 gap-x-3 gap-y-2">
                        {m.cifras.map((c) => (
                            <div key={c.etiqueta} className="min-w-0">
                                <dt className="text-muted-foreground truncate text-xs" title={c.etiqueta}>
                                    {c.etiqueta}
                                </dt>
                                <dd className="truncate text-sm font-semibold tabular-nums" title={c.valor}>
                                    {c.valor}
                                </dd>
                            </div>
                        ))}
                    </dl>
                ) : (
                    <p className="text-muted-foreground text-sm">Sin datos todavía.</p>
                )}
                {m.alertas.slice(0, 2).map((a) => (
                    <p key={a} className="flex gap-1.5 text-xs text-amber-800 dark:text-amber-300">
                        <CircleAlert className="mt-px size-3.5 shrink-0" />
                        <span>{a}</span>
                    </p>
                ))}
            </CardContent>
        </Card>
    );

    return m.url ? (
        <Link href={m.url} className="block h-full min-w-0">
            {cuerpo}
        </Link>
    ) : (
        cuerpo
    );
}

function VistaCliente({ props }: { props: DashboardProps }) {
    const agenda = props.agenda ?? [];
    const r = props.agenda_resumen ?? { vencido: 0, por_vencer: 0, proximo: 0 };
    const dias = props.agenda_dias ?? { por_vencer: 30, horizonte: 60 };
    const modulos = props.modulos ?? [];
    const atencion = modulos.flatMap((m) => m.alertas.map((a) => ({ modulo: m, texto: a })));

    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Vencidos" value={r.vencido} icon={CalendarX2} alerta={r.vencido > 0} />
                <StatCard label={`Por vencer (${dias.por_vencer} días)`} value={r.por_vencer} icon={CalendarClock} />
                <StatCard label={`Próximos (hasta ${dias.horizonte} días)`} value={r.proximo} icon={CalendarCheck2} />
                <StatCard label="Módulos activos" value={modulos.length} icon={LayoutGrid} />
            </div>

            <div className="grid gap-6 xl:grid-cols-3">
                <div className="min-w-0 xl:col-span-2">
                    <Agenda items={agenda} dias={dias} />
                </div>
                <Card className="min-w-0 gap-0 py-0">
                    <CardHeader className="border-b py-4">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <AlertTriangle className="size-4" /> Puntos de atención
                        </CardTitle>
                        <p className="text-muted-foreground mt-1 text-sm">Lo que cada módulo marca para revisar.</p>
                    </CardHeader>
                    <CardContent className="p-0">
                        {atencion.length === 0 ? (
                            <p className="text-muted-foreground px-5 py-6 text-sm">Ningún módulo marca alertas.</p>
                        ) : (
                            <ul className="divide-border divide-y">
                                {atencion.map(({ modulo, texto }, n) => (
                                    <li key={n}>
                                        {modulo.url ? (
                                            <Link href={modulo.url} className="hover:bg-muted/50 block px-4 py-3">
                                                <div className="text-muted-foreground flex items-center gap-2 text-xs">
                                                    <CodigoSig modulo={modulo.modulo} />
                                                    {modulo.titulo}
                                                </div>
                                                <div className="mt-0.5 text-sm">{texto}</div>
                                            </Link>
                                        ) : (
                                            <div className="px-4 py-3">
                                                <div className="text-muted-foreground text-xs">{modulo.titulo}</div>
                                                <div className="mt-0.5 text-sm">{texto}</div>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>

            {modulos.length > 0 && (
                <div>
                    <h2 className="font-brand text-muted-foreground mb-3 text-sm font-semibold tracking-wide uppercase">
                        Módulos · año en curso
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                        {modulos.map((m) => (
                            <TarjetaModulo key={m.clave} m={m} />
                        ))}
                    </div>
                </div>
            )}
        </>
    );
}

function VistaMaestra({ props }: { props: DashboardProps }) {
    const clientes = props.clientes ?? [];
    const alertas = props.alertas ?? [];
    const dias = props.agenda_dias ?? { por_vencer: 30, horizonte: 60 };
    const trabajar = (id: number, ir?: string) => router.post(route('clientes.select', id), ir ? { ir } : {});

    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Clientes" value={props.stats.clients ?? 0} icon={Building2} />
                <StatCard label="Clientes activos" value={props.stats.clients_active ?? 0} icon={ShieldCheck} />
                <StatCard label="Usuarios cliente" value={props.stats.client_users ?? 0} icon={Users} />
                <StatCard label="Equipo CMK" value={props.stats.cmk_users ?? 0} icon={UserCircle2} />
            </div>

            <div className="grid gap-6 xl:grid-cols-5">
                <Card className="min-w-0 gap-0 py-0 xl:col-span-2">
                    <CardHeader className="border-b py-4">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Building2 className="size-4" /> Clientes
                        </CardTitle>
                        <p className="text-muted-foreground mt-1 text-sm">Los que tienen vencimientos, primero.</p>
                    </CardHeader>
                    <CardContent className="p-0">
                        {clientes.length === 0 ? (
                            <p className="text-muted-foreground px-5 py-6 text-sm">Todavía no hay clientes activos.</p>
                        ) : (
                            <div className="divide-border divide-y">
                                {clientes.map((c) => (
                                    <div key={c.id} className="flex items-center justify-between gap-3 px-4 py-3">
                                        <div className="min-w-0">
                                            <div className="truncate font-medium">{c.name}</div>
                                            <div className="text-muted-foreground truncate text-xs">
                                                {[c.city, c.nit ? `NIT ${c.nit}` : null, `${c.users_count} usuarios`].filter(Boolean).join(' · ')}
                                            </div>
                                            <div className="mt-1 flex flex-wrap gap-1.5">
                                                {c.vencido > 0 && <Badge className={cn('font-medium', ESTADO_CLASE.vencido)}>{c.vencido} vencido{c.vencido === 1 ? '' : 's'}</Badge>}
                                                {c.por_vencer > 0 && <Badge className={cn('font-medium', ESTADO_CLASE.por_vencer)}>{c.por_vencer} por vencer</Badge>}
                                                {c.vencido === 0 && c.por_vencer === 0 && (
                                                    <Badge variant="secondary" className="font-medium">
                                                        Al día
                                                    </Badge>
                                                )}
                                            </div>
                                        </div>
                                        <Button size="sm" variant="outline" onClick={() => trabajar(c.id, '/dashboard')}>
                                            Abrir
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card className="min-w-0 gap-0 py-0 xl:col-span-3">
                    <CardHeader className="border-b py-4">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <AlertTriangle className="size-4" /> Alertas de todos los clientes
                        </CardTitle>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Lo vencido y lo que vence en {dias.por_vencer} días. Clic para trabajar ese cliente e ir directo al registro.
                        </p>
                    </CardHeader>
                    <CardContent className="p-0">
                        {alertas.length === 0 ? (
                            <div className="text-muted-foreground flex items-center gap-3 px-5 py-8 text-sm">
                                <CalendarCheck2 className="size-5 text-emerald-600" /> Ningún cliente tiene vencimientos cercanos.
                            </div>
                        ) : (
                            <div className="divide-border divide-y">
                                {alertas.map((a, n) => (
                                    <FilaAgenda key={n} item={a} onAbrir={() => a.tenant && trabajar(a.tenant.id, ruta(a.url))} />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

export default function Dashboard() {
    const { props } = usePage<SharedData & DashboardProps>();
    const { auth } = props;
    const { roleLabel } = usePermissions();
    const isMaster = props.view === 'master';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            {isMaster ? 'Panel de consultoría' : (props.tenant?.name ?? 'Mi empresa')}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Hola, {auth.user.name}
                            {roleLabel ? ` · ${roleLabel}` : ''}
                        </p>
                    </div>
                    {isMaster ? (
                        <Badge variant="secondary" className="gap-1.5">
                            <UserCircle2 className="size-3.5" /> Vista consolidada
                        </Badge>
                    ) : (
                        props.tenant?.nit && <Badge variant="outline">NIT {props.tenant.nit}</Badge>
                    )}
                </div>

                {isMaster ? <VistaMaestra props={props} /> : <VistaCliente props={props} />}
            </div>
        </AppLayout>
    );
}
