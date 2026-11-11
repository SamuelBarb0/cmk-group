import { CodigoSig } from '@/components/codigo-sig';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { MESES, num, TONO_PUNTO, TONO_TEXTO, type Cumplimiento, type IndicadorKpi, type Tono } from './tipos';

/** Estado con punto + palabra: el color nunca va solo. */
export function Estado({ tono, texto, className }: { tono: Tono; texto: string; className?: string }) {
    return (
        <span className={cn('inline-flex items-center gap-1.5 text-xs font-medium whitespace-nowrap', TONO_TEXTO[tono], className)}>
            <span className={cn('size-2 rounded-full', TONO_PUNTO[tono])} aria-hidden />
            {texto}
        </span>
    );
}

const TONO_CUMPLIMIENTO: Record<Cumplimiento['estado'], { tono: Tono; texto: string }> = {
    bien: { tono: 'verde', texto: 'En meta' },
    mal: { tono: 'rojo', texto: 'Requiere acción' },
    sin_dato: { tono: 'gris', texto: 'Sin datos' },
};

function tonoIndicador(cumple: boolean | null): { tono: Tono; texto: string } {
    if (cumple === null) return { tono: 'gris', texto: 'Sin medir' };
    return cumple ? { tono: 'verde', texto: 'Cumple' } : { tono: 'rojo', texto: 'No cumple' };
}

/**
 * Los 12 meses del indicador con la meta punteada. Un mes sin lectura corta
 * la línea en vez de dibujarse como cero; cada mes muestra su valor al pasar
 * el cursor.
 */
function Tendencia({ i, ancho = 112, alto = 32 }: { i: IndicadorKpi; ancho?: number; alto?: number }) {
    const valores = i.serie.filter((v): v is number => v !== null);
    const min = Math.min(...valores, i.meta);
    const max = Math.max(...valores, i.meta);
    const rango = max - min || 1;
    const pad = 3;
    const x = (m: number) => pad + (m * (ancho - pad * 2)) / 11;
    const y = (v: number) => alto - pad - ((v - min) / rango) * (alto - pad * 2);

    const tramos: { m: number; v: number }[][] = [];
    i.serie.forEach((v, m) => {
        if (v === null) return;
        const ultimo = tramos[tramos.length - 1];
        if (ultimo && ultimo[ultimo.length - 1].m === m - 1) ultimo.push({ m, v });
        else tramos.push([{ m, v }]);
    });
    const color = i.cumple === false ? 'text-red-600 dark:text-red-400' : 'text-primary dark:text-sky-300';

    return (
        <svg
            width={ancho}
            height={alto}
            viewBox={`0 0 ${ancho} ${alto}`}
            role="img"
            aria-label={`${i.codigo}: evolución de enero a diciembre`}
            className={cn('shrink-0 overflow-visible', color)}
        >
            <line
                x1={pad}
                x2={ancho - pad}
                y1={y(i.meta)}
                y2={y(i.meta)}
                className="stroke-muted-foreground/45"
                strokeWidth={1}
                strokeDasharray="2 3"
            />
            {tramos.map((t, n) =>
                t.length === 1 ? (
                    <circle key={n} cx={x(t[0].m)} cy={y(t[0].v)} r={2.5} fill="currentColor" />
                ) : (
                    <polyline
                        key={n}
                        points={t.map((p) => `${x(p.m)},${y(p.v)}`).join(' ')}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth={2}
                        strokeLinejoin="round"
                        strokeLinecap="round"
                    />
                ),
            )}
            {i.serie.map((v, m) => (
                <rect key={m} x={x(m) - (ancho - pad * 2) / 22} y={0} width={(ancho - pad * 2) / 11} height={alto} fill="transparent">
                    <title>{`${MESES[m]}: ${v === null ? 'sin lectura' : num(v)}`}</title>
                </rect>
            ))}
        </svg>
    );
}

const fila =
    'flex items-center gap-3 rounded-lg px-3 py-2.5 transition-colors duration-150 hover:bg-muted/60 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-2';

/**
 * «Salud del sistema»: el estado de lo que se mide, en filas compactas. El
 * puntaje 0312 no se repite aquí: ya es el ancla del encabezado.
 */
export function SaludSistema({
    cumplimiento,
    kpis,
    otros,
}: {
    cumplimiento: Cumplimiento[];
    kpis: IndicadorKpi[];
    otros: { medidos: number; fuera: number } | null;
}) {
    // Lo que pide acción primero; lo que no tiene datos, agrupado al final.
    const ORDEN: Record<Cumplimiento['estado'], number> = { mal: 0, bien: 1, sin_dato: 2 };
    const filasCumplimiento = cumplimiento.filter((c) => c.clave !== 'diagnostico').sort((a, b) => ORDEN[a.estado] - ORDEN[b.estado]);
    const sinDatos = filasCumplimiento.filter((c) => c.estado === 'sin_dato');
    const agruparSinDatos = sinDatos.length >= 2;
    const filasVisibles = agruparSinDatos ? filasCumplimiento.filter((c) => c.estado !== 'sin_dato') : filasCumplimiento;
    const medidos = kpis.filter((k) => k.valor !== null);
    const sinMedir = kpis.filter((k) => k.valor === null);

    return (
        <section aria-labelledby="salud-sistema" className="bg-card min-w-0 rounded-2xl border p-4 sm:p-5">
            <h2 id="salud-sistema" className="font-brand mb-3 px-1 text-lg font-semibold tracking-tight">
                Salud del sistema
            </h2>

            {filasCumplimiento.length > 0 && (
                <div className="space-y-0.5">
                    {filasVisibles.map((c) => {
                        const e = TONO_CUMPLIMIENTO[c.estado];
                        const contenido = (
                            <>
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-medium">{c.titulo}</span>
                                    <span className="text-muted-foreground flex items-start gap-1.5 text-xs">
                                        <CodigoSig modulo={c.modulo} className="shrink-0" />
                                        {c.estado === 'mal' && c.detalle && <span className="line-clamp-2">{c.detalle}</span>}
                                    </span>
                                </span>
                                <span className="min-w-6 shrink-0 text-right text-base font-semibold tabular-nums">{c.valor ?? '—'}</span>
                                <Estado {...e} className="shrink-0 justify-end sm:w-28" />
                            </>
                        );
                        return c.url ? (
                            <Link key={c.clave} href={c.url} className={fila}>
                                {contenido}
                            </Link>
                        ) : (
                            <div key={c.clave} className={fila}>
                                {contenido}
                            </div>
                        );
                    })}
                    {agruparSinDatos && (
                        <div className={cn(fila, 'hover:bg-transparent')}>
                            <span className="min-w-0 flex-1 text-sm">
                                <span className="font-medium">{sinDatos.length} sin datos todavía</span>
                                <span className="text-muted-foreground block text-xs">
                                    {sinDatos.map((c, n) => (
                                        <span key={c.clave}>
                                            {n > 0 && ' · '}
                                            {c.url ? (
                                                <Link href={c.url} className="hover:text-foreground underline-offset-2 hover:underline">
                                                    {c.titulo}
                                                </Link>
                                            ) : (
                                                c.titulo
                                            )}
                                        </span>
                                    ))}
                                </span>
                            </span>
                            <Estado tono="gris" texto="Sin datos" className="shrink-0 justify-end sm:w-28" />
                        </div>
                    )}
                </div>
            )}

            {kpis.length > 0 && (
                <>
                    <h3 className="text-muted-foreground mt-5 mb-1 px-3 text-xs font-semibold">Indicadores mínimos de la Res. 0312 · año en curso</h3>
                    <div className="space-y-0.5">
                        {medidos.map((k) => {
                            const e = tonoIndicador(k.cumple);
                            return (
                                <Link key={k.codigo} href="/indicadores" className={cn(fila, 'flex-col items-stretch gap-1.5')}>
                                    {/* Arriba qué es; abajo cómo va. Así el nombre no se corta. */}
                                    <span className="flex min-w-0 items-baseline gap-2">
                                        <span className="shrink-0 font-mono text-[11px] font-semibold whitespace-nowrap">{k.codigo}</span>
                                        <span className="text-muted-foreground truncate text-xs" title={k.nombre}>
                                            {k.nombre}
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-3">
                                        <Tendencia i={k} ancho={104} />
                                        <span className="min-w-0 flex-1 text-right">
                                            <span className="block text-base leading-tight font-semibold tabular-nums">
                                                {num(k.valor)}
                                                {k.unidad === '%' ? ' %' : ''}
                                            </span>
                                            <span className="text-muted-foreground block text-[11px] whitespace-nowrap tabular-nums">
                                                meta {k.sentido === 'asc' ? '≥' : '≤'} {num(k.meta)}
                                            </span>
                                        </span>
                                        <Estado {...e} className="shrink-0 justify-end" />
                                    </span>
                                </Link>
                            );
                        })}
                        {sinMedir.length > 0 && (
                            <Link href="/indicadores" className={cn(fila, 'text-sm')}>
                                <span className="min-w-0 flex-1">
                                    <span className="font-medium">
                                        {sinMedir.length} de {kpis.length} sin medir este año
                                    </span>
                                    <span className="text-muted-foreground block truncate font-mono text-[11px]">
                                        {sinMedir.map((k) => k.codigo).join(' · ')}
                                    </span>
                                </span>
                                <span className="text-primary inline-flex items-center gap-1 text-sm font-medium">
                                    Registrar lecturas <ArrowRight className="size-3.5" />
                                </span>
                            </Link>
                        )}
                    </div>
                </>
            )}

            {otros && otros.medidos > 0 && (
                <Link href="/indicadores" className={cn(fila, 'mt-1 text-sm')}>
                    <span className="min-w-0 flex-1">
                        Otros {otros.medidos} indicador{otros.medidos === 1 ? '' : 'es'} con medición
                    </span>
                    {otros.fuera > 0 ? <Estado tono="rojo" texto={`${otros.fuera} fuera de meta`} /> : <Estado tono="verde" texto="Todos en meta" />}
                    <ArrowRight className="text-muted-foreground size-3.5" />
                </Link>
            )}

            {filasCumplimiento.length === 0 && kpis.length === 0 && (
                <p className="text-muted-foreground px-1 text-sm">Los módulos contratados todavía no tienen cifras para medir.</p>
            )}
        </section>
    );
}
