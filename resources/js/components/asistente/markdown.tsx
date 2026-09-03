import { type ReactNode } from 'react';

/**
 * Render de Markdown mínimo para las respuestas del asistente.
 *
 * Se hace a mano en vez de traer react-markdown: el proyecto no tiene ninguna
 * librería de markdown y el subconjunto que produce el asistente (encabezados,
 * listas, tablas, código y énfasis) cabe en este archivo.
 *
 * Nunca se inyecta HTML: todo sale como elementos de React, así que el texto
 * que devuelva la IA no puede convertirse en marcado activo.
 */

/** Aplica los énfasis en línea: **negrita**, *cursiva* y `código`. */
function inline(texto: string, clave: string): ReactNode[] {
    const partes: ReactNode[] = [];
    const patron = /(\*\*[^*]+\*\*|`[^`]+`|\*[^*]+\*)/g;
    let ultimo = 0;
    let match: RegExpExecArray | null;
    let i = 0;

    while ((match = patron.exec(texto)) !== null) {
        if (match.index > ultimo) {
            partes.push(texto.slice(ultimo, match.index));
        }

        const token = match[0];
        const k = `${clave}-i${i++}`;

        if (token.startsWith('**')) {
            partes.push(
                <strong key={k} className="font-semibold">
                    {token.slice(2, -2)}
                </strong>,
            );
        } else if (token.startsWith('`')) {
            partes.push(
                <code key={k} className="rounded bg-muted px-1 py-0.5 font-mono text-[0.85em]">
                    {token.slice(1, -1)}
                </code>,
            );
        } else {
            partes.push(
                <em key={k} className="italic">
                    {token.slice(1, -1)}
                </em>,
            );
        }

        ultimo = match.index + token.length;
    }

    if (ultimo < texto.length) {
        partes.push(texto.slice(ultimo));
    }

    return partes;
}

const NIVEL_TITULO: Record<number, string> = {
    1: 'mt-4 mb-2 text-base font-semibold',
    2: 'mt-4 mb-2 text-[0.95rem] font-semibold',
    3: 'mt-3 mb-1.5 text-sm font-semibold',
};

/** Separa una fila de tabla `| a | b |` en celdas. */
function celdas(linea: string): string[] {
    return linea
        .replace(/^\||\|$/g, '')
        .split('|')
        .map((c) => c.trim());
}

const esSeparadorTabla = (linea: string) => /^\|?[\s:|-]+\|[\s:|-]*$/.test(linea) && linea.includes('-');

export function Markdown({ children }: { children: string }) {
    const lineas = children.split('\n');
    const bloques: ReactNode[] = [];
    let parrafo: string[] = [];
    let i = 0;

    const cerrarParrafo = () => {
        if (parrafo.length === 0) return;

        const texto = parrafo.join(' ');
        bloques.push(
            <p key={`p${bloques.length}`} className="my-1.5 leading-relaxed">
                {inline(texto, `p${bloques.length}`)}
            </p>,
        );
        parrafo = [];
    };

    while (i < lineas.length) {
        const linea = lineas[i];

        // Bloque de código cercado.
        if (linea.trimStart().startsWith('```')) {
            cerrarParrafo();
            const codigo: string[] = [];
            i++;
            while (i < lineas.length && !lineas[i].trimStart().startsWith('```')) {
                codigo.push(lineas[i]);
                i++;
            }
            i++;
            bloques.push(
                <pre key={`c${bloques.length}`} className="my-2 overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs">
                    <code>{codigo.join('\n')}</code>
                </pre>,
            );
            continue;
        }

        // Tabla con tuberías: cabecera + separador + filas.
        if (linea.includes('|') && i + 1 < lineas.length && esSeparadorTabla(lineas[i + 1])) {
            cerrarParrafo();
            const cabecera = celdas(linea);
            i += 2;
            const filas: string[][] = [];
            while (i < lineas.length && lineas[i].includes('|') && lineas[i].trim() !== '') {
                filas.push(celdas(lineas[i]));
                i++;
            }
            bloques.push(
                <div key={`t${bloques.length}`} className="my-2 overflow-x-auto">
                    <table className="w-full border-collapse text-xs">
                        <thead>
                            <tr className="border-b border-border">
                                {cabecera.map((c, n) => (
                                    <th key={n} className="px-2 py-1 text-left font-semibold">
                                        {inline(c, `th${n}`)}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {filas.map((fila, f) => (
                                <tr key={f} className="border-b border-border/50">
                                    {fila.map((c, n) => (
                                        <td key={n} className="px-2 py-1 align-top">
                                            {inline(c, `td${f}-${n}`)}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>,
            );
            continue;
        }

        // Encabezados.
        const titulo = /^(#{1,6})\s+(.*)$/.exec(linea);
        if (titulo) {
            cerrarParrafo();
            const nivel = Math.min(titulo[1].length, 3);
            bloques.push(
                <div key={`h${bloques.length}`} className={NIVEL_TITULO[nivel]}>
                    {inline(titulo[2], `h${bloques.length}`)}
                </div>,
            );
            i++;
            continue;
        }

        // Listas (con o sin numeración); se agrupan las líneas consecutivas.
        const vinieta = /^\s*([-*+]|\d+[.)])\s+(.*)$/.exec(linea);
        if (vinieta) {
            cerrarParrafo();
            const ordenada = /\d/.test(vinieta[1]);
            const items: string[] = [];
            while (i < lineas.length) {
                const m = /^\s*([-*+]|\d+[.)])\s+(.*)$/.exec(lineas[i]);
                if (!m) break;
                items.push(m[2]);
                i++;
            }
            const Lista = ordenada ? 'ol' : 'ul';
            bloques.push(
                <Lista key={`l${bloques.length}`} className={`my-1.5 ml-4 space-y-1 ${ordenada ? 'list-decimal' : 'list-disc'}`}>
                    {items.map((item, n) => (
                        <li key={n} className="leading-relaxed">
                            {inline(item, `li${n}`)}
                        </li>
                    ))}
                </Lista>,
            );
            continue;
        }

        if (linea.trim() === '') {
            cerrarParrafo();
        } else {
            parrafo.push(linea.trim());
        }

        i++;
    }

    cerrarParrafo();

    return <div className="text-sm">{bloques}</div>;
}
