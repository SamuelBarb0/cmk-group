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
import { ArrowLeftRight, CalendarClock, CheckCircle2, Clock, PenLine, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Estado = 'pendiente_aprobacion' | 'aprobado' | 'rechazado' | 'cerrado';
type SiNo = boolean | '';

interface FilaAnalisis {
    [key: string]: string | number | null | undefined;
    actividad: string;
    costo: number | string | null;
    observaciones: string;
}

interface Accion {
    [key: string]: string | boolean | undefined;
    descripcion: string;
    responsable: string;
    fecha_compromiso: string;
    ejecutada: boolean;
    fecha_ejecucion: string;
    observacion: string;
}

interface Cambio {
    id: number;
    fecha_solicitud: string;
    solicitante: string;
    cargo_solicitante: string | null;
    area: string;
    procesos_involucrados: string | null;
    tipo: string;
    tipo_otro: string | null;
    condicion: string;
    descripcion: string;
    fecha_limite: string | null;
    analisis: Record<string, FilaAnalisis> | null;
    costo_presupuestado: string | null;
    costo_ejecutado: string | null;
    requiere_actualizar_iperc: boolean;
    iperc_actualizada_at: string | null;
    requiere_capacitacion: boolean;
    aprobacion_gerencia_nombre: string | null;
    aprobacion_gerencia_fecha: string | null;
    aprobacion_sst_nombre: string | null;
    aprobacion_sst_fecha: string | null;
    rechazado_at: string | null;
    motivo_rechazo: string | null;
    cierre_fecha: string | null;
    cierre_implementado: boolean | null;
    cierre_a_tiempo: boolean | null;
    cierre_eficaz: boolean | null;
    cierre_justificacion: string | null;
    observaciones: string | null;
    estado: Estado;
    vencido: boolean;
    actions: {
        descripcion: string;
        responsable: string | null;
        fecha_compromiso: string | null;
        ejecutada: boolean;
        fecha_ejecucion: string | null;
        observacion: string | null;
    }[];
}

interface Props {
    cambios: Cambio[];
    stats: { pendientes: number; en_curso: number; vencidos: number; cerrados: number; eficacia: number | null };
    catalogos: { tipos: string[]; condiciones: string[]; elementos: Record<string, string> };
    needsClient: boolean;
}

const ETIQUETA_TIPO: Record<string, string> = {
    infraestructura: 'Infraestructura',
    requisito_legal: 'Requisito legal o contractual',
    proceso: 'Procesos, actividades o materiales',
    personal: 'Personal',
    contratista: 'Contratistas o proveedores',
    pesv: 'Seguridad vial (PESV)',
    sistema_gestion: 'Sistema de gestión',
    otro: 'Otro',
};

const ETIQUETA_CONDICION: Record<string, string> = {
    temporal: 'Temporal',
    fijo: 'Fijo',
    ciclico: 'Cíclico',
    emergencia: 'Emergencia',
};

const ESTADO: Record<Estado, { label: string; clase: string }> = {
    pendiente_aprobacion: { label: 'Pendiente de aprobación', clase: 'bg-amber-500/15 text-amber-700 dark:text-amber-400' },
    aprobado: { label: 'En implementación', clase: 'bg-sky-500/15 text-sky-700 dark:text-sky-400' },
    rechazado: { label: 'Rechazado', clase: 'bg-muted text-muted-foreground' },
    cerrado: { label: 'Cerrado', clase: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400' },
};

const SELECT = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const AREA = 'border-input bg-background w-full rounded-md border px-3 py-2 text-sm';

const hoy = () => new Date().toISOString().slice(0, 10);

const vacio = {
    fecha_solicitud: hoy(),
    solicitante: '',
    cargo_solicitante: '',
    area: '',
    procesos_involucrados: '',
    tipo: 'proceso',
    tipo_otro: '',
    condicion: 'fijo',
    descripcion: '',
    fecha_limite: '',
    analisis: {} as Record<string, FilaAnalisis>,
    costo_presupuestado: '' as number | string,
    costo_ejecutado: '' as number | string,
    requiere_actualizar_iperc: false as boolean,
    iperc_actualizada_at: '',
    requiere_capacitacion: false as boolean,
    aprobacion_gerencia_nombre: '',
    aprobacion_gerencia_fecha: '',
    aprobacion_sst_nombre: '',
    aprobacion_sst_fecha: '',
    rechazado_at: '',
    motivo_rechazo: '',
    cierre_fecha: '',
    cierre_implementado: '' as SiNo,
    cierre_a_tiempo: '' as SiNo,
    cierre_eficaz: '' as SiNo,
    cierre_justificacion: '',
    observaciones: '',
    actions: [] as Accion[],
};

const aSiNo = (v: boolean | null): SiNo => (v === null ? '' : v);

export default function GestionCambioIndex({ cambios, stats, catalogos, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const [filtro, setFiltro] = useState<'todos' | Estado>('todos');
    const [dlg, setDlg] = useState(false);
    const [edit, setEdit] = useState<Cambio | null>(null);
    const f = useForm({ ...vacio });

    function abrir(c?: Cambio) {
        setEdit(c ?? null);
        f.clearErrors();
        f.setData(
            c
                ? {
                      fecha_solicitud: c.fecha_solicitud,
                      solicitante: c.solicitante,
                      cargo_solicitante: c.cargo_solicitante ?? '',
                      area: c.area,
                      procesos_involucrados: c.procesos_involucrados ?? '',
                      tipo: c.tipo,
                      tipo_otro: c.tipo_otro ?? '',
                      condicion: c.condicion,
                      descripcion: c.descripcion,
                      fecha_limite: c.fecha_limite ?? '',
                      analisis: c.analisis ?? {},
                      costo_presupuestado: c.costo_presupuestado ?? '',
                      costo_ejecutado: c.costo_ejecutado ?? '',
                      requiere_actualizar_iperc: c.requiere_actualizar_iperc,
                      iperc_actualizada_at: c.iperc_actualizada_at ?? '',
                      requiere_capacitacion: c.requiere_capacitacion,
                      aprobacion_gerencia_nombre: c.aprobacion_gerencia_nombre ?? '',
                      aprobacion_gerencia_fecha: c.aprobacion_gerencia_fecha ?? '',
                      aprobacion_sst_nombre: c.aprobacion_sst_nombre ?? '',
                      aprobacion_sst_fecha: c.aprobacion_sst_fecha ?? '',
                      rechazado_at: c.rechazado_at ?? '',
                      motivo_rechazo: c.motivo_rechazo ?? '',
                      cierre_fecha: c.cierre_fecha ?? '',
                      cierre_implementado: aSiNo(c.cierre_implementado),
                      cierre_a_tiempo: aSiNo(c.cierre_a_tiempo),
                      cierre_eficaz: aSiNo(c.cierre_eficaz),
                      cierre_justificacion: c.cierre_justificacion ?? '',
                      observaciones: c.observaciones ?? '',
                      actions: c.actions.map((a) => ({
                          descripcion: a.descripcion,
                          responsable: a.responsable ?? '',
                          fecha_compromiso: a.fecha_compromiso ?? '',
                          ejecutada: a.ejecutada,
                          fecha_ejecucion: a.fecha_ejecucion ?? '',
                          observacion: a.observacion ?? '',
                      })),
                  }
                : { ...vacio, analisis: {}, actions: [] },
        );
        setDlg(true);
    }

    function setFila(clave: string, campo: keyof FilaAnalisis, valor: string) {
        const fila = f.data.analisis[clave] ?? { actividad: '', costo: '', observaciones: '' };
        f.setData('analisis', { ...f.data.analisis, [clave]: { ...fila, [campo]: valor } });
    }

    function setAccion(i: number, campo: keyof Accion, valor: string | boolean) {
        f.setData(
            'actions',
            f.data.actions.map((a, j) => (j === i ? { ...a, [campo]: valor } : a)),
        );
    }

    const guardar: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setDlg(false) };
        if (edit) f.put(route('gestion-cambio.update', edit.id), opts);
        else f.post(route('gestion-cambio.store'), opts);
    };

    const aprobadoEnFormulario = !!f.data.aprobacion_gerencia_fecha && !!f.data.aprobacion_sst_fecha;
    const errores = f.errors as Record<string, string | undefined>;
    const visibles = filtro === 'todos' ? cambios : cambios.filter((c) => c.estado === filtro);

    const siNoSelect = (id: string, label: string, campo: 'cierre_implementado' | 'cierre_a_tiempo' | 'cierre_eficaz') => (
        <Campo id={id} label={label} error={errores[campo]}>
            <select
                id={id}
                value={f.data[campo] === '' ? '' : f.data[campo] ? 'si' : 'no'}
                onChange={(e) => f.setData(campo, e.target.value === '' ? '' : e.target.value === 'si')}
                className={SELECT}
            >
                <option value="">—</option>
                <option value="si">Sí</option>
                <option value="no">No</option>
            </select>
        </Campo>
    );

    return (
        <ModuloPage
            titulo="Gestión del cambio"
            descripcion="Solicitud, aprobación de Gerencia y SG-SST, plan de acción y cierre (estándar 2.11.1)"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button onClick={() => abrir()} className="gap-2">
                        <Plus className="size-4" /> Registrar cambio
                    </Button>
                ) : undefined
            }
            filtros={
                <div className="flex flex-wrap gap-1">
                    {(['todos', 'pendiente_aprobacion', 'aprobado', 'cerrado', 'rechazado'] as const).map((k) => (
                        <button
                            key={k}
                            type="button"
                            onClick={() => setFiltro(k)}
                            className={cn('rounded-md px-3 py-1.5 text-sm', filtro === k ? 'bg-primary text-primary-foreground' : 'hover:bg-muted')}
                        >
                            {k === 'todos' ? 'Todos' : ESTADO[k].label}
                        </button>
                    ))}
                </div>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Pendientes de aprobación" value={stats.pendientes} icon={Clock} alerta={stats.pendientes > 0} />
                <StatCard label="En implementación" value={stats.en_curso} icon={ArrowLeftRight} />
                <StatCard label="Vencidos sin cerrar" value={stats.vencidos} icon={CalendarClock} alerta={stats.vencidos > 0} />
                <StatCard
                    label={`Cerrados eficaces (${stats.cerrados} cerrados)`}
                    value={stats.eficacia === null ? '—' : `${stats.eficacia} %`}
                    icon={CheckCircle2}
                />
            </div>

            <Card>
                <CardContent className="p-0">
                    {visibles.length === 0 ? (
                        <p className="text-muted-foreground p-8 text-center text-sm">
                            {cambios.length === 0
                                ? 'No hay cambios registrados. Todo cambio que afecte peligros, procesos, personal o requisitos legales se gestiona aquí.'
                                : 'Ningún cambio en este estado.'}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2.5 font-semibold">Fecha</th>
                                        <th className="px-4 py-2.5 font-semibold">Cambio</th>
                                        <th className="px-4 py-2.5 font-semibold">Tipo</th>
                                        <th className="px-4 py-2.5 font-semibold">Aprobación</th>
                                        <th className="px-4 py-2.5 font-semibold">Plan</th>
                                        <th className="px-4 py-2.5 font-semibold">Estado</th>
                                        {canManage && <th className="px-4 py-2.5" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {visibles.map((c) => {
                                        const hechas = c.actions.filter((a) => a.ejecutada).length;
                                        return (
                                            <tr key={c.id} className="border-t align-top">
                                                <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">{c.fecha_solicitud}</td>
                                                <td className="max-w-md px-4 py-2.5">
                                                    <div className="line-clamp-2 font-medium">{c.descripcion}</div>
                                                    <div className="text-muted-foreground text-xs">
                                                        {c.area} · {c.solicitante}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {c.tipo === 'otro' && c.tipo_otro ? c.tipo_otro : ETIQUETA_TIPO[c.tipo]}
                                                    <div className="text-muted-foreground text-xs">{ETIQUETA_CONDICION[c.condicion]}</div>
                                                </td>
                                                <td className="px-4 py-2.5 text-xs whitespace-nowrap">
                                                    <Firma ok={!!c.aprobacion_gerencia_fecha} label="Gerencia" />
                                                    <Firma ok={!!c.aprobacion_sst_fecha} label="SG-SST" />
                                                </td>
                                                <td className="px-4 py-2.5 tabular-nums">
                                                    {c.actions.length === 0 ? '—' : `${hechas} / ${c.actions.length}`}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <Badge className={cn('font-normal', ESTADO[c.estado].clase)}>{ESTADO[c.estado].label}</Badge>
                                                    {c.vencido && <div className="text-destructive mt-1 text-xs">vencido ({c.fecha_limite})</div>}
                                                    {c.estado === 'cerrado' && c.cierre_eficaz === false && (
                                                        <div className="text-destructive mt-1 text-xs">no fue eficaz</div>
                                                    )}
                                                </td>
                                                {canManage && (
                                                    <td className="px-4 py-2.5">
                                                        <div className="flex justify-end gap-1">
                                                            <Button size="icon" variant="ghost" onClick={() => abrir(c)} aria-label="Editar">
                                                                <PenLine className="size-4" />
                                                            </Button>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label="Eliminar"
                                                                onClick={() =>
                                                                    confirm('¿Eliminar este cambio y su plan de acción?') &&
                                                                    router.delete(route('gestion-cambio.destroy', c.id), { preserveScroll: true })
                                                                }
                                                            >
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

            <Dialog open={dlg} onOpenChange={setDlg}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>{edit ? 'Editar cambio' : 'Registrar cambio'}</DialogTitle>
                        <DialogDescription>
                            Solicitud y autorización para la gestión del cambio. Ningún cambio se ejecuta sin la aprobación de la Gerencia y del
                            encargado del SG-SST.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={guardar} className="space-y-6">
                        {/* ---------------------------------------- 1. solicitud */}
                        <Seccion titulo="1. Información general">
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Campo id="c-fecha" label="Fecha" error={errores.fecha_solicitud}>
                                    <Input
                                        id="c-fecha"
                                        type="date"
                                        value={f.data.fecha_solicitud}
                                        onChange={(e) => f.setData('fecha_solicitud', e.target.value)}
                                    />
                                </Campo>
                                <Campo id="c-sol" label="Solicitante" error={errores.solicitante}>
                                    <Input id="c-sol" value={f.data.solicitante} onChange={(e) => f.setData('solicitante', e.target.value)} />
                                </Campo>
                                <Campo id="c-cargo" label="Cargo" error={errores.cargo_solicitante}>
                                    <Input
                                        id="c-cargo"
                                        value={f.data.cargo_solicitante}
                                        onChange={(e) => f.setData('cargo_solicitante', e.target.value)}
                                    />
                                </Campo>
                                <Campo id="c-area" label="Proceso donde se genera" error={errores.area}>
                                    <Input id="c-area" value={f.data.area} onChange={(e) => f.setData('area', e.target.value)} />
                                </Campo>
                                <Campo id="c-inv" label="Procesos involucrados" error={errores.procesos_involucrados} className="sm:col-span-2">
                                    <Input
                                        id="c-inv"
                                        value={f.data.procesos_involucrados}
                                        onChange={(e) => f.setData('procesos_involucrados', e.target.value)}
                                    />
                                </Campo>
                                <Campo id="c-tipo" label="Tipo de cambio" error={errores.tipo}>
                                    <select id="c-tipo" value={f.data.tipo} onChange={(e) => f.setData('tipo', e.target.value)} className={SELECT}>
                                        {catalogos.tipos.map((t) => (
                                            <option key={t} value={t}>
                                                {ETIQUETA_TIPO[t] ?? t}
                                            </option>
                                        ))}
                                    </select>
                                </Campo>
                                {f.data.tipo === 'otro' ? (
                                    <Campo id="c-otro" label="¿Cuál?" error={errores.tipo_otro}>
                                        <Input id="c-otro" value={f.data.tipo_otro} onChange={(e) => f.setData('tipo_otro', e.target.value)} />
                                    </Campo>
                                ) : (
                                    <div />
                                )}
                                <Campo id="c-cond" label="Condición" error={errores.condicion}>
                                    <select
                                        id="c-cond"
                                        value={f.data.condicion}
                                        onChange={(e) => f.setData('condicion', e.target.value)}
                                        className={SELECT}
                                    >
                                        {catalogos.condiciones.map((c) => (
                                            <option key={c} value={c}>
                                                {ETIQUETA_CONDICION[c] ?? c}
                                            </option>
                                        ))}
                                    </select>
                                </Campo>
                            </div>
                            <Campo id="c-desc" label="Descripción del cambio" error={errores.descripcion}>
                                <textarea
                                    id="c-desc"
                                    rows={3}
                                    value={f.data.descripcion}
                                    onChange={(e) => f.setData('descripcion', e.target.value)}
                                    className={AREA}
                                />
                            </Campo>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Campo id="c-lim" label="Fecha límite de implementación" error={errores.fecha_limite}>
                                    <Input
                                        id="c-lim"
                                        type="date"
                                        value={f.data.fecha_limite}
                                        onChange={(e) => f.setData('fecha_limite', e.target.value)}
                                    />
                                </Campo>
                            </div>
                        </Seccion>

                        {/* ---------------------------------------- 2. análisis */}
                        <Seccion titulo="2. Análisis para la gestión del cambio">
                            <div className="overflow-x-auto rounded-md border">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                        <tr>
                                            <th className="px-3 py-2 font-semibold">Elemento</th>
                                            <th className="px-3 py-2 font-semibold">Cambio / actividad</th>
                                            <th className="w-32 px-3 py-2 font-semibold">Costo</th>
                                            <th className="px-3 py-2 font-semibold">Observaciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {Object.entries(catalogos.elementos).map(([clave, nombre]) => (
                                            <tr key={clave} className="border-t">
                                                <td className="px-3 py-1.5 text-xs font-medium whitespace-nowrap">{nombre}</td>
                                                <td className="px-3 py-1.5">
                                                    <Input
                                                        aria-label={`${nombre}: actividad`}
                                                        value={String(f.data.analisis[clave]?.actividad ?? '')}
                                                        onChange={(e) => setFila(clave, 'actividad', e.target.value)}
                                                    />
                                                </td>
                                                <td className="px-3 py-1.5">
                                                    <Input
                                                        type="number"
                                                        min={0}
                                                        aria-label={`${nombre}: costo`}
                                                        value={String(f.data.analisis[clave]?.costo ?? '')}
                                                        onChange={(e) => setFila(clave, 'costo', e.target.value)}
                                                    />
                                                </td>
                                                <td className="px-3 py-1.5">
                                                    <Input
                                                        aria-label={`${nombre}: observaciones`}
                                                        value={String(f.data.analisis[clave]?.observaciones ?? '')}
                                                        onChange={(e) => setFila(clave, 'observaciones', e.target.value)}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Campo id="c-cp" label="Costo presupuestado ($)" error={errores.costo_presupuestado}>
                                    <Input
                                        id="c-cp"
                                        type="number"
                                        min={0}
                                        value={f.data.costo_presupuestado}
                                        onChange={(e) => f.setData('costo_presupuestado', e.target.value)}
                                    />
                                </Campo>
                                <Campo id="c-ce" label="Costo ejecutado ($)" error={errores.costo_ejecutado}>
                                    <Input
                                        id="c-ce"
                                        type="number"
                                        min={0}
                                        value={f.data.costo_ejecutado}
                                        onChange={(e) => f.setData('costo_ejecutado', e.target.value)}
                                    />
                                </Campo>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Casilla
                                    label="Exige actualizar la matriz IPERC"
                                    checked={f.data.requiere_actualizar_iperc}
                                    onChange={(v) => f.setData('requiere_actualizar_iperc', v)}
                                />
                                {f.data.requiere_actualizar_iperc ? (
                                    <Campo id="c-iperc" label="IPERC actualizada el" error={errores.iperc_actualizada_at}>
                                        <Input
                                            id="c-iperc"
                                            type="date"
                                            value={f.data.iperc_actualizada_at}
                                            onChange={(e) => f.setData('iperc_actualizada_at', e.target.value)}
                                        />
                                    </Campo>
                                ) : (
                                    <div />
                                )}
                                <Casilla
                                    label="Requiere capacitación o inducción"
                                    checked={f.data.requiere_capacitacion}
                                    onChange={(v) => f.setData('requiere_capacitacion', v)}
                                />
                            </div>
                        </Seccion>

                        {/* ---------------------------------------- 3. aprobación */}
                        <Seccion titulo="3. Aprobación">
                            <div className="grid gap-4 sm:grid-cols-4">
                                <Campo id="c-gn" label="Gerencia: nombre" error={errores.aprobacion_gerencia_nombre}>
                                    <Input
                                        id="c-gn"
                                        value={f.data.aprobacion_gerencia_nombre}
                                        onChange={(e) => f.setData('aprobacion_gerencia_nombre', e.target.value)}
                                    />
                                </Campo>
                                <Campo id="c-gf" label="Gerencia: fecha" error={errores.aprobacion_gerencia_fecha}>
                                    <Input
                                        id="c-gf"
                                        type="date"
                                        value={f.data.aprobacion_gerencia_fecha}
                                        onChange={(e) => f.setData('aprobacion_gerencia_fecha', e.target.value)}
                                    />
                                </Campo>
                                <Campo id="c-sn" label="SG-SST: nombre" error={errores.aprobacion_sst_nombre}>
                                    <Input
                                        id="c-sn"
                                        value={f.data.aprobacion_sst_nombre}
                                        onChange={(e) => f.setData('aprobacion_sst_nombre', e.target.value)}
                                    />
                                </Campo>
                                <Campo id="c-sf" label="SG-SST: fecha" error={errores.aprobacion_sst_fecha}>
                                    <Input
                                        id="c-sf"
                                        type="date"
                                        value={f.data.aprobacion_sst_fecha}
                                        onChange={(e) => f.setData('aprobacion_sst_fecha', e.target.value)}
                                    />
                                </Campo>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-4">
                                <Campo id="c-rf" label="Rechazado el" error={errores.rechazado_at}>
                                    <Input
                                        id="c-rf"
                                        type="date"
                                        value={f.data.rechazado_at}
                                        onChange={(e) => f.setData('rechazado_at', e.target.value)}
                                    />
                                </Campo>
                                {f.data.rechazado_at && (
                                    <Campo id="c-rm" label="Motivo del rechazo" error={errores.motivo_rechazo} className="sm:col-span-3">
                                        <Input
                                            id="c-rm"
                                            value={f.data.motivo_rechazo}
                                            onChange={(e) => f.setData('motivo_rechazo', e.target.value)}
                                        />
                                    </Campo>
                                )}
                            </div>
                        </Seccion>

                        {/* ---------------------------------------- 4. plan */}
                        <Seccion
                            titulo="4. Plan de acción y seguimiento"
                            accion={
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="gap-1"
                                    onClick={() =>
                                        f.setData('actions', [
                                            ...f.data.actions,
                                            {
                                                descripcion: '',
                                                responsable: '',
                                                fecha_compromiso: '',
                                                ejecutada: false,
                                                fecha_ejecucion: '',
                                                observacion: '',
                                            },
                                        ])
                                    }
                                >
                                    <Plus className="size-3.5" /> Agregar actividad
                                </Button>
                            }
                        >
                            {f.data.actions.length === 0 && <p className="text-muted-foreground text-xs">Sin actividades.</p>}
                            {f.data.actions.map((a, i) => (
                                <div key={i} className="grid gap-2 rounded-md border p-2 sm:grid-cols-[1fr_10rem_9rem_auto_auto] sm:items-start">
                                    <div className="space-y-1">
                                        <Input
                                            placeholder="Actividad"
                                            aria-label={`Actividad ${i + 1}`}
                                            value={a.descripcion}
                                            onChange={(e) => setAccion(i, 'descripcion', e.target.value)}
                                        />
                                        <Input
                                            placeholder="Observación / hallazgo del seguimiento"
                                            value={a.observacion}
                                            onChange={(e) => setAccion(i, 'observacion', e.target.value)}
                                        />
                                        <InputError message={errores[`actions.${i}.descripcion`]} />
                                    </div>
                                    <Input
                                        placeholder="Responsable"
                                        value={a.responsable}
                                        onChange={(e) => setAccion(i, 'responsable', e.target.value)}
                                    />
                                    <Input
                                        type="date"
                                        aria-label="Fecha compromiso"
                                        value={a.fecha_compromiso}
                                        onChange={(e) => setAccion(i, 'fecha_compromiso', e.target.value)}
                                    />
                                    <Casilla label="Ejecutada" checked={a.ejecutada} onChange={(v) => setAccion(i, 'ejecutada', v)} />
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        aria-label="Quitar actividad"
                                        onClick={() =>
                                            f.setData(
                                                'actions',
                                                f.data.actions.filter((_, j) => j !== i),
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            ))}
                        </Seccion>

                        {/* ---------------------------------------- 5. cierre */}
                        <Seccion titulo="5. Evaluación de cierre">
                            {!aprobadoEnFormulario && (
                                <p className="text-xs text-amber-700 dark:text-amber-400">
                                    Para cerrarlo faltan aprobaciones: la Gerencia y el encargado del SG-SST deben haberlo aprobado.
                                </p>
                            )}
                            <div className="grid gap-4 sm:grid-cols-4">
                                <Campo id="c-cf" label="Fecha de cierre" error={errores.cierre_fecha}>
                                    <Input
                                        id="c-cf"
                                        type="date"
                                        value={f.data.cierre_fecha}
                                        onChange={(e) => f.setData('cierre_fecha', e.target.value)}
                                    />
                                </Campo>
                                {siNoSelect('c-ci', '¿Se implementaron las actividades?', 'cierre_implementado')}
                                {siNoSelect('c-ct', '¿En el tiempo establecido?', 'cierre_a_tiempo')}
                                {siNoSelect('c-ce2', '¿Fue eficaz?', 'cierre_eficaz')}
                            </div>
                            <Campo id="c-cj" label="Justificación (si alguna respuesta es «No»)" error={errores.cierre_justificacion}>
                                <textarea
                                    id="c-cj"
                                    rows={2}
                                    value={f.data.cierre_justificacion}
                                    onChange={(e) => f.setData('cierre_justificacion', e.target.value)}
                                    className={AREA}
                                />
                            </Campo>
                        </Seccion>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDlg(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={f.processing}>
                                Guardar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ModuloPage>
    );
}

function Seccion({ titulo, accion, children }: { titulo: string; accion?: React.ReactNode; children: React.ReactNode }) {
    return (
        <section className="space-y-3">
            <div className="flex items-center justify-between gap-2 border-b pb-1">
                <h3 className="text-sm font-semibold">{titulo}</h3>
                {accion}
            </div>
            {children}
        </section>
    );
}

function Campo({
    id,
    label,
    error,
    className,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className={cn('grid gap-2', className)}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function Casilla({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }) {
    return (
        <label className="flex items-center gap-2 pt-2 text-sm">
            <input type="checkbox" className="size-4" checked={checked} onChange={(e) => onChange(e.target.checked)} />
            {label}
        </label>
    );
}

function Firma({ ok, label }: { ok: boolean; label: string }) {
    return (
        <div className={cn('flex items-center gap-1', ok ? 'text-emerald-600 dark:text-emerald-500' : 'text-muted-foreground')}>
            {ok ? <CheckCircle2 className="size-3.5" /> : <Clock className="size-3.5" />} {label}
        </div>
    );
}
