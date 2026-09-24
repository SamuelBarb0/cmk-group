import { NOMBRE_SISTEMA, SistemaChips, textareaCls, type Sistema } from '@/components/control-documental/etiquetas';
import { ModuloPage } from '@/components/modulo-page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Plus, Save } from 'lucide-react';
import { useMemo, useState } from 'react';

type Resultado = 'conforme' | 'no_conforme' | 'observacion' | 'no_aplica';
type TipoHallazgo = 'no_conformidad_mayor' | 'no_conformidad_menor' | 'observacion' | 'oportunidad' | 'fortaleza';

interface Fila {
    clave_comun: string;
    titulo: string;
    etapa: string;
    modulo: string;
    referencias: { norma: Sistema; referencia: string }[];
    resultado: Resultado | null;
    evidencia: string | null;
    hallazgos: { id: number; tipo: TipoHallazgo; descripcion: string }[];
}

interface Norma {
    clave: Sistema;
    nombre: string;
    requisitos: number;
    conformes: number;
    no_conformes: number;
    no_aplica: number;
    pendientes: number;
    cumplimiento: number | null;
    hallazgos: number;
}

interface Props {
    auditoria: {
        id: number;
        codigo: string;
        objetivo: string;
        alcance: string | null;
        procesos: string | null;
        fecha_programada: string;
        auditor_lider: string | null;
        sistemas: Sistema[] | null;
    };
    lista: Fila[];
    cumplimiento: Norma[];
    etapas: Record<string, string>;
}

const RESULTADOS: { v: Resultado; label: string; cls: string }[] = [
    { v: 'conforme', label: 'Conforme', cls: 'bg-emerald-600 text-white' },
    { v: 'no_conforme', label: 'No conforme', cls: 'bg-red-600 text-white' },
    { v: 'observacion', label: 'Observación', cls: 'bg-amber-500 text-white' },
    { v: 'no_aplica', label: 'N/A', cls: 'bg-slate-400 text-white' },
];

const ETIQUETA_HALLAZGO: Record<TipoHallazgo, string> = {
    no_conformidad_mayor: 'NC mayor',
    no_conformidad_menor: 'NC menor',
    observacion: 'Observación',
    oportunidad: 'Oportunidad',
    fortaleza: 'Fortaleza',
};

type Respuestas = Record<string, { resultado: Resultado | null; evidencia: string }>;

/**
 * Lista de verificación de la auditoría: sale de la tabla de requisitos,
 * filtrada por las normas del alcance, y se evalúa una vez por requisito
 * común. El informe arriba se desglosa por norma.
 */
export default function AuditoriaShow({ auditoria, lista, cumplimiento, etapas }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const errores = usePage<SharedData>().props.errors as Record<string, string | undefined>;

    const inicial = useMemo<Respuestas>(
        () => Object.fromEntries(lista.map((f) => [f.clave_comun, { resultado: f.resultado, evidencia: f.evidencia ?? '' }])),
        [lista],
    );
    const [respuestas, setRespuestas] = useState<Respuestas>(inicial);
    const [soloPendientes, setSoloPendientes] = useState(false);
    const [nuevoHallazgo, setNuevoHallazgo] = useState<string | null>(null);
    const [guardando, setGuardando] = useState(false);

    const cambiadas = lista.filter(
        (f) =>
            respuestas[f.clave_comun].resultado !== inicial[f.clave_comun].resultado ||
            respuestas[f.clave_comun].evidencia !== inicial[f.clave_comun].evidencia,
    );

    const porEtapa = useMemo(() => {
        const g: Record<string, Fila[]> = {};
        lista.filter((f) => !soloPendientes || !respuestas[f.clave_comun].resultado).forEach((f) => (g[f.etapa] ??= []).push(f));
        return g;
    }, [lista, soloPendientes, respuestas]);

    function set(clave: string, cambio: Partial<Respuestas[string]>) {
        setRespuestas((r) => ({ ...r, [clave]: { ...r[clave], ...cambio } }));
    }

    function guardar() {
        router.put(
            `/auditoria/${auditoria.id}/verificacion`,
            { respuestas: cambiadas.map((f) => ({ clave_comun: f.clave_comun, ...respuestas[f.clave_comun] })) },
            { preserveScroll: true, onStart: () => setGuardando(true), onFinish: () => setGuardando(false) },
        );
    }

    const sinAlcance = !auditoria.sistemas || auditoria.sistemas.length === 0;

    return (
        <ModuloPage
            titulo={`Auditoría ${auditoria.codigo}`}
            descripcion={auditoria.objetivo}
            needsClient={false}
            accion={
                <div className="flex gap-2">
                    <Button asChild variant="ghost" className="gap-2">
                        <Link href="/auditoria">
                            <ArrowLeft className="size-4" /> Auditorías
                        </Link>
                    </Button>
                    {canManage && !sinAlcance && (
                        <Button className="gap-2" disabled={guardando || cambiadas.length === 0} onClick={guardar}>
                            <Save className="size-4" /> Guardar {cambiadas.length > 0 ? `(${cambiadas.length})` : ''}
                        </Button>
                    )}
                </div>
            }
        >
            <div className="text-muted-foreground flex flex-wrap items-center gap-3 text-sm">
                <span>Programada para {auditoria.fecha_programada}</span>
                {auditoria.auditor_lider && <span>· Auditor líder: {auditoria.auditor_lider}</span>}
                {auditoria.sistemas && <SistemaChips sistemas={auditoria.sistemas} />}
            </div>

            {sinAlcance ? (
                <Card>
                    <CardContent className="text-muted-foreground p-8 text-center text-sm">
                        Esta auditoría no tiene normas en su alcance. Edítala en el listado de auditorías y elige las normas para generar su lista de
                        verificación.
                    </CardContent>
                </Card>
            ) : (
                <>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                        {cumplimiento.map((n) => (
                            <Card key={n.clave}>
                                <CardContent className="space-y-2 p-4">
                                    <div className="flex items-baseline justify-between gap-2">
                                        <span className="font-semibold">{n.nombre}</span>
                                        <span className="text-2xl font-bold tabular-nums">
                                            {n.cumplimiento === null ? '—' : `${n.cumplimiento}%`}
                                        </span>
                                    </div>
                                    <div className="bg-muted flex h-2 overflow-hidden rounded-full" aria-hidden>
                                        <div className="bg-emerald-500" style={{ width: `${(n.conformes / Math.max(1, n.requisitos)) * 100}%` }} />
                                        <div className="bg-red-500" style={{ width: `${(n.no_conformes / Math.max(1, n.requisitos)) * 100}%` }} />
                                    </div>
                                    <div className="text-muted-foreground text-xs tabular-nums">
                                        {n.conformes} conformes · {n.no_conformes} no conformes · {n.pendientes} pendientes
                                        {n.hallazgos > 0 && ` · ${n.hallazgos} hallazgos`}
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={soloPendientes} onChange={(e) => setSoloPendientes(e.target.checked)} />
                        Solo los que faltan por evaluar
                    </label>

                    {(errores.respuestas || errores.clave_comun) && (
                        <p className="text-destructive text-sm">{errores.respuestas ?? errores.clave_comun}</p>
                    )}

                    {Object.keys(etapas)
                        .filter((e) => porEtapa[e])
                        .map((e) => (
                            <Card key={e}>
                                <CardContent className="p-0">
                                    <div className="bg-muted/40 px-4 py-2.5 text-sm font-semibold">
                                        Etapa {e}. {etapas[e]}
                                    </div>
                                    <div className="divide-y">
                                        {porEtapa[e].map((f) => {
                                            const r = respuestas[f.clave_comun];
                                            return (
                                                <div key={f.clave_comun} className="space-y-2 px-4 py-3">
                                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                                        <div className="min-w-0 flex-1">
                                                            <div className="text-sm font-medium">{f.titulo}</div>
                                                            <div className="text-muted-foreground text-[11px]">
                                                                {f.referencias
                                                                    .map((ref) => `${NOMBRE_SISTEMA[ref.norma]} ${ref.referencia}`)
                                                                    .join(' · ')}
                                                            </div>
                                                        </div>
                                                        <div className="flex flex-wrap gap-1">
                                                            {RESULTADOS.map((o) => (
                                                                <button
                                                                    key={o.v}
                                                                    type="button"
                                                                    disabled={!canManage}
                                                                    onClick={() =>
                                                                        set(f.clave_comun, { resultado: r.resultado === o.v ? null : o.v })
                                                                    }
                                                                    className={cn(
                                                                        'rounded px-2 py-1 text-xs transition-colors',
                                                                        r.resultado === o.v
                                                                            ? o.cls
                                                                            : 'bg-muted text-muted-foreground hover:bg-muted/70',
                                                                    )}
                                                                >
                                                                    {o.label}
                                                                </button>
                                                            ))}
                                                        </div>
                                                    </div>
                                                    {(r.resultado || r.evidencia) && (
                                                        <Input
                                                            value={r.evidencia}
                                                            disabled={!canManage}
                                                            onChange={(ev) => set(f.clave_comun, { evidencia: ev.target.value })}
                                                            placeholder="Evidencia revisada (documento, registro, entrevista…)"
                                                            className="h-8 text-xs"
                                                        />
                                                    )}
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        {f.hallazgos.map((h) => (
                                                            <span
                                                                key={h.id}
                                                                className="bg-muted rounded px-2 py-0.5 text-[11px]"
                                                                title={h.descripcion}
                                                            >
                                                                {ETIQUETA_HALLAZGO[h.tipo]}: {h.descripcion.slice(0, 60)}
                                                            </span>
                                                        ))}
                                                        {canManage && (r.resultado === 'no_conforme' || r.resultado === 'observacion') && (
                                                            <button
                                                                type="button"
                                                                className="text-primary inline-flex items-center gap-1 text-xs hover:underline"
                                                                onClick={() =>
                                                                    setNuevoHallazgo(nuevoHallazgo === f.clave_comun ? null : f.clave_comun)
                                                                }
                                                            >
                                                                <Plus className="size-3" /> Registrar hallazgo
                                                            </button>
                                                        )}
                                                    </div>
                                                    {nuevoHallazgo === f.clave_comun && (
                                                        <NuevoHallazgo
                                                            auditoriaId={auditoria.id}
                                                            clave={f.clave_comun}
                                                            tipoInicial={r.resultado === 'observacion' ? 'observacion' : 'no_conformidad_menor'}
                                                            evidencia={r.evidencia}
                                                            onClose={() => setNuevoHallazgo(null)}
                                                        />
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                </>
            )}
        </ModuloPage>
    );
}

function NuevoHallazgo({
    auditoriaId,
    clave,
    tipoInicial,
    evidencia,
    onClose,
}: {
    auditoriaId: number;
    clave: string;
    tipoInicial: TipoHallazgo;
    evidencia: string;
    onClose: () => void;
}) {
    const [tipo, setTipo] = useState<TipoHallazgo>(tipoInicial);
    const [descripcion, setDescripcion] = useState('');
    const errores = usePage<SharedData>().props.errors as Record<string, string | undefined>;

    function registrar() {
        router.post(
            `/auditoria/${auditoriaId}/hallazgos`,
            { clave_comun: clave, tipo, descripcion, evidencia },
            { preserveScroll: true, onSuccess: onClose },
        );
    }

    return (
        <div className="bg-muted/40 space-y-2 rounded-md p-3">
            <select
                value={tipo}
                onChange={(e) => setTipo(e.target.value as TipoHallazgo)}
                className="border-input bg-background h-8 rounded-md border px-2 text-xs"
                aria-label="Tipo de hallazgo"
            >
                {(Object.keys(ETIQUETA_HALLAZGO) as TipoHallazgo[]).map((t) => (
                    <option key={t} value={t}>
                        {ETIQUETA_HALLAZGO[t]}
                    </option>
                ))}
            </select>
            <textarea
                rows={2}
                value={descripcion}
                onChange={(e) => setDescripcion(e.target.value)}
                placeholder="Qué se encontró."
                className={textareaCls}
                aria-label="Descripción del hallazgo"
            />
            {errores.descripcion && <p className="text-destructive text-xs">{errores.descripcion}</p>}
            <div className="flex justify-end gap-2">
                <Button size="sm" variant="ghost" onClick={onClose}>
                    Cancelar
                </Button>
                <Button size="sm" onClick={registrar}>
                    Registrar
                </Button>
            </div>
        </div>
    );
}
