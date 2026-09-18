import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { CircleAlert, ClipboardCheck, Pencil, Plus, ShieldCheck, Trash2, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Tipo = 'interna' | 'externa' | 'contratistas' | 'terceros';
type Estado = 'programada' | 'en_curso' | 'cerrada';
type TipoHallazgo = 'no_conformidad_mayor' | 'no_conformidad_menor' | 'observacion' | 'oportunidad' | 'fortaleza';

interface Hallazgo {
    /** Exigida por useForm de Inertia para los objetos anidados. */
    [key: string]: string | number | null | undefined;
    tipo: TipoHallazgo;
    proceso: string | null;
    requisito: string | null;
    descripcion: string;
    evidencia: string | null;
    acpm_action_id: number | null;
}

interface Auditoria {
    id: number;
    codigo: string;
    tipo: Tipo;
    objetivo: string;
    alcance: string | null;
    criterios: string | null;
    procesos: string | null;
    fecha_programada: string;
    fecha_inicio: string | null;
    fecha_fin: string | null;
    auditor_lider: string | null;
    equipo_auditor: string | null;
    estado: Estado;
    conclusiones: string | null;
    observaciones: string | null;
    /** Calculado por el modelo: solo cuenta no conformidades. */
    no_conformidades: number;
    findings: Hallazgo[];
}

interface AccionRow {
    id: number;
    codigo: string;
    accion: string;
}

interface Props {
    auditorias: Auditoria[];
    acciones: AccionRow[];
    stats: { total: number; programadas: number; no_conformidades: number; sin_accion: number };
    catalogos: { tipos: Tipo[]; estados: Estado[]; tipos_hallazgo: TipoHallazgo[] };
    needsClient: boolean;
}

const ETIQUETA_ESTADO: Record<Estado, string> = {
    programada: 'Programada',
    en_curso: 'En curso',
    cerrada: 'Cerrada',
};

const CLS_ESTADO: Record<Estado, string> = {
    programada: 'bg-muted text-muted-foreground',
    en_curso: 'bg-blue-500/15 text-blue-700 dark:text-blue-400',
    cerrada: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
};

const ETIQUETA_HALLAZGO: Record<TipoHallazgo, string> = {
    no_conformidad_mayor: 'No conformidad mayor',
    no_conformidad_menor: 'No conformidad menor',
    observacion: 'Observación',
    oportunidad: 'Oportunidad de mejora',
    fortaleza: 'Fortaleza',
};

const CLS_HALLAZGO: Record<TipoHallazgo, string> = {
    no_conformidad_mayor: 'bg-destructive/15 text-destructive',
    no_conformidad_menor: 'bg-orange-500/15 text-orange-700 dark:text-orange-400',
    observacion: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    oportunidad: 'bg-blue-500/15 text-blue-700 dark:text-blue-400',
    fortaleza: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
};

/** Las que son incumplimiento y exigen acción correctiva. */
const NO_CONFORMIDADES: TipoHallazgo[] = ['no_conformidad_mayor', 'no_conformidad_menor'];

const hoy = () => new Date().toISOString().slice(0, 10);

const hallazgoVacio: Hallazgo = {
    tipo: 'no_conformidad_menor',
    proceso: '',
    requisito: '',
    descripcion: '',
    evidencia: '',
    acpm_action_id: null,
};

const emptyForm = {
    tipo: 'interna' as Tipo,
    objetivo: '',
    alcance: '',
    criterios: '',
    procesos: '',
    fecha_programada: hoy(),
    fecha_inicio: '',
    fecha_fin: '',
    auditor_lider: '',
    equipo_auditor: '',
    estado: 'programada' as Estado,
    conclusiones: '',
    observaciones: '',
    findings: [] as Hallazgo[],
};

export default function AuditoriaIndex({ auditorias, acciones, stats, catalogos, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Auditoria | null>(null);

    const { data, setData, post, put, processing, errors, clearErrors } = useForm({ ...emptyForm });

    function openCreate() {
        setEditing(null);
        clearErrors();
        setData({ ...emptyForm });
        setOpen(true);
    }

    function openEdit(a: Auditoria) {
        setEditing(a);
        clearErrors();
        setData({
            tipo: a.tipo,
            objetivo: a.objetivo,
            alcance: a.alcance ?? '',
            criterios: a.criterios ?? '',
            procesos: a.procesos ?? '',
            fecha_programada: a.fecha_programada,
            fecha_inicio: a.fecha_inicio ?? '',
            fecha_fin: a.fecha_fin ?? '',
            auditor_lider: a.auditor_lider ?? '',
            equipo_auditor: a.equipo_auditor ?? '',
            estado: a.estado,
            conclusiones: a.conclusiones ?? '',
            observaciones: a.observaciones ?? '',
            findings: a.findings.map((f) => ({ ...f })),
        });
        setOpen(true);
    }

    function setHallazgo(i: number, cambio: Partial<Hallazgo>) {
        setData(
            'findings',
            data.findings.map((f, j) => (i === j ? { ...f, ...cambio } : f)),
        );
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) put(route('auditoria.update', editing.id), opts);
        else post(route('auditoria.store'), opts);
    };

    function eliminar(a: Auditoria) {
        if (confirm(`¿Eliminar la auditoría ${a.codigo}?`)) {
            router.delete(route('auditoria.destroy', a.id), { preserveScroll: true });
        }
    }

    return (
        <ModuloPage
            titulo="Auditoría"
            descripcion="Programa de auditorías y sus hallazgos"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button onClick={openCreate} className="gap-2">
                        <Plus className="size-4" /> Nueva auditoría
                    </Button>
                ) : undefined
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Auditorías" value={stats.total} icon={ShieldCheck} />
                <StatCard label="Programadas" value={stats.programadas} icon={ClipboardCheck} />
                <StatCard label="No conformidades" value={stats.no_conformidades} icon={TriangleAlert} />
                {/* El hueco que un auditor externo encuentra primero. */}
                <StatCard
                    label="Sin acción correctiva"
                    value={stats.sin_accion}
                    icon={CircleAlert}
                    alerta={stats.sin_accion > 0}
                />
            </div>

            <Card>
                <CardContent className="p-0">
                    {auditorias.length === 0 ? (
                        <p className="text-muted-foreground p-8 text-center text-sm">
                            Todavía no hay auditorías programadas.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2.5 font-semibold">Código</th>
                                        <th className="px-4 py-2.5 font-semibold">Tipo</th>
                                        <th className="px-4 py-2.5 font-semibold">Objetivo</th>
                                        <th className="px-4 py-2.5 font-semibold">Programada</th>
                                        <th className="px-4 py-2.5 font-semibold">Hallazgos</th>
                                        <th className="px-4 py-2.5 font-semibold">Estado</th>
                                        {canManage && <th className="px-4 py-2.5" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {auditorias.map((a) => (
                                        <tr key={a.id} className="border-t">
                                            <td className="px-4 py-2.5 font-medium tabular-nums">{a.codigo}</td>
                                            <td className="px-4 py-2.5 capitalize">{a.tipo}</td>
                                            <td className="max-w-md px-4 py-2.5">
                                                <div className="truncate">{a.objetivo}</div>
                                                {a.auditor_lider && (
                                                    <div className="text-muted-foreground text-xs">{a.auditor_lider}</div>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5 tabular-nums whitespace-nowrap">{a.fecha_programada}</td>
                                            <td className="px-4 py-2.5">
                                                {a.findings.length}
                                                {a.no_conformidades > 0 && (
                                                    <span className="text-destructive block text-xs">
                                                        {a.no_conformidades} no conformidad(es)
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <Badge className={cn('font-normal', CLS_ESTADO[a.estado])}>
                                                    {ETIQUETA_ESTADO[a.estado]}
                                                </Badge>
                                            </td>
                                            {canManage && (
                                                <td className="px-4 py-2.5">
                                                    <div className="flex justify-end gap-1">
                                                        <Button size="icon" variant="ghost" onClick={() => openEdit(a)} aria-label="Editar">
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button size="icon" variant="ghost" onClick={() => eliminar(a)} aria-label="Eliminar">
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

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Auditoría ${editing.codigo}` : 'Nueva auditoría'}</DialogTitle>
                        <DialogDescription>
                            La lista de chequeo y las actas de apertura y cierre se diligencian en Formatos.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="tipo">Tipo</Label>
                                <select
                                    id="tipo"
                                    value={data.tipo}
                                    onChange={(e) => setData('tipo', e.target.value as Tipo)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm capitalize"
                                >
                                    {catalogos.tipos.map((t) => (
                                        <option key={t} value={t}>
                                            {t}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fecha_programada">Programada para</Label>
                                <Input
                                    id="fecha_programada"
                                    type="date"
                                    value={data.fecha_programada}
                                    onChange={(e) => setData('fecha_programada', e.target.value)}
                                />
                                <InputError message={errors.fecha_programada} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="estado">Estado</Label>
                                <select
                                    id="estado"
                                    value={data.estado}
                                    onChange={(e) => setData('estado', e.target.value as Estado)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    {catalogos.estados.map((e) => (
                                        <option key={e} value={e}>
                                            {ETIQUETA_ESTADO[e]}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="objetivo">Objetivo</Label>
                            <Input id="objetivo" value={data.objetivo} onChange={(e) => setData('objetivo', e.target.value)} />
                            <InputError message={errors.objetivo} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="alcance">Alcance</Label>
                                <textarea
                                    id="alcance"
                                    value={data.alcance}
                                    onChange={(e) => setData('alcance', e.target.value)}
                                    rows={2}
                                    className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="criterios">Criterios</Label>
                                <textarea
                                    id="criterios"
                                    value={data.criterios}
                                    onChange={(e) => setData('criterios', e.target.value)}
                                    rows={2}
                                    placeholder="Normas contra las que se audita."
                                    className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                                />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-4">
                            <div className="grid gap-2">
                                <Label htmlFor="fecha_inicio">Inicio</Label>
                                <Input
                                    id="fecha_inicio"
                                    type="date"
                                    value={data.fecha_inicio}
                                    onChange={(e) => setData('fecha_inicio', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fecha_fin">Fin</Label>
                                <Input
                                    id="fecha_fin"
                                    type="date"
                                    value={data.fecha_fin}
                                    onChange={(e) => setData('fecha_fin', e.target.value)}
                                />
                                <InputError message={errors.fecha_fin} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="auditor_lider">Auditor líder</Label>
                                <Input
                                    id="auditor_lider"
                                    value={data.auditor_lider}
                                    onChange={(e) => setData('auditor_lider', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="procesos">Procesos auditados</Label>
                                <Input id="procesos" value={data.procesos} onChange={(e) => setData('procesos', e.target.value)} />
                            </div>
                        </div>

                        {/* ------------------------------------------------ hallazgos */}
                        <div className="rounded-md border">
                            <div className="flex items-center justify-between border-b px-4 py-2.5">
                                <span className="text-sm font-medium">Hallazgos ({data.findings.length})</span>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setData('findings', [...data.findings, { ...hallazgoVacio }])}
                                >
                                    <Plus className="size-4" /> Agregar
                                </Button>
                            </div>

                            {data.findings.length === 0 ? (
                                <p className="text-muted-foreground px-4 py-4 text-xs">
                                    Registra también las fortalezas: un informe que solo lista fallos hace que el auditado deje de
                                    colaborar en la siguiente.
                                </p>
                            ) : (
                                <div className="divide-y">
                                    {data.findings.map((f, i) => (
                                        <div key={i} className="space-y-3 px-4 py-3">
                                            <div className="grid gap-3 sm:grid-cols-[1fr_1fr_1fr_auto]">
                                                <select
                                                    value={f.tipo}
                                                    onChange={(e) => setHallazgo(i, { tipo: e.target.value as TipoHallazgo })}
                                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                                >
                                                    {catalogos.tipos_hallazgo.map((t) => (
                                                        <option key={t} value={t}>
                                                            {ETIQUETA_HALLAZGO[t]}
                                                        </option>
                                                    ))}
                                                </select>
                                                <Input
                                                    value={f.proceso ?? ''}
                                                    onChange={(e) => setHallazgo(i, { proceso: e.target.value })}
                                                    placeholder="Proceso"
                                                    className="h-9"
                                                />
                                                <Input
                                                    value={f.requisito ?? ''}
                                                    onChange={(e) => setHallazgo(i, { requisito: e.target.value })}
                                                    placeholder="Requisito o cláusula"
                                                    className="h-9"
                                                />
                                                <Button
                                                    type="button"
                                                    size="icon"
                                                    variant="ghost"
                                                    onClick={() => setData('findings', data.findings.filter((_, j) => j !== i))}
                                                    aria-label="Quitar hallazgo"
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </div>
                                            <textarea
                                                value={f.descripcion}
                                                onChange={(e) => setHallazgo(i, { descripcion: e.target.value })}
                                                rows={2}
                                                placeholder="Descripción del hallazgo."
                                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                                            />

                                            {/* La acción solo se pide donde tiene sentido: una fortaleza
                                                no necesita acción correctiva. */}
                                            {NO_CONFORMIDADES.includes(f.tipo) && (
                                                <div className="grid gap-1.5">
                                                    <Label className="text-xs">Acción correctiva (ACPM)</Label>
                                                    <select
                                                        value={f.acpm_action_id ?? ''}
                                                        onChange={(e) =>
                                                            setHallazgo(i, {
                                                                acpm_action_id: e.target.value ? Number(e.target.value) : null,
                                                            })
                                                        }
                                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                                    >
                                                        <option value="">Sin acción todavía</option>
                                                        {acciones.map((ac) => (
                                                            <option key={ac.id} value={ac.id}>
                                                                {ac.codigo} — {ac.accion.slice(0, 60)}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    {!f.acpm_action_id && (
                                                        <p className="text-xs text-amber-600 dark:text-amber-500">
                                                            Una no conformidad sin acción correctiva es la observación que levanta
                                                            el siguiente auditor.
                                                        </p>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="conclusiones">Conclusiones</Label>
                            <textarea
                                id="conclusiones"
                                value={data.conclusiones}
                                onChange={(e) => setData('conclusiones', e.target.value)}
                                rows={3}
                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                            />
                        </div>

                        <DialogFooter className="gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {editing ? 'Guardar' : 'Registrar auditoría'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ModuloPage>
    );
}
