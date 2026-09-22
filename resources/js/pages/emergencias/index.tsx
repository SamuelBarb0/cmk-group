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
import { Flame, PenLine, Phone, Plus, ShieldAlert, Siren, Trash2, Users } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Pestana = 'brigada' | 'simulacros' | 'equipos' | 'directorio';

interface Brigadista {
    id: number;
    employee_id: number | null;
    nombres: string;
    numero_documento: string | null;
    cargo: string | null;
    telefono: string | null;
    rol: string;
    especialidad: string | null;
    fecha_inscripcion: string | null;
    grupo_sanguineo: string | null;
    eps: string | null;
    arl: string | null;
    limitaciones_fisicas: string | null;
    usa_anteojos: boolean;
    contacto_emergencia_nombre: string | null;
    contacto_emergencia_telefono: string | null;
    curso_primer_respondiente: boolean;
    fecha_curso: string | null;
    activo: boolean;
    observaciones: string | null;
}

interface Recomendacion {
    [key: string]: string | boolean | undefined;
    descripcion: string;
    responsable: string;
    fecha_limite: string;
    implementada: boolean;
    fecha_implementacion: string;
}

interface Simulacro {
    id: number;
    fecha: string;
    tipo: string;
    escenario: string;
    estado: string;
    sede: string | null;
    entidades_apoyo: string | null;
    hora_inicio: string | null;
    hora_fin: string | null;
    tiempo_evacuacion_segundos: number | null;
    evacuados: number | null;
    convocados: number | null;
    participantes: number | null;
    observaciones: string | null;
    recommendations: {
        descripcion: string;
        responsable: string | null;
        fecha_limite: string | null;
        implementada: boolean;
        fecha_implementacion: string | null;
    }[];
}

interface Equipo {
    id: number;
    ubicacion: string;
    ciudad: string | null;
    direccion: string | null;
    elemento: string;
    cantidad: number;
    ubicacion_exacta: string | null;
    tipo: string;
    estado: string;
    fecha_revision: string | null;
    fecha_vencimiento: string | null;
    observaciones: string | null;
    vencido: boolean;
    por_vencer: boolean;
}

interface Contacto {
    id: number;
    tipo: string;
    nombre: string;
    detalle: string | null;
    telefono: string | null;
    telefono_alterno: string | null;
    direccion: string | null;
    ciudad: string | null;
    orden: number;
}

interface EmpleadoRow {
    id: number;
    nombres: string;
    apellidos: string;
    numero_documento: string;
    cargo: string | null;
    telefono: string | null;
    grupo_sanguineo: string | null;
    eps: string | null;
    arl: string | null;
}

interface Stats {
    brigadistas: number;
    sin_primer_respondiente: number;
    roles_vacantes: string[];
    simulacros_programados: number;
    simulacros_realizados: number;
    simulacros_externos: number;
    meta_anual: number;
    meta_externos: number;
    recomendaciones_total: number;
    recomendaciones_implementadas: number;
    participacion: number | null;
    equipos_malos: number;
    equipos_vencidos: number;
    equipos_por_vencer: number;
}

interface Props {
    anio: number;
    brigada: Brigadista[];
    simulacros: Simulacro[];
    equipos: Equipo[];
    directorio: Contacto[];
    empleados: EmpleadoRow[];
    stats: Stats;
    catalogos: {
        roles: string[];
        especialidades: string[];
        tipos_simulacro: string[];
        estados_simulacro: string[];
        tipos_equipo: string[];
        estados_equipo: string[];
        tipos_contacto: string[];
    };
    needsClient: boolean;
}

const ETIQUETA_ROL: Record<string, string> = {
    coordinador_emergencias: 'Coordinador de emergencias',
    jefe_brigada: 'Jefe de brigada',
    lider_primeros_auxilios: 'Líder de primeros auxilios',
    lider_incendios: 'Líder de control de incendios',
    lider_evacuacion: 'Líder de evacuación y rescate',
    seguridad_fisica: 'Seguridad física',
    enlace_logistica: 'Enlace – logística',
    brigadista: 'Brigadista',
};

const ETIQUETA_ESPECIALIDAD: Record<string, string> = {
    primeros_auxilios: 'Primeros auxilios',
    evacuacion: 'Evacuación',
    contra_incendios: 'Contra incendios',
};

const ETIQUETA_TIPO_EQUIPO: Record<string, string> = {
    primeros_auxilios: 'Primeros auxilios',
    contra_incendios: 'Contra incendios',
    evacuacion: 'Evacuación',
};

const ETIQUETA_TIPO_CONTACTO: Record<string, string> = {
    interno: 'Responsables internos',
    entidad_apoyo: 'Entidades de apoyo',
    prestador_salud: 'Prestadores de salud',
};

/** Qué significa «detalle» según el tipo de contacto. */
const ETIQUETA_DETALLE: Record<string, string> = {
    interno: 'Cargo',
    entidad_apoyo: 'Llamar en caso de',
    prestador_salud: 'Servicio',
};

const hoy = () => new Date().toISOString().slice(0, 10);

const SELECT = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

const brigadistaVacio = {
    employee_id: '' as number | string,
    nombres: '',
    numero_documento: '',
    cargo: '',
    telefono: '',
    rol: 'brigadista',
    especialidad: '',
    fecha_inscripcion: hoy(),
    grupo_sanguineo: '',
    eps: '',
    arl: '',
    limitaciones_fisicas: '',
    usa_anteojos: false as boolean,
    contacto_emergencia_nombre: '',
    contacto_emergencia_telefono: '',
    curso_primer_respondiente: false as boolean,
    fecha_curso: '',
    activo: true as boolean,
    observaciones: '',
};

const simulacroVacio = {
    fecha: hoy(),
    tipo: 'interno',
    escenario: '',
    estado: 'programado',
    sede: '',
    entidades_apoyo: '',
    hora_inicio: '',
    hora_fin: '',
    tiempo_evacuacion_segundos: '' as number | string,
    evacuados: '' as number | string,
    convocados: '' as number | string,
    participantes: '' as number | string,
    observaciones: '',
    recommendations: [] as Recomendacion[],
};

const equipoVacio = {
    ubicacion: '',
    ciudad: '',
    direccion: '',
    elemento: '',
    cantidad: 1 as number | string,
    ubicacion_exacta: '',
    tipo: 'primeros_auxilios',
    estado: 'bueno',
    fecha_revision: '',
    fecha_vencimiento: '',
    observaciones: '',
};

const contactoVacio = {
    tipo: 'interno',
    nombre: '',
    detalle: '',
    telefono: '',
    telefono_alterno: '',
    direccion: '',
    ciudad: '',
    orden: 0 as number | string,
};

/** mm:ss a partir de segundos, que es como se cronometra una evacuación. */
function duracion(seg: number | null): string {
    if (seg === null) return '—';
    const m = Math.floor(seg / 60);
    const s = seg % 60;
    return `${m} min ${String(s).padStart(2, '0')} s`;
}

function porcentaje(num: number, den: number): string {
    return den > 0 ? `${Math.round((num / den) * 100)} %` : '—';
}

export default function EmergenciasIndex({ anio, brigada, simulacros, equipos, directorio, empleados, stats, catalogos, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const [pestana, setPestana] = useState<Pestana>('brigada');

    const [dlgBrig, setDlgBrig] = useState(false);
    const [editBrig, setEditBrig] = useState<Brigadista | null>(null);
    const [dlgSim, setDlgSim] = useState(false);
    const [editSim, setEditSim] = useState<Simulacro | null>(null);
    const [dlgEq, setDlgEq] = useState(false);
    const [editEq, setEditEq] = useState<Equipo | null>(null);
    const [dlgCon, setDlgCon] = useState(false);
    const [editCon, setEditCon] = useState<Contacto | null>(null);

    const fBrig = useForm({ ...brigadistaVacio });
    const fSim = useForm({ ...simulacroVacio });
    const fEq = useForm({ ...equipoVacio });
    const fCon = useForm({ ...contactoVacio });

    // --------------------------------------------------------------- brigada
    function abrirBrig(b?: Brigadista) {
        setEditBrig(b ?? null);
        fBrig.clearErrors();
        fBrig.setData(
            b
                ? {
                      employee_id: b.employee_id ?? '',
                      nombres: b.nombres,
                      numero_documento: b.numero_documento ?? '',
                      cargo: b.cargo ?? '',
                      telefono: b.telefono ?? '',
                      rol: b.rol,
                      especialidad: b.especialidad ?? '',
                      fecha_inscripcion: b.fecha_inscripcion ?? '',
                      grupo_sanguineo: b.grupo_sanguineo ?? '',
                      eps: b.eps ?? '',
                      arl: b.arl ?? '',
                      limitaciones_fisicas: b.limitaciones_fisicas ?? '',
                      usa_anteojos: b.usa_anteojos,
                      contacto_emergencia_nombre: b.contacto_emergencia_nombre ?? '',
                      contacto_emergencia_telefono: b.contacto_emergencia_telefono ?? '',
                      curso_primer_respondiente: b.curso_primer_respondiente,
                      fecha_curso: b.fecha_curso ?? '',
                      activo: b.activo,
                      observaciones: b.observaciones ?? '',
                  }
                : { ...brigadistaVacio },
        );
        setDlgBrig(true);
    }

    /**
     * Al elegir a alguien de la nómina se copian sus datos del MEDEVAC (RH,
     * EPS, ARL, teléfono). Solo se rellenan los vacíos: si el consultor ya
     * corrigió algo a mano, no se le pisa.
     */
    function elegirEmpleado(id: string) {
        const emp = empleados.find((e) => String(e.id) === id);
        if (!emp) {
            fBrig.setData('employee_id', '');
            return;
        }
        const d = fBrig.data;
        fBrig.setData({
            ...d,
            employee_id: emp.id,
            nombres: `${emp.nombres} ${emp.apellidos}`,
            numero_documento: emp.numero_documento,
            cargo: d.cargo || emp.cargo || '',
            telefono: d.telefono || emp.telefono || '',
            grupo_sanguineo: d.grupo_sanguineo || emp.grupo_sanguineo || '',
            eps: d.eps || emp.eps || '',
            arl: d.arl || emp.arl || '',
        });
    }

    const guardarBrig: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setDlgBrig(false) };
        if (editBrig) fBrig.put(route('emergencias.brigada.update', editBrig.id), opts);
        else fBrig.post(route('emergencias.brigada.store'), opts);
    };

    // ------------------------------------------------------------ simulacros
    function abrirSim(s?: Simulacro) {
        setEditSim(s ?? null);
        fSim.clearErrors();
        fSim.setData(
            s
                ? {
                      fecha: s.fecha,
                      tipo: s.tipo,
                      escenario: s.escenario,
                      estado: s.estado,
                      sede: s.sede ?? '',
                      entidades_apoyo: s.entidades_apoyo ?? '',
                      hora_inicio: s.hora_inicio ?? '',
                      hora_fin: s.hora_fin ?? '',
                      tiempo_evacuacion_segundos: s.tiempo_evacuacion_segundos ?? '',
                      evacuados: s.evacuados ?? '',
                      convocados: s.convocados ?? '',
                      participantes: s.participantes ?? '',
                      observaciones: s.observaciones ?? '',
                      recommendations: s.recommendations.map((r) => ({
                          descripcion: r.descripcion,
                          responsable: r.responsable ?? '',
                          fecha_limite: r.fecha_limite ?? '',
                          implementada: r.implementada,
                          fecha_implementacion: r.fecha_implementacion ?? '',
                      })),
                  }
                : { ...simulacroVacio, recommendations: [] },
        );
        setDlgSim(true);
    }

    function setRec(i: number, campo: keyof Recomendacion, valor: string | boolean) {
        const lista = fSim.data.recommendations.map((r, j) => (j === i ? { ...r, [campo]: valor } : r));
        fSim.setData('recommendations', lista);
    }

    const guardarSim: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setDlgSim(false) };
        if (editSim) fSim.put(route('emergencias.simulacros.update', editSim.id), opts);
        else fSim.post(route('emergencias.simulacros.store'), opts);
    };

    // --------------------------------------------------------------- equipos
    function abrirEq(q?: Equipo) {
        setEditEq(q ?? null);
        fEq.clearErrors();
        fEq.setData(
            q
                ? {
                      ubicacion: q.ubicacion,
                      ciudad: q.ciudad ?? '',
                      direccion: q.direccion ?? '',
                      elemento: q.elemento,
                      cantidad: q.cantidad,
                      ubicacion_exacta: q.ubicacion_exacta ?? '',
                      tipo: q.tipo,
                      estado: q.estado,
                      fecha_revision: q.fecha_revision ?? '',
                      fecha_vencimiento: q.fecha_vencimiento ?? '',
                      observaciones: q.observaciones ?? '',
                  }
                : { ...equipoVacio },
        );
        setDlgEq(true);
    }

    const guardarEq: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setDlgEq(false) };
        if (editEq) fEq.put(route('emergencias.equipos.update', editEq.id), opts);
        else fEq.post(route('emergencias.equipos.store'), opts);
    };

    // ------------------------------------------------------------ directorio
    function abrirCon(c?: Contacto) {
        setEditCon(c ?? null);
        fCon.clearErrors();
        fCon.setData(
            c
                ? {
                      tipo: c.tipo,
                      nombre: c.nombre,
                      detalle: c.detalle ?? '',
                      telefono: c.telefono ?? '',
                      telefono_alterno: c.telefono_alterno ?? '',
                      direccion: c.direccion ?? '',
                      ciudad: c.ciudad ?? '',
                      orden: c.orden,
                  }
                : { ...contactoVacio },
        );
        setDlgCon(true);
    }

    const guardarCon: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setDlgCon(false) };
        if (editCon) fCon.put(route('emergencias.directorio.update', editCon.id), opts);
        else fCon.post(route('emergencias.directorio.store'), opts);
    };

    const eliminar = (ruta: string, id: number, pregunta: string) =>
        confirm(pregunta) && router.delete(route(ruta, id), { preserveScroll: true });

    const cambiarAnio = (a: number) => router.get(route('emergencias.index'), { anio: a }, { preserveState: true, preserveScroll: true });

    const PESTANAS: { key: Pestana; label: string; n: number }[] = [
        { key: 'brigada', label: 'Brigada', n: brigada.length },
        { key: 'simulacros', label: 'Simulacros', n: simulacros.length },
        { key: 'equipos', label: 'Equipos', n: equipos.length },
        { key: 'directorio', label: 'Directorio MEDEVAC', n: directorio.length },
    ];

    const ACCION: Record<Pestana, [string, () => void]> = {
        brigada: ['Inscribir brigadista', () => abrirBrig()],
        simulacros: ['Nuevo simulacro', () => abrirSim()],
        equipos: ['Nuevo equipo', () => abrirEq()],
        directorio: ['Nuevo contacto', () => abrirCon()],
    };

    const metaSimulacros = stats.simulacros_realizados >= stats.meta_anual && stats.simulacros_externos >= stats.meta_externos;

    return (
        <ModuloPage
            titulo="Plan de emergencias"
            descripcion="Brigada, simulacros, equipos y directorio (estándares 5.1.1 y 5.1.2)"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button onClick={ACCION[pestana][1]} className="gap-2">
                        <Plus className="size-4" />
                        {ACCION[pestana][0]}
                    </Button>
                ) : undefined
            }
            filtros={
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap gap-1">
                        {PESTANAS.map((p) => (
                            <button
                                key={p.key}
                                type="button"
                                onClick={() => setPestana(p.key)}
                                className={cn(
                                    'rounded-md px-3 py-1.5 text-sm',
                                    pestana === p.key ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                                )}
                            >
                                {p.label} <span className="tabular-nums opacity-70">({p.n})</span>
                            </button>
                        ))}
                    </div>
                    <label className="text-muted-foreground flex items-center gap-2 text-sm">
                        Año del programa
                        <select value={anio} onChange={(e) => cambiarAnio(Number(e.target.value))} className={cn(SELECT, 'w-auto')}>
                            {[0, 1, 2, 3].map((k) => {
                                const a = new Date().getFullYear() + 1 - k;
                                return (
                                    <option key={a} value={a}>
                                        {a}
                                    </option>
                                );
                            })}
                        </select>
                    </label>
                </div>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Brigadistas activos"
                    value={stats.brigadistas}
                    icon={Users}
                    alerta={stats.roles_vacantes.length > 0}
                />
                <StatCard
                    label={`Simulacros ${anio} (meta ${stats.meta_anual}, ${stats.meta_externos} externo)`}
                    value={`${stats.simulacros_realizados} / ${stats.simulacros_programados}`}
                    icon={Siren}
                    alerta={!metaSimulacros}
                />
                <StatCard
                    label="Recomendaciones implementadas"
                    value={porcentaje(stats.recomendaciones_implementadas, stats.recomendaciones_total)}
                    icon={ShieldAlert}
                    alerta={
                        stats.recomendaciones_total > 0 && stats.recomendaciones_implementadas / stats.recomendaciones_total < 0.9
                    }
                />
                <StatCard
                    label="Equipos vencidos o en mal estado"
                    value={stats.equipos_vencidos + stats.equipos_malos}
                    icon={Flame}
                    alerta={stats.equipos_vencidos + stats.equipos_malos > 0}
                />
            </div>

            {/* Lo que un auditor preguntaría primero, en una línea. */}
            {!needsClient && (stats.roles_vacantes.length > 0 || stats.sin_primer_respondiente > 0 || stats.equipos_por_vencer > 0) && (
                <div className="text-muted-foreground space-y-1 rounded-md border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
                    {stats.roles_vacantes.length > 0 && (
                        <p>
                            <span className="font-medium text-amber-700 dark:text-amber-400">Cargos sin asignar en la brigada:</span>{' '}
                            {stats.roles_vacantes.map((r) => ETIQUETA_ROL[r] ?? r).join(', ')}.
                        </p>
                    )}
                    {stats.sin_primer_respondiente > 0 && (
                        <p>
                            <span className="font-medium text-amber-700 dark:text-amber-400">{stats.sin_primer_respondiente}</span> brigadista(s)
                            sin el curso de primer respondiente, que el formato marca como obligatorio.
                        </p>
                    )}
                    {stats.equipos_por_vencer > 0 && (
                        <p>
                            <span className="font-medium text-amber-700 dark:text-amber-400">{stats.equipos_por_vencer}</span> equipo(s) vencen en
                            los próximos 30 días.
                        </p>
                    )}
                    {stats.participacion !== null && (
                        <p>Participación en los simulacros realizados de {anio}: {stats.participacion} % (meta 80 %).</p>
                    )}
                </div>
            )}

            <Card>
                <CardContent className="p-0">
                    {/* ------------------------------------------------ brigada */}
                    {pestana === 'brigada' &&
                        (brigada.length === 0 ? (
                            <p className="text-muted-foreground p-8 text-center text-sm">
                                Nadie inscrito todavía. Empieza por el coordinador de emergencias y el jefe de brigada.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                        <tr>
                                            <th className="px-4 py-2.5 font-semibold">Nombre</th>
                                            <th className="px-4 py-2.5 font-semibold">Rol</th>
                                            <th className="px-4 py-2.5 font-semibold">Especialidad</th>
                                            <th className="px-4 py-2.5 font-semibold">RH / EPS</th>
                                            <th className="px-4 py-2.5 font-semibold">Primer respondiente</th>
                                            {canManage && <th className="px-4 py-2.5" />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {brigada.map((b) => (
                                            <tr key={b.id} className={cn('border-t', !b.activo && 'opacity-60')}>
                                                <td className="px-4 py-2.5">
                                                    <div className="font-medium">{b.nombres}</div>
                                                    <div className="text-muted-foreground text-xs">{b.cargo ?? '—'}</div>
                                                </td>
                                                <td className="px-4 py-2.5">{ETIQUETA_ROL[b.rol] ?? b.rol}</td>
                                                <td className="px-4 py-2.5">{b.especialidad ? ETIQUETA_ESPECIALIDAD[b.especialidad] : '—'}</td>
                                                <td className="px-4 py-2.5">
                                                    {b.grupo_sanguineo ?? '—'} · {b.eps ?? '—'}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {b.curso_primer_respondiente ? (
                                                        <span className="text-xs text-emerald-600 dark:text-emerald-500">
                                                            sí{b.fecha_curso && ` · ${b.fecha_curso}`}
                                                        </span>
                                                    ) : (
                                                        <span className="text-destructive text-xs">pendiente</span>
                                                    )}
                                                </td>
                                                {canManage && (
                                                    <td className="px-4 py-2.5">
                                                        <div className="flex justify-end gap-1">
                                                            <Button size="icon" variant="ghost" onClick={() => abrirBrig(b)} aria-label="Editar">
                                                                <PenLine className="size-4" />
                                                            </Button>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label="Eliminar"
                                                                onClick={() =>
                                                                    eliminar('emergencias.brigada.destroy', b.id, `¿Retirar a ${b.nombres} de la brigada?`)
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
                        ))}

                    {/* --------------------------------------------- simulacros */}
                    {pestana === 'simulacros' &&
                        (simulacros.length === 0 ? (
                            <p className="text-muted-foreground p-8 text-center text-sm">
                                Sin simulacros. El programa de CMK pide mínimo dos al año, uno de ellos con entidades externas.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                        <tr>
                                            <th className="px-4 py-2.5 font-semibold">Fecha</th>
                                            <th className="px-4 py-2.5 font-semibold">Escenario</th>
                                            <th className="px-4 py-2.5 font-semibold">Tipo</th>
                                            <th className="px-4 py-2.5 font-semibold">Estado</th>
                                            <th className="px-4 py-2.5 font-semibold">Evacuación</th>
                                            <th className="px-4 py-2.5 font-semibold">Participación</th>
                                            <th className="px-4 py-2.5 font-semibold">Recomendaciones</th>
                                            {canManage && <th className="px-4 py-2.5" />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {simulacros.map((s) => {
                                            const impl = s.recommendations.filter((r) => r.implementada).length;
                                            return (
                                                <tr key={s.id} className="border-t">
                                                    <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">{s.fecha}</td>
                                                    <td className="px-4 py-2.5">
                                                        <div className="font-medium">{s.escenario}</div>
                                                        {s.sede && <div className="text-muted-foreground text-xs">{s.sede}</div>}
                                                    </td>
                                                    <td className="px-4 py-2.5 capitalize">{s.tipo}</td>
                                                    <td className="px-4 py-2.5">
                                                        <Badge
                                                            className={cn(
                                                                'font-normal',
                                                                s.estado === 'realizado'
                                                                    ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                                                                    : 'bg-muted text-muted-foreground',
                                                            )}
                                                        >
                                                            {s.estado}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">
                                                        {duracion(s.tiempo_evacuacion_segundos)}
                                                        {s.evacuados !== null && (
                                                            <span className="text-muted-foreground text-xs"> · {s.evacuados} pers.</span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-2.5 tabular-nums">
                                                        {s.convocados ? porcentaje(s.participantes ?? 0, s.convocados) : '—'}
                                                    </td>
                                                    <td className="px-4 py-2.5 tabular-nums">
                                                        {s.recommendations.length === 0 ? '—' : `${impl} / ${s.recommendations.length}`}
                                                    </td>
                                                    {canManage && (
                                                        <td className="px-4 py-2.5">
                                                            <div className="flex justify-end gap-1">
                                                                <Button size="icon" variant="ghost" onClick={() => abrirSim(s)} aria-label="Editar">
                                                                    <PenLine className="size-4" />
                                                                </Button>
                                                                <Button
                                                                    size="icon"
                                                                    variant="ghost"
                                                                    aria-label="Eliminar"
                                                                    onClick={() =>
                                                                        eliminar('emergencias.simulacros.destroy', s.id, '¿Eliminar este simulacro?')
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
                        ))}

                    {/* ------------------------------------------------ equipos */}
                    {pestana === 'equipos' &&
                        (equipos.length === 0 ? (
                            <p className="text-muted-foreground p-8 text-center text-sm">
                                Inventario vacío. La inspección periódica de extintores y botiquines se lleva en Formatos.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                        <tr>
                                            <th className="px-4 py-2.5 font-semibold">Ubicación</th>
                                            <th className="px-4 py-2.5 font-semibold">Elemento</th>
                                            <th className="px-4 py-2.5 font-semibold">Cant.</th>
                                            <th className="px-4 py-2.5 font-semibold">Tipo</th>
                                            <th className="px-4 py-2.5 font-semibold">Estado</th>
                                            <th className="px-4 py-2.5 font-semibold">Vence</th>
                                            {canManage && <th className="px-4 py-2.5" />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {equipos.map((q) => (
                                            <tr key={q.id} className="border-t">
                                                <td className="px-4 py-2.5">
                                                    <div className="font-medium">{q.ubicacion}</div>
                                                    <div className="text-muted-foreground text-xs">
                                                        {[q.ubicacion_exacta, q.ciudad].filter(Boolean).join(' · ') || '—'}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-2.5">{q.elemento}</td>
                                                <td className="px-4 py-2.5 tabular-nums">{q.cantidad}</td>
                                                <td className="px-4 py-2.5">{ETIQUETA_TIPO_EQUIPO[q.tipo] ?? q.tipo}</td>
                                                <td className="px-4 py-2.5">
                                                    <span
                                                        className={cn(
                                                            'text-xs capitalize',
                                                            q.estado === 'malo' && 'text-destructive',
                                                            q.estado === 'regular' && 'text-amber-600 dark:text-amber-400',
                                                        )}
                                                    >
                                                        {q.estado}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">
                                                    {q.fecha_vencimiento ?? '—'}
                                                    {q.vencido && <span className="text-destructive text-xs"> · vencido</span>}
                                                    {q.por_vencer && <span className="text-xs text-amber-600 dark:text-amber-400"> · por vencer</span>}
                                                </td>
                                                {canManage && (
                                                    <td className="px-4 py-2.5">
                                                        <div className="flex justify-end gap-1">
                                                            <Button size="icon" variant="ghost" onClick={() => abrirEq(q)} aria-label="Editar">
                                                                <PenLine className="size-4" />
                                                            </Button>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label="Eliminar"
                                                                onClick={() =>
                                                                    eliminar('emergencias.equipos.destroy', q.id, `¿Eliminar ${q.elemento} del inventario?`)
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
                        ))}

                    {/* --------------------------------------------- directorio */}
                    {pestana === 'directorio' && (
                        <div>
                            {canManage && (
                                <div className="flex items-center justify-between gap-2 border-b p-3 text-sm">
                                    <span className="text-muted-foreground">
                                        Las líneas nacionales (123, bomberos, Cruz Roja…) se cargan de una vez; las locales dependen de dónde opera
                                        la empresa.
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="shrink-0 gap-2"
                                        onClick={() => router.post(route('emergencias.directorio.nacionales'), {}, { preserveScroll: true })}
                                    >
                                        <Phone className="size-4" />
                                        Cargar líneas nacionales
                                    </Button>
                                </div>
                            )}
                            {directorio.length === 0 ? (
                                <p className="text-muted-foreground p-8 text-center text-sm">El directorio está vacío.</p>
                            ) : (
                                catalogos.tipos_contacto
                                    .filter((t) => directorio.some((c) => c.tipo === t))
                                    .map((t) => (
                                        <div key={t} className="overflow-x-auto">
                                            <div className="bg-muted/40 text-muted-foreground px-4 py-2 text-xs font-semibold">
                                                {ETIQUETA_TIPO_CONTACTO[t]}
                                            </div>
                                            <table className="w-full text-sm">
                                                <tbody>
                                                    {directorio
                                                        .filter((c) => c.tipo === t)
                                                        .map((c) => (
                                                            <tr key={c.id} className="border-t">
                                                                <td className="w-1/3 px-4 py-2.5 font-medium">{c.nombre}</td>
                                                                <td className="text-muted-foreground px-4 py-2.5">{c.detalle ?? '—'}</td>
                                                                <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">
                                                                    {[c.telefono, c.telefono_alterno].filter(Boolean).join(' / ') || '—'}
                                                                </td>
                                                                <td className="text-muted-foreground px-4 py-2.5">
                                                                    {[c.direccion, c.ciudad].filter(Boolean).join(', ') || '—'}
                                                                </td>
                                                                {canManage && (
                                                                    <td className="px-4 py-2.5">
                                                                        <div className="flex justify-end gap-1">
                                                                            <Button
                                                                                size="icon"
                                                                                variant="ghost"
                                                                                onClick={() => abrirCon(c)}
                                                                                aria-label="Editar"
                                                                            >
                                                                                <PenLine className="size-4" />
                                                                            </Button>
                                                                            <Button
                                                                                size="icon"
                                                                                variant="ghost"
                                                                                aria-label="Eliminar"
                                                                                onClick={() =>
                                                                                    eliminar(
                                                                                        'emergencias.directorio.destroy',
                                                                                        c.id,
                                                                                        `¿Eliminar ${c.nombre} del directorio?`,
                                                                                    )
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
                                    ))
                            )}
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* --------------------------------------------- diálogo: brigadista */}
            <Dialog open={dlgBrig} onOpenChange={setDlgBrig}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{editBrig ? 'Editar brigadista' : 'Inscribir brigadista'}</DialogTitle>
                        <DialogDescription>Formato de inscripción a brigadas de emergencia.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={guardarBrig} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="b-emp">Trabajador de la nómina (opcional)</Label>
                            <select
                                id="b-emp"
                                value={String(fBrig.data.employee_id)}
                                onChange={(e) => elegirEmpleado(e.target.value)}
                                className={SELECT}
                            >
                                <option value="">— Externo o contratista —</option>
                                {empleados.map((e) => (
                                    <option key={e.id} value={e.id}>
                                        {e.apellidos} {e.nombres} {e.cargo ? `· ${e.cargo}` : ''}
                                    </option>
                                ))}
                            </select>
                            <InputError message={fBrig.errors.employee_id} />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Campo id="b-nombres" label="Nombre y apellidos" error={fBrig.errors.nombres}>
                                <Input id="b-nombres" value={fBrig.data.nombres} onChange={(e) => fBrig.setData('nombres', e.target.value)} />
                            </Campo>
                            <Campo id="b-doc" label="Documento" error={fBrig.errors.numero_documento}>
                                <Input
                                    id="b-doc"
                                    value={fBrig.data.numero_documento}
                                    onChange={(e) => fBrig.setData('numero_documento', e.target.value)}
                                />
                            </Campo>
                            <Campo id="b-rol" label="Rol en la brigada" error={fBrig.errors.rol}>
                                <select id="b-rol" value={fBrig.data.rol} onChange={(e) => fBrig.setData('rol', e.target.value)} className={SELECT}>
                                    {catalogos.roles.map((r) => (
                                        <option key={r} value={r}>
                                            {ETIQUETA_ROL[r] ?? r}
                                        </option>
                                    ))}
                                </select>
                            </Campo>
                            <Campo id="b-esp" label="Especialidad" error={fBrig.errors.especialidad}>
                                <select
                                    id="b-esp"
                                    value={fBrig.data.especialidad}
                                    onChange={(e) => fBrig.setData('especialidad', e.target.value)}
                                    className={SELECT}
                                >
                                    <option value="">—</option>
                                    {catalogos.especialidades.map((s) => (
                                        <option key={s} value={s}>
                                            {ETIQUETA_ESPECIALIDAD[s] ?? s}
                                        </option>
                                    ))}
                                </select>
                            </Campo>
                            <Campo id="b-cargo" label="Cargo" error={fBrig.errors.cargo}>
                                <Input id="b-cargo" value={fBrig.data.cargo} onChange={(e) => fBrig.setData('cargo', e.target.value)} />
                            </Campo>
                            <Campo id="b-tel" label="Celular" error={fBrig.errors.telefono}>
                                <Input id="b-tel" value={fBrig.data.telefono} onChange={(e) => fBrig.setData('telefono', e.target.value)} />
                            </Campo>
                            <Campo id="b-rh" label="Grupo sanguíneo y RH" error={fBrig.errors.grupo_sanguineo}>
                                <Input
                                    id="b-rh"
                                    value={fBrig.data.grupo_sanguineo}
                                    onChange={(e) => fBrig.setData('grupo_sanguineo', e.target.value)}
                                />
                            </Campo>
                            <Campo id="b-eps" label="EPS" error={fBrig.errors.eps}>
                                <Input id="b-eps" value={fBrig.data.eps} onChange={(e) => fBrig.setData('eps', e.target.value)} />
                            </Campo>
                            <Campo id="b-arl" label="ARL" error={fBrig.errors.arl}>
                                <Input id="b-arl" value={fBrig.data.arl} onChange={(e) => fBrig.setData('arl', e.target.value)} />
                            </Campo>
                            <Campo id="b-lim" label="Limitaciones físicas (vacío = ninguna)" error={fBrig.errors.limitaciones_fisicas}>
                                <Input
                                    id="b-lim"
                                    value={fBrig.data.limitaciones_fisicas}
                                    onChange={(e) => fBrig.setData('limitaciones_fisicas', e.target.value)}
                                />
                            </Campo>
                            <Campo id="b-cen" label="En caso de emergencia llamar a" error={fBrig.errors.contacto_emergencia_nombre}>
                                <Input
                                    id="b-cen"
                                    value={fBrig.data.contacto_emergencia_nombre}
                                    onChange={(e) => fBrig.setData('contacto_emergencia_nombre', e.target.value)}
                                />
                            </Campo>
                            <Campo id="b-cet" label="Teléfono de ese contacto" error={fBrig.errors.contacto_emergencia_telefono}>
                                <Input
                                    id="b-cet"
                                    value={fBrig.data.contacto_emergencia_telefono}
                                    onChange={(e) => fBrig.setData('contacto_emergencia_telefono', e.target.value)}
                                />
                            </Campo>
                            <Campo id="b-fi" label="Fecha de inscripción" error={fBrig.errors.fecha_inscripcion}>
                                <Input
                                    id="b-fi"
                                    type="date"
                                    value={fBrig.data.fecha_inscripcion}
                                    onChange={(e) => fBrig.setData('fecha_inscripcion', e.target.value)}
                                />
                            </Campo>
                            <Campo id="b-fc" label="Fecha del curso de primer respondiente" error={fBrig.errors.fecha_curso}>
                                <Input
                                    id="b-fc"
                                    type="date"
                                    value={fBrig.data.fecha_curso}
                                    onChange={(e) => fBrig.setData('fecha_curso', e.target.value)}
                                />
                            </Campo>
                        </div>
                        <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                            <Casilla
                                label="Curso de primer respondiente (obligatorio)"
                                checked={fBrig.data.curso_primer_respondiente}
                                onChange={(v) => fBrig.setData('curso_primer_respondiente', v)}
                            />
                            <Casilla label="Usa anteojos" checked={fBrig.data.usa_anteojos} onChange={(v) => fBrig.setData('usa_anteojos', v)} />
                            <Casilla label="Activo" checked={fBrig.data.activo} onChange={(v) => fBrig.setData('activo', v)} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDlgBrig(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={fBrig.processing}>
                                Guardar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ---------------------------------------------- diálogo: simulacro */}
            <Dialog open={dlgSim} onOpenChange={setDlgSim}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>{editSim ? 'Editar simulacro' : 'Nuevo simulacro'}</DialogTitle>
                        <DialogDescription>
                            La lista de chequeo detallada del simulacro se diligencia en Formatos (FT-LCH-SIMULACRO); aquí van las cifras que miden
                            el programa.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={guardarSim} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Campo id="s-fecha" label="Fecha" error={fSim.errors.fecha}>
                                <Input id="s-fecha" type="date" value={fSim.data.fecha} onChange={(e) => fSim.setData('fecha', e.target.value)} />
                            </Campo>
                            <Campo id="s-tipo" label="Tipo" error={fSim.errors.tipo}>
                                <select id="s-tipo" value={fSim.data.tipo} onChange={(e) => fSim.setData('tipo', e.target.value)} className={SELECT}>
                                    <option value="interno">Interno</option>
                                    <option value="externo">Con partes interesadas externas</option>
                                </select>
                            </Campo>
                            <Campo id="s-estado" label="Estado" error={fSim.errors.estado}>
                                <select
                                    id="s-estado"
                                    value={fSim.data.estado}
                                    onChange={(e) => fSim.setData('estado', e.target.value)}
                                    className={SELECT}
                                >
                                    <option value="programado">Programado</option>
                                    <option value="realizado">Realizado</option>
                                </select>
                            </Campo>
                            <Campo id="s-esc" label="Escenario" error={fSim.errors.escenario}>
                                <Input
                                    id="s-esc"
                                    placeholder="Sismo, incendio, derrame…"
                                    value={fSim.data.escenario}
                                    onChange={(e) => fSim.setData('escenario', e.target.value)}
                                />
                            </Campo>
                            <Campo id="s-sede" label="Sede" error={fSim.errors.sede}>
                                <Input id="s-sede" value={fSim.data.sede} onChange={(e) => fSim.setData('sede', e.target.value)} />
                            </Campo>
                            <Campo id="s-ent" label="Entidades de apoyo" error={fSim.errors.entidades_apoyo}>
                                <Input
                                    id="s-ent"
                                    placeholder="Bomberos, Defensa Civil…"
                                    value={fSim.data.entidades_apoyo}
                                    onChange={(e) => fSim.setData('entidades_apoyo', e.target.value)}
                                />
                            </Campo>
                        </div>

                        {fSim.data.estado === 'realizado' && (
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Campo id="s-hi" label="Hora de inicio" error={fSim.errors.hora_inicio}>
                                    <Input
                                        id="s-hi"
                                        type="time"
                                        value={fSim.data.hora_inicio}
                                        onChange={(e) => fSim.setData('hora_inicio', e.target.value)}
                                    />
                                </Campo>
                                <Campo id="s-hf" label="Hora de finalización" error={fSim.errors.hora_fin}>
                                    <Input id="s-hf" type="time" value={fSim.data.hora_fin} onChange={(e) => fSim.setData('hora_fin', e.target.value)} />
                                </Campo>
                                <Campo id="s-te" label="Tiempo de evacuación (segundos)" error={fSim.errors.tiempo_evacuacion_segundos}>
                                    <Input
                                        id="s-te"
                                        type="number"
                                        min={0}
                                        value={fSim.data.tiempo_evacuacion_segundos}
                                        onChange={(e) => fSim.setData('tiempo_evacuacion_segundos', e.target.value)}
                                    />
                                </Campo>
                                <Campo id="s-ev" label="Personas evacuadas" error={fSim.errors.evacuados}>
                                    <Input
                                        id="s-ev"
                                        type="number"
                                        min={0}
                                        value={fSim.data.evacuados}
                                        onChange={(e) => fSim.setData('evacuados', e.target.value)}
                                    />
                                </Campo>
                                <Campo id="s-con" label="Convocados" error={fSim.errors.convocados}>
                                    <Input
                                        id="s-con"
                                        type="number"
                                        min={0}
                                        value={fSim.data.convocados}
                                        onChange={(e) => fSim.setData('convocados', e.target.value)}
                                    />
                                </Campo>
                                <Campo id="s-par" label="Participantes" error={fSim.errors.participantes}>
                                    <Input
                                        id="s-par"
                                        type="number"
                                        min={0}
                                        value={fSim.data.participantes}
                                        onChange={(e) => fSim.setData('participantes', e.target.value)}
                                    />
                                </Campo>
                            </div>
                        )}

                        <Campo id="s-obs" label="Observaciones" error={fSim.errors.observaciones}>
                            <textarea
                                id="s-obs"
                                rows={2}
                                value={fSim.data.observaciones}
                                onChange={(e) => fSim.setData('observaciones', e.target.value)}
                                className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                            />
                        </Campo>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label>Recomendaciones del simulacro</Label>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="gap-1"
                                    onClick={() =>
                                        fSim.setData('recommendations', [
                                            ...fSim.data.recommendations,
                                            { descripcion: '', responsable: '', fecha_limite: '', implementada: false, fecha_implementacion: '' },
                                        ])
                                    }
                                >
                                    <Plus className="size-3.5" /> Agregar
                                </Button>
                            </div>
                            {fSim.data.recommendations.length === 0 && (
                                <p className="text-muted-foreground text-xs">Sin recomendaciones registradas.</p>
                            )}
                            {fSim.data.recommendations.map((r, i) => {
                                const err = (fSim.errors as Record<string, string | undefined>)[`recommendations.${i}.descripcion`];
                                return (
                                    <div key={i} className="grid gap-2 rounded-md border p-2 sm:grid-cols-[1fr_10rem_9rem_auto_auto] sm:items-center">
                                        <div>
                                            <Input
                                                placeholder="Recomendación"
                                                value={r.descripcion}
                                                onChange={(e) => setRec(i, 'descripcion', e.target.value)}
                                                aria-label={`Recomendación ${i + 1}`}
                                            />
                                            <InputError message={err} />
                                        </div>
                                        <Input
                                            placeholder="Responsable"
                                            value={r.responsable}
                                            onChange={(e) => setRec(i, 'responsable', e.target.value)}
                                        />
                                        <Input
                                            type="date"
                                            value={r.fecha_limite}
                                            onChange={(e) => setRec(i, 'fecha_limite', e.target.value)}
                                            aria-label="Fecha límite"
                                        />
                                        <Casilla label="Implementada" checked={r.implementada} onChange={(v) => setRec(i, 'implementada', v)} />
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            aria-label="Quitar recomendación"
                                            onClick={() =>
                                                fSim.setData(
                                                    'recommendations',
                                                    fSim.data.recommendations.filter((_, j) => j !== i),
                                                )
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                );
                            })}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDlgSim(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={fSim.processing}>
                                Guardar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ------------------------------------------------- diálogo: equipo */}
            <Dialog open={dlgEq} onOpenChange={setDlgEq}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{editEq ? 'Editar equipo' : 'Nuevo equipo'}</DialogTitle>
                        <DialogDescription>Inventario general de equipos y elementos de emergencias.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={guardarEq} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Campo id="q-ub" label="Vehículo / puesto de control / sede" error={fEq.errors.ubicacion}>
                                <Input id="q-ub" value={fEq.data.ubicacion} onChange={(e) => fEq.setData('ubicacion', e.target.value)} />
                            </Campo>
                            <Campo id="q-el" label="Equipo o elemento" error={fEq.errors.elemento}>
                                <Input
                                    id="q-el"
                                    placeholder="Extintor ABC 10 lb, botiquín tipo B…"
                                    value={fEq.data.elemento}
                                    onChange={(e) => fEq.setData('elemento', e.target.value)}
                                />
                            </Campo>
                            <Campo id="q-cant" label="Cantidad" error={fEq.errors.cantidad}>
                                <Input
                                    id="q-cant"
                                    type="number"
                                    min={1}
                                    value={fEq.data.cantidad}
                                    onChange={(e) => fEq.setData('cantidad', e.target.value)}
                                />
                            </Campo>
                            <Campo id="q-ue" label="Ubicación exacta" error={fEq.errors.ubicacion_exacta}>
                                <Input
                                    id="q-ue"
                                    value={fEq.data.ubicacion_exacta}
                                    onChange={(e) => fEq.setData('ubicacion_exacta', e.target.value)}
                                />
                            </Campo>
                            <Campo id="q-tipo" label="Tipo" error={fEq.errors.tipo}>
                                <select id="q-tipo" value={fEq.data.tipo} onChange={(e) => fEq.setData('tipo', e.target.value)} className={SELECT}>
                                    {catalogos.tipos_equipo.map((t) => (
                                        <option key={t} value={t}>
                                            {ETIQUETA_TIPO_EQUIPO[t] ?? t}
                                        </option>
                                    ))}
                                </select>
                            </Campo>
                            <Campo id="q-est" label="Estado" error={fEq.errors.estado}>
                                <select id="q-est" value={fEq.data.estado} onChange={(e) => fEq.setData('estado', e.target.value)} className={SELECT}>
                                    <option value="bueno">Bueno</option>
                                    <option value="regular">Regular</option>
                                    <option value="malo">En mal estado</option>
                                </select>
                            </Campo>
                            <Campo id="q-ciu" label="Ciudad" error={fEq.errors.ciudad}>
                                <Input id="q-ciu" value={fEq.data.ciudad} onChange={(e) => fEq.setData('ciudad', e.target.value)} />
                            </Campo>
                            <Campo id="q-dir" label="Dirección" error={fEq.errors.direccion}>
                                <Input id="q-dir" value={fEq.data.direccion} onChange={(e) => fEq.setData('direccion', e.target.value)} />
                            </Campo>
                            <Campo id="q-rev" label="Última revisión" error={fEq.errors.fecha_revision}>
                                <Input
                                    id="q-rev"
                                    type="date"
                                    value={fEq.data.fecha_revision}
                                    onChange={(e) => fEq.setData('fecha_revision', e.target.value)}
                                />
                            </Campo>
                            <Campo id="q-ven" label="Vencimiento (recarga, insumos)" error={fEq.errors.fecha_vencimiento}>
                                <Input
                                    id="q-ven"
                                    type="date"
                                    value={fEq.data.fecha_vencimiento}
                                    onChange={(e) => fEq.setData('fecha_vencimiento', e.target.value)}
                                />
                            </Campo>
                        </div>
                        <Campo id="q-obs" label="Observaciones" error={fEq.errors.observaciones}>
                            <Input id="q-obs" value={fEq.data.observaciones} onChange={(e) => fEq.setData('observaciones', e.target.value)} />
                        </Campo>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDlgEq(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={fEq.processing}>
                                Guardar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ----------------------------------------------- diálogo: contacto */}
            <Dialog open={dlgCon} onOpenChange={setDlgCon}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>{editCon ? 'Editar contacto' : 'Nuevo contacto'}</DialogTitle>
                        <DialogDescription>Directorio de emergencias del MEDEVAC.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={guardarCon} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Campo id="c-tipo" label="Tipo" error={fCon.errors.tipo}>
                                <select id="c-tipo" value={fCon.data.tipo} onChange={(e) => fCon.setData('tipo', e.target.value)} className={SELECT}>
                                    {catalogos.tipos_contacto.map((t) => (
                                        <option key={t} value={t}>
                                            {ETIQUETA_TIPO_CONTACTO[t] ?? t}
                                        </option>
                                    ))}
                                </select>
                            </Campo>
                            <Campo id="c-nom" label="Nombre" error={fCon.errors.nombre}>
                                <Input id="c-nom" value={fCon.data.nombre} onChange={(e) => fCon.setData('nombre', e.target.value)} />
                            </Campo>
                            <Campo id="c-det" label={ETIQUETA_DETALLE[fCon.data.tipo] ?? 'Detalle'} error={fCon.errors.detalle}>
                                <Input id="c-det" value={fCon.data.detalle} onChange={(e) => fCon.setData('detalle', e.target.value)} />
                            </Campo>
                            <Campo id="c-tel" label="Teléfono" error={fCon.errors.telefono}>
                                <Input id="c-tel" value={fCon.data.telefono} onChange={(e) => fCon.setData('telefono', e.target.value)} />
                            </Campo>
                            <Campo id="c-tel2" label="Teléfono alterno" error={fCon.errors.telefono_alterno}>
                                <Input
                                    id="c-tel2"
                                    value={fCon.data.telefono_alterno}
                                    onChange={(e) => fCon.setData('telefono_alterno', e.target.value)}
                                />
                            </Campo>
                            <Campo id="c-ciu" label="Ciudad" error={fCon.errors.ciudad}>
                                <Input id="c-ciu" value={fCon.data.ciudad} onChange={(e) => fCon.setData('ciudad', e.target.value)} />
                            </Campo>
                        </div>
                        <Campo id="c-dir" label="Dirección" error={fCon.errors.direccion}>
                            <Input id="c-dir" value={fCon.data.direccion} onChange={(e) => fCon.setData('direccion', e.target.value)} />
                        </Campo>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDlgCon(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={fCon.processing}>
                                Guardar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ModuloPage>
    );
}

function Campo({ id, label, error, children }: { id: string; label: string; error?: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function Casilla({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }) {
    return (
        <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="size-4" />
            {label}
        </label>
    );
}
