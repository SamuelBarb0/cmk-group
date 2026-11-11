import { useCodigosSig } from '@/components/codigo-sig';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { plazo, TONO_FONDO, TONO_PUNTO, type Alerta, type ItemAgenda, type Tono } from './tipos';

/** Una cosa por hacer: sale de la agenda (una fecha) o de una alerta (un estado). */
export interface Tarea {
    clave: string;
    /** 0 alerta alta · 1 vencido · 2 vence en 7 días · 3 alerta media · 4 por vencer · 5 próximo. */
    nivel: number;
    /** Orden dentro del nivel (días: lo más vencido primero). */
    orden: number;
    tono: Tono;
    titulo: string;
    contexto: string;
    modulo: string;
    etiqueta: string;
    url: string | null;
    tenant?: { id: number; name: string };
}

const GRUPOS: { titulo: string; niveles: number[] }[] = [
    { titulo: 'Urgente', niveles: [0, 1] },
    { titulo: 'Esta semana', niveles: [2] },
    { titulo: 'Para revisar', niveles: [3] },
    { titulo: 'Más adelante', niveles: [4, 5] },
];

const porPrioridad = (a: Tarea, b: Tarea) => a.nivel - b.nivel || a.orden - b.orden;

function deAgenda(i: ItemAgenda): Tarea {
    return {
        clave: `a-${i.modulo}-${i.url}-${i.titulo}-${i.fecha}`,
        nivel: i.estado === 'vencido' ? 1 : i.estado === 'por_vencer' && i.dias <= 7 ? 2 : i.estado === 'por_vencer' ? 4 : 5,
        // Dentro del mismo nivel, lo que más tiempo lleva vencido va primero.
        orden: i.dias,
        tono: i.estado === 'vencido' ? 'rojo' : i.estado === 'por_vencer' ? 'ambar' : 'azul',
        titulo: i.titulo,
        contexto: [i.tenant?.name, i.modulo_titulo, i.tipo, i.detalle].filter(Boolean).join(' · '),
        modulo: i.modulo,
        etiqueta: plazo(i.dias),
        url: i.url,
        tenant: i.tenant,
    };
}

/**
 * La lista del cliente: alertas y agenda en un solo orden de prioridad.
 *
 * Una alerta de módulo que solo repite «hay algo vencido» se omite cuando la
 * agenda ya trae ese vencimiento concreto: una cosa, un renglón.
 */
export function tareasDelCliente(agenda: ItemAgenda[], alertas: Alerta[]): Tarea[] {
    const conVencidos = new Set(agenda.filter((i) => i.estado === 'vencido').map((i) => i.modulo));
    const deAlertas = alertas
        .filter((a) => !(a.nivel === 'media' && conVencidos.has(a.modulo) && /vencid/i.test(a.texto)))
        .map<Tarea>((a, n) => {
            // Las alertas propias del panel ya traen un título corto («IF-AT
            // fuera de meta»); las de módulo traen el nombre del módulo como
            // título y la frase accionable en el texto.
            const tituloCorto = a.modulo === 'indicadores' || a.modulo === 'documentos-ia';
            return {
                clave: `l-${n}-${a.titulo}`,
                nivel: a.nivel === 'alta' ? 0 : 3,
                orden: n,
                tono: a.nivel === 'alta' ? 'rojo' : 'ambar',
                titulo: tituloCorto ? a.titulo : a.texto,
                contexto: tituloCorto ? a.texto : a.titulo,
                modulo: a.modulo,
                etiqueta: a.nivel === 'alta' ? 'Prioridad alta' : 'Revisar',
                url: a.url,
            };
        });

    return [...deAlertas, ...agenda.map(deAgenda)].sort(porPrioridad);
}

export function tareasDeAgenda(items: ItemAgenda[]): Tarea[] {
    return items.map(deAgenda).sort(porPrioridad);
}

function Codigo({ modulo }: { modulo: string }) {
    const [codigo] = useCodigosSig(modulo);
    if (!codigo) return null;
    return <span className="text-primary/80 font-mono text-[11px] font-semibold">{codigo}</span>;
}

function Fila({ t, onAbrir }: { t: Tarea; onAbrir?: (t: Tarea) => void }) {
    const cuerpo = (
        <>
            <span className={cn('mt-1.5 size-2.5 shrink-0 rounded-full', TONO_PUNTO[t.tono])} aria-hidden />
            <span className="min-w-0 flex-1">
                <span className="line-clamp-2 text-[15px] leading-snug font-medium text-pretty">{t.titulo}</span>
                <span className="text-muted-foreground mt-0.5 flex min-w-0 items-center gap-1.5 text-xs">
                    <Codigo modulo={t.modulo} />
                    <span className="truncate">{t.contexto}</span>
                </span>
                {/* En el teléfono la etiqueta baja: el título se queda con el ancho. */}
                <span className={cn('mt-1.5 inline-block rounded-md px-2 py-0.5 text-xs font-medium sm:hidden', TONO_FONDO[t.tono])}>
                    {t.etiqueta}
                </span>
            </span>
            <span className={cn('mt-0.5 hidden shrink-0 rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap sm:inline', TONO_FONDO[t.tono])}>
                {t.etiqueta}
            </span>
        </>
    );
    const clase =
        'group flex w-full items-start gap-3 rounded-lg px-3 py-2.5 text-left transition-colors duration-150 hover:bg-muted/60 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-2';

    if (onAbrir) {
        return (
            <button type="button" onClick={() => onAbrir(t)} className={clase}>
                {cuerpo}
            </button>
        );
    }
    return t.url ? (
        <Link href={t.url} className={clase}>
            {cuerpo}
        </Link>
    ) : (
        <div className={clase}>{cuerpo}</div>
    );
}

/**
 * «Qué hacer hoy»: lo primero de la lista a la vista; el resto, agrupado por
 * urgencia, a un clic. Sin filtros: el orden ya es la decisión.
 */
export function QueHacerHoy({
    tareas,
    titulo = 'Qué hacer hoy',
    descripcion,
    visibles = 6,
    onAbrir,
}: {
    tareas: Tarea[];
    titulo?: string;
    descripcion?: string;
    visibles?: number;
    onAbrir?: (t: Tarea) => void;
}) {
    const [todas, setTodas] = useState(false);

    return (
        <section aria-labelledby="que-hacer-hoy" className="bg-card min-w-0 rounded-2xl border p-4 sm:p-5">
            <div className="mb-3 flex items-baseline justify-between gap-3 px-1">
                <h2 id="que-hacer-hoy" className="font-brand text-lg font-semibold tracking-tight">
                    {titulo}
                </h2>
                {tareas.length > 0 && (
                    <span className="text-muted-foreground shrink-0 text-sm whitespace-nowrap tabular-nums">{tareas.length} en total</span>
                )}
            </div>
            {descripcion && <p className="text-muted-foreground -mt-2 mb-3 px-1 text-sm">{descripcion}</p>}

            {tareas.length === 0 ? (
                <div className="flex items-center gap-3 rounded-xl bg-emerald-50 px-4 py-6 text-sm text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
                    <CheckCircle2 className="size-5 shrink-0" />
                    <span>
                        <span className="font-medium">Nada pendiente.</span> No hay vencimientos ni indicadores por atender. Lo que vaya venciendo
                        aparece aquí.
                    </span>
                </div>
            ) : todas ? (
                <div className="space-y-4">
                    {GRUPOS.map((g) => {
                        const del = tareas.filter((t) => g.niveles.includes(t.nivel));
                        if (del.length === 0) return null;
                        return (
                            <div key={g.titulo}>
                                <h3 className="text-muted-foreground mb-1 flex justify-between px-3 text-xs font-semibold">
                                    <span>{g.titulo}</span>
                                    <span className="tabular-nums">{del.length}</span>
                                </h3>
                                <div className="space-y-0.5">
                                    {del.map((t) => (
                                        <Fila key={t.clave} t={t} onAbrir={onAbrir} />
                                    ))}
                                </div>
                            </div>
                        );
                    })}
                </div>
            ) : (
                <div className="space-y-0.5">
                    {tareas.slice(0, visibles).map((t) => (
                        <Fila key={t.clave} t={t} onAbrir={onAbrir} />
                    ))}
                </div>
            )}

            {tareas.length > visibles && (
                <button
                    type="button"
                    onClick={() => setTodas(!todas)}
                    className="text-primary hover:bg-primary/5 focus-visible:ring-ring mt-2 flex w-full items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none"
                >
                    {todas ? (
                        <>
                            Ver solo lo primero <ChevronDown className="size-4 rotate-180" />
                        </>
                    ) : (
                        <>
                            Ver las {tareas.length}, agrupadas por urgencia <ArrowRight className="size-4" />
                        </>
                    )}
                </button>
            )}
        </section>
    );
}
