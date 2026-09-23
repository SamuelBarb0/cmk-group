import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/** Estructura que arman InformeGestion y ReporteAutogestion en el servidor. */
export interface Cifra {
    etiqueta: string;
    valor: string;
    alerta: string | null;
}
export interface Tabla {
    titulo: string;
    columnas: string[];
    filas: string[][];
    omitidas: number;
    vacio: string;
}
export interface SeccionInforme {
    clave: string;
    titulo: string;
    cifras: Cifra[];
    tablas: Tabla[];
    notas: string[];
}
export interface Informe {
    empresa: { nombre: string; razon_social: string; nit: string | null; ciudad: string | null };
    periodo: { desde: string; hasta: string; etiqueta: string };
    generado: { fecha: string; por: string };
    atencion: { seccion: string; texto: string }[];
    secciones: SeccionInforme[];
}
/** Una sección del informe: cifras, tablas y notas, igual que en el Word y el PDF. */
export function SeccionCard({ s }: { s: SeccionInforme }) {
    return (
        <Card>
            <CardContent className="flex flex-col gap-4 p-5">
                <h2 className="font-brand text-primary text-lg font-bold">{s.titulo}</h2>
                {s.cifras.length > 0 && (
                    <div className="grid grid-cols-2 gap-2 md:grid-cols-3">
                        {s.cifras.map((c) => (
                            <div key={c.etiqueta} className={cn('rounded-lg border p-3', c.alerta && 'border-red-600/40 bg-red-600/5')}>
                                <div className="text-muted-foreground text-xs">{c.etiqueta}</div>
                                <div className={cn('font-semibold', c.alerta && 'text-red-700 dark:text-red-400')}>{c.valor}</div>
                            </div>
                        ))}
                    </div>
                )}
                {s.tablas
                    .filter((t) => t.filas.length > 0 || t.vacio !== '')
                    .map((t) => (
                        <div key={t.titulo}>
                            <h3 className="mb-1.5 text-sm font-semibold">{t.titulo}</h3>
                            {t.filas.length === 0 ? (
                                <p className="text-muted-foreground text-sm italic">{t.vacio}</p>
                            ) : (
                                <div className="overflow-x-auto rounded-lg border">
                                    <table className="w-full text-sm">
                                        <thead className="bg-muted/50 text-muted-foreground text-left">
                                            <tr>
                                                {t.columnas.map((c) => (
                                                    <th key={c} className="px-3 py-2 font-medium whitespace-nowrap">
                                                        {c}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {t.filas.map((f, i) => (
                                                <tr key={i}>
                                                    {f.map((v, j) => (
                                                        <td key={j} className="px-3 py-1.5">
                                                            {v}
                                                        </td>
                                                    ))}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    {t.omitidas > 0 && (
                                        <p className="text-muted-foreground border-t px-3 py-1.5 text-xs">
                                            … y {t.omitidas} más en la exportación a Excel.
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                    ))}
                {s.notas.map((n) => (
                    <p key={n} className="text-muted-foreground text-xs italic">
                        {n}
                    </p>
                ))}
            </CardContent>
        </Card>
    );
}
