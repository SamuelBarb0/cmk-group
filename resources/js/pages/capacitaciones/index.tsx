import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Building2, CheckCircle2, Download, FileText, GraduationCap, Plus, Save, Trash2, UserPlus, Users } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Modalidad = 'presencial' | 'virtual';
type Estado = 'programada' | 'realizada';

interface Topic {
    id: number;
    codigo: string;
    titulo: string;
    categoria: string;
    descripcion: string | null;
    duracion_sugerida: number | null;
    tiene_archivo: boolean;
}
interface TrainingRow {
    id: number;
    titulo: string;
    categoria: string;
    fecha: string | null;
    instructor: string | null;
    modalidad: Modalidad;
    estado: Estado;
    asistentes: number;
    asistieron: number;
}
interface Attendee {
    employee_id: number | null;
    nombres: string;
    numero_documento: string | null;
    cargo: string | null;
    asistio: boolean;
}
interface EmployeeRow {
    id: number;
    nombre: string;
    numero_documento: string | null;
    cargo: string | null;
}
interface OpenTraining {
    id: number;
    titulo: string;
    fecha: string | null;
    instructor: string | null;
    modalidad: Modalidad;
    duracion_minutos: number | null;
    lugar: string | null;
    objetivo: string | null;
    estado: Estado;
    observaciones: string | null;
    attendees: Attendee[];
}

interface Props {
    topics: Topic[];
    trainings: TrainingRow[];
    employees: EmployeeRow[];
    needsClient: boolean;
    open?: OpenTraining;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Capacitaciones', href: '/capacitaciones' },
];

const CAT: Record<string, string> = {
    SST: 'bg-blue-500/10 text-blue-700 dark:text-blue-400',
    PESV: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    HSEQ: 'bg-teal-500/10 text-teal-700 dark:text-teal-400',
};
const ESTADO: Record<Estado, { label: string; cls: string }> = {
    programada: { label: 'Programada', cls: 'bg-slate-500 text-white' },
    realizada: { label: 'Realizada', cls: 'bg-green-600 text-white' },
};

export default function CapacitacionesIndex({ topics, trainings, employees, needsClient, open }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const page = usePage<SharedData>();
    const flash = page.props.flash;
    const tenant = page.props.tenant as { id: number; name: string } | null;

    const [notice, setNotice] = useState<string | null>(null);
    const [creating, setCreating] = useState<number | 'libre' | null>(null);

    useEffect(() => {
        if (flash?.success) {
            setNotice(flash.success);
            const t = setTimeout(() => setNotice(null), 4000);
            return () => clearTimeout(t);
        }
    }, [flash?.success]);

    function programar(topicId: number | null) {
        router.post(
            route('capacitaciones.store'),
            topicId ? { training_topic_id: topicId } : { titulo: 'Capacitación' },
            { onStart: () => setCreating(topicId ?? 'libre'), onFinish: () => setCreating(null) },
        );
    }
    function abrir(t: TrainingRow) {
        router.get(route('capacitaciones.show', t.id), {}, { preserveScroll: true });
    }
    function eliminar(t: TrainingRow) {
        if (confirm(`¿Eliminar la capacitación «${t.titulo}»?`)) {
            router.delete(route('capacitaciones.destroy', t.id), { preserveScroll: true });
        }
    }

    if (needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Capacitaciones" />
                <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Capacitaciones</h1>
                        <p className="text-muted-foreground text-sm">Biblioteca de temas y registro de asistencia del SGI.</p>
                    </div>
                    <Card>
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <Building2 className="size-7" />
                            </div>
                            <p className="font-medium">Selecciona un cliente para gestionar sus capacitaciones</p>
                            <Button asChild variant="outline" className="gap-2">
                                <Link href="/clientes">
                                    <Building2 className="size-4" /> Ir a Clientes
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Capacitaciones" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Capacitaciones</h1>
                        <p className="text-muted-foreground text-sm">
                            Biblioteca de temas y registro de asistencia de <span className="font-medium">{tenant?.name ?? 'la empresa'}</span>.
                        </p>
                    </div>
                    {canManage && (
                        <Button variant="outline" className="gap-2" disabled={creating !== null} onClick={() => programar(null)}>
                            <Plus className="size-4" /> Capacitación libre
                        </Button>
                    )}
                </div>

                {notice && (
                    <div className="flex items-center gap-2 rounded-lg border border-green-600/30 bg-green-600/10 px-4 py-2.5 text-sm text-green-700 dark:text-green-400">
                        <CheckCircle2 className="size-4" /> {notice}
                    </div>
                )}

                {/* Biblioteca de temas */}
                <div>
                    <h2 className="font-brand text-primary mb-3 text-lg font-bold tracking-tight">Biblioteca de temas</h2>
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {topics.map((t) => (
                            <Card key={t.id} className="flex flex-col">
                                <CardContent className="flex flex-1 flex-col gap-3 p-5">
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <div className="text-primary font-mono text-xs font-semibold">{t.codigo}</div>
                                            <div className="font-semibold leading-tight">{t.titulo}</div>
                                        </div>
                                        <span className={cn('rounded px-1.5 py-0.5 text-[10px] font-medium', CAT[t.categoria] ?? CAT.SST)}>{t.categoria}</span>
                                    </div>
                                    {t.descripcion && <p className="text-muted-foreground text-sm">{t.descripcion}</p>}
                                    {t.duracion_sugerida && <p className="text-muted-foreground text-xs">Duración sugerida: {t.duracion_sugerida} min</p>}
                                    <div className="mt-auto flex flex-wrap gap-2 pt-1">
                                        {t.tiene_archivo && (
                                            <Button variant="secondary" size="sm" asChild className="gap-1.5">
                                                <a href={route('capacitaciones.material', t.id)}>
                                                    <Download className="size-3.5" /> Material
                                                </a>
                                            </Button>
                                        )}
                                        {canManage && (
                                            <Button size="sm" className="gap-1.5" disabled={creating !== null} onClick={() => programar(t.id)}>
                                                <Plus className="size-3.5" /> Programar
                                            </Button>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </div>

                {/* Capacitaciones registradas */}
                <div>
                    <h2 className="font-brand text-primary mb-3 text-lg font-bold tracking-tight">Capacitaciones de la empresa</h2>
                    <Card className="overflow-hidden">
                        {trainings.length === 0 ? (
                            <CardContent className="flex min-h-40 flex-col items-center justify-center gap-2 text-center">
                                <GraduationCap className="text-muted-foreground size-7" />
                                <p className="text-muted-foreground text-sm">Aún no hay capacitaciones. Programa una desde la biblioteca.</p>
                            </CardContent>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground border-b text-left">
                                        <tr>
                                            <th className="px-5 py-3 font-medium">Capacitación</th>
                                            <th className="px-5 py-3 font-medium">Fecha</th>
                                            <th className="px-5 py-3 font-medium">Instructor</th>
                                            <th className="px-5 py-3 text-center font-medium">Asistencia</th>
                                            <th className="px-5 py-3 text-center font-medium">Estado</th>
                                            <th className="px-5 py-3 text-right font-medium">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {trainings.map((t) => (
                                            <tr key={t.id} className="hover:bg-muted/40 transition-colors">
                                                <td className="px-5 py-3">
                                                    <div className="font-medium">{t.titulo}</div>
                                                    <span className={cn('rounded px-1.5 py-0.5 text-[10px] font-medium', CAT[t.categoria] ?? CAT.SST)}>{t.categoria}</span>
                                                </td>
                                                <td className="text-muted-foreground px-5 py-3">{t.fecha ?? '—'}</td>
                                                <td className="text-muted-foreground px-5 py-3">{t.instructor ?? '—'}</td>
                                                <td className="px-5 py-3 text-center tabular-nums">
                                                    {t.asistieron}/{t.asistentes}
                                                </td>
                                                <td className="px-5 py-3 text-center">
                                                    <Badge className={ESTADO[t.estado].cls}>{ESTADO[t.estado].label}</Badge>
                                                </td>
                                                <td className="px-5 py-3">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Button variant="ghost" size="icon" asChild aria-label="Descargar registro de asistencia">
                                                            <a href={route('capacitaciones.export', t.id)}>
                                                                <Download className="size-4" />
                                                            </a>
                                                        </Button>
                                                        <Button variant="ghost" size="icon" onClick={() => abrir(t)} aria-label="Abrir">
                                                            <FileText className="size-4" />
                                                        </Button>
                                                        {canManage && (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                onClick={() => eliminar(t)}
                                                                aria-label="Eliminar"
                                                                className="text-destructive hover:text-destructive"
                                                            >
                                                                <Trash2 className="size-4" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>
                </div>
            </div>

            {open && <TrainingEditor key={open.id} training={open} employees={employees} canManage={canManage} />}
        </AppLayout>
    );
}

function TrainingEditor({ training, employees, canManage }: { training: OpenTraining; employees: EmployeeRow[]; canManage: boolean }) {
    const [openState, setOpenState] = useState(true);
    const [titulo, setTitulo] = useState(training.titulo);
    const [fecha, setFecha] = useState(training.fecha ?? '');
    const [instructor, setInstructor] = useState(training.instructor ?? '');
    const [modalidad, setModalidad] = useState<Modalidad>(training.modalidad);
    const [duracion, setDuracion] = useState(training.duracion_minutos ? String(training.duracion_minutos) : '');
    const [lugar, setLugar] = useState(training.lugar ?? '');
    const [objetivo, setObjetivo] = useState(training.objetivo ?? '');
    const [estado, setEstado] = useState<Estado>(training.estado);
    const [observaciones, setObservaciones] = useState(training.observaciones ?? '');
    const [attendees, setAttendees] = useState<Attendee[]>(training.attendees ?? []);
    const [picker, setPicker] = useState(false);
    const [saving, setSaving] = useState(false);

    // Empleados aún no agregados a la lista.
    const yaAgregados = useMemo(() => new Set(attendees.map((a) => a.employee_id).filter(Boolean)), [attendees]);
    const disponibles = employees.filter((e) => !yaAgregados.has(e.id));

    function agregarEmpleados(ids: number[]) {
        const nuevos = employees
            .filter((e) => ids.includes(e.id))
            .map<Attendee>((e) => ({ employee_id: e.id, nombres: e.nombre, numero_documento: e.numero_documento, cargo: e.cargo, asistio: true }));
        setAttendees((a) => [...a, ...nuevos]);
        setPicker(false);
    }
    function agregarManual() {
        setAttendees((a) => [...a, { employee_id: null, nombres: '', numero_documento: '', cargo: '', asistio: true }]);
    }
    function setAtt(i: number, patch: Partial<Attendee>) {
        setAttendees((a) => a.map((x, j) => (j === i ? { ...x, ...patch } : x)));
    }
    function quitar(i: number) {
        setAttendees((a) => a.filter((_, j) => j !== i));
    }

    function guardar(nuevoEstado?: Estado) {
        router.put(
            route('capacitaciones.update', training.id),
            {
                titulo,
                fecha: fecha || null,
                instructor: instructor || null,
                modalidad,
                duracion_minutos: duracion ? Number(duracion) : null,
                lugar: lugar || null,
                objetivo: objetivo || null,
                estado: nuevoEstado ?? estado,
                observaciones: observaciones || null,
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                attendees: attendees.filter((a) => a.nombres.trim() !== '') as any,
            },
            { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false), onSuccess: () => close() },
        );
    }
    function close() {
        setOpenState(false);
        setTimeout(() => router.get(route('capacitaciones.index'), {}, { preserveScroll: true, preserveState: false }), 150);
    }

    const asistieron = attendees.filter((a) => a.asistio).length;

    return (
        <Dialog open={openState} onOpenChange={(o) => !o && close()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="font-brand">{training.titulo}</DialogTitle>
                    <DialogDescription>Diligencia los datos de la capacitación y el registro de asistencia.</DialogDescription>
                </DialogHeader>

                <div className="space-y-5">
                    {/* Datos de la sesión */}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <Label>Título</Label>
                            <Input value={titulo} onChange={(e) => setTitulo(e.target.value)} disabled={!canManage} />
                        </div>
                        <div>
                            <Label>Fecha</Label>
                            <Input type="date" value={fecha} onChange={(e) => setFecha(e.target.value)} disabled={!canManage} />
                        </div>
                        <div>
                            <Label>Instructor / facilitador</Label>
                            <Input value={instructor} onChange={(e) => setInstructor(e.target.value)} disabled={!canManage} />
                        </div>
                        <div>
                            <Label>Modalidad</Label>
                            <select
                                value={modalidad}
                                onChange={(e) => setModalidad(e.target.value as Modalidad)}
                                disabled={!canManage}
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm disabled:opacity-60"
                            >
                                <option value="presencial">Presencial</option>
                                <option value="virtual">Virtual</option>
                            </select>
                        </div>
                        <div>
                            <Label>Duración (min)</Label>
                            <Input type="number" min={0} value={duracion} onChange={(e) => setDuracion(e.target.value)} disabled={!canManage} />
                        </div>
                        <div className="sm:col-span-2">
                            <Label>Lugar</Label>
                            <Input value={lugar} onChange={(e) => setLugar(e.target.value)} disabled={!canManage} />
                        </div>
                        <div className="sm:col-span-2">
                            <Label>Objetivo</Label>
                            <textarea
                                value={objetivo}
                                onChange={(e) => setObjetivo(e.target.value)}
                                disabled={!canManage}
                                rows={2}
                                className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm disabled:opacity-60"
                            />
                        </div>
                    </div>

                    {/* Registro de asistencia */}
                    <div>
                        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <h3 className="font-brand text-primary text-sm font-bold tracking-tight uppercase">
                                Asistentes ({asistieron}/{attendees.length})
                            </h3>
                            {canManage && (
                                <div className="flex gap-2">
                                    <Button type="button" variant="outline" size="sm" className="gap-1.5" onClick={() => setPicker((v) => !v)} disabled={disponibles.length === 0}>
                                        <Users className="size-3.5" /> Desde empleados
                                    </Button>
                                    <Button type="button" variant="outline" size="sm" className="gap-1.5" onClick={agregarManual}>
                                        <UserPlus className="size-3.5" /> Manual
                                    </Button>
                                </div>
                            )}
                        </div>

                        {picker && (
                            <EmployeePicker employees={disponibles} onAdd={agregarEmpleados} onClose={() => setPicker(false)} />
                        )}

                        {attendees.length === 0 ? (
                            <p className="text-muted-foreground rounded-md border border-dashed py-6 text-center text-sm">
                                Sin asistentes. Agrégalos desde los empleados de la empresa o manualmente.
                            </p>
                        ) : (
                            <div className="divide-border overflow-hidden rounded-md border">
                                <div className="text-muted-foreground grid grid-cols-[1fr_7rem_1fr_4rem_2rem] gap-2 border-b bg-muted/40 px-2 py-1.5 text-[11px] font-semibold">
                                    <span>Nombre</span>
                                    <span>Documento</span>
                                    <span>Cargo</span>
                                    <span className="text-center">Asistió</span>
                                    <span></span>
                                </div>
                                {attendees.map((a, i) => (
                                    <div key={i} className="grid grid-cols-[1fr_7rem_1fr_4rem_2rem] items-center gap-2 border-b px-2 py-1.5 last:border-b-0">
                                        <Input value={a.nombres} onChange={(e) => setAtt(i, { nombres: e.target.value })} disabled={!canManage} className="h-8" placeholder="Nombre" />
                                        <Input value={a.numero_documento ?? ''} onChange={(e) => setAtt(i, { numero_documento: e.target.value })} disabled={!canManage} className="h-8" />
                                        <Input value={a.cargo ?? ''} onChange={(e) => setAtt(i, { cargo: e.target.value })} disabled={!canManage} className="h-8" />
                                        <div className="flex justify-center">
                                            <input type="checkbox" checked={a.asistio} onChange={(e) => setAtt(i, { asistio: e.target.checked })} disabled={!canManage} className="size-4" />
                                        </div>
                                        {canManage && (
                                            <button type="button" onClick={() => quitar(i)} className="text-muted-foreground hover:text-destructive flex justify-center" aria-label="Quitar">
                                                <Trash2 className="size-4" />
                                            </button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    <div>
                        <Label>Observaciones</Label>
                        <textarea
                            value={observaciones}
                            onChange={(e) => setObservaciones(e.target.value)}
                            disabled={!canManage}
                            rows={2}
                            className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm disabled:opacity-60"
                        />
                    </div>
                </div>

                {canManage && (
                    <DialogFooter className="flex-wrap gap-2">
                        <Button variant="outline" onClick={close}>
                            Cerrar
                        </Button>
                        <Button variant="secondary" disabled={saving} onClick={() => guardar('programada')} className="gap-2">
                            <Save className="size-4" /> Guardar
                        </Button>
                        <Button disabled={saving} onClick={() => guardar('realizada')} className="gap-2">
                            <CheckCircle2 className="size-4" /> {saving ? 'Guardando…' : 'Marcar realizada'}
                        </Button>
                    </DialogFooter>
                )}
            </DialogContent>
        </Dialog>
    );
}

function EmployeePicker({ employees, onAdd, onClose }: { employees: EmployeeRow[]; onAdd: (ids: number[]) => void; onClose: () => void }) {
    const [sel, setSel] = useState<Set<number>>(new Set());

    function toggle(id: number) {
        setSel((s) => {
            const n = new Set(s);
            n.has(id) ? n.delete(id) : n.add(id);
            return n;
        });
    }

    return (
        <div className="mb-3 rounded-md border p-2">
            <div className="mb-2 flex items-center justify-between">
                <span className="text-muted-foreground text-xs font-medium">Selecciona empleados</span>
                <button type="button" onClick={() => setSel(new Set(employees.map((e) => e.id)))} className="text-primary text-xs hover:underline">
                    Todos
                </button>
            </div>
            <div className="max-h-48 space-y-1 overflow-y-auto">
                {employees.map((e) => (
                    <label key={e.id} className="hover:bg-muted/50 flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm">
                        <input type="checkbox" checked={sel.has(e.id)} onChange={() => toggle(e.id)} className="size-4" />
                        <span className="flex-1">{e.nombre}</span>
                        <span className="text-muted-foreground text-xs">{e.cargo ?? ''}</span>
                    </label>
                ))}
            </div>
            <div className="mt-2 flex justify-end gap-2">
                <Button type="button" variant="ghost" size="sm" onClick={onClose}>
                    Cancelar
                </Button>
                <Button type="button" size="sm" disabled={sel.size === 0} onClick={() => onAdd([...sel])}>
                    Agregar {sel.size > 0 ? `(${sel.size})` : ''}
                </Button>
            </div>
        </div>
    );
}
