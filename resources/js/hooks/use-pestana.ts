import { usePartes } from '@/hooks/use-partes';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** La `?parte=` de una URL de Inertia (relativa), o null. */
function parteDe(url: string): string | null {
    return new URL(url, 'http://local').searchParams.get('parte');
}

/**
 * Pestaña activa de un módulo, atada a `?parte=` en la URL.
 *
 * Así cada submódulo tiene su propio enlace: el menú lateral lleva directo a
 * él, se puede compartir y sobrevive a una recarga. Cambiar de pestaña
 * reescribe la URL con una visita del lado del cliente (sin ir al servidor),
 * para que el menú marque el submódulo en el que se está.
 *
 * `base` son las pestañas que no son partes contratables (el catálogo de EPP,
 * el estado por trabajador de salud): siempre están. Una `?parte=` que no
 * existe o que la empresa no contrató se ignora y se abre la primera.
 */
export function usePestana<T extends string>(modulo: string, orden: readonly T[], base: readonly T[] = []) {
    const { url } = usePage();
    const { tiene } = usePartes(modulo);

    const disponible = (p: string | null): p is T => p !== null && (orden as readonly string[]).includes(p) && ((base as readonly string[]).includes(p) || tiene(p));
    const primera = orden.find((p) => disponible(p)) ?? orden[0];
    const desdeUrl = (u: string) => {
        const p = parteDe(u);
        return disponible(p) ? p : null;
    };

    const [pestana, setEstado] = useState<T>(() => desdeUrl(url) ?? primera);

    // Un clic en el menú sobre el mismo módulo no remonta la página: solo
    // cambia la URL, y de ahí se toma la pestaña.
    useEffect(() => {
        const p = desdeUrl(url);
        if (p) setEstado(p);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [url]);

    const setPestana = (p: T) => {
        setEstado(p);
        const destino = new URL(url, 'http://local');
        destino.searchParams.set('parte', p);
        router.replace({ url: destino.pathname + destino.search, preserveState: true, preserveScroll: true });
    };

    return [pestana, setPestana] as const;
}
