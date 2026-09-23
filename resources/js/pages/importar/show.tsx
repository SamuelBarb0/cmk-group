import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CheckCircle2, Copy, Loader2, RotateCcw, Save, Sparkles, Trash2, Upload, XCircle } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { ESTADOS } from './estados';

interface Campo {
    label: string;
    tipo: 'texto' | 'fecha' | 'numero' | 'entero' | 'documento' | 'booleano' | 'lista' | 'empleado' | 'placa';
    requerido?: boolean;
    opciones?: string[];
    ayuda?: string;
}

interface Mapeo {
    fila_encabezado: number;
    fila_inicio: number;
    columnas: Record<string, number | null>;
    nombre_completo: { columna: number | null; orden: 'nombres_apellidos' | 'apellidos_nombres' };
    valores: Record<string, Record<string, string>>;
    fijos: Record<string, string>;
    advertencias?: string[];
}

interface Fila {
    fila: number;
    estado: 'valida' | 'error' | 'duplicada';
    datos: Record<string, unknown>;
    errores: Record<string, string>;
    /** Lo que se muestra en vez del dato (el nombre del trabajador, no su id). */
    etiquetas?: Record<string, string | null>;
}

interface Props {
    needsClient: boolean;
    importacion: {
        id: number;
        destino: string;
        nombre_original: string;
        hojas: { nombre: string; filas: number }[];
        hoja: string | null;
        estado: string;
        mapeo: Mapeo | null;
        mapeo_editado: boolean;
        error: string | null;
        resultado: { creados?: number[]; borrados?: number; validas?: number; errores?: number; duplicadas?: number } | null;
        aplicado_at: string | null;
    };
    destino: { nombre: string; nombre_completo: boolean; padre: string | null; campos: Record<string, Campo> };
    padre: string | null;
    modulo: string;
    encabezados: { indice: number; letra: string; titulo: string }[];
    distintos: Record<string, string[]>;
    previa: { resumen: { total: number; validas: number; errores: number; duplicadas: number }; filas: Fila[] } | null;
}

// Igual que Aplicador::normalizar(): minúsculas, sin tildes y sin espacios de más.
// String(): un valor numérico del Excel («4») puede llegar como número.
const normalizar = (v: string | number) => String(v).trim().replace(/\s+/g, ' ').toLowerCase().normalize('NFD').replace(/\p{M}/gu, '');

/** Editor keyed por importación: Inertia reutiliza el componente entre páginas. */
export default function ImportarShow(props: Props) {
    return <Revision key={`${props.importacion.id}-${props.importacion.estado}`} {...props} />;
}

function Revision({ needsClient, importacion: imp, destino, padre, modulo, encabezados, distintos, previa }: Props) {
    const errors = usePage<SharedData & { errors: Record<string, string> }>().props.errors;
    const [mapeo, setMapeo] = useState<Mapeo | null>(imp.mapeo);
    const [sucio, setSucio] = useState(false);
    const [ocupado, setOcupado] = useState(false);
    const [hojaElegida, setHojaElegida] = useState(imp.hoja ?? imp.hojas[0]?.nombre ?? '');

    // Mientras la IA trabaja (job en cola), se consulta cada 4 s.
    useEffect(() => {
        if (imp.estado !== 'mapeando') return;
        const t = setInterval(() => router.reload({ only: ['importacion', 'previa', 'encabezados', 'distintos'] }), 4000);
        return () => clearInterval(t);
    }, [imp.estado]);

    const editable = imp.estado === 'listo';
    const campos = Object.entries(destino.campos);

    const cambiar = (fn: (m: Mapeo) => Mapeo) => {
        setMapeo((m) => (m ? fn(m) : m));
        setSucio(true);
    };

    const guardarMapeo = () => {
        if (!mapeo) return;
        setOcupado(true);
        router.put(
            `/importar/${imp.id}/mapeo`,
            {
                fila_inicio: mapeo.fila_inicio,
                columnas: mapeo.columnas,
                nombre_completo: mapeo.nombre_completo,
                // Lista y no mapa: un valor del Excel con puntos rompería la validación.
                valores: Object.entries(mapeo.valores).flatMap(([campo, m]) =>
                    Object.entries(m).map(([original, destinoValor]) => ({ campo, original, destino: destinoValor })),
                ),
                fijos: mapeo.fijos,
            } as never,
            { preserveScroll: true, onSuccess: () => setSucio(false), onFinish: () => setOcupado(false) },
        );
    };

    const accion = (url: string, confirmar?: string) => {
        if (confirmar && !confirm(confirmar)) return;
        setOcupado(true);
        router.post(url, {}, { preserveScroll: true, onFinish: () => setOcupado(false) });
    };

    // Campos de lista o sí/no con columna: los valores distintos que hay que traducir.
    const porTraducir = useMemo(() => {
        if (!mapeo) return [];
        return campos
            .filter(([k, c]) => (c.tipo === 'lista' || c.tipo === 'booleano') && mapeo.columnas[k] !== null && mapeo.columnas[k] !== undefined)
            .map(([k, c]) => ({ campo: k, def: c, valores: distintos[String(mapeo.columnas[k])] ?? [] }))
            .filter((x) => x.valores.length > 0);
    }, [mapeo, campos, distintos]);

    const r = previa?.resumen;
    const titulo = (k: string) => destino.campos[k]?.label ?? k;
    const columnasVisibles = campos.filter(([k]) => mapeo?.columnas[k] !== null || mapeo?.fijos[k]).map(([k]) => k);
    if (mapeo?.nombre_completo.columna !== null && mapeo?.nombre_completo.columna !== undefined) {
        columnasVisibles.unshift(...['nombres', 'apellidos'].filter((k) => !columnasVisibles.includes(k)));
    }
    // Todos los campos que se van a llenar: la vista previa es donde el consultor
    // revisa ANTES de importar, y un campo oculto es un campo sin revisar.
    const vista = columnasVisibles;

    return (
        <ModuloPage
            titulo={imp.nombre_original}
            descripcion={`${destino.nombre}${padre ? ` · ${destino.padre}: ${padre}` : ''}${imp.hoja ? ` · hoja «${imp.hoja}»` : ''}`}
            needsClient={needsClient}
            accion={
                <Button variant="outline" asChild className="gap-2">
                    <Link href="/importar">
                        <ArrowLeft className="size-4" /> Importaciones
                    </Link>
                </Button>
            }
        >
            {Object.values(errors ?? {})[0] && <InputError message={Object.values(errors)[0]} />}

            {/* Elegir hoja */}
            {(imp.estado === 'subido' || imp.estado === 'error') && (
                <Card>
                    <CardContent className="space-y-4 p-5">
                        {imp.estado === 'error' && (
                            <div className="flex items-start gap-2 rounded-md border border-red-600/30 bg-red-600/10 p-3 text-sm text-red-700 dark:text-red-400">
                                <XCircle className="mt-0.5 size-4 shrink-0" /> La IA no pudo proponer el mapeo: {imp.error}
                            </div>
                        )}
                        <div className="space-y-1.5">
                            <Label htmlFor="hoja">¿Qué hoja tiene los datos?</Label>
                            <select
                                id="hoja"
                                value={hojaElegida}
                                onChange={(e) => setHojaElegida(e.target.value)}
                                className="border-input bg-background h-9 w-full max-w-lg rounded-md border px-2 text-sm"
                            >
                                {imp.hojas.map((h) => (
                                    <option key={h.nombre} value={h.nombre}>
                                        {h.nombre} ({h.filas} filas)
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button
                            className="gap-2"
                            disabled={ocupado}
                            onClick={() => {
                                setOcupado(true);
                                router.post(`/importar/${imp.id}/mapear`, { hoja: hojaElegida }, { onFinish: () => setOcupado(false) });
                            }}
                        >
                            <Sparkles className="size-4" /> Analizar con IA
                        </Button>
                    </CardContent>
                </Card>
            )}

            {imp.estado === 'mapeando' && (
                <Card>
                    <CardContent className="flex items-center gap-3 p-6 text-sm">
                        <Loader2 className="text-primary size-5 animate-spin" />
                        La IA está leyendo los encabezados y una muestra de «{imp.hoja}» para proponer el mapeo. Suele tardar menos de un minuto.
                    </CardContent>
                </Card>
            )}

            {/* Resultado aplicado */}
            {(imp.estado === 'aplicado' || imp.estado === 'deshecho') && (
                <Card>
                    <CardContent className="flex flex-wrap items-center justify-between gap-3 p-5">
                        <div className={cn('flex items-center gap-2 text-sm', ESTADOS[imp.estado][1])}>
                            {imp.estado === 'aplicado' ? <CheckCircle2 className="size-5" /> : <RotateCcw className="size-5" />}
                            {imp.estado === 'aplicado'
                                ? `${imp.resultado?.creados?.length ?? 0} registro(s) importado(s) el ${imp.aplicado_at}.`
                                : `Importación deshecha: ${imp.resultado?.borrados ?? 0} registro(s) eliminado(s).`}
                        </div>
                        <div className="flex gap-2">
                            <Button variant="outline" asChild>
                                <Link href={`/${modulo}`}>Ver en el módulo</Link>
                            </Button>
                            {imp.estado === 'aplicado' && (
                                <Button
                                    variant="outline"
                                    className="text-destructive gap-2"
                                    disabled={ocupado}
                                    onClick={() =>
                                        accion(
                                            `/importar/${imp.id}/deshacer`,
                                            `¿Deshacer? Se eliminan los ${imp.resultado?.creados?.length ?? 0} registros que creó esta importación.`,
                                        )
                                    }
                                >
                                    <RotateCcw className="size-4" /> Deshacer importación
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Mapeo */}
            {mapeo && editable && (
                <>
                    {!!mapeo.advertencias?.length && !imp.mapeo_editado && (
                        <Card className="border-amber-500/40">
                            <CardContent className="space-y-1.5 p-4">
                                <div className="flex items-center gap-2 text-sm font-medium text-amber-700 dark:text-amber-400">
                                    <AlertTriangle className="size-4" /> La IA pide revisar
                                </div>
                                <ul className="text-muted-foreground list-disc space-y-0.5 pl-6 text-sm">
                                    {mapeo.advertencias.map((a, i) => (
                                        <li key={i}>{a}</li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    )}

                    <Card className="overflow-hidden">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                            <h2 className="font-semibold">Qué columna va a cada campo</h2>
                            <div className="flex items-center gap-2 text-sm">
                                <Label htmlFor="fila_inicio" className="text-muted-foreground">
                                    Los datos empiezan en la fila
                                </Label>
                                <Input
                                    id="fila_inicio"
                                    type="number"
                                    min={1}
                                    value={mapeo.fila_inicio + 1}
                                    onChange={(e) => cambiar((m) => ({ ...m, fila_inicio: Math.max(0, Number(e.target.value) - 1) }))}
                                    className="h-8 w-20"
                                />
                            </div>
                        </div>
                        <div className="divide-y">
                            {destino.nombre_completo && (
                                <div className="grid items-center gap-2 px-4 py-2 sm:grid-cols-[14rem_1fr_14rem]">
                                    <div className="text-sm">
                                        Nombre completo <span className="text-muted-foreground text-xs">(si viene en una sola columna)</span>
                                    </div>
                                    <select
                                        aria-label="Columna de nombre completo"
                                        value={mapeo.nombre_completo.columna ?? ''}
                                        onChange={(e) =>
                                            cambiar((m) => ({
                                                ...m,
                                                nombre_completo: {
                                                    ...m.nombre_completo,
                                                    columna: e.target.value === '' ? null : Number(e.target.value),
                                                },
                                            }))
                                        }
                                        className="border-input bg-background h-8 rounded-md border px-2 text-sm"
                                    >
                                        <option value="">No viene junto</option>
                                        {encabezados.map((e) => (
                                            <option key={e.indice} value={e.indice}>
                                                {e.letra} · {e.titulo || '(sin título)'}
                                            </option>
                                        ))}
                                    </select>
                                    <select
                                        aria-label="Orden del nombre completo"
                                        value={mapeo.nombre_completo.orden}
                                        disabled={mapeo.nombre_completo.columna === null}
                                        onChange={(e) =>
                                            cambiar((m) => ({
                                                ...m,
                                                nombre_completo: { ...m.nombre_completo, orden: e.target.value as Mapeo['nombre_completo']['orden'] },
                                            }))
                                        }
                                        className="border-input bg-background h-8 rounded-md border px-2 text-sm"
                                    >
                                        <option value="nombres_apellidos">Nombres y luego apellidos</option>
                                        <option value="apellidos_nombres">Apellidos y luego nombres</option>
                                    </select>
                                </div>
                            )}
                            {campos.map(([k, c]) => {
                                const col = mapeo.columnas[k] ?? null;
                                const desdeNombre = (k === 'nombres' || k === 'apellidos') && mapeo.nombre_completo.columna !== null;
                                return (
                                    <div key={k} className="grid items-center gap-2 px-4 py-2 sm:grid-cols-[14rem_1fr_14rem]">
                                        <div className="text-sm">
                                            {c.label}
                                            {c.requerido && <span className="text-destructive"> *</span>}
                                            {c.opciones && (
                                                <div className="text-muted-foreground text-[11px]">
                                                    {c.opciones.length <= 7 ? c.opciones.join(' · ') : `${c.opciones.length} opciones`}
                                                </div>
                                            )}
                                        </div>
                                        {desdeNombre ? (
                                            <div className="text-muted-foreground text-xs">Sale de la columna de nombre completo</div>
                                        ) : (
                                            <select
                                                aria-label={`Columna de ${c.label}`}
                                                value={col ?? ''}
                                                onChange={(e) =>
                                                    cambiar((m) => ({
                                                        ...m,
                                                        columnas: { ...m.columnas, [k]: e.target.value === '' ? null : Number(e.target.value) },
                                                    }))
                                                }
                                                className={cn(
                                                    'border-input bg-background h-8 rounded-md border px-2 text-sm',
                                                    c.requerido && col === null && !mapeo.fijos[k] && 'border-destructive',
                                                )}
                                            >
                                                <option value="">— Sin columna —</option>
                                                {encabezados.map((e) => (
                                                    <option key={e.indice} value={e.indice}>
                                                        {e.letra} · {e.titulo || '(sin título)'}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                        {desdeNombre ? (
                                            <span />
                                        ) : c.opciones ? (
                                            <select
                                                aria-label={`Valor fijo de ${c.label}`}
                                                value={mapeo.fijos[k] ?? ''}
                                                onChange={(e) => cambiar((m) => ({ ...m, fijos: { ...m.fijos, [k]: e.target.value } }))}
                                                className="border-input bg-background h-8 rounded-md border px-2 text-xs"
                                            >
                                                <option value="">{col === null ? 'Sin valor fijo' : 'Si la celda está vacía: nada'}</option>
                                                {c.opciones.map((o) => (
                                                    <option key={o} value={o}>
                                                        {col === null ? 'Fijo: ' : 'Si está vacía: '}
                                                        {o}
                                                    </option>
                                                ))}
                                            </select>
                                        ) : (
                                            <Input
                                                aria-label={`Valor fijo de ${c.label}`}
                                                value={mapeo.fijos[k] ?? ''}
                                                placeholder={col === null ? 'Valor fijo (opcional)' : 'Si la celda está vacía'}
                                                onChange={(e) => cambiar((m) => ({ ...m, fijos: { ...m.fijos, [k]: e.target.value } }))}
                                                className="h-8 text-xs"
                                            />
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </Card>

                    {porTraducir.length > 0 && (
                        <Card className="overflow-hidden">
                            <div className="border-b px-4 py-3">
                                <h2 className="font-semibold">Traducción de valores</h2>
                                <p className="text-muted-foreground text-xs">Cómo viene escrito en el Excel y a qué opción del módulo corresponde.</p>
                            </div>
                            <div className="grid gap-4 p-4 md:grid-cols-2">
                                {porTraducir.map(({ campo, def, valores }) => (
                                    <div key={campo} className="space-y-1.5">
                                        <div className="text-sm font-medium">{def.label}</div>
                                        {valores.map((v) => {
                                            const actual = mapeo.valores[campo]?.[normalizar(v)] ?? '';
                                            const opciones = def.tipo === 'booleano' ? ['si', 'no'] : (def.opciones ?? []);
                                            return (
                                                <div key={v} className="flex items-center gap-2 text-sm">
                                                    <span className="min-w-0 flex-1 truncate" title={v}>
                                                        «{v}»
                                                    </span>
                                                    <span className="text-muted-foreground">→</span>
                                                    <select
                                                        aria-label={`Traducción de ${v} en ${def.label}`}
                                                        value={actual}
                                                        onChange={(e) =>
                                                            cambiar((m) => ({
                                                                ...m,
                                                                valores: {
                                                                    ...m.valores,
                                                                    [campo]: { ...(m.valores[campo] ?? {}), [normalizar(v)]: e.target.value },
                                                                },
                                                            }))
                                                        }
                                                        className="border-input bg-background h-8 w-40 rounded-md border px-2 text-xs"
                                                    >
                                                        <option value="">Tal cual / sin traducir</option>
                                                        {opciones.map((o) => (
                                                            <option key={o} value={o}>
                                                                {o === 'si' ? 'Sí' : o === 'no' ? 'No' : o}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </div>
                                            );
                                        })}
                                    </div>
                                ))}
                            </div>
                        </Card>
                    )}

                    {sucio && (
                        <div className="bg-background/95 sticky bottom-4 z-10 flex items-center justify-end gap-3 rounded-lg border p-3 shadow-lg">
                            <span className="text-muted-foreground text-sm">Cambiaste el mapeo: guarda para recalcular la vista previa</span>
                            <Button onClick={guardarMapeo} disabled={ocupado} className="gap-2">
                                <Save className="size-4" /> Guardar y recalcular
                            </Button>
                        </div>
                    )}
                </>
            )}

            {/* Vista previa */}
            {previa && r && (
                <>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <StatCard label="Filas que se importan" value={r.validas} icon={CheckCircle2} />
                        <StatCard label="Con errores (no se importan)" value={r.errores} icon={XCircle} alerta={r.errores > 0} />
                        <StatCard label="Duplicadas (no se importan)" value={r.duplicadas} icon={Copy} />
                    </div>

                    <Card className="overflow-hidden">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                            <h2 className="font-semibold">Vista previa</h2>
                            <span className="text-muted-foreground text-xs">
                                {previa.filas.length < r.total
                                    ? `Todas las filas con problema y una muestra de ${previa.filas.filter((f) => f.estado === 'valida').length} válidas`
                                    : 'Todas las filas'}
                            </span>
                        </div>
                        <div className="max-h-[32rem] overflow-auto">
                            <table className="w-full min-w-[48rem] text-sm">
                                <thead className="bg-muted/50 text-muted-foreground sticky top-0 text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Fila</th>
                                        {vista.map((k) => (
                                            <th key={k} className="px-3 py-2 font-medium">
                                                {titulo(k)}
                                            </th>
                                        ))}
                                        <th className="px-3 py-2 font-medium">Resultado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {previa.filas.map((f) => (
                                        <tr key={f.fila} className={cn('border-t align-top', f.estado !== 'valida' && 'bg-red-600/5')}>
                                            <td className="text-muted-foreground px-3 py-2 tabular-nums">{f.fila}</td>
                                            {vista.map((k) => (
                                                <td
                                                    key={k}
                                                    className={cn('max-w-56 truncate px-3 py-2', f.errores[k] && 'text-red-700 dark:text-red-400')}
                                                >
                                                    {f.etiquetas?.[k]
                                                        ? f.etiquetas[k]
                                                        : f.datos[k] === null || f.datos[k] === undefined
                                                          ? '—'
                                                          : typeof f.datos[k] === 'boolean'
                                                            ? f.datos[k]
                                                                ? 'Sí'
                                                                : 'No'
                                                            : String(f.datos[k])}
                                                </td>
                                            ))}
                                            <td className="px-3 py-2 text-xs">
                                                {f.estado === 'valida' ? (
                                                    <span className="text-green-700 dark:text-green-400">Se importa</span>
                                                ) : (
                                                    <ul className="space-y-0.5 text-red-700 dark:text-red-400">
                                                        {Object.entries(f.errores).map(([k, e]) => (
                                                            <li key={k}>
                                                                <span className="font-medium">{titulo(k)}:</span> {e}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>

                    {editable && (
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex gap-2">
                                <Button
                                    variant="ghost"
                                    className="gap-2"
                                    disabled={ocupado}
                                    onClick={() => {
                                        if (!confirm('¿Pedirle a la IA un mapeo nuevo? Se pierden los cambios que hiciste.')) return;
                                        setOcupado(true);
                                        router.post(`/importar/${imp.id}/mapear`, { hoja: imp.hoja }, { onFinish: () => setOcupado(false) });
                                    }}
                                >
                                    <Sparkles className="size-4" /> Volver a analizar
                                </Button>
                                <Button
                                    variant="ghost"
                                    className="text-destructive gap-2"
                                    disabled={ocupado}
                                    onClick={() => confirm('¿Descartar esta importación?') && router.delete(`/importar/${imp.id}`)}
                                >
                                    <Trash2 className="size-4" /> Descartar
                                </Button>
                            </div>
                            <Button
                                className="gap-2"
                                disabled={ocupado || sucio || r.validas === 0}
                                title={sucio ? 'Guarda el mapeo primero' : undefined}
                                onClick={() =>
                                    accion(
                                        `/importar/${imp.id}/aplicar`,
                                        `¿Importar ${r.validas} fila(s) a ${destino.nombre}? Las filas con error o duplicadas no se importan.`,
                                    )
                                }
                            >
                                <Upload className="size-4" /> Importar {r.validas} fila(s)
                            </Button>
                        </div>
                    )}
                </>
            )}
        </ModuloPage>
    );
}
