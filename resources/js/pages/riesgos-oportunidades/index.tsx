import { SistemaChips, SistemasPicker, selectCls, textareaCls, type Sistema } from '@/components/control-documental/etiquetas';
import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowDownToLine, CircleAlert, FileOutput, ListChecks, Pencil, Plus, Sparkles, Trash2, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Tipo = 'riesgo' | 'oportunidad';
type Nivel = 'bajo' | 'medio' | 'alto' | 'critico';
type Estado = 'abierto' | 'en_tratamiento' | 'cerrado';

interface Fila {
    id: number;
    tipo: Tipo;
    process_id: number | null;
    context_issue_id: number | null;
    descripcion: string;
    causa: string | null;
    efecto: string | null;
    probabilidad: number;
    impacto: number;
    valor: number;
    nivel: Nivel;
    tratamiento: string;
    acciones: string | null;
    responsable: string | null;
    fecha_limite: string | null;
    estado: Estado;
    eficacia: string | null;
    evaluado_at: string | null;
    sistemas: Sistema[];
    sin_tratar: boolean;
    process: { id: number; sigla: string; nombre: string } | null;
    context_issue: { id: number; dofa: string; descripcion: string } | null;
    acpm_action: { id: number; codigo: string; estado: string } | null;
}

interface Props {
    needsClient: boolean;
    filas: Fila[];
    procesos: { id: number; sigla: string; nombre: string }[];
    dofaPendientes: number;
    stats: { riesgos: number; oportunidades: number; altos: number; sin_tratar: number; sin_eficacia: number };
    documento: { titulo: string; id: number | null; codigo: string | null; estado: string | null } | null;
    tratamientos: Record<Tipo, string[]>;
}

const NIVEL: Record<Nivel, { label: string; cls: string }> = {
    bajo: { label: 'Bajo', cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' },
    medio: { label: 'Medio', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' },
    alto: { label: 'Alto', cls: 'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-300' },
    critico: { label: 'Crítico', cls: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' },
};

const nivelDe = (v: number): Nivel => (v <= 4 ? 'bajo' : v <= 9 ? 'medio' : v <= 16 ? 'alto' : 'critico');

const ESCALA_P = ['Rara vez', 'Improbable', 'Posible', 'Probable', 'Casi seguro'];
const ESCALA_I = ['Mínimo', 'Menor', 'Moderado', 'Mayor', 'Grave'];

const ESTADO: Record<Estado, string> = { abierto: 'Abierto', en_tratamiento: 'En tratamiento', cerrado: 'Cerrado' };

export default function RiesgosOportunidades({ needsClient, filas, procesos, dofaPendientes, stats, documento, tratamientos }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const errores = usePage<SharedData>().props.errors as Record<string, string | undefined>;
    const [tipo, setTipo] = useState<Tipo>('riesgo');
    const [editar, setEditar] = useState<Fila | 'nuevo' | null>(null);
    const [accion, setAccion] = useState<Fila | null>(null);

    const lista = filas.filter((f) => f.tipo === tipo).sort((a, b) => b.valor - a.valor);
    const riesgos = filas.filter((f) => f.tipo === 'riesgo');

    return (
        <ModuloPage
            titulo="Riesgos y oportunidades"
            descripcion="De los procesos y del contexto: valoración, tratamiento y eficacia (ISO 45001 6.1.1, ISO 9001 6.1, ISO 14001 6.1.1)"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <div className="flex flex-wrap gap-2">
                        {dofaPendientes > 0 && (
                            <Button
                                variant="outline"
                                className="gap-2"
                                onClick={() => router.post('/riesgos-oportunidades/desde-dofa', {}, { preserveScroll: true })}
                            >
                                <ArrowDownToLine className="size-4" /> Traer de la DOFA ({dofaPendientes})
                            </Button>
                        )}
                        <Button className="gap-2" onClick={() => setEditar('nuevo')}>
                            <Plus className="size-4" /> {tipo === 'riesgo' ? 'Nuevo riesgo' : 'Nueva oportunidad'}
                        </Button>
                    </div>
                ) : undefined
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Riesgos" value={stats.riesgos} icon={TriangleAlert} />
                <StatCard label="Oportunidades" value={stats.oportunidades} icon={Sparkles} />
                <StatCard label="Altos o críticos" value={stats.altos} icon={CircleAlert} alerta={stats.altos > 0} />
                <StatCard label="Altos sin tratar" value={stats.sin_tratar} icon={ListChecks} alerta={stats.sin_tratar > 0} />
            </div>

            <div className="grid gap-4 lg:grid-cols-[auto_1fr]">
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Mapa de calor de riesgos</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Mapa riesgos={riesgos} />
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Documento y seguimiento</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        {documento ? (
                            <div className="flex items-center justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="truncate">{documento.titulo}</div>
                                    <div className="text-muted-foreground text-xs">
                                        {documento.codigo ? (
                                            <Link href={`/control-documental/${documento.id}`} className="underline underline-offset-2">
                                                {documento.codigo} · {documento.estado?.replace('_', ' ')}
                                            </Link>
                                        ) : (
                                            'Aún no está en el control documental'
                                        )}
                                    </div>
                                </div>
                                {canManage && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="shrink-0 gap-1.5"
                                        onClick={() => router.post('/riesgos-oportunidades/enviar', {}, { preserveScroll: true })}
                                    >
                                        <FileOutput className="size-3.5" /> Enviar como borrador
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <p className="text-muted-foreground text-xs">El catálogo del SIG no está cargado en esta instalación.</p>
                        )}
                        {(errores.documento || errores.estado || errores.acpm) && (
                            <p className="text-destructive text-xs">{errores.documento ?? errores.estado ?? errores.acpm}</p>
                        )}
                        {stats.sin_eficacia > 0 && (
                            <p className="text-amber-700 dark:text-amber-400">
                                {stats.sin_eficacia} tratamiento(s) cerrado(s) sin evaluar su eficacia. ISO 9001 6.1.2 pide evaluarla.
                            </p>
                        )}
                        <p className="text-muted-foreground text-xs">
                            Nivel = probabilidad × impacto: bajo 1–4 · medio 5–9 · alto 10–16 · crítico 20–25. En una oportunidad el impacto es el
                            beneficio si se aprovecha.
                        </p>
                    </CardContent>
                </Card>
            </div>

            <div className="flex gap-1 border-b">
                {(['riesgo', 'oportunidad'] as const).map((t) => (
                    <button
                        key={t}
                        type="button"
                        onClick={() => setTipo(t)}
                        className={cn(
                            '-mb-px border-b-2 px-4 py-2 text-sm',
                            tipo === t ? 'border-primary font-medium' : 'text-muted-foreground border-transparent',
                        )}
                    >
                        {t === 'riesgo' ? `Riesgos (${stats.riesgos})` : `Oportunidades (${stats.oportunidades})`}
                    </button>
                ))}
            </div>

            <Card>
                <CardContent className="p-0">
                    {lista.length === 0 ? (
                        <p className="text-muted-foreground p-8 text-center text-sm">
                            {dofaPendientes > 0
                                ? 'Todavía no hay registros. Trae las cuestiones de la DOFA del contexto o agrégalos uno a uno.'
                                : 'Todavía no hay registros.'}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2.5 font-semibold">Proceso</th>
                                        <th className="px-4 py-2.5 font-semibold">{tipo === 'riesgo' ? 'Riesgo' : 'Oportunidad'}</th>
                                        <th className="px-4 py-2.5 font-semibold">Nivel</th>
                                        <th className="px-4 py-2.5 font-semibold">Tratamiento</th>
                                        <th className="px-4 py-2.5 font-semibold">Seguimiento</th>
                                        {canManage && <th className="px-4 py-2.5" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {lista.map((f) => (
                                        <tr key={f.id} className="border-t align-top">
                                            <td className="px-4 py-2.5 whitespace-nowrap">{f.process ? f.process.sigla : 'General'}</td>
                                            <td className="max-w-md px-4 py-2.5">
                                                <div>{f.descripcion}</div>
                                                {(f.causa || f.efecto) && (
                                                    <div className="text-muted-foreground mt-1 text-xs">
                                                        {[f.causa, f.efecto].filter(Boolean).join(' → ')}
                                                    </div>
                                                )}
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    {f.context_issue && (
                                                        <Badge variant="outline" className="font-normal">
                                                            De la DOFA ({f.context_issue.dofa})
                                                        </Badge>
                                                    )}
                                                    <SistemaChips sistemas={f.sistemas} />
                                                </div>
                                            </td>
                                            <td className="px-4 py-2.5 whitespace-nowrap">
                                                <span className={cn('rounded px-1.5 py-0.5 text-xs font-medium', NIVEL[f.nivel].cls)}>
                                                    {NIVEL[f.nivel].label} · {f.valor}
                                                </span>
                                                <div className="text-muted-foreground mt-1 text-xs tabular-nums">
                                                    P{f.probabilidad} × I{f.impacto}
                                                </div>
                                            </td>
                                            <td className="max-w-xs px-4 py-2.5">
                                                <div className="capitalize">{f.tratamiento}</div>
                                                {f.acciones && <div className="text-muted-foreground mt-0.5 line-clamp-2 text-xs">{f.acciones}</div>}
                                                {f.responsable && <div className="text-muted-foreground text-xs">Responsable: {f.responsable}</div>}
                                                {f.sin_tratar && <div className="text-destructive mt-0.5 text-xs">Sin acción o responsable</div>}
                                            </td>
                                            <td className="px-4 py-2.5 text-xs">
                                                <div>{ESTADO[f.estado]}</div>
                                                {f.acpm_action ? (
                                                    <Link href="/acpm" className="text-muted-foreground underline underline-offset-2">
                                                        {f.acpm_action.codigo} · {f.acpm_action.estado.replace('_', ' ')}
                                                    </Link>
                                                ) : (
                                                    canManage &&
                                                    f.estado !== 'cerrado' && (
                                                        <button
                                                            type="button"
                                                            className="text-primary underline underline-offset-2"
                                                            onClick={() => setAccion(f)}
                                                        >
                                                            Crear acción en ACPM
                                                        </button>
                                                    )
                                                )}
                                                {f.eficacia && (
                                                    <div className="text-muted-foreground mt-0.5">
                                                        Eficacia evaluada{f.evaluado_at ? ` el ${f.evaluado_at}` : ''}
                                                    </div>
                                                )}
                                            </td>
                                            {canManage && (
                                                <td className="px-4 py-2.5">
                                                    <div className="flex justify-end gap-1">
                                                        <Button size="icon" variant="ghost" onClick={() => setEditar(f)} aria-label="Editar">
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            aria-label="Eliminar"
                                                            onClick={() =>
                                                                confirm('¿Eliminar este registro?') &&
                                                                router.delete(`/riesgos-oportunidades/${f.id}`, { preserveScroll: true })
                                                            }
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    </div>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </CardContent>
            </Card>

            {editar && (
                <FilaDialog
                    fila={editar === 'nuevo' ? null : editar}
                    tipoInicial={tipo}
                    procesos={procesos}
                    tratamientos={tratamientos}
                    onClose={() => setEditar(null)}
                />
            )}
            {accion && <AccionDialog fila={accion} onClose={() => setAccion(null)} />}
        </ModuloPage>
    );
}

/** Matriz 5×5: cuántos riesgos caen en cada casilla de probabilidad e impacto. */
function Mapa({ riesgos }: { riesgos: Fila[] }) {
    return (
        <div className="flex gap-2">
            <div className="text-muted-foreground flex rotate-180 items-center text-[10px] [writing-mode:vertical-rl]">Probabilidad</div>
            <div>
                <div className="grid grid-cols-5 gap-1">
                    {[5, 4, 3, 2, 1].flatMap((p) =>
                        [1, 2, 3, 4, 5].map((i) => {
                            const n = riesgos.filter((r) => r.probabilidad === p && r.impacto === i).length;
                            return (
                                <div
                                    key={`${p}-${i}`}
                                    title={`Probabilidad ${p} × impacto ${i} = ${p * i}`}
                                    className={cn(
                                        'flex size-9 items-center justify-center rounded text-xs font-semibold tabular-nums',
                                        NIVEL[nivelDe(p * i)].cls,
                                        n === 0 && 'opacity-40',
                                    )}
                                >
                                    {n || ''}
                                </div>
                            );
                        }),
                    )}
                </div>
                <div className="text-muted-foreground mt-1 text-center text-[10px]">Impacto</div>
            </div>
        </div>
    );
}

type FilaForm = {
    tipo: Tipo;
    process_id: number | '';
    context_issue_id: number | null;
    descripcion: string;
    causa: string;
    efecto: string;
    probabilidad: number;
    impacto: number;
    tratamiento: string;
    acciones: string;
    responsable: string;
    fecha_limite: string;
    estado: Estado;
    eficacia: string;
    sistemas: Sistema[];
};

function FilaDialog({
    fila,
    tipoInicial,
    procesos,
    tratamientos,
    onClose,
}: {
    fila: Fila | null;
    tipoInicial: Tipo;
    procesos: Props['procesos'];
    tratamientos: Props['tratamientos'];
    onClose: () => void;
}) {
    const { data, setData, post, put, processing, errors } = useForm<FilaForm>({
        tipo: fila?.tipo ?? tipoInicial,
        process_id: fila?.process_id ?? '',
        context_issue_id: fila?.context_issue_id ?? null,
        descripcion: fila?.descripcion ?? '',
        causa: fila?.causa ?? '',
        efecto: fila?.efecto ?? '',
        probabilidad: fila?.probabilidad ?? 3,
        impacto: fila?.impacto ?? 3,
        tratamiento: fila?.tratamiento ?? (tipoInicial === 'riesgo' ? 'reducir' : 'aprovechar'),
        acciones: fila?.acciones ?? '',
        responsable: fila?.responsable ?? '',
        fecha_limite: fila?.fecha_limite ?? '',
        estado: fila?.estado ?? 'abierto',
        eficacia: fila?.eficacia ?? '',
        sistemas: fila?.sistemas ?? ['iso45001', 'iso9001', 'iso14001'],
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (fila) put(`/riesgos-oportunidades/${fila.id}`, opts);
        else post('/riesgos-oportunidades', opts);
    };

    const valor = data.probabilidad * data.impacto;

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{fila ? 'Editar' : data.tipo === 'riesgo' ? 'Nuevo riesgo' : 'Nueva oportunidad'}</DialogTitle>
                    <DialogDescription>Qué puede pasar, por qué, qué tan probable y grave es, y qué se hace al respecto.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="tipo">Tipo</Label>
                            <select
                                id="tipo"
                                value={data.tipo}
                                onChange={(e) => {
                                    const t = e.target.value as Tipo;
                                    setData((prev) => ({ ...prev, tipo: t, tratamiento: t === 'riesgo' ? 'reducir' : 'aprovechar' }));
                                }}
                                className={selectCls}
                            >
                                <option value="riesgo">Riesgo</option>
                                <option value="oportunidad">Oportunidad</option>
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="proceso">Proceso</Label>
                            <select
                                id="proceso"
                                value={data.process_id}
                                onChange={(e) => setData('process_id', e.target.value ? Number(e.target.value) : '')}
                                className={selectCls}
                            >
                                <option value="">General (toda la empresa)</option>
                                {procesos.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.sigla} · {p.nombre}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.process_id} />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="descripcion">Descripción</Label>
                        <textarea
                            id="descripcion"
                            rows={2}
                            value={data.descripcion}
                            onChange={(e) => setData('descripcion', e.target.value)}
                            className={textareaCls}
                        />
                        <InputError message={errors.descripcion} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="causa">Causa</Label>
                            <textarea
                                id="causa"
                                rows={2}
                                value={data.causa}
                                onChange={(e) => setData('causa', e.target.value)}
                                className={textareaCls}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="efecto">Efecto</Label>
                            <textarea
                                id="efecto"
                                rows={2}
                                value={data.efecto}
                                onChange={(e) => setData('efecto', e.target.value)}
                                className={textareaCls}
                            />
                        </div>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="probabilidad">Probabilidad</Label>
                            <select
                                id="probabilidad"
                                value={data.probabilidad}
                                onChange={(e) => setData('probabilidad', Number(e.target.value))}
                                className={selectCls}
                            >
                                {ESCALA_P.map((l, i) => (
                                    <option key={l} value={i + 1}>
                                        {i + 1} · {l}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="impacto">{data.tipo === 'riesgo' ? 'Impacto' : 'Beneficio'}</Label>
                            <select
                                id="impacto"
                                value={data.impacto}
                                onChange={(e) => setData('impacto', Number(e.target.value))}
                                className={selectCls}
                            >
                                {ESCALA_I.map((l, i) => (
                                    <option key={l} value={i + 1}>
                                        {i + 1} · {l}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label>Nivel</Label>
                            <div className={cn('flex h-9 items-center rounded-md px-3 text-sm font-medium', NIVEL[nivelDe(valor)].cls)}>
                                {NIVEL[nivelDe(valor)].label} · {valor}
                            </div>
                        </div>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="tratamiento">Tratamiento</Label>
                            <select
                                id="tratamiento"
                                value={data.tratamiento}
                                onChange={(e) => setData('tratamiento', e.target.value)}
                                className={selectCls}
                            >
                                {tratamientos[data.tipo].map((t) => (
                                    <option key={t} value={t}>
                                        {t.charAt(0).toUpperCase() + t.slice(1)}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.tratamiento} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="responsable">Responsable</Label>
                            <Input id="responsable" value={data.responsable} onChange={(e) => setData('responsable', e.target.value)} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="fecha_limite">Fecha límite</Label>
                            <Input
                                id="fecha_limite"
                                type="date"
                                value={data.fecha_limite}
                                onChange={(e) => setData('fecha_limite', e.target.value)}
                            />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="acciones">Acciones</Label>
                        <textarea
                            id="acciones"
                            rows={2}
                            value={data.acciones}
                            onChange={(e) => setData('acciones', e.target.value)}
                            className={textareaCls}
                        />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="estado">Estado</Label>
                            <select
                                id="estado"
                                value={data.estado}
                                onChange={(e) => setData('estado', e.target.value as Estado)}
                                className={selectCls}
                            >
                                {Object.entries(ESTADO).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="eficacia">Eficacia de lo hecho</Label>
                            <textarea
                                id="eficacia"
                                rows={2}
                                value={data.eficacia}
                                placeholder="¿Las acciones redujeron el riesgo o aprovecharon la oportunidad? Con qué evidencia."
                                onChange={(e) => setData('eficacia', e.target.value)}
                                className={textareaCls}
                            />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label>Normas</Label>
                        <SistemasPicker value={data.sistemas} onChange={(v) => setData('sistemas', v)} />
                        <InputError message={errors.sistemas} />
                    </div>
                    <DialogFooter className="gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function AccionDialog({ fila, onClose }: { fila: Fila; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({
        accion: fila.acciones ?? '',
        responsable: fila.responsable ?? '',
        fecha_limite: fila.fecha_limite ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/riesgos-oportunidades/${fila.id}/accion`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Acción en ACPM</DialogTitle>
                    <DialogDescription>
                        {fila.tipo === 'riesgo' ? 'Acción preventiva' : 'Acción de mejora'} para: {fila.descripcion}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-2">
                        <Label htmlFor="accion">Acción</Label>
                        <textarea
                            id="accion"
                            rows={3}
                            value={data.accion}
                            onChange={(e) => setData('accion', e.target.value)}
                            className={textareaCls}
                        />
                        <InputError message={errors.accion} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="acc-resp">Responsable</Label>
                            <Input id="acc-resp" value={data.responsable} onChange={(e) => setData('responsable', e.target.value)} />
                            <InputError message={errors.responsable} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="acc-fecha">Fecha límite</Label>
                            <Input id="acc-fecha" type="date" value={data.fecha_limite} onChange={(e) => setData('fecha_limite', e.target.value)} />
                            <InputError message={errors.fecha_limite} />
                        </div>
                    </div>
                    <DialogFooter className="gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Crear en ACPM
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
