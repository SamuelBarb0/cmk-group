/** Lo que el DashboardController manda a la página (vista cliente y consultor). */

export type EstadoFecha = 'vencido' | 'por_vencer' | 'proximo';

export interface ItemAgenda {
    modulo: string;
    modulo_titulo: string;
    tipo: string;
    titulo: string;
    detalle: string | null;
    fecha: string;
    dias: number;
    estado: EstadoFecha;
    url: string;
    tenant?: { id: number; name: string };
}

export interface Resumen {
    vencido: number;
    por_vencer: number;
    proximo: number;
}

export interface ModuloTarjeta {
    clave: string;
    modulo: string;
    titulo: string;
    url: string | null;
    cifras: { etiqueta: string; valor: string }[];
    alertas: string[];
    vencidos: number;
    por_vencer: number;
}

export interface ClienteFila extends Resumen {
    id: number;
    name: string;
    city: string | null;
    nit: string | null;
    users_count: number;
    /** null = no aplica (módulo no contratado). */
    fuera_meta: number | null;
    /** Puntaje de estándares mínimos 0312 (null = sin autoevaluación). */
    estandares: number | null;
}

export interface IndicadorKpi {
    codigo: string;
    nombre: string;
    categoria: string;
    unidad: string;
    sentido: 'asc' | 'desc';
    meta: number;
    valor: number | null;
    cumple: boolean | null;
    es_legal: boolean;
    /** 12 meses, ene→dic; null = mes sin lectura. */
    serie: (number | null)[];
}

export interface Cumplimiento {
    clave: string;
    modulo: string;
    titulo: string;
    valor: string | null;
    estado: 'bien' | 'mal' | 'sin_dato';
    detalle: string | null;
    url: string | null;
}

export interface Alerta {
    nivel: 'alta' | 'media';
    modulo: string;
    titulo: string;
    texto: string;
    url: string | null;
}

export interface DashboardProps {
    view: 'master' | 'client';
    stats: Record<string, number>;
    tenant?: { id: number; name: string; nit: string | null; city: string | null } | null;
    agenda?: ItemAgenda[];
    agenda_resumen?: Resumen;
    agenda_dias?: { por_vencer: number; horizonte: number };
    modulos?: ModuloTarjeta[];
    clientes?: ClienteFila[];
    /** Vista maestra: vencimientos de todos los clientes. Vista cliente: centro de alertas. */
    alertas?: ItemAgenda[] | Alerta[];
    kpis?: IndicadorKpi[];
    indicadores?: { filas: IndicadorKpi[]; sin_medir: number; total: number };
    cumplimiento?: Cumplimiento[];
}

/**
 * El vocabulario de estado de todo el panel. Siempre se pinta con punto +
 * icono + palabra: el color nunca va solo.
 */
export type Tono = 'rojo' | 'ambar' | 'verde' | 'azul' | 'gris';

export const TONO_PUNTO: Record<Tono, string> = {
    rojo: 'bg-red-600 dark:bg-red-400',
    ambar: 'bg-amber-500 dark:bg-amber-400',
    verde: 'bg-emerald-600 dark:bg-emerald-400',
    azul: 'bg-sky-600 dark:bg-sky-400',
    gris: 'bg-muted-foreground/40',
};

export const TONO_TEXTO: Record<Tono, string> = {
    rojo: 'text-red-700 dark:text-red-400',
    ambar: 'text-amber-700 dark:text-amber-400',
    verde: 'text-emerald-700 dark:text-emerald-400',
    azul: 'text-sky-700 dark:text-sky-400',
    gris: 'text-muted-foreground',
};

export const TONO_FONDO: Record<Tono, string> = {
    rojo: 'bg-red-50 text-red-800 dark:bg-red-950/60 dark:text-red-300',
    ambar: 'bg-amber-50 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
    verde: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
    azul: 'bg-sky-50 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300',
    gris: 'bg-muted text-muted-foreground',
};

/** «7,25»: como lo escribe el informe (coma decimal, sin ceros de más). */
export function num(v: number | null, decimales = 2) {
    return v === null ? '—' : new Intl.NumberFormat('es-CO', { maximumFractionDigits: decimales }).format(v);
}

/** «68,0 %» (texto del informe) → 68. null si no hay número. */
export function porcentajeDeTexto(texto: string | null | undefined): number | null {
    if (!texto) return null;
    const n = parseFloat(texto.replace(/\./g, '').replace(',', '.'));
    return Number.isFinite(n) ? n : null;
}

/** Banda de la Res. 0312 para un puntaje: <60 crítico, ≤85 moderado, >85 aceptable. */
export function banda0312(puntaje: number): { texto: string; tono: Tono } {
    if (puntaje < 60) return { texto: 'Crítico', tono: 'rojo' };
    if (puntaje <= 85) return { texto: 'Moderadamente aceptable', tono: 'ambar' };
    return { texto: 'Aceptable', tono: 'verde' };
}

export function plazo(dias: number): string {
    if (dias < -1) return `Vencido hace ${Math.abs(dias)} d`;
    if (dias === -1) return 'Venció ayer';
    if (dias === 0) return 'Vence hoy';
    if (dias === 1) return 'Vence mañana';
    return `En ${dias} días`;
}

export const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

/** Ruta interna de una URL absoluta: la agenda consolidada navega selecciona-y-va. */
export function ruta(url: string) {
    try {
        const u = new URL(url, window.location.origin);
        return u.pathname + u.search;
    } catch {
        return url;
    }
}
