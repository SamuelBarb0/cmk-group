import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { Activity, CalendarX, HeartPulse, Pencil, Plus, Stethoscope, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Ausencia {
    id: number;
    employee_id: number;
    fecha_inicio: string;
    fecha_fin: string;
    dias: number;
    tipo: string;
    diagnostico: string | null;
    cie10: string | null;
    entidad: string | null;
    incapacidad_numero: string | null;
    prorroga: boolean;
    observaciones: string | null;
    employee?: { id: number; nombres: string; apellidos: string; cargo: string | null; area: string | null } | null;
}

interface EmpleadoRow {
    id: number;
    nombres: string;
    apellidos: string;
    cargo: string | null;
    area: string | null;
}

interface Props {
    ausencias: Ausencia[];
    empleados: EmpleadoRow[];
    stats: { registros: number; dias_total: number; dias_medicos: number; dias_at: number; casos_el: number };
    anio: number;
    catalogos: { tipos: string[] };
    needsClient: boolean;
}

const ETIQUETA_TIPO: Record<string, string> = {
    enfermedad_general: 'Enfermedad general',
    accidente_trabajo: 'Accidente de trabajo',
    enfermedad_laboral: 'Enfermedad laboral',
    accidente_comun: 'Accidente común',
    licencia_maternidad: 'Licencia de maternidad',
    licencia_luto: 'Licencia de luto',
    permiso: 'Permiso',
    otro: 'Otro',
};

/** Las que entran en el indicador de ausentismo por causa médica. */
const CAUSA_MEDICA = ['enfermedad_general', 'accidente_trabajo', 'enfermedad_laboral', 'accidente_comun'];

const hoy = () => new Date().toISOString().slice(0, 10);

const emptyForm = {
    employee_id: '' as number | string,
    fecha_inicio: hoy(),
    fecha_fin: hoy(),
    dias: '' as number | string,
    tipo: 'enfermedad_general',
    diagnostico: '',
    cie10: '',
    entidad: '',
    incapacidad_numero: '',
    prorroga: false as boolean,
    observaciones: '',
};

export default function AusentismoIndex({ ausencias, empleados, stats, anio, catalogos, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Ausencia | null>(null);

    const { data, setData, post, put, processing, errors, clearErrors } = useForm({ ...emptyForm });

    function openCreate() {
        setEditing(null);
        clearErrors();
        setData({ ...emptyForm });
        setOpen(true);
    }

    function openEdit(a: Ausencia) {
        setEditing(a);
        clearErrors();
        setData({
            employee_id: a.employee_id,
            fecha_inicio: a.fecha_inicio,
            fecha_fin: a.fecha_fin,
            dias: a.dias,
            tipo: a.tipo,
            diagnostico: a.diagnostico ?? '',
            cie10: a.cie10 ?? '',
            entidad: a.entidad ?? '',
            incapacidad_numero: a.incapacidad_numero ?? '',
            prorroga: a.prorroga,
            observaciones: a.observaciones ?? '',
        });
        setOpen(true);
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) put(route('ausentismo.update', editing.id), opts);
        else post(route('ausentismo.store'), opts);
    };

    function eliminar(a: Ausencia) {
        if (confirm('¿Eliminar esta ausencia?')) {
            router.delete(route('ausentismo.destroy', a.id), { preserveScroll: true });
        }
    }

    /**
     * Días sugeridos con los dos extremos incluidos: una incapacidad de un solo
     * día es 1, no 0. Solo es una propuesta — el campo queda editable porque hay
     * empresas que cuentan únicamente días hábiles.
     */
    const diasSugeridos = (() => {
        if (!data.fecha_inicio || !data.fecha_fin) return null;
        const ini = new Date(data.fecha_inicio);
        const fin = new Date(data.fecha_fin);
        if (fin < ini) return null;
        return Math.round((fin.getTime() - ini.getTime()) / 86400000) + 1;
    })();

    return (
        <ModuloPage
            titulo="Ausentismo"
            descripcion={`Incapacidades, licencias y permisos de ${anio}`}
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button onClick={openCreate} className="gap-2">
                        <Plus className="size-4" /> Nueva ausencia
                    </Button>
                ) : undefined
            }
            filtros={
                <div className="flex items-center gap-2">
                    <Label htmlFor="anio" className="text-sm">
                        Año
                    </Label>
                    <select
                        id="anio"
                        value={anio}
                        onChange={(e) => router.get(route('ausentismo.index'), { anio: e.target.value }, { preserveScroll: true })}
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    >
                        {Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i).map((y) => (
                            <option key={y} value={y}>
                                {y}
                            </option>
                        ))}
                    </select>
                </div>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Registros" value={stats.registros} icon={CalendarX} />
                <StatCard label="Días perdidos" value={stats.dias_total} icon={Activity} />
                {/* Los dos números que alimentan indicadores legales. */}
                <StatCard label="Días por causa médica" value={stats.dias_medicos} icon={Stethoscope} />
                <StatCard label="Días por accidente de trabajo" value={stats.dias_at} icon={HeartPulse} />
            </div>

            {stats.casos_el > 0 && (
                <div className="border-destructive/30 bg-destructive/10 text-destructive rounded-lg border px-4 py-2.5 text-sm">
                    {stats.casos_el} caso(s) de enfermedad laboral en {anio}. Alimentan la prevalencia y la incidencia de
                    enfermedad laboral.
                </div>
            )}

            <Card>
                <CardContent className="p-0">
                    {ausencias.length === 0 ? (
                        <p className="text-muted-foreground p-8 text-center text-sm">No hay ausencias registradas en {anio}.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2.5 font-semibold">Trabajador</th>
                                        <th className="px-4 py-2.5 font-semibold">Desde</th>
                                        <th className="px-4 py-2.5 font-semibold">Hasta</th>
                                        <th className="px-4 py-2.5 font-semibold">Días</th>
                                        <th className="px-4 py-2.5 font-semibold">Tipo</th>
                                        <th className="px-4 py-2.5 font-semibold">Diagnóstico</th>
                                        {canManage && <th className="px-4 py-2.5" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {ausencias.map((a) => (
                                        <tr key={a.id} className="border-t">
                                            <td className="px-4 py-2.5">
                                                <div className="font-medium">
                                                    {a.employee ? `${a.employee.apellidos} ${a.employee.nombres}` : '—'}
                                                </div>
                                                {a.employee?.cargo && (
                                                    <div className="text-muted-foreground text-xs">{a.employee.cargo}</div>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5 tabular-nums whitespace-nowrap">{a.fecha_inicio}</td>
                                            <td className="px-4 py-2.5 tabular-nums whitespace-nowrap">{a.fecha_fin}</td>
                                            <td className="px-4 py-2.5 tabular-nums">{a.dias}</td>
                                            <td className="px-4 py-2.5">
                                                <Badge
                                                    className={cn(
                                                        'font-normal',
                                                        CAUSA_MEDICA.includes(a.tipo)
                                                            ? 'bg-amber-500/15 text-amber-700 dark:text-amber-400'
                                                            : 'bg-muted text-muted-foreground',
                                                    )}
                                                >
                                                    {ETIQUETA_TIPO[a.tipo] ?? a.tipo}
                                                </Badge>
                                                {a.prorroga && <span className="text-muted-foreground block text-xs">prórroga</span>}
                                            </td>
                                            <td className="max-w-xs px-4 py-2.5">
                                                <div className="truncate">{a.diagnostico ?? '—'}</div>
                                                {a.cie10 && <div className="text-muted-foreground text-xs">CIE-10 {a.cie10}</div>}
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
                        <DialogTitle>{editing ? 'Editar ausencia' : 'Nueva ausencia'}</DialogTitle>
                        <DialogDescription>
                            Solo las de causa médica entran en el indicador de ausentismo.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="employee_id">Trabajador</Label>
                            <select
                                id="employee_id"
                                value={data.employee_id}
                                onChange={(e) => setData('employee_id', e.target.value)}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                <option value="">Selecciona…</option>
                                {empleados.map((e) => (
                                    <option key={e.id} value={e.id}>
                                        {e.apellidos} {e.nombres}
                                        {e.cargo ? ` — ${e.cargo}` : ''}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.employee_id} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-4">
                            <div className="grid gap-2">
                                <Label htmlFor="fecha_inicio">Desde</Label>
                                <Input
                                    id="fecha_inicio"
                                    type="date"
                                    value={data.fecha_inicio}
                                    onChange={(e) => setData('fecha_inicio', e.target.value)}
                                />
                                <InputError message={errors.fecha_inicio} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fecha_fin">Hasta</Label>
                                <Input
                                    id="fecha_fin"
                                    type="date"
                                    value={data.fecha_fin}
                                    onChange={(e) => setData('fecha_fin', e.target.value)}
                                />
                                <InputError message={errors.fecha_fin} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="dias">Días</Label>
                                <Input
                                    id="dias"
                                    type="number"
                                    min={1}
                                    value={data.dias}
                                    onChange={(e) => setData('dias', e.target.value)}
                                    placeholder={diasSugeridos ? String(diasSugeridos) : ''}
                                />
                                {diasSugeridos !== null && (
                                    <p className="text-muted-foreground text-xs">
                                        Calendario: {diasSugeridos}. Cámbialo si se cuentan solo hábiles.
                                    </p>
                                )}
                                <InputError message={errors.dias} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="tipo">Tipo</Label>
                                <select
                                    id="tipo"
                                    value={data.tipo}
                                    onChange={(e) => setData('tipo', e.target.value)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    {catalogos.tipos.map((t) => (
                                        <option key={t} value={t}>
                                            {ETIQUETA_TIPO[t] ?? t}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="diagnostico">Diagnóstico</Label>
                                <Input id="diagnostico" value={data.diagnostico} onChange={(e) => setData('diagnostico', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="cie10">Código CIE-10</Label>
                                <Input id="cie10" value={data.cie10} onChange={(e) => setData('cie10', e.target.value)} placeholder="M545" />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="entidad">Entidad que la expide</Label>
                                <Input
                                    id="entidad"
                                    value={data.entidad}
                                    onChange={(e) => setData('entidad', e.target.value)}
                                    placeholder="EPS o ARL"
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="incapacidad_numero">N.° de incapacidad</Label>
                                <Input
                                    id="incapacidad_numero"
                                    value={data.incapacidad_numero}
                                    onChange={(e) => setData('incapacidad_numero', e.target.value)}
                                />
                            </div>
                        </div>

                        <label className="flex cursor-pointer items-center gap-3">
                            <Checkbox checked={data.prorroga} onCheckedChange={(v) => setData('prorroga', v === true)} />
                            <span className="text-sm">Es prórroga de una incapacidad anterior</span>
                        </label>

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
                                {editing ? 'Guardar' : 'Registrar ausencia'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ModuloPage>
    );
}
