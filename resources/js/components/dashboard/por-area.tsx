import { areasDeModulos } from '@/components/app-sidebar';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type ModuloTarjeta, TONO_PUNTO, type Tono } from './tipos';

/** El estado de un módulo a partir de su resumen: lo vencido manda sobre lo demás. */
function estadoModulo(m: ModuloTarjeta): { tono: Tono; texto: string } {
    if (m.vencidos > 0) return { tono: 'rojo', texto: `${m.vencidos} vencido${m.vencidos === 1 ? '' : 's'}` };
    if (m.por_vencer > 0) return { tono: 'ambar', texto: `${m.por_vencer} por vencer` };
    if (m.alertas.length > 0) return { tono: 'ambar', texto: `${m.alertas.length} por revisar` };
    return { tono: 'verde', texto: 'Al día' };
}

/**
 * «Por área»: los módulos contratados agrupados como en el menú, cada uno con
 * su estado. Reemplaza la pared de tarjetas: de un vistazo se ve qué área
 * tiene algo pendiente, y un clic lleva al módulo.
 */
export function PorArea({ modulos }: { modulos: ModuloTarjeta[] }) {
    const porClave = new Map(modulos.map((m) => [m.modulo, m]));
    const usados = new Set<string>();

    const areas = areasDeModulos()
        .map(({ area, claves }) => {
            const delArea = claves
                .map((c) => porClave.get(c))
                .filter((m): m is ModuloTarjeta => {
                    if (!m || usados.has(m.clave)) return false;
                    usados.add(m.clave);
                    return true;
                });
            return { area, modulos: delArea };
        })
        .filter((a) => a.modulos.length > 0);

    // Lo que el menú no ubica en un área (p. ej. una sección nueva) no se pierde.
    const sueltos = modulos.filter((m) => !usados.has(m.clave));
    if (sueltos.length > 0) areas.push({ area: 'Otros', modulos: sueltos });

    if (areas.length === 0) return null;

    return (
        <section aria-labelledby="por-area" className="bg-card rounded-2xl border p-4 sm:p-5">
            <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2 px-1">
                <h2 id="por-area" className="font-brand text-lg font-semibold tracking-tight">
                    Por área
                </h2>
                <span className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                    {(['rojo', 'ambar', 'verde'] as Tono[]).map((t) => (
                        <span key={t} className="inline-flex items-center gap-1.5">
                            <span className={cn('size-2 rounded-full', TONO_PUNTO[t])} aria-hidden />
                            {t === 'rojo' ? 'Vencido' : t === 'ambar' ? 'Pendiente' : 'Al día'}
                        </span>
                    ))}
                </span>
            </div>

            <div className="divide-border divide-y">
                {areas.map(({ area, modulos: delArea }) => {
                    const pendientes = delArea.filter((m) => estadoModulo(m).tono !== 'verde').length;
                    return (
                        <div key={area} className="grid gap-2 py-3 first:pt-1 last:pb-1 md:grid-cols-[11rem_1fr] md:gap-4">
                            <div className="px-1">
                                <div className="text-sm font-semibold">{area}</div>
                                <div className="text-muted-foreground text-xs">
                                    {pendientes === 0 ? 'Todo al día' : `${pendientes} de ${delArea.length} con pendientes`}
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-1.5">
                                {delArea.map((m) => {
                                    const e = estadoModulo(m);
                                    const pista = [e.texto, ...m.alertas.slice(0, 2)].join(' · ');
                                    const chip = (
                                        <>
                                            <span className={cn('size-2 shrink-0 rounded-full', TONO_PUNTO[e.tono])} aria-hidden />
                                            <span className="min-w-0 truncate">{m.titulo}</span>
                                            {e.tono !== 'verde' && (
                                                <span className="text-muted-foreground shrink-0 whitespace-nowrap tabular-nums">· {e.texto}</span>
                                            )}
                                        </>
                                    );
                                    const clase =
                                        'inline-flex max-w-full items-center gap-1.5 rounded-full border bg-background px-3 py-1 text-xs font-medium transition-colors duration-150';
                                    return m.url ? (
                                        <Link
                                            key={m.clave}
                                            href={m.url}
                                            title={pista}
                                            className={cn(
                                                clase,
                                                'hover:border-primary/40 hover:bg-muted/60 focus-visible:ring-ring focus-visible:ring-2 focus-visible:outline-none',
                                            )}
                                        >
                                            {chip}
                                        </Link>
                                    ) : (
                                        <span key={m.clave} title={pista} className={clase}>
                                            {chip}
                                        </span>
                                    );
                                })}
                            </div>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
