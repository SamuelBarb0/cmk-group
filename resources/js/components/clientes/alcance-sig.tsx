import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useMemo, useState } from 'react';

export interface ModuloSig {
    modulo: string;
    nombre: string;
    documentos: { id: number; tipo: string; nombre: string; codigo: string | null; condicional: boolean }[];
}

export interface ReglasAlcance {
    pantallas: Record<string, number[]>;
    partes: Record<string, Record<string, number[]>>;
}

interface Props {
    catalogo: ModuloSig[];
    reglas: ReglasAlcance;
    herramientas: Record<string, string>;
    modulosCatalogo: Record<string, string>;
    submodulosCatalogo: Record<string, Record<string, string>>;
    documentos: number[];
    herramientasElegidas: string[];
    onDocumentos: (ids: number[]) => void;
    onHerramientas: (claves: string[]) => void;
}

/**
 * Lo que CMK contrata para un cliente, con el mapa documental del SIG: los
 * módulos M01–M20 y, dentro de cada uno, los documentos con su código del
 * listado maestro. Debajo, en vivo, las pantallas que la empresa verá (se
 * deducen de los documentos; el servidor hace la misma cuenta al guardar).
 */
export function AlcanceSig(props: Props) {
    const { catalogo, reglas, documentos, onDocumentos } = props;
    const [abiertos, setAbiertos] = useState<string[]>([]);
    const elegidos = useMemo(() => new Set(documentos), [documentos]);
    const total = catalogo.reduce((s, m) => s + m.documentos.length, 0);

    const alternarModulo = (m: ModuloSig) => {
        const ids = m.documentos.map((d) => d.id);
        const todos = ids.every((id) => elegidos.has(id));
        onDocumentos(todos ? documentos.filter((id) => !ids.includes(id)) : [...new Set([...documentos, ...ids])]);
    };
    const alternarDoc = (id: number) => onDocumentos(elegidos.has(id) ? documentos.filter((x) => x !== id) : [...documentos, id]);
    const alternarAbierto = (m: string) => setAbiertos((a) => (a.includes(m) ? a.filter((x) => x !== m) : [...a, m]));

    // La misma deducción que AlcanceDocumental::deducir, para la vista previa.
    const pantallas = Object.entries(reglas.pantallas)
        .filter(([, ids]) => ids.some((id) => elegidos.has(id)))
        .map(([clave]) => {
            const partes = reglas.partes[clave];
            if (!partes) return { clave, partes: null as string[] | null };
            const con = Object.entries(partes)
                .filter(([, ids]) => ids.some((id) => elegidos.has(id)))
                .map(([p]) => p);
            return { clave, partes: con.length > 0 && con.length < Object.keys(partes).length ? con : null };
        });

    return (
        <div className="space-y-4">
            <div className="space-y-2">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <div className="text-sm font-medium">Mapa documental del SIG</div>
                        <div className="text-muted-foreground text-xs tabular-nums">
                            {documentos.length} de {total} documentos
                        </div>
                    </div>
                    <div className="flex gap-3 text-xs font-medium">
                        <button
                            type="button"
                            className="text-primary hover:underline"
                            onClick={() => onDocumentos(catalogo.flatMap((m) => m.documentos.map((d) => d.id)))}
                        >
                            Todo el mapa
                        </button>
                        <button type="button" className="text-primary hover:underline" onClick={() => onDocumentos([])}>
                            Nada
                        </button>
                    </div>
                </div>
                <div className="divide-y rounded-lg border">
                    {catalogo.map((m) => {
                        const n = m.documentos.filter((d) => elegidos.has(d.id)).length;
                        const abierto = abiertos.includes(m.modulo);
                        const estado = n === 0 ? false : n === m.documentos.length ? true : 'indeterminate';
                        return (
                            <div key={m.modulo}>
                                <div className="flex items-center gap-2 px-3 py-2 text-sm">
                                    <Checkbox checked={estado} onCheckedChange={() => alternarModulo(m)} aria-label={`${m.modulo} ${m.nombre}`} />
                                    <button
                                        type="button"
                                        onClick={() => alternarAbierto(m.modulo)}
                                        className="flex flex-1 items-center gap-2 text-left"
                                        aria-expanded={abierto}
                                    >
                                        <span className="text-muted-foreground font-mono text-xs">{m.modulo}</span>
                                        <span className={cn('flex-1', n === 0 && 'text-muted-foreground')}>{m.nombre}</span>
                                        <span className="text-muted-foreground text-xs tabular-nums">
                                            {n} de {m.documentos.length}
                                        </span>
                                        {abierto ? (
                                            <ChevronDown className="text-muted-foreground size-4" />
                                        ) : (
                                            <ChevronRight className="text-muted-foreground size-4" />
                                        )}
                                    </button>
                                </div>
                                {abierto && (
                                    <div className="bg-muted/30 space-y-1 border-t px-3 py-2 pl-9">
                                        {m.documentos.map((d) => (
                                            <label key={d.id} className="flex cursor-pointer items-start gap-2 text-xs">
                                                <Checkbox
                                                    checked={elegidos.has(d.id)}
                                                    onCheckedChange={() => alternarDoc(d.id)}
                                                    className="mt-0.5"
                                                    aria-label={d.nombre}
                                                />
                                                <span className="text-muted-foreground w-24 shrink-0 font-mono">{d.codigo ?? d.tipo}</span>
                                                <span className="flex-1">
                                                    {d.nombre}
                                                    {d.condicional && (
                                                        <Badge variant="outline" className="ml-1.5 px-1 py-0 text-[10px] font-normal">
                                                            si aplica
                                                        </Badge>
                                                    )}
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            <div className="space-y-1.5">
                <div className="text-sm font-medium">Herramientas de la plataforma</div>
                <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                    {Object.entries(props.herramientas).map(([clave, nombre]) => (
                        <label key={clave} className="flex cursor-pointer items-center gap-2 text-sm">
                            <Checkbox
                                checked={props.herramientasElegidas.includes(clave)}
                                onCheckedChange={() =>
                                    props.onHerramientas(
                                        props.herramientasElegidas.includes(clave)
                                            ? props.herramientasElegidas.filter((h) => h !== clave)
                                            : [...props.herramientasElegidas, clave],
                                    )
                                }
                            />
                            <span>{nombre}</span>
                        </label>
                    ))}
                </div>
            </div>

            <div className="bg-muted/40 space-y-1.5 rounded-lg p-3">
                <div className="text-xs font-medium">
                    Pantallas que verá la empresa ({pantallas.length + props.herramientasElegidas.length}), además de Organización y Empleados
                </div>
                {pantallas.length === 0 && props.herramientasElegidas.length === 0 ? (
                    <p className="text-muted-foreground text-xs">Ninguna todavía: elige documentos del mapa.</p>
                ) : (
                    <div className="flex flex-wrap gap-1">
                        {pantallas.map(({ clave, partes }) => (
                            <Badge key={clave} variant="secondary" className="font-normal" title={props.modulosCatalogo[clave]}>
                                {props.modulosCatalogo[clave]?.split(' (')[0] ?? clave}
                                {partes && (
                                    <span className="text-muted-foreground ml-1">
                                        · {partes.map((p) => props.submodulosCatalogo[clave]?.[p] ?? p).join(', ')}
                                    </span>
                                )}
                            </Badge>
                        ))}
                        {props.herramientasElegidas.map((h) => (
                            <Badge key={h} variant="outline" className="font-normal">
                                {props.herramientas[h]?.split(' (')[0] ?? h}
                            </Badge>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
