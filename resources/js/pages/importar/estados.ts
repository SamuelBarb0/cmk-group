/** Cómo se muestra cada estado de una importación: [texto, clase de color]. */
export const ESTADOS: Record<string, [string, string]> = {
    subido: ['Elegir hoja', 'text-muted-foreground'],
    mapeando: ['La IA está analizando…', 'text-blue-700 dark:text-blue-400'],
    listo: ['Por revisar', 'text-amber-700 dark:text-amber-400'],
    error: ['Error', 'text-red-700 dark:text-red-400'],
    aplicado: ['Importado', 'text-green-700 dark:text-green-400'],
    deshecho: ['Deshecho', 'text-muted-foreground'],
};
