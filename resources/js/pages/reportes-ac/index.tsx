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
import { CircleAlert, MessageSquareWarning, Pencil, Percent, Plus, Trash2, Wrench } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Tipo = 'acto' | 'condicion';
type Severidad = 'bajo' | 'medio' | 'alto' | 'critico';
type Estado = 'reportado' | 'intervenido' | 'cerrado';

interface Reporte {
    id: number;
    fecha: string;
    reportado_por: string;
    employee_id: number | null;
    area: string | null;
    lugar: string | null;
    tipo: Tipo;
    descripcion: string;
    clasificacion_peligro: string | null;
    severidad: Severidad;
    accion_inmediata: string | null;
    estado: Estado;
    fecha_intervencion: string | null;
    responsable_intervencion: string | null;
    acpm_action_id: number | null;
    observaciones: string | null;
}

interface EmpleadoRow {
    id: number;
    nombres: string;
    apellidos: string;
    cargo: string | null;
    area: string | null;
}

interface AccionRow {
    id: number;
    codigo: string;
    accion: string;
}

interface Props {
    reportes: Reporte[];
    empleados: EmpleadoRow[];
    acciones: AccionRow[];
    stats: { total: number; pendientes: number; intervenidos: number; porcentaje: number; criticos: number };
    catalogos: { tipos: Tipo[]; severidades: Severidad[]; estados: Estado[]; clasificaciones: string[] };
    needsClient: boolean;
}

const ETIQUETA_TIPO: Record<Tipo, string> = { acto: 'Acto inseguro', condicion: 'Condición insegura' };

const ETIQUETA_ESTADO: Record<Estado, string> = {
    reportado: 'Reportado',
    intervenido: 'Intervenido',
    cerrado: 'Cerrado',
};

const CLS_ESTADO: Record<Estado, string> = {
    reportado: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    intervenido: 'bg-blue-500/15 text-blue-700 dark:text-blue-400',
    cerrado: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
};

const CLS_SEVERIDAD: Record<Severidad, string> = {
    bajo: 'bg-muted text-muted-foreground',
    medio: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    alto: 'bg-orange-500/15 text-orange-700 dark:text-orange-400',
    critico: 'bg-destructive/15 text-destructive',
};

const hoy = () => new Date().toISOString().slice(0, 10);

const emptyForm = {
    fecha: hoy(),
    reportado_por: '',
    employee_id: '' as number | string,
    area: '',
    lugar: '',
    tipo: 'condicion' as Tipo,
    descripcion: '',
    clasificacion_peligro: '',
    severidad: 'medio' as Severidad,
    accion_inmediata: '',
    estado: 'reportado' as Estado,
    fecha_intervencion: '',
    responsable_intervencion: '',
    acpm_action_id: '' as number | string,
    observaciones: '',
};

export default function ReportesAcIndex({ reportes, empleados, acciones, stats, catalogos, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('incidents.manage');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Reporte | null>(null);

    const { data, setData, post, put, processing, errors, clearErrors } = useForm({ ...emptyForm });

    function openCreate() {
        setEditing(null);
        clearErrors();
        setData({ ...emptyForm });
        setOpen(true);
    }

    function openEdit(r: Reporte) {
        setEditing(r);
        clearErrors();
        setData({
            fecha: r.fecha,
            reportado_por: r.reportado_por,
            employee_id: r.employee_id ?? '',
            area: r.area ?? '',
            lugar: r.lugar ?? '',
            tipo: r.tipo,
            descripcion: r.descripcion,
            clasificacion_peligro: r.clasificacion_peligro ?? '',
            severidad: r.severidad,
            accion_inmediata: r.accion_inmediata ?? '',
            estado: r.estado,
            fecha_intervencion: r.fecha_intervencion ?? '',
            responsable_intervencion: r.responsable_intervencion ?? '',
            acpm_action_id: r.acpm_action_id ?? '',
            observaciones: r.observaciones ?? '',
        });
        setOpen(true);
    }

    /**
     * Al elegir un empleado de la nómina se copian su nombre y su área. El
     * reportante sigue siendo texto: quien reporta no siempre está en la nómina
     * (un contratista, una visita) y exigir el vínculo haría que esos reportes
     * no se registraran.
     */
    function elegirEmpleado(id: string) {
        setData('employee_id', id);
        const emp = empleados.find((e) => String(e.id) === id);
        if (emp) {
            setData('reportado_por', `${emp.nombres} ${emp.apellidos}`.trim());
            if (emp.area) setData('area', emp.area);
        }
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) put(route('reportes-ac.update', editing.id), opts);
        else post(route('reportes-ac.store'), opts);
    };

    function eliminar(r: Reporte) {
        if (confirm('¿Eliminar este reporte?')) {
            router.delete(route('reportes-ac.destroy', r.id), { preserveScroll: true });
        }
    }

    return (
        <ModuloPage
            titulo="Actos y condiciones"
            descripcion="Reportes de actos y condiciones inseguras"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button onClick={openCreate} className="gap-2">
                        <Plus className="size-4" /> Nuevo reporte
                    </Button>
                ) : undefined
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Reportes" value={stats.total} icon={MessageSquareWarning} />
                <StatCard label="Sin intervenir" value={stats.pendientes} icon={Wrench} alerta={stats.pendientes > 0} />
                {/* Indicador RED-AC: intervenidas sobre reportadas. */}
                <StatCard label="Intervención" value={stats.porcentaje} sufijo="%" icon={Percent} />
                {/* Críticos sin tocar: lo que hay que atender hoy. */}
                <StatCard label="Críticos pendientes" value={stats.criticos} icon={CircleAlert} alerta={stats.criticos > 0} />
            </div>

            <Card>
                <CardContent className="p-0">
                    {reportes.length === 0 ? (
                        <p className="text-muted-foreground p-8 text-center text-sm">Todavía no hay reportes.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2.5 font-semibold">Fecha</th>
                                        <th className="px-4 py-2.5 font-semibold">Tipo</th>
                                        <th className="px-4 py-2.5 font-semibold">Descripción</th>
                                        <th className="px-4 py-2.5 font-semibold">Reportado por</th>
                                        <th className="px-4 py-2.5 font-semibold">Severidad</th>
                                        <th className="px-4 py-2.5 font-semibold">Estado</th>
                                        {canManage && <th className="px-4 py-2.5" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {reportes.map((r) => (
                                        <tr key={r.id} className="border-t">
                                            <td className="px-4 py-2.5 tabular-nums whitespace-nowrap">{r.fecha}</td>
                                            <td className="px-4 py-2.5">{ETIQUETA_TIPO[r.tipo]}</td>
                                            <td className="max-w-md px-4 py-2.5">
                                                <div className="truncate">{r.descripcion}</div>
                                                {(r.area || r.lugar) && (
                                                    <div className="text-muted-foreground text-xs">
                                                        {[r.area, r.lugar].filter(Boolean).join(' · ')}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5">{r.reportado_por}</td>
                                            <td className="px-4 py-2.5">
                                                <Badge className={cn('font-normal capitalize', CLS_SEVERIDAD[r.severidad])}>
                                                    {r.severidad}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <Badge className={cn('font-normal', CLS_ESTADO[r.estado])}>
                                                    {ETIQUETA_ESTADO[r.estado]}
                                                </Badge>
                                            </td>
                                            {canManage && (
                                                <td className="px-4 py-2.5">
                                                    <div className="flex justify-end gap-1">
                                                        <Button size="icon" variant="ghost" onClick={() => openEdit(r)} aria-label="Editar">
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button size="icon" variant="ghost" onClick={() => eliminar(r)} aria-label="Eliminar">
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
                        <DialogTitle>{editing ? 'Editar reporte' : 'Nuevo reporte'}</DialogTitle>
                        <DialogDescription>
                            Un acto es lo que hace una persona; una condición es cómo está el entorno.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="fecha">Fecha</Label>
                                <Input id="fecha" type="date" value={data.fecha} onChange={(e) => setData('fecha', e.target.value)} />
                                <InputError message={errors.fecha} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="tipo">Tipo</Label>
                                <select
                                    id="tipo"
                                    value={data.tipo}
                                    onChange={(e) => setData('tipo', e.target.value as Tipo)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    {catalogos.tipos.map((t) => (
                                        <option key={t} value={t}>
                                            {ETIQUETA_TIPO[t]}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="severidad">Severidad</Label>
                                <select
                                    id="severidad"
                                    value={data.severidad}
                                    onChange={(e) => setData('severidad', e.target.value as Severidad)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm capitalize"
                                >
                                    {catalogos.severidades.map((s) => (
                                        <option key={s} value={s}>
                                            {s}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="employee_id">Trabajador (si está en la nómina)</Label>
                                <select
                                    id="employee_id"
                                    value={data.employee_id}
                                    onChange={(e) => elegirEmpleado(e.target.value)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    <option value="">No está en la nómina</option>
                                    {empleados.map((e) => (
                                        <option key={e.id} value={e.id}>
                                            {e.apellidos} {e.nombres}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="reportado_por">Reportado por</Label>
                                <Input
                                    id="reportado_por"
                                    value={data.reportado_por}
                                    onChange={(e) => setData('reportado_por', e.target.value)}
                                />
                                <InputError message={errors.reportado_por} />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="area">Área</Label>
                                <Input id="area" value={data.area} onChange={(e) => setData('area', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="lugar">Lugar exacto</Label>
                                <Input id="lugar" value={data.lugar} onChange={(e) => setData('lugar', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="clasificacion_peligro">Clasificación del peligro</Label>
                                <select
                                    id="clasificacion_peligro"
                                    value={data.clasificacion_peligro}
                                    onChange={(e) => setData('clasificacion_peligro', e.target.value)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    <option value="">Sin clasificar</option>
                                    {catalogos.clasificaciones.map((c) => (
                                        <option key={c} value={c}>
                                            {c}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="descripcion">Qué se observó</Label>
                            <textarea
                                id="descripcion"
                                value={data.descripcion}
                                onChange={(e) => setData('descripcion', e.target.value)}
                                rows={3}
                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                            />
                            <InputError message={errors.descripcion} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="accion_inmediata">Acción inmediata</Label>
                            <textarea
                                id="accion_inmediata"
                                value={data.accion_inmediata}
                                onChange={(e) => setData('accion_inmediata', e.target.value)}
                                rows={2}
                                placeholder="Qué se hizo en el momento para controlar el riesgo."
                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                            />
                        </div>

                        <div className="bg-muted/40 grid gap-3 rounded-md p-3 sm:grid-cols-3">
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
                            {data.estado !== 'reportado' && (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="fecha_intervencion">Intervenido el</Label>
                                        <Input
                                            id="fecha_intervencion"
                                            type="date"
                                            value={data.fecha_intervencion}
                                            onChange={(e) => setData('fecha_intervencion', e.target.value)}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="responsable_intervencion">Quién intervino</Label>
                                        <Input
                                            id="responsable_intervencion"
                                            value={data.responsable_intervencion}
                                            onChange={(e) => setData('responsable_intervencion', e.target.value)}
                                        />
                                    </div>
                                </>
                            )}
                        </div>

                        {/* Enlazar con una acción ACPM en vez de repetir el seguimiento aquí. */}
                        <div className="grid gap-2">
                            <Label htmlFor="acpm_action_id">Acción ACPM asociada</Label>
                            <select
                                id="acpm_action_id"
                                value={data.acpm_action_id}
                                onChange={(e) => setData('acpm_action_id', e.target.value)}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                <option value="">Ninguna</option>
                                {acciones.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.codigo} — {a.accion.slice(0, 60)}
                                    </option>
                                ))}
                            </select>
                            <p className="text-muted-foreground text-xs">
                                Si el reporte exige una acción formal, se registra en ACPM y se enlaza aquí.
                            </p>
                        </div>

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
                                {editing ? 'Guardar' : 'Registrar reporte'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ModuloPage>
    );
}
