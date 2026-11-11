import { cn } from '@/lib/utils';
import { useEffect, useState } from 'react';
import { banda0312, num } from './tipos';

/** El trazo según la banda, legible sobre el azul marino del encabezado o sobre blanco. */
const TRAZO = {
    sobreMarino: { rojo: 'stroke-red-400', ambar: 'stroke-amber-300', verde: 'stroke-emerald-300', azul: 'stroke-sky-300', gris: 'stroke-white/40' },
    sobreClaro: {
        rojo: 'stroke-red-600',
        ambar: 'stroke-amber-500',
        verde: 'stroke-emerald-600',
        azul: 'stroke-sky-600',
        gris: 'stroke-muted-foreground/40',
    },
} as const;

/**
 * Puntaje de estándares mínimos (Res. 0312) como anillo. Es el único número
 * que resume el sistema entero, por eso es el ancla del encabezado. El anillo
 * se llena una vez al llegar; sin movimiento si el usuario lo pidió así.
 */
export function Anillo0312({
    puntaje,
    tamano = 132,
    sobre = 'sobreMarino',
}: {
    puntaje: number | null;
    tamano?: number;
    sobre?: keyof typeof TRAZO;
}) {
    const grosor = Math.max(6, Math.round(tamano / 13));
    const radio = (tamano - grosor) / 2;
    const circ = 2 * Math.PI * radio;
    const [lleno, setLleno] = useState(false);
    useEffect(() => {
        const id = requestAnimationFrame(() => setLleno(true));
        return () => cancelAnimationFrame(id);
    }, []);

    const banda = puntaje === null ? null : banda0312(puntaje);
    const fraccion = puntaje === null ? 0 : Math.min(100, Math.max(0, puntaje)) / 100;
    const grande = tamano >= 96;

    return (
        <div className="relative shrink-0" style={{ width: tamano, height: tamano }}>
            <svg width={tamano} height={tamano} viewBox={`0 0 ${tamano} ${tamano}`} className="-rotate-90" aria-hidden>
                <circle
                    cx={tamano / 2}
                    cy={tamano / 2}
                    r={radio}
                    fill="none"
                    strokeWidth={grosor}
                    className={sobre === 'sobreMarino' ? 'stroke-white/12' : 'stroke-muted'}
                />
                {banda && (
                    <circle
                        cx={tamano / 2}
                        cy={tamano / 2}
                        r={radio}
                        fill="none"
                        strokeWidth={grosor}
                        strokeLinecap="round"
                        strokeDasharray={circ}
                        strokeDashoffset={circ * (1 - (lleno ? fraccion : 0))}
                        className={cn(
                            TRAZO[sobre][banda.tono],
                            'motion-safe:transition-[stroke-dashoffset] motion-safe:duration-1000 motion-safe:ease-out',
                        )}
                    />
                )}
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
                <span className={cn('leading-none font-semibold tabular-nums', grande ? 'font-brand text-3xl' : 'text-xs')}>
                    {puntaje === null ? '—' : num(puntaje, grande ? 1 : 0)}
                    {puntaje !== null && <span className={cn('font-medium opacity-70', grande ? 'ml-0.5 text-base' : 'text-[9px]')}>%</span>}
                </span>
                {grande && <span className="mt-1 text-[11px] font-medium opacity-75">Res. 0312</span>}
            </div>
        </div>
    );
}
