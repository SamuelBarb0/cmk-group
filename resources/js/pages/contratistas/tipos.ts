/** Tipos y cálculo compartidos por las dos pantallas de contratistas. */

export interface Opcion {
    label: string;
    puntos: number;
}

export interface Item {
    key: string;
    texto: string;
    peso?: number;
    opciones: Opcion[];
}

export interface Formato {
    nombre: string;
    fuente: string;
    uso: 'seleccion' | 'evaluacion' | 'requisitos_sst';
    escala: 'ponderada' | 'puntos' | 'chequeo';
    secciones: { titulo: string; items: Item[] }[];
}

export interface Resumen {
    id: number;
    fecha: string;
    formato: string;
    puntaje: number;
    porcentaje: number;
    resultado: string;
    incumplimientos: number;
}

export interface Situacion {
    seleccion: Resumen | null;
    evaluacion: Resumen | null;
    requisitos_sst: Resumen | null;
    proxima_evaluacion: string | null;
    evaluacion_vencida: boolean;
    reevaluacion: { anio: number; evaluaciones: number; porcentaje: number; resultado: string } | null;
    documentos_vencidos: number;
    documentos_por_vencer: number;
    documentos_pendientes: number;
}

export interface Catalogos {
    tipos: string[];
    documentos: Record<string, string>;
    requeridos: Record<'juridica' | 'natural', string[]>;
    formatos: Record<string, Formato>;
    resultados: Record<string, string>;
    umbrales: { confiable: number; regular: number; seleccion: number; meses: number };
}

export type Respuesta = { opcion: number | 'na' | null; observacion: string };

export const TIPO_LABEL: Record<string, string> = {
    contratista: 'Contratista',
    subcontratista: 'Subcontratista',
    tercero: 'Tercero',
    proveedor: 'Proveedor',
    propietario_vehiculo: 'Propietario de vehículo',
};

export const COLOR_RESULTADO: Record<string, string> = {
    apto: 'border-green-600/40 bg-green-600/10 text-green-700 dark:text-green-400',
    confiable: 'border-green-600/40 bg-green-600/10 text-green-700 dark:text-green-400',
    cumple: 'border-green-600/40 bg-green-600/10 text-green-700 dark:text-green-400',
    regular: 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    no_apto: 'border-red-600/40 bg-red-600/10 text-red-700 dark:text-red-400',
    no_confiable: 'border-red-600/40 bg-red-600/10 text-red-700 dark:text-red-400',
    no_cumple: 'border-red-600/40 bg-red-600/10 text-red-700 dark:text-red-400',
};

/**
 * Misma cuenta que EvaluacionesContratistas::calificar() en el servidor, para
 * ver el resultado mientras se diligencia. N/A vale la máxima calificación
 * (regla de CMK). Devuelve null mientras falte algún ítem por responder.
 */
export function calificar(f: Formato, respuestas: Record<string, Respuesta>, u: Catalogos['umbrales']) {
    let obtenido = 0;
    let maximo = 0;
    let incumplimientos = 0;
    let faltan = 0;
    for (const s of f.secciones) {
        for (const it of s.items) {
            const max = Math.max(...it.opciones.map((o) => o.puntos));
            const peso = it.peso ?? 1;
            const r = respuestas[it.key]?.opcion;
            if (r === null || r === undefined) {
                faltan++;
                continue;
            }
            const valor = r === 'na' ? max : (it.opciones[r]?.puntos ?? 0);
            if (r !== 'na' && valor < max && f.escala === 'chequeo') incumplimientos++;
            obtenido += valor * peso;
            maximo += max * peso;
        }
    }
    if (faltan > 0) return { faltan, puntaje: null, porcentaje: null, resultado: null, incumplimientos };
    const puntaje = Math.round(obtenido * 100) / 100;
    const porcentaje = maximo > 0 ? Math.round((obtenido / maximo) * 1000) / 10 : 0;
    const resultado =
        f.escala === 'ponderada'
            ? puntaje >= u.seleccion
                ? 'apto'
                : 'no_apto'
            : f.escala === 'puntos'
              ? porcentaje >= u.confiable
                  ? 'confiable'
                  : porcentaje >= u.regular
                    ? 'regular'
                    : 'no_confiable'
              : incumplimientos === 0
                ? 'cumple'
                : 'no_cumple';
    return { faltan: 0, puntaje, porcentaje, resultado, incumplimientos };
}

/** Cómo se lee el puntaje según la escala: 4,2 / 5 · 88 % · 95 %. */
export function puntajeTexto(escala: Formato['escala'] | undefined, puntaje: number, porcentaje: number): string {
    return escala === 'ponderada' ? `${puntaje.toFixed(2)} / 5` : `${porcentaje} %`;
}
