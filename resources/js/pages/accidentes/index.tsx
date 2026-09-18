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
import { CalendarClock, Pencil, Plus, Search, Siren, Skull, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Clase = 'incidente' | 'accidente' | 'casi_accidente';

interface Accidente {
    id: number;
    codigo: string;
    employee_id: number | null;
    clase: Clase;
    fecha: string;
    hora: string | null;
    lugar: string | null;
    area: string | null;
    descripcion: string;
    tipo_lesion: string | null;
    parte_cuerpo: string | null;
    mecanismo: string | null;
    agente: string | null;
    mortal: boolean;
    grave: boolean;
    reportado_arl: boolean;
    fecha_reporte_arl: string | null;
    absence_id: number | null;
    investigado: boolean;
    fecha_investigacion: string | null;
    equipo_investigador: string | null;
    causas_inmediatas: string[] | null;
    causas_basicas: string[] | null;
    causa_raiz: string | null;
    leccion_aprendida: string | null;
    observaciones: string | null;
    /** Calculados por el modelo: plazo de la Res. 1401. */
    dias_para_investigar: number | null;
    investigacion_vencida: boolean;
    employee?: { id: number; nombres: string; apellidos: string; cargo: string | null } | null;
    ausencia?: { id: number; dias: number; fecha_inicio: string } | null;
}

interface EmpleadoRow {
    id: number;
    nombres: string;
    apellidos: string;
    cargo: string | null;
    area: string | null;
}

interface AusenciaRow {
    id: number;
    employee_id: number;
    fecha_inicio: string;
    fecha_fin: string;
    dias: number;
    employee?: { id: number; nombres: string; apellidos: string } | null;
}

interface Props {
    accidentes: Accidente[];
    empleados: EmpleadoRow[];
    ausencias: AusenciaRow[];
    stats: {
        total: number;
        accidentes: number;
        mortales: number;
        sin_investigar: number;
        vencidos: number;
        dias_perdidos: number;
    };
    anio: number;
    catalogos: { clases: Clase[] };
    needsClient: boolean;
}

const ETIQUETA_CLASE: Record<Clase, string> = {
    incidente: 'Incidente',
    accidente: 'Accidente',
    casi_accidente: 'Casi accidente',
};

/** Causas del modelo SCAT que usa CMK: subestándar (inmediatas) y de fondo (básicas). */
const CAUSAS_INMEDIATAS = [
    'Operar sin autorización',
    'No señalizar o advertir',
    'Anular dispositivos de seguridad',
    'Usar equipo defectuoso',
    'No usar el EPP disponible',
    'Adoptar una postura insegura',
    'Levantar cargas de forma incorrecta',
    'Guardas o barreras inadecuadas',
    'EPP inadecuado o insuficiente',
    'Herramientas o equipos defectuosos',
    'Espacio o orden deficientes',
    'Iluminación o ventilación deficientes',
    'Superficie de trabajo en mal estado',
];

const CAUSAS_BASICAS = [
    'Capacidad física inadecuada',
    'Falta de conocimiento',
    'Falta de habilidad',
    'Tensión o fatiga',
    'Motivación inadecuada',
    'Supervisión o liderazgo deficientes',
    'Ingeniería inadecuada',
    'Adquisiciones deficientes',
    'Mantenimiento deficiente',
    'Herramientas o equipos inadecuados',
    'Estándares de trabajo inadecuados',
    'Uso y desgaste normal',
    'Abuso o mal uso',
];

const hoy = () => new Date().toISOString().slice(0, 10);

const emptyForm = {
    employee_id: '' as number | string,
    clase: 'accidente' as Clase,
    fecha: hoy(),
    hora: '',
    lugar: '',
    area: '',
    descripcion: '',
    tipo_lesion: '',
    parte_cuerpo: '',
    mecanismo: '',
    agente: '',
    mortal: false as boolean,
    grave: false as boolean,
    reportado_arl: false as boolean,
    fecha_reporte_arl: '',
    absence_id: '' as number | string,
    investigado: false as boolean,
    fecha_investigacion: '',
    equipo_investigador: '',
    causas_inmediatas: [] as string[],
    causas_basicas: [] as string[],
    causa_raiz: '',
    leccion_aprendida: '',
    observaciones: '',
};

export default function AccidentesIndex({ accidentes, empleados, ausencias, stats, anio, catalogos, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('incidents.manage');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Accidente | null>(null);

    const { data, setData, post, put, processing, errors, clearErrors } = useForm({ ...emptyForm });

    function openCreate() {
        setEditing(null);
        clearErrors();
        setData({ ...emptyForm });
        setOpen(true);
    }

    function openEdit(a: Accidente) {
        setEditing(a);
        clearErrors();
        setData({
            employee_id: a.employee_id ?? '',
            clase: a.clase,
            fecha: a.fecha,
            hora: a.hora ? a.hora.slice(0, 5) : '',
            lugar: a.lugar ?? '',
            area: a.area ?? '',
            descripcion: a.descripcion,
            tipo_lesion: a.tipo_lesion ?? '',
            parte_cuerpo: a.parte_cuerpo ?? '',
            mecanismo: a.mecanismo ?? '',
            agente: a.agente ?? '',
            mortal: a.mortal,
            grave: a.grave,
            reportado_arl: a.reportado_arl,
            fecha_reporte_arl: a.fecha_reporte_arl ?? '',
            absence_id: a.absence_id ?? '',
            investigado: a.investigado,
            fecha_investigacion: a.fecha_investigacion ?? '',
            equipo_investigador: a.equipo_investigador ?? '',
            causas_inmediatas: a.causas_inmediatas ?? [],
            causas_basicas: a.causas_basicas ?? [],
            causa_raiz: a.causa_raiz ?? '',
            leccion_aprendida: a.leccion_aprendida ?? '',
            observaciones: a.observaciones ?? '',
        });
        setOpen(true);
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) put(route('accidentes.update', editing.id), opts);
        else post(route('accidentes.store'), opts);
    };

    function eliminar(a: Accidente) {
        if (confirm(`¿Eliminar el evento ${a.codigo}?`)) {
            router.delete(route('accidentes.destroy', a.id), { preserveScroll: true });
        }
    }

    function alternar(campo: 'causas_inmediatas' | 'causas_basicas', causa: string) {
        const actuales = data[campo];
        setData(campo, actuales.includes(causa) ? actuales.filter((c) => c !== causa) : [...actuales, causa]);
    }

    return (
        <ModuloPage
            titulo="Accidentalidad"
            descripcion={`Accidentes e incidentes de trabajo de ${anio}, con su investigación`}
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button onClick={openCreate} className="gap-2">
                        <Plus className="size-4" /> Nuevo evento
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
                        onChange={(e) => router.get(route('accidentes.index'), { anio: e.target.value }, { preserveScroll: true })}
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
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <StatCard label="Eventos" value={stats.total} icon={Siren} />
                <StatCard label="Accidentes" value={stats.accidentes} icon={Siren} />
                <StatCard label="Mortales" value={stats.mortales} icon={Skull} alerta={stats.mortales > 0} />
                <StatCard label="Sin investigar" value={stats.sin_investigar} icon={Search} alerta={stats.sin_investigar > 0} />
                {/* Los que pasaron los 15 días calendario de la Res. 1401: es la
                    cifra que le cuesta una sanción al cliente. */}
                <StatCard
                    label="Fuera del plazo legal"
                    value={stats.vencidos}
                    icon={CalendarClock}
                    alerta={stats.vencidos > 0}
                />
            </div>

            <div className="text-muted-foreground text-sm">
                Días perdidos del año: <span className="text-foreground font-medium tabular-nums">{stats.dias_perdidos}</span>. Salen de
                las ausencias enlazadas, no de un campo propio.
            </div>

            <Card>
                <CardContent className="p-0">
                    {accidentes.length === 0 ? (
                        <p className="text-muted-foreground p-8 text-center text-sm">No hay eventos registrados en {anio}.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2.5 font-semibold">Código</th>
                                        <th className="px-4 py-2.5 font-semibold">Fecha</th>
                                        <th className="px-4 py-2.5 font-semibold">Clase</th>
                                        <th className="px-4 py-2.5 font-semibold">Trabajador</th>
                                        <th className="px-4 py-2.5 font-semibold">Descripción</th>
                                        <th className="px-4 py-2.5 font-semibold">Días</th>
                                        <th className="px-4 py-2.5 font-semibold">Investigación</th>
                                        {canManage && <th className="px-4 py-2.5" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {accidentes.map((a) => (
                                        <tr key={a.id} className="border-t">
                                            <td className="px-4 py-2.5 font-medium tabular-nums">{a.codigo}</td>
                                            <td className="px-4 py-2.5 tabular-nums whitespace-nowrap">
                                                {a.fecha}
                                                {a.hora && <span className="text-muted-foreground block text-xs">{a.hora.slice(0, 5)}</span>}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                {ETIQUETA_CLASE[a.clase]}
                                                {a.mortal && (
                                                    <Badge className="bg-destructive/15 text-destructive ml-1 font-normal">mortal</Badge>
                                                )}
                                                {!a.mortal && a.grave && (
                                                    <Badge className="ml-1 bg-orange-500/15 font-normal text-orange-700 dark:text-orange-400">
                                                        grave
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                {a.employee ? `${a.employee.apellidos} ${a.employee.nombres}` : '—'}
                                            </td>
                                            <td className="max-w-sm px-4 py-2.5">
                                                <div className="truncate">{a.descripcion}</div>
                                            </td>
                                            <td className="px-4 py-2.5 tabular-nums">{a.ausencia?.dias ?? '—'}</td>
                                            <td className="px-4 py-2.5">
                                                {a.investigado ? (
                                                    <Badge className="bg-emerald-500/15 font-normal text-emerald-700 dark:text-emerald-400">
                                                        investigado
                                                    </Badge>
                                                ) : a.investigacion_vencida ? (
                                                    <span className="text-destructive text-xs">
                                                        vencida hace {Math.abs(a.dias_para_investigar ?? 0)} d
                                                    </span>
                                                ) : (
                                                    <span className="text-xs text-amber-600 dark:text-amber-500">
                                                        quedan {a.dias_para_investigar} d
                                                    </span>
                                                )}
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
                        <DialogTitle>{editing ? `Evento ${editing.codigo}` : 'Nuevo evento'}</DialogTitle>
                        <DialogDescription>
                            El plazo legal para investigar es de 15 días calendario (Res. 1401 de 2007).
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-4">
                            <div className="grid gap-2">
                                <Label htmlFor="clase">Clase</Label>
                                <select
                                    id="clase"
                                    value={data.clase}
                                    onChange={(e) => setData('clase', e.target.value as Clase)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    {catalogos.clases.map((c) => (
                                        <option key={c} value={c}>
                                            {ETIQUETA_CLASE[c]}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fecha">Fecha</Label>
                                <Input id="fecha" type="date" value={data.fecha} onChange={(e) => setData('fecha', e.target.value)} />
                                <InputError message={errors.fecha} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="hora">Hora</Label>
                                <Input id="hora" type="time" value={data.hora} onChange={(e) => setData('hora', e.target.value)} />
                                <InputError message={errors.hora} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="employee_id">Trabajador</Label>
                                <select
                                    id="employee_id"
                                    value={data.employee_id}
                                    onChange={(e) => setData('employee_id', e.target.value)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    <option value="">Sin asignar</option>
                                    {empleados.map((e) => (
                                        <option key={e.id} value={e.id}>
                                            {e.apellidos} {e.nombres}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="area">Área</Label>
                                <Input id="area" value={data.area} onChange={(e) => setData('area', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="lugar">Lugar exacto</Label>
                                <Input id="lugar" value={data.lugar} onChange={(e) => setData('lugar', e.target.value)} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="descripcion">Qué pasó</Label>
                            <textarea
                                id="descripcion"
                                value={data.descripcion}
                                onChange={(e) => setData('descripcion', e.target.value)}
                                rows={3}
                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                            />
                            <InputError message={errors.descripcion} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-4">
                            <div className="grid gap-2">
                                <Label htmlFor="tipo_lesion">Tipo de lesión</Label>
                                <Input id="tipo_lesion" value={data.tipo_lesion} onChange={(e) => setData('tipo_lesion', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="parte_cuerpo">Parte del cuerpo</Label>
                                <Input id="parte_cuerpo" value={data.parte_cuerpo} onChange={(e) => setData('parte_cuerpo', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="mecanismo">Mecanismo</Label>
                                <Input
                                    id="mecanismo"
                                    value={data.mecanismo}
                                    onChange={(e) => setData('mecanismo', e.target.value)}
                                    placeholder="Caída, atrapamiento…"
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="agente">Agente</Label>
                                <Input
                                    id="agente"
                                    value={data.agente}
                                    onChange={(e) => setData('agente', e.target.value)}
                                    placeholder="Máquina, herramienta…"
                                />
                            </div>
                        </div>

                        <div className="bg-muted/40 grid gap-3 rounded-md p-3">
                            <div className="flex flex-wrap gap-x-6 gap-y-2">
                                <label className="flex cursor-pointer items-center gap-2 text-sm">
                                    <Checkbox checked={data.mortal} onCheckedChange={(v) => setData('mortal', v === true)} />
                                    <span>Evento mortal</span>
                                </label>
                                <label className="flex cursor-pointer items-center gap-2 text-sm">
                                    {/* Un mortal es grave por definición: el modelo lo fuerza, así que
                                        aquí la casilla se marca y se bloquea para no dar a entender
                                        que se puede desmarcar. */}
                                    <Checkbox
                                        checked={data.grave || data.mortal}
                                        disabled={data.mortal}
                                        onCheckedChange={(v) => setData('grave', v === true)}
                                    />
                                    <span>Evento grave</span>
                                </label>
                                <label className="flex cursor-pointer items-center gap-2 text-sm">
                                    <Checkbox checked={data.reportado_arl} onCheckedChange={(v) => setData('reportado_arl', v === true)} />
                                    <span>Reportado a la ARL</span>
                                </label>
                            </div>
                            {data.reportado_arl && (
                                <div className="grid gap-2 sm:max-w-xs">
                                    <Label htmlFor="fecha_reporte_arl">Fecha del reporte a la ARL</Label>
                                    <Input
                                        id="fecha_reporte_arl"
                                        type="date"
                                        value={data.fecha_reporte_arl}
                                        onChange={(e) => setData('fecha_reporte_arl', e.target.value)}
                                    />
                                </div>
                            )}
                        </div>

                        {/* Los días perdidos no se teclean aquí: se toman de la ausencia
                            enlazada, para que no existan dos versiones del mismo número. */}
                        <div className="grid gap-2">
                            <Label htmlFor="absence_id">Incapacidad asociada</Label>
                            <select
                                id="absence_id"
                                value={data.absence_id}
                                onChange={(e) => setData('absence_id', e.target.value)}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                <option value="">Sin incapacidad</option>
                                {ausencias.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.employee ? `${a.employee.apellidos} ${a.employee.nombres} — ` : ''}
                                        {a.fecha_inicio} a {a.fecha_fin} ({a.dias} d)
                                    </option>
                                ))}
                            </select>
                            <p className="text-muted-foreground text-xs">
                                Los días perdidos salen de aquí. Registra primero la ausencia en el módulo de Ausentismo.
                            </p>
                        </div>

                        {/* ------------------------------------------- investigación */}
                        <div className="rounded-md border">
                            <label className="flex cursor-pointer items-center gap-3 px-4 py-3">
                                <Checkbox checked={data.investigado} onCheckedChange={(v) => setData('investigado', v === true)} />
                                <span className="text-sm font-medium">
                                    Investigación realizada
                                    <span className="text-muted-foreground block text-xs">
                                        Plazo legal: 15 días calendario desde que ocurrió.
                                    </span>
                                </span>
                            </label>

                            {data.investigado && (
                                <div className="space-y-4 border-t px-4 py-4">
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="fecha_investigacion">Fecha de la investigación</Label>
                                            <Input
                                                id="fecha_investigacion"
                                                type="date"
                                                value={data.fecha_investigacion}
                                                onChange={(e) => setData('fecha_investigacion', e.target.value)}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="equipo_investigador">Equipo investigador</Label>
                                            <Input
                                                id="equipo_investigador"
                                                value={data.equipo_investigador}
                                                onChange={(e) => setData('equipo_investigador', e.target.value)}
                                                placeholder="Res. 1401: jefe inmediato, representante del COPASST y profesional de SST."
                                            />
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label>Causas inmediatas</Label>
                                        <p className="text-muted-foreground text-xs">Actos y condiciones subestándar: lo que se ve.</p>
                                        <div className="grid gap-1.5 sm:grid-cols-2">
                                            {CAUSAS_INMEDIATAS.map((c) => (
                                                <label key={c} className="flex cursor-pointer items-center gap-2 text-sm">
                                                    <Checkbox
                                                        checked={data.causas_inmediatas.includes(c)}
                                                        onCheckedChange={() => alternar('causas_inmediatas', c)}
                                                    />
                                                    <span>{c}</span>
                                                </label>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label>Causas básicas</Label>
                                        <p className="text-muted-foreground text-xs">
                                            Factores personales y del trabajo: por qué existieron las inmediatas.
                                        </p>
                                        <div className="grid gap-1.5 sm:grid-cols-2">
                                            {CAUSAS_BASICAS.map((c) => (
                                                <label key={c} className="flex cursor-pointer items-center gap-2 text-sm">
                                                    <Checkbox
                                                        checked={data.causas_basicas.includes(c)}
                                                        onCheckedChange={() => alternar('causas_basicas', c)}
                                                    />
                                                    <span>{c}</span>
                                                </label>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="causa_raiz">Causa raíz</Label>
                                        <textarea
                                            id="causa_raiz"
                                            value={data.causa_raiz}
                                            onChange={(e) => setData('causa_raiz', e.target.value)}
                                            rows={2}
                                            placeholder="La falla de fondo que, corregida, evita que vuelva a pasar."
                                            className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="leccion_aprendida">Lección aprendida</Label>
                                        <textarea
                                            id="leccion_aprendida"
                                            value={data.leccion_aprendida}
                                            onChange={(e) => setData('leccion_aprendida', e.target.value)}
                                            rows={2}
                                            placeholder="Lo que se le comunica al resto de la organización."
                                            className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                                        />
                                    </div>

                                    <p className="text-muted-foreground text-xs">
                                        Las acciones que salgan de esta investigación se registran en ACPM, con origen «Accidente de
                                        trabajo».
                                    </p>
                                </div>
                            )}
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
                                {editing ? 'Guardar' : 'Registrar evento'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ModuloPage>
    );
}
