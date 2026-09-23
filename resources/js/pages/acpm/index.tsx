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
import { CircleAlert, ClipboardList, ListChecks, Pencil, Plus, ShieldQuestion, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Tipo = 'correctiva' | 'preventiva' | 'mejora';
type Estado = 'abierta' | 'en_proceso' | 'cerrada';

interface Accion {
    id: number;
    codigo: string;
    tipo: Tipo;
    origen_tipo: string;
    origen_id: number | null;
    hallazgo: string;
    causa: string | null;
    accion: string;
    responsable: string;
    fecha_deteccion: string;
    fecha_limite: string;
    fecha_cierre: string | null;
    estado: Estado;
    eficaz: boolean | null;
    verificacion: string | null;
    fecha_verificacion: string | null;
    observaciones: string | null;
    /** Calculados por el modelo. */
    vencida: boolean;
    dias_restantes: number | null;
}

interface Props {
    acciones: Accion[];
    stats: { total: number; pendientes: number; vencidas: number; sin_verificar: number };
    catalogos: { tipos: Tipo[]; estados: Estado[]; origenes: string[] };
    needsClient: boolean;
}

const ETIQUETA_ESTADO: Record<Estado, string> = {
    abierta: 'Abierta',
    en_proceso: 'En proceso',
    cerrada: 'Cerrada',
};

const CLS_ESTADO: Record<Estado, string> = {
    abierta: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    en_proceso: 'bg-blue-500/15 text-blue-700 dark:text-blue-400',
    cerrada: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
};

const ETIQUETA_ORIGEN: Record<string, string> = {
    manual: 'Registro manual',
    accidente: 'Accidente de trabajo',
    siniestro_vial: 'Siniestro vial (PESV)',
    reporte: 'Reporte de acto o condición',
    auditoria: 'Auditoría',
    inspeccion: 'Inspección',
    iperc: 'Matriz IPERC',
};

const hoy = () => new Date().toISOString().slice(0, 10);

const emptyForm = {
    tipo: 'correctiva' as Tipo,
    origen_tipo: 'manual',
    origen_id: '' as number | string,
    hallazgo: '',
    causa: '',
    accion: '',
    responsable: '',
    fecha_deteccion: hoy(),
    fecha_limite: '',
    estado: 'abierta' as Estado,
    eficaz: null as boolean | null,
    verificacion: '',
    fecha_verificacion: '',
    observaciones: '',
};

export default function AcpmIndex({ acciones, stats, catalogos, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Accion | null>(null);

    const { data, setData, post, put, processing, errors, clearErrors } = useForm({ ...emptyForm });

    function openCreate() {
        setEditing(null);
        clearErrors();
        setData({ ...emptyForm });
        setOpen(true);
    }

    function openEdit(a: Accion) {
        setEditing(a);
        clearErrors();
        setData({
            tipo: a.tipo,
            origen_tipo: a.origen_tipo,
            origen_id: a.origen_id ?? '',
            hallazgo: a.hallazgo,
            causa: a.causa ?? '',
            accion: a.accion,
            responsable: a.responsable,
            fecha_deteccion: a.fecha_deteccion,
            fecha_limite: a.fecha_limite,
            estado: a.estado,
            eficaz: a.eficaz,
            verificacion: a.verificacion ?? '',
            fecha_verificacion: a.fecha_verificacion ?? '',
            observaciones: a.observaciones ?? '',
        });
        setOpen(true);
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) put(route('acpm.update', editing.id), opts);
        else post(route('acpm.store'), opts);
    };

    function eliminar(a: Accion) {
        if (confirm(`¿Eliminar la acción ${a.codigo}?`)) {
            router.delete(route('acpm.destroy', a.id), { preserveScroll: true });
        }
    }

    return (
        <ModuloPage
            titulo="ACPM"
            descripcion="Acciones correctivas, preventivas y de mejora"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button onClick={openCreate} className="gap-2">
                        <Plus className="size-4" /> Nueva acción
                    </Button>
                ) : undefined
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Acciones registradas" value={stats.total} icon={ListChecks} />
                <StatCard label="Pendientes" value={stats.pendientes} icon={ClipboardList} />
                <StatCard label="Vencidas" value={stats.vencidas} icon={CircleAlert} alerta={stats.vencidas > 0} />
                {/* Cerrar no es resolver: esta cifra son las acciones que nadie verificó. */}
                <StatCard label="Cerradas sin verificar" value={stats.sin_verificar} icon={ShieldQuestion} alerta={stats.sin_verificar > 0} />
            </div>

            <Card>
                <CardContent className="p-0">
                    {acciones.length === 0 ? (
                        <p className="text-muted-foreground p-8 text-center text-sm">Todavía no hay acciones registradas.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2.5 font-semibold">Código</th>
                                        <th className="px-4 py-2.5 font-semibold">Tipo</th>
                                        <th className="px-4 py-2.5 font-semibold">Hallazgo</th>
                                        <th className="px-4 py-2.5 font-semibold">Responsable</th>
                                        <th className="px-4 py-2.5 font-semibold">Límite</th>
                                        <th className="px-4 py-2.5 font-semibold">Estado</th>
                                        {canManage && <th className="px-4 py-2.5" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {acciones.map((a) => (
                                        <tr key={a.id} className="border-t">
                                            <td className="px-4 py-2.5 font-medium tabular-nums">{a.codigo}</td>
                                            <td className="px-4 py-2.5 capitalize">{a.tipo}</td>
                                            <td className="max-w-md px-4 py-2.5">
                                                <div className="truncate">{a.hallazgo}</div>
                                                <div className="text-muted-foreground text-xs">{ETIQUETA_ORIGEN[a.origen_tipo] ?? a.origen_tipo}</div>
                                            </td>
                                            <td className="px-4 py-2.5">{a.responsable}</td>
                                            <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">
                                                {a.fecha_limite}
                                                {/* El plazo solo se muestra mientras signifique algo: una
                                                    acción cerrada ya no tiene cuenta atrás. */}
                                                {a.vencida ? (
                                                    <span className="text-destructive block text-xs">
                                                        vencida hace {Math.abs(a.dias_restantes ?? 0)} d
                                                    </span>
                                                ) : a.dias_restantes !== null && a.dias_restantes <= 7 ? (
                                                    <span className="block text-xs text-amber-600 dark:text-amber-500">
                                                        quedan {a.dias_restantes} d
                                                    </span>
                                                ) : null}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <Badge className={cn('font-normal', CLS_ESTADO[a.estado])}>{ETIQUETA_ESTADO[a.estado]}</Badge>
                                                {a.estado === 'cerrada' && a.eficaz === null && (
                                                    <span className="text-muted-foreground block text-xs">sin verificar</span>
                                                )}
                                                {a.eficaz === false && <span className="text-destructive block text-xs">no fue eficaz</span>}
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
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Acción ${editing.codigo}` : 'Nueva acción'}</DialogTitle>
                        <DialogDescription>El código se asigna solo, consecutivo por empresa.</DialogDescription>
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
                                <Label htmlFor="origen_tipo">Origen</Label>
                                <select
                                    id="origen_tipo"
                                    value={data.origen_tipo}
                                    onChange={(e) => setData('origen_tipo', e.target.value)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    {catalogos.origenes.map((o) => (
                                        <option key={o} value={o}>
                                            {ETIQUETA_ORIGEN[o] ?? o}
                                        </option>
                                    ))}
                                </select>
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
                            <Label htmlFor="hallazgo">Hallazgo</Label>
                            <textarea
                                id="hallazgo"
                                value={data.hallazgo}
                                onChange={(e) => setData('hallazgo', e.target.value)}
                                rows={2}
                                placeholder="Qué se encontró."
                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                            />
                            <InputError message={errors.hallazgo} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="causa">Causa</Label>
                            <textarea
                                id="causa"
                                value={data.causa}
                                onChange={(e) => setData('causa', e.target.value)}
                                rows={2}
                                placeholder="Por qué pasó. Sin causa, la acción ataca el síntoma."
                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="accion">Acción</Label>
                            <textarea
                                id="accion"
                                value={data.accion}
                                onChange={(e) => setData('accion', e.target.value)}
                                rows={2}
                                placeholder="Qué se va a hacer."
                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                            />
                            <InputError message={errors.accion} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="responsable">Responsable</Label>
                                <Input id="responsable" value={data.responsable} onChange={(e) => setData('responsable', e.target.value)} />
                                <InputError message={errors.responsable} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fecha_deteccion">Detectado el</Label>
                                <Input
                                    id="fecha_deteccion"
                                    type="date"
                                    value={data.fecha_deteccion}
                                    onChange={(e) => setData('fecha_deteccion', e.target.value)}
                                />
                                <InputError message={errors.fecha_deteccion} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fecha_limite">Fecha límite</Label>
                                <Input
                                    id="fecha_limite"
                                    type="date"
                                    value={data.fecha_limite}
                                    onChange={(e) => setData('fecha_limite', e.target.value)}
                                />
                                <InputError message={errors.fecha_limite} />
                            </div>
                        </div>

                        {/* Verificación de eficacia: solo tiene sentido en una acción cerrada. */}
                        {data.estado === 'cerrada' && (
                            <div className="bg-muted/40 grid gap-3 rounded-md p-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="eficaz">¿La acción fue eficaz?</Label>
                                    <select
                                        id="eficaz"
                                        value={data.eficaz === null ? '' : data.eficaz ? 'si' : 'no'}
                                        onChange={(e) => setData('eficaz', e.target.value === '' ? null : e.target.value === 'si')}
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm sm:max-w-xs"
                                    >
                                        <option value="">Sin verificar</option>
                                        <option value="si">Sí, el problema no volvió</option>
                                        <option value="no">No, hay que replantearla</option>
                                    </select>
                                </div>
                                {data.eficaz !== null && (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="verificacion">Cómo se verificó</Label>
                                            <textarea
                                                id="verificacion"
                                                value={data.verificacion}
                                                onChange={(e) => setData('verificacion', e.target.value)}
                                                rows={2}
                                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                                            />
                                        </div>
                                        <div className="grid gap-2 sm:max-w-xs">
                                            <Label htmlFor="fecha_verificacion">Fecha de verificación</Label>
                                            <Input
                                                id="fecha_verificacion"
                                                type="date"
                                                value={data.fecha_verificacion}
                                                onChange={(e) => setData('fecha_verificacion', e.target.value)}
                                            />
                                        </div>
                                    </>
                                )}
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="observaciones">Observaciones</Label>
                            <textarea
                                id="observaciones"
                                value={data.observaciones}
                                onChange={(e) => setData('observaciones', e.target.value)}
                                rows={2}
                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                            />
                        </div>

                        <DialogFooter className="gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {editing ? 'Guardar' : 'Registrar acción'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ModuloPage>
    );
}
