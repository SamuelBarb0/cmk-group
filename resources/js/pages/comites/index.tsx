import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { router, useForm } from '@inertiajs/react';
import { CalendarX, Percent, Pencil, Plus, Scale, Trash2, UsersRound } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Tipo = 'copasst' | 'cocolab';
type Rol = 'presidente' | 'secretario' | 'principal' | 'suplente';
type Representa = 'empleador' | 'trabajadores';

interface Miembro {
    /** Exigida por useForm de Inertia para los objetos anidados. */
    [key: string]: string | number | null | undefined;
    employee_id: number | null;
    nombres: string;
    numero_documento: string | null;
    cargo: string | null;
    rol: Rol;
    representa: Representa;
    votos: number | null;
}

interface Actividad {
    /** Exigida por useForm de Inertia para los objetos anidados. */
    [key: string]: string | boolean | null | undefined;
    descripcion: string;
    programada: boolean;
    ejecutada: boolean;
    evidencia: string | null;
}

interface Comite {
    id: number;
    tipo: Tipo;
    periodo: number;
    fecha_conformacion: string;
    fecha_vencimiento: string | null;
    numero_trabajadores: number | null;
    acta_conformacion: string | null;
    observaciones: string | null;
    /** Calculados por el modelo. */
    vencido: boolean;
    composicion_correcta: boolean;
    members: Miembro[];
    activities: Actividad[];
}

interface EmpleadoRow {
    id: number;
    nombres: string;
    apellidos: string;
    cargo: string | null;
    numero_documento: string;
}

interface Props {
    comites: Comite[];
    empleados: EmpleadoRow[];
    stats: { total: number; vencidos: number; sin_paridad: number; cumplimiento: number };
    catalogos: { tipos: Tipo[]; roles: Rol[]; representa: Representa[] };
    needsClient: boolean;
}

const ETIQUETA_TIPO: Record<Tipo, string> = {
    copasst: 'COPASST',
    cocolab: 'Convivencia laboral',
};

const ETIQUETA_REPRESENTA: Record<Representa, string> = {
    empleador: 'Empleador',
    trabajadores: 'Trabajadores',
};

/**
 * Plan de trabajo de arranque, tomado de la hoja «1.1.7 Seguimiento C» del
 * libro de CMK. Se ofrece al conformar un comité nuevo para no empezar con una
 * lista en blanco; el consultor la ajusta.
 */
const ACTIVIDADES_BASE: Record<Tipo, string[]> = {
    copasst: [
        'Acta de conformación y registro',
        'Capacitación de las funciones del comité',
        'Actas de reunión mensuales',
        'Capacitación en SG-SST',
        'Capacitación en investigación de accidentes',
        'Capacitación en inspecciones planeadas',
        'Divulgación del plan de trabajo anual',
        'Seguimiento a las inspecciones realizadas',
        'Consolidado de los planes de acción',
    ],
    cocolab: [
        'Acta de conformación y registro',
        'Capacitación de las funciones del comité',
        'Actas de reunión trimestrales',
        'Capacitación en acoso laboral (Ley 1010)',
        'Capacitación en modalidades de acoso',
        'Divulgación del plan de trabajo anual',
        'Seguimiento a posibles casos reportados',
        'Consolidado de los planes de acción',
    ],
};

const hoy = () => new Date().toISOString().slice(0, 10);

const emptyForm = {
    tipo: 'copasst' as Tipo,
    periodo: new Date().getFullYear(),
    fecha_conformacion: hoy(),
    fecha_vencimiento: '',
    numero_trabajadores: '' as number | string,
    acta_conformacion: '',
    observaciones: '',
    members: [] as Miembro[],
    activities: [] as Actividad[],
};

export default function ComitesIndex({ comites, empleados, stats, catalogos, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Comite | null>(null);

    const { data, setData, post, put, processing, errors, clearErrors } = useForm({ ...emptyForm });

    function openCreate() {
        setEditing(null);
        clearErrors();
        setData({
            ...emptyForm,
            // Nace con su plan de trabajo propuesto, no en blanco.
            activities: ACTIVIDADES_BASE.copasst.map((d) => ({
                descripcion: d,
                programada: true,
                ejecutada: false,
                evidencia: '',
            })),
        });
        setOpen(true);
    }

    function openEdit(c: Comite) {
        setEditing(c);
        clearErrors();
        setData({
            tipo: c.tipo,
            periodo: c.periodo,
            fecha_conformacion: c.fecha_conformacion,
            fecha_vencimiento: c.fecha_vencimiento ?? '',
            numero_trabajadores: c.numero_trabajadores ?? '',
            acta_conformacion: c.acta_conformacion ?? '',
            observaciones: c.observaciones ?? '',
            members: c.members.map((m) => ({ ...m })),
            activities: c.activities.map((a) => ({ ...a })),
        });
        setOpen(true);
    }

    /** Al cambiar de tipo se repone el plan base, pero solo si no se ha tocado. */
    function cambiarTipo(tipo: Tipo) {
        setData('tipo', tipo);
        const intacto =
            !editing &&
            data.activities.every((a) => !a.ejecutada) &&
            data.activities.length === ACTIVIDADES_BASE[data.tipo].length;
        if (intacto) {
            setData(
                'activities',
                ACTIVIDADES_BASE[tipo].map((d) => ({ descripcion: d, programada: true, ejecutada: false, evidencia: '' })),
            );
        }
    }

    function setMiembro(i: number, cambio: Partial<Miembro>) {
        setData('members', data.members.map((m, j) => (i === j ? { ...m, ...cambio } : m)));
    }

    function elegirEmpleado(i: number, id: string) {
        const emp = empleados.find((e) => String(e.id) === id);
        setMiembro(i, {
            employee_id: id ? Number(id) : null,
            ...(emp
                ? { nombres: `${emp.nombres} ${emp.apellidos}`.trim(), numero_documento: emp.numero_documento, cargo: emp.cargo }
                : {}),
        });
    }

    function setActividad(i: number, cambio: Partial<Actividad>) {
        setData('activities', data.activities.map((a, j) => (i === j ? { ...a, ...cambio } : a)));
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) put(route('comites.update', editing.id), opts);
        else post(route('comites.store'), opts);
    };

    function eliminar(c: Comite) {
        if (confirm(`¿Eliminar el ${ETIQUETA_TIPO[c.tipo]} de ${c.periodo}?`)) {
            router.delete(route('comites.destroy', c.id), { preserveScroll: true });
        }
    }

    // Aviso en vivo de la paridad, con el mismo criterio del modelo.
    const porParte = (r: Representa) => data.members.filter((m) => m.representa === r).length;

    return (
        <ModuloPage
            titulo="Comités"
            descripcion="COPASST y Comité de Convivencia Laboral"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button onClick={openCreate} className="gap-2">
                        <Plus className="size-4" /> Conformar comité
                    </Button>
                ) : undefined
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Comités" value={stats.total} icon={UsersRound} />
                <StatCard label="Vencidos" value={stats.vencidos} icon={CalendarX} alerta={stats.vencidos > 0} />
                <StatCard label="Sin paridad" value={stats.sin_paridad} icon={Scale} alerta={stats.sin_paridad > 0} />
                {/* Es la cuenta de CUMP-COPASST y CUMP-COCOLAB, consolidada. */}
                <StatCard label="Cumplimiento del plan" value={stats.cumplimiento} sufijo="%" icon={Percent} />
            </div>

            <Card>
                <CardContent className="p-0">
                    {comites.length === 0 ? (
                        <p className="text-muted-foreground p-8 text-center text-sm">Todavía no hay comités conformados.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2.5 font-semibold">Comité</th>
                                        <th className="px-4 py-2.5 font-semibold">Periodo</th>
                                        <th className="px-4 py-2.5 font-semibold">Conformado</th>
                                        <th className="px-4 py-2.5 font-semibold">Vence</th>
                                        <th className="px-4 py-2.5 font-semibold">Integrantes</th>
                                        <th className="px-4 py-2.5 font-semibold">Plan de trabajo</th>
                                        {canManage && <th className="px-4 py-2.5" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {comites.map((c) => {
                                        const prog = c.activities.filter((a) => a.programada).length;
                                        const ejec = c.activities.filter((a) => a.ejecutada).length;
                                        return (
                                            <tr key={c.id} className="border-t">
                                                <td className="px-4 py-2.5 font-medium">{ETIQUETA_TIPO[c.tipo]}</td>
                                                <td className="px-4 py-2.5 tabular-nums">{c.periodo}</td>
                                                <td className="px-4 py-2.5 tabular-nums whitespace-nowrap">{c.fecha_conformacion}</td>
                                                <td className="px-4 py-2.5 tabular-nums whitespace-nowrap">
                                                    {c.fecha_vencimiento ?? '—'}
                                                    {c.vencido && <span className="text-destructive block text-xs">vencido</span>}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {c.members.length}
                                                    {!c.composicion_correcta && (
                                                        <span className="block text-xs text-amber-600 dark:text-amber-500">
                                                            sin la paridad que exige la norma
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5 tabular-nums">
                                                    {ejec} / {prog}
                                                </td>
                                                {canManage && (
                                                    <td className="px-4 py-2.5">
                                                        <div className="flex justify-end gap-1">
                                                            <Button size="icon" variant="ghost" onClick={() => openEdit(c)} aria-label="Editar">
                                                                <Pencil className="size-4" />
                                                            </Button>
                                                            <Button size="icon" variant="ghost" onClick={() => eliminar(c)} aria-label="Eliminar">
                                                                <Trash2 className="size-4" />
                                                            </Button>
                                                        </div>
                                                    </td>
                                                )}
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </CardContent>
            </Card>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>{editing ? `${ETIQUETA_TIPO[editing.tipo]} ${editing.periodo}` : 'Conformar comité'}</DialogTitle>
                        <DialogDescription>
                            La vigencia legal es de dos años y se calcula sola desde la conformación.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-4">
                            <div className="grid gap-2">
                                <Label htmlFor="tipo">Comité</Label>
                                <select
                                    id="tipo"
                                    value={data.tipo}
                                    onChange={(e) => cambiarTipo(e.target.value as Tipo)}
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
                                <Label htmlFor="periodo">Periodo</Label>
                                <Input
                                    id="periodo"
                                    type="number"
                                    value={data.periodo}
                                    onChange={(e) => setData('periodo', Number(e.target.value))}
                                />
                                <InputError message={errors.periodo} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fecha_conformacion">Conformado el</Label>
                                <Input
                                    id="fecha_conformacion"
                                    type="date"
                                    value={data.fecha_conformacion}
                                    onChange={(e) => setData('fecha_conformacion', e.target.value)}
                                />
                                <InputError message={errors.fecha_conformacion} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="numero_trabajadores">N.° de trabajadores</Label>
                                <Input
                                    id="numero_trabajadores"
                                    type="number"
                                    value={data.numero_trabajadores}
                                    onChange={(e) => setData('numero_trabajadores', e.target.value)}
                                />
                                <p className="text-muted-foreground text-xs">Decide cuántos integrantes exige la norma.</p>
                            </div>
                        </div>

                        {/* ---------------------------------------------- integrantes */}
                        <div className="rounded-md border">
                            <div className="flex items-center justify-between border-b px-4 py-2.5">
                                <div>
                                    <span className="text-sm font-medium">Integrantes ({data.members.length})</span>
                                    <span className="text-muted-foreground ml-2 text-xs">
                                        empleador {porParte('empleador')} · trabajadores {porParte('trabajadores')}
                                    </span>
                                </div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setData('members', [
                                            ...data.members,
                                            {
                                                employee_id: null,
                                                nombres: '',
                                                numero_documento: '',
                                                cargo: '',
                                                rol: 'principal',
                                                representa: 'trabajadores',
                                                votos: null,
                                            },
                                        ])
                                    }
                                >
                                    <Plus className="size-4" /> Agregar
                                </Button>
                            </div>

                            {porParte('empleador') !== porParte('trabajadores') && data.members.length > 0 && (
                                <p className="px-4 pt-3 text-xs text-amber-600 dark:text-amber-500">
                                    La norma exige el mismo número de representantes por cada parte.
                                </p>
                            )}

                            <div className="divide-y">
                                {data.members.map((m, i) => (
                                    <div key={i} className="grid gap-2 px-4 py-2.5 sm:grid-cols-[1.4fr_1fr_1fr_1fr_auto]">
                                        <select
                                            value={m.employee_id ?? ''}
                                            onChange={(e) => elegirEmpleado(i, e.target.value)}
                                            className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                        >
                                            <option value="">Escribir a mano…</option>
                                            {empleados.map((e) => (
                                                <option key={e.id} value={e.id}>
                                                    {e.apellidos} {e.nombres}
                                                </option>
                                            ))}
                                        </select>
                                        <Input
                                            value={m.nombres}
                                            onChange={(e) => setMiembro(i, { nombres: e.target.value })}
                                            placeholder="Nombre"
                                            className="h-9"
                                        />
                                        <select
                                            value={m.representa}
                                            onChange={(e) => setMiembro(i, { representa: e.target.value as Representa })}
                                            className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                        >
                                            {catalogos.representa.map((r) => (
                                                <option key={r} value={r}>
                                                    {ETIQUETA_REPRESENTA[r]}
                                                </option>
                                            ))}
                                        </select>
                                        <select
                                            value={m.rol}
                                            onChange={(e) => setMiembro(i, { rol: e.target.value as Rol })}
                                            className="border-input bg-background h-9 rounded-md border px-2 text-sm capitalize"
                                        >
                                            {catalogos.roles.map((r) => (
                                                <option key={r} value={r}>
                                                    {r}
                                                </option>
                                            ))}
                                        </select>
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            onClick={() => setData('members', data.members.filter((_, j) => j !== i))}
                                            aria-label="Quitar integrante"
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* ------------------------------------------ plan de trabajo */}
                        <div className="rounded-md border">
                            <div className="flex items-center justify-between border-b px-4 py-2.5">
                                <span className="text-sm font-medium">
                                    Plan de trabajo anual
                                    <span className="text-muted-foreground ml-2 text-xs font-normal">
                                        alimenta el indicador de cumplimiento del comité
                                    </span>
                                </span>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setData('activities', [
                                            ...data.activities,
                                            { descripcion: '', programada: true, ejecutada: false, evidencia: '' },
                                        ])
                                    }
                                >
                                    <Plus className="size-4" /> Agregar
                                </Button>
                            </div>

                            <div className="text-muted-foreground bg-muted/40 grid grid-cols-[1fr_5rem_5rem_2rem] gap-2 border-b px-4 py-1.5 text-[11px] font-semibold">
                                <span>Actividad</span>
                                <span className="text-center">Programada</span>
                                <span className="text-center">Ejecutada</span>
                                <span />
                            </div>

                            <div className="divide-y">
                                {data.activities.map((a, i) => (
                                    <div key={i} className="grid grid-cols-[1fr_5rem_5rem_2rem] items-center gap-2 px-4 py-2">
                                        <Input
                                            value={a.descripcion}
                                            onChange={(e) => setActividad(i, { descripcion: e.target.value })}
                                            className="h-8"
                                        />
                                        <div className="flex justify-center">
                                            <Checkbox
                                                checked={a.programada}
                                                onCheckedChange={(v) => setActividad(i, { programada: v === true })}
                                            />
                                        </div>
                                        <div className="flex justify-center">
                                            <Checkbox
                                                checked={a.ejecutada}
                                                onCheckedChange={(v) => setActividad(i, { ejecutada: v === true })}
                                            />
                                        </div>
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            onClick={() => setData('activities', data.activities.filter((_, j) => j !== i))}
                                            aria-label="Quitar actividad"
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="acta_conformacion">N.° del acta de conformación</Label>
                                <Input
                                    id="acta_conformacion"
                                    value={data.acta_conformacion}
                                    onChange={(e) => setData('acta_conformacion', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="observaciones">Observaciones</Label>
                                <Input
                                    id="observaciones"
                                    value={data.observaciones}
                                    onChange={(e) => setData('observaciones', e.target.value)}
                                />
                            </div>
                        </div>

                        <DialogFooter className="gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {editing ? 'Guardar' : 'Conformar comité'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ModuloPage>
    );
}
