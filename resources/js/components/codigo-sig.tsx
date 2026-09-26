import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/** Rutas cuyo primer segmento no es la clave del módulo. */
const ALIAS: Record<string, string> = { formatos: 'inspecciones' };

/**
 * Códigos del mapa documental del SIG (M01–M20) de una pantalla. Sin clave,
 * toma la pantalla de la URL actual.
 */
export function useCodigosSig(modulo?: string): string[] {
    const { url, props } = usePage<SharedData>();
    const segmento = url.split(/[/?#]/)[1] ?? '';
    const clave = modulo ?? ALIAS[segmento] ?? segmento;

    return props.codigos_sig?.[clave] ?? [];
}

/** Etiqueta con el código del mapa (M03), para los encabezados de las pantallas. */
export function CodigoSig({ modulo, className }: { modulo?: string; className?: string }) {
    const codigos = useCodigosSig(modulo);
    if (codigos.length === 0) return null;

    return (
        <span
            title={`Mapa documental del SIG: ${codigos.join(', ')}`}
            className={cn('bg-primary/10 text-primary rounded px-1.5 py-0.5 align-middle font-mono text-xs font-semibold', className)}
        >
            {codigos.join(' · ')}
        </span>
    );
}
