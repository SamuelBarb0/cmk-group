import { EstadoBadge, NOMBRE_SISTEMA, selectCls, SISTEMAS, type EstadoDocumento, type Sistema } from '@/components/control-documental/etiquetas';
import { ModuloPage } from '@/components/modulo-page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useMemo, useState } from 'react';

interface ResumenNorma {
    clave: Sistema;
    nombre: string;
    edicion: string;
    total: number;
    cubiertos: number;
    en_proceso: number;
    cobertura: number;
}

interface Requisito {
    clave_comun: string;
    titulo: string;
    etapa: string;
    evidencia: 'documento' | 'registro' | 'ambos';
    modulo: string;
    nota: string | null;
    referencias: Partial<Record<Sistema, { referencia: string; cubierto: boolean }>>;
    documentos: { id: number; codigo: string; titulo: string; estado: EstadoDocumento }[];
}

interface Props {
    needsClient: boolean;
    normas: ResumenNorma[];
    requisitos: Requisito[];
    etapas: Record<string, string>;
}

const EVIDENCIA: Record<Requisito['evidencia'], string> = {
    documento: 'DOC',
    registro: 'REG',
    ambos: 'DOC + REG',
};

/**
 * Cobertura de requisitos por norma: cuáles tienen ya un documento vigente que
 * los evidencie. Un requisito común (la política integrada, la auditoría) se
 * cubre una vez y suma en cada norma.
 */
export default function ControlDocumentalRequisitos({ needsClient, normas, requisitos, etapas }: Props) {
    const [norma, setNorma] = useState<Sistema | ''>('');
    const [buscar, setBuscar] = useState('');
    const [soloPendientes, setSoloPendientes] = useState(false);

    const filtrados = useMemo(() => {
        const q = buscar.trim().toLowerCase();
        return requisitos.filter((r) => {
            if (norma && !r.referencias[norma]) return false;
            if (q && !r.titulo.toLowerCase().includes(q) && !Object.values(r.referencias).some((x) => x?.referencia.toLowerCase().includes(q)))
                return false;
            if (soloPendientes) {
                const refs = norma ? [r.referencias[norma]] : Object.values(r.referencias);
                if (refs.every((x) => x?.cubierto)) return false;
            }
            return true;
        });
    }, [requisitos, norma, buscar, soloPendientes]);

    const porEtapa = useMemo(() => {
        const g: Record<string, Requisito[]> = {};
        filtrados.forEach((r) => (g[r.etapa] ??= []).push(r));
        return g;
    }, [filtrados]);

    return (
        <ModuloPage
            titulo="Requisitos por norma"
            descripcion="Qué requisitos del SIG ya tienen un documento vigente que los evidencie"
            needsClient={needsClient}
            accion={
                <Button asChild variant="ghost" className="gap-2">
                    <Link href="/control-documental">
                        <ArrowLeft className="size-4" /> Listado maestro
                    </Link>
                </Button>
            }
            filtros={
                needsClient ? undefined : (
                    <div className="flex flex-wrap items-center gap-3">
                        <select value={norma} onChange={(e) => setNorma(e.target.value as Sistema | '')} className={selectCls} aria-label="Norma">
                            <option value="">Todas las normas</option>
                            {SISTEMAS.map((s) => (
                                <option key={s} value={s}>
                                    {NOMBRE_SISTEMA[s]}
                                </option>
                            ))}
                        </select>
                        <Input
                            value={buscar}
                            onChange={(e) => setBuscar(e.target.value)}
                            placeholder="Buscar requisito o numeral…"
                            className="w-64"
                        />
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={soloPendientes} onCheckedChange={(v) => setSoloPendientes(v === true)} />
                            Solo sin cubrir
                        </label>
                    </div>
                )
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                {normas.map((n) => (
                    <button
                        key={n.clave}
                        type="button"
                        onClick={() => setNorma(norma === n.clave ? '' : n.clave)}
                        className={cn('text-left', norma === n.clave && 'ring-primary rounded-xl ring-2')}
                    >
                        <Card className="h-full">
                            <CardContent className="space-y-2 p-4">
                                <div className="flex items-baseline justify-between gap-2">
                                    <span className="font-semibold">{n.nombre}</span>
                                    <span className="text-2xl font-bold tabular-nums">{n.cobertura}%</span>
                                </div>
                                <div className="text-muted-foreground text-xs">{n.edicion}</div>
                                <div className="bg-muted flex h-2 overflow-hidden rounded-full" aria-hidden>
                                    <div className="bg-emerald-500" style={{ width: `${(n.cubiertos / Math.max(1, n.total)) * 100}%` }} />
                                    <div className="bg-amber-400" style={{ width: `${(n.en_proceso / Math.max(1, n.total)) * 100}%` }} />
                                </div>
                                <div className="text-muted-foreground text-xs tabular-nums">
                                    {n.cubiertos} cubiertos · {n.en_proceso} en elaboración · {n.total} requisitos
                                </div>
                            </CardContent>
                        </Card>
                    </button>
                ))}
            </div>

            {Object.keys(etapas)
                .filter((e) => porEtapa[e])
                .map((e) => (
                    <Card key={e}>
                        <CardContent className="p-0">
                            <div className="bg-muted/40 px-4 py-2.5 text-sm font-semibold">
                                Etapa {e}. {etapas[e]}
                            </div>
                            <table className="w-full text-sm">
                                <tbody>
                                    {porEtapa[e].map((r) => (
                                        <tr key={r.clave_comun} className="border-t align-top">
                                            <td className="w-full px-4 py-3">
                                                <div className="flex flex-wrap items-baseline gap-2">
                                                    <span className="font-medium">{r.titulo}</span>
                                                    <span className="text-muted-foreground font-mono text-[11px]">
                                                        {EVIDENCIA[r.evidencia]} · {r.modulo}
                                                    </span>
                                                </div>
                                                <div className="mt-1.5 flex flex-wrap gap-1.5">
                                                    {SISTEMAS.filter((s) => r.referencias[s]).map((s) => (
                                                        <span
                                                            key={s}
                                                            className={cn(
                                                                'rounded border px-1.5 py-px text-[11px]',
                                                                r.referencias[s]!.cubierto
                                                                    ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                                                    : 'text-muted-foreground',
                                                            )}
                                                        >
                                                            {NOMBRE_SISTEMA[s]} {r.referencias[s]!.referencia}
                                                        </span>
                                                    ))}
                                                </div>
                                                {r.nota && <p className="text-muted-foreground mt-1.5 text-xs">{r.nota}</p>}
                                            </td>
                                            <td className="min-w-72 px-4 py-3">
                                                {r.documentos.length === 0 ? (
                                                    <span className="text-muted-foreground text-xs">Sin documento vinculado</span>
                                                ) : (
                                                    <ul className="space-y-1">
                                                        {r.documentos.map((d) => (
                                                            <li key={d.id} className="flex items-center justify-between gap-2">
                                                                <Link
                                                                    href={`/control-documental/${d.id}`}
                                                                    className="truncate text-xs hover:underline"
                                                                    title={d.titulo}
                                                                >
                                                                    <span className="font-medium tabular-nums">{d.codigo}</span> {d.titulo}
                                                                </Link>
                                                                <EstadoBadge estado={d.estado} />
                                                            </li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                ))}

            {filtrados.length === 0 && !needsClient && (
                <p className="text-muted-foreground text-center text-sm">Ningún requisito coincide con los filtros.</p>
            )}
        </ModuloPage>
    );
}
