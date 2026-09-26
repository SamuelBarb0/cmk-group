import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePartes } from '@/hooks/use-partes';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { CalendarClock, ClipboardList, FileWarning, PenLine, Plus, Stethoscope, Trash2, UserX } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Pestana = 'trabajadores' | 'examenes' | 'profesiograma';
type Momento = 'ingreso' | 'periodico' | 'retiro';
type Matriz = Record<string, Partial<Record<Momento, boolean>>>;

interface Trabajador {
    employee_id: number;
    nombre: string;
    numero_documento: string;
    cargo: string | null;
    tiene_perfil: boolean;
    ultimo_examen: string | null;
    ultimo_tipo: string | null;
    concepto: string | null;
    proximo_examen: string | null;
    estado: 'al_dia' | 'por_vencer' | 'vencido' | 'sin_examen';
    con_restricciones: boolean;
}

interface Examen {
    id: number;
    employee_id: number;
    fecha: string;
    tipo: string;
    ips: string | null;
    examenes_realizados: string[] | null;
    concepto: string;
    restricciones: string | null;
    recomendaciones_personales: string | null;
    recomendaciones_sst: string | null;
    recomendaciones_medicas: string | null;
    carta_entregada: boolean;
    fecha_carta: string | null;
    pve: string | null;
    plan_accion: string | null;
    seguimiento: string | null;
    proximo_examen: string | null;
    faltantes: string[];
    requiere_carta: boolean;
    employee?: { id: number; nombres: string; apellidos: string; numero_documento: string; cargo: string | null } | null;
}

interface Perfil {
    id: number;
    cargo: string;
    factores_riesgo: string | null;
    pve: string | null;
    examenes: Matriz | null;
    otros_examenes: string | null;
    periodicidad_meses: number;
    revisado_por: string | null;
    licencia_so: string | null;
}

interface EmpleadoRow {
    id: number;
    nombres: string;
    apellidos: string;
    numero_documento: string;
    cargo: string | null;
}

interface Stats {
    trabajadores: number;
    al_dia: number;
    por_vencer: number;
    vencidos: number;
    sin_examen: number;
    con_restricciones: number;
    cartas_pendientes: number;
    cargos_sin_perfil: number;
}

interface Props {
    trabajadores: Trabajador[];
    examenes: Examen[];
    perfiles: Perfil[];
    empleados: EmpleadoRow[];
    cargosSinPerfil: string[];
    stats: Stats;
    catalogos: {
        examenes: Record<string, string>;
        momentos: Momento[];
        tipos: string[];
        conceptos: string[];
        dias_aviso: number;
    };
    needsClient: boolean;
}

const ETIQUETA_TIPO: Record<string, string> = {
    ingreso: 'Ingreso',
    periodico: 'Periódico',
    retiro: 'Retiro',
    post_incapacidad: 'Post-incapacidad',
    reintegro: 'Reintegro',
};

const ETIQUETA_CONCEPTO: Record<string, string> = {
    apto: 'Apto',
    apto_con_restricciones: 'Apto con restricciones',
    no_apto: 'No apto',
    aplazado: 'Aplazado',
};

const ETIQUETA_MOMENTO: Record<Momento, string> = { ingreso: 'Ingreso', periodico: 'Periódico', retiro: 'Retiro' };

const ESTADO: Record<Trabajador['estado'], { label: string; clase: string }> = {
    al_dia: { label: 'Al día', clase: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400' },
    por_vencer: { label: 'Por vencer', clase: 'bg-amber-500/15 text-amber-700 dark:text-amber-400' },
    vencido: { label: 'Vencido', clase: 'bg-destructive/15 text-destructive' },
    sin_examen: { label: 'Sin examen', clase: 'bg-muted text-muted-foreground' },
};

const SELECT = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const AREA = 'border-input bg-background w-full rounded-md border px-3 py-2 text-sm';

const hoy = () => new Date().toISOString().slice(0, 10);

const examenVacio = {
    employee_id: '' as number | string,
    fecha: hoy(),
    tipo: 'periodico',
    ips: '',
    examenes_realizados: [] as string[],
    concepto: 'apto',
    restricciones: '',
    recomendaciones_personales: '',
    recomendaciones_sst: '',
    recomendaciones_medicas: '',
    carta_entregada: false as boolean,
    fecha_carta: '',
    pve: '',
    plan_accion: '',
    seguimiento: '',
    proximo_examen: '',
};

const perfilVacio = {
    cargo: '',
    factores_riesgo: '',
    pve: '',
    examenes: {} as Matriz,
    otros_examenes: '',
    periodicidad_meses: 12 as number | string,
    revisado_por: '',
    licencia_so: '',
};

const clave = (c: string | null | undefined) => (c ?? '').trim().toLowerCase();

export default function SaludOcupacionalIndex({
    trabajadores,
    examenes,
    perfiles,
    empleados,
    cargosSinPerfil,
    stats,
    catalogos,
    needsClient,
}: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const { tiene } = usePartes('salud-ocupacional');
    const [pestana, setPestana] = useState<Pestana>('trabajadores');
    const [filtro, setFiltro] = useState<'todos' | Trabajador['estado']>('todos');

    const [dlgEx, setDlgEx] = useState(false);
    const [editEx, setEditEx] = useState<Examen | null>(null);
    const [dlgPer, setDlgPer] = useState(false);
    const [editPer, setEditPer] = useState<Perfil | null>(null);

    const fEx = useForm({ ...examenVacio });
    const fPer = useForm({ ...perfilVacio });

    const codigos = Object.keys(catalogos.examenes);

    // --------------------------------------------------------------- examen
    function abrirEx(e?: Examen, employeeId?: number) {
        setEditEx(e ?? null);
        fEx.clearErrors();
        fEx.setData(
            e
                ? {
                      employee_id: e.employee_id,
                      fecha: e.fecha,
                      tipo: e.tipo,
                      ips: e.ips ?? '',
                      examenes_realizados: e.examenes_realizados ?? [],
                      concepto: e.concepto,
                      restricciones: e.restricciones ?? '',
                      recomendaciones_personales: e.recomendaciones_personales ?? '',
                      recomendaciones_sst: e.recomendaciones_sst ?? '',
                      recomendaciones_medicas: e.recomendaciones_medicas ?? '',
                      carta_entregada: e.carta_entregada,
                      fecha_carta: e.fecha_carta ?? '',
                      pve: e.pve ?? '',
                      plan_accion: e.plan_accion ?? '',
                      seguimiento: e.seguimiento ?? '',
                      proximo_examen: e.proximo_examen ?? '',
                  }
                : { ...examenVacio, employee_id: employeeId ?? '', examenes_realizados: [] },
        );
        setDlgEx(true);
    }

    /**
     * Lo que el profesiograma exige al trabajador elegido en el momento
     * elegido. Es la razón de tener profesiograma: que quien registra el
     * examen vea al instante si la IPS dejó algo por hacer.
     */
    const perfilDelExamen = (() => {
        const emp = empleados.find((e) => String(e.id) === String(fEx.data.employee_id));
        return emp ? perfiles.find((p) => clave(p.cargo) === clave(emp.cargo)) : undefined;
    })();
    const exigidos =
        perfilDelExamen && (catalogos.momentos as string[]).includes(fEx.data.tipo)
            ? codigos.filter((c) => perfilDelExamen.examenes?.[c]?.[fEx.data.tipo as Momento])
            : [];
    const faltan = exigidos.filter((c) => !fEx.data.examenes_realizados.includes(c));

    function toggleRealizado(c: string) {
        const lista = fEx.data.examenes_realizados;
        fEx.setData('examenes_realizados', lista.includes(c) ? lista.filter((x) => x !== c) : [...lista, c]);
    }

    const guardarEx: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setDlgEx(false) };
        if (editEx) fEx.put(route('salud-ocupacional.examenes.update', editEx.id), opts);
        else fEx.post(route('salud-ocupacional.examenes.store'), opts);
    };

    // --------------------------------------------------------------- perfil
    function abrirPer(p?: Perfil) {
        setEditPer(p ?? null);
        fPer.clearErrors();
        fPer.setData(
            p
                ? {
                      cargo: p.cargo,
                      factores_riesgo: p.factores_riesgo ?? '',
                      pve: p.pve ?? '',
                      examenes: p.examenes ?? {},
                      otros_examenes: p.otros_examenes ?? '',
                      periodicidad_meses: p.periodicidad_meses,
                      revisado_por: p.revisado_por ?? '',
                      licencia_so: p.licencia_so ?? '',
                  }
                : { ...perfilVacio, examenes: {} },
        );
        setDlgPer(true);
    }

    function toggleMatriz(c: string, m: Momento) {
        const actual = fPer.data.examenes[c] ?? {};
        fPer.setData('examenes', { ...fPer.data.examenes, [c]: { ...actual, [m]: !actual[m] } });
    }

    const guardarPer: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setDlgPer(false) };
        if (editPer) fPer.put(route('salud-ocupacional.perfiles.update', editPer.id), opts);
        else fPer.post(route('salud-ocupacional.perfiles.store'), opts);
    };

    const eliminar = (ruta: string, id: number, pregunta: string) => confirm(pregunta) && router.delete(route(ruta, id), { preserveScroll: true });

    const PESTANAS = (
        [
            { key: 'trabajadores', label: 'Estado por trabajador', n: trabajadores.length },
            { key: 'examenes', label: 'Exámenes', n: examenes.length },
            { key: 'profesiograma', label: 'Profesiograma', n: perfiles.length },
        ] as { key: Pestana; label: string; n: number }[]
    ).filter((p) => p.key === 'trabajadores' || tiene(p.key));

    const visibles = filtro === 'todos' ? trabajadores : trabajadores.filter((t) => t.estado === filtro);

    const resumenExamenes = (lista: string[] | null) =>
        (lista ?? []).length === 0 ? '—' : (lista ?? []).map((c) => catalogos.examenes[c] ?? c).join(', ');

    return (
        <ModuloPage
            titulo="Salud ocupacional"
            descripcion="Profesiograma y exámenes médicos ocupacionales (Res. 2346 de 2007)"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button onClick={() => (pestana === 'profesiograma' ? abrirPer() : abrirEx())} className="gap-2">
                        <Plus className="size-4" />
                        {pestana === 'profesiograma' ? 'Agregar cargo' : 'Registrar examen'}
                    </Button>
                ) : undefined
            }
            filtros={
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
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label={`Trabajadores con examen al día (de ${stats.trabajadores})`}
                    value={stats.trabajadores > 0 ? `${Math.round(((stats.al_dia + stats.por_vencer) / stats.trabajadores) * 100)} %` : '—'}
                    icon={Stethoscope}
                />
                <StatCard
                    label="Vencidos o sin examen"
                    value={stats.vencidos + stats.sin_examen}
                    icon={UserX}
                    alerta={stats.vencidos + stats.sin_examen > 0}
                />
                <StatCard
                    label={`Vencen en ${catalogos.dias_aviso} días`}
                    value={stats.por_vencer}
                    icon={CalendarClock}
                    alerta={stats.por_vencer > 0}
                />
                {/* Sin carta firmada, el trabajador no fue notificado de sus restricciones. */}
                <StatCard
                    label="Cartas de recomendaciones sin entregar"
                    value={stats.cartas_pendientes}
                    icon={FileWarning}
                    alerta={stats.cartas_pendientes > 0}
                />
            </div>

            {!needsClient && (stats.cargos_sin_perfil > 0 || stats.con_restricciones > 0) && (
                <div className="text-muted-foreground space-y-1 rounded-md border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
                    {stats.cargos_sin_perfil > 0 && (
                        <p>
                            <span className="font-medium text-amber-700 dark:text-amber-400">
                                {stats.cargos_sin_perfil} cargo(s) de la nómina sin profesiograma:
                            </span>{' '}
                            {cargosSinPerfil.join(', ')}. Sin perfil no se puede saber qué exámenes les tocan.
                        </p>
                    )}
                    {stats.con_restricciones > 0 && (
                        <p>
                            <span className="font-medium text-amber-700 dark:text-amber-400">{stats.con_restricciones}</span> trabajador(es) con
                            restricciones o no aptos en su último examen: revisar reubicación y el PVE que aplique.
                        </p>
                    )}
                </div>
            )}

            <Card>
                <CardContent className="p-0">
                    {/* ------------------------------------------- trabajadores */}
                    {pestana === 'trabajadores' && (
                        <div>
                            <div className="flex flex-wrap gap-1 border-b p-3">
                                {(['todos', 'vencido', 'sin_examen', 'por_vencer', 'al_dia'] as const).map((f) => (
                                    <button
                                        key={f}
                                        type="button"
                                        onClick={() => setFiltro(f)}
                                        className={cn('rounded-md px-2.5 py-1 text-xs', filtro === f ? 'bg-muted font-medium' : 'hover:bg-muted/60')}
                                    >
                                        {f === 'todos' ? 'Todos' : ESTADO[f].label}
                                    </button>
                                ))}
                            </div>
                            {visibles.length === 0 ? (
                                <p className="text-muted-foreground p-8 text-center text-sm">
                                    {trabajadores.length === 0 ? 'No hay trabajadores activos en la nómina.' : 'Nadie en este estado.'}
                                </p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                            <tr>
                                                <th className="px-4 py-2.5 font-semibold">Trabajador</th>
                                                <th className="px-4 py-2.5 font-semibold">Cargo</th>
                                                <th className="px-4 py-2.5 font-semibold">Último examen</th>
                                                <th className="px-4 py-2.5 font-semibold">Concepto</th>
                                                <th className="px-4 py-2.5 font-semibold">Próximo</th>
                                                <th className="px-4 py-2.5 font-semibold">Estado</th>
                                                {canManage && <th className="px-4 py-2.5" />}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {visibles.map((t) => (
                                                <tr key={t.employee_id} className="border-t">
                                                    <td className="px-4 py-2.5">
                                                        <div className="font-medium">{t.nombre}</div>
                                                        <div className="text-muted-foreground text-xs">{t.numero_documento}</div>
                                                    </td>
                                                    <td className="px-4 py-2.5">
                                                        {t.cargo ?? '—'}
                                                        {t.cargo && !t.tiene_perfil && (
                                                            <div className="text-xs text-amber-600 dark:text-amber-400">sin profesiograma</div>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">
                                                        {t.ultimo_examen ?? '—'}
                                                        {t.ultimo_tipo && (
                                                            <span className="text-muted-foreground text-xs"> · {ETIQUETA_TIPO[t.ultimo_tipo]}</span>
                                                        )}
                                                    </td>
                                                    <td className={cn('px-4 py-2.5', t.con_restricciones && 'text-amber-700 dark:text-amber-400')}>
                                                        {t.concepto ? ETIQUETA_CONCEPTO[t.concepto] : '—'}
                                                    </td>
                                                    <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">{t.proximo_examen ?? '—'}</td>
                                                    <td className="px-4 py-2.5">
                                                        <Badge className={cn('font-normal', ESTADO[t.estado].clase)}>{ESTADO[t.estado].label}</Badge>
                                                    </td>
                                                    {canManage && (
                                                        <td className="px-4 py-2.5 text-right">
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                className="gap-1"
                                                                onClick={() => abrirEx(undefined, t.employee_id)}
                                                            >
                                                                <Plus className="size-3.5" /> Examen
                                                            </Button>
                                                        </td>
                                                    )}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    )}

                    {/* ----------------------------------------------- exámenes */}
                    {pestana === 'examenes' &&
                        (examenes.length === 0 ? (
                            <p className="text-muted-foreground p-8 text-center text-sm">Todavía no hay exámenes registrados.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                        <tr>
                                            <th className="px-4 py-2.5 font-semibold">Fecha</th>
                                            <th className="px-4 py-2.5 font-semibold">Trabajador</th>
                                            <th className="px-4 py-2.5 font-semibold">Tipo</th>
                                            <th className="px-4 py-2.5 font-semibold">Concepto</th>
                                            <th className="px-4 py-2.5 font-semibold">Profesiograma</th>
                                            <th className="px-4 py-2.5 font-semibold">Carta</th>
                                            {canManage && <th className="px-4 py-2.5" />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {examenes.map((e) => (
                                            <tr key={e.id} className="border-t">
                                                <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">{e.fecha}</td>
                                                <td className="px-4 py-2.5">
                                                    <div className="font-medium">
                                                        {e.employee ? `${e.employee.apellidos} ${e.employee.nombres}` : '—'}
                                                    </div>
                                                    <div className="text-muted-foreground text-xs">{e.employee?.cargo ?? ''}</div>
                                                </td>
                                                <td className="px-4 py-2.5">{ETIQUETA_TIPO[e.tipo] ?? e.tipo}</td>
                                                <td
                                                    className={cn(
                                                        'px-4 py-2.5',
                                                        (e.concepto === 'apto_con_restricciones' || e.concepto === 'no_apto') &&
                                                            'text-amber-700 dark:text-amber-400',
                                                    )}
                                                >
                                                    {ETIQUETA_CONCEPTO[e.concepto] ?? e.concepto}
                                                </td>
                                                <td className="px-4 py-2.5 text-xs">
                                                    {e.faltantes.length === 0 ? (
                                                        <span className="text-emerald-600 dark:text-emerald-500">completo</span>
                                                    ) : (
                                                        <span className="text-destructive" title={resumenExamenes(e.faltantes)}>
                                                            faltan {e.faltantes.length}: {resumenExamenes(e.faltantes)}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5 text-xs">
                                                    {!e.requiere_carta ? (
                                                        <span className="text-muted-foreground">no aplica</span>
                                                    ) : e.carta_entregada ? (
                                                        <span className="text-emerald-600 dark:text-emerald-500">entregada {e.fecha_carta}</span>
                                                    ) : (
                                                        <span className="text-destructive">pendiente</span>
                                                    )}
                                                </td>
                                                {canManage && (
                                                    <td className="px-4 py-2.5">
                                                        <div className="flex justify-end gap-1">
                                                            <Button size="icon" variant="ghost" onClick={() => abrirEx(e)} aria-label="Editar">
                                                                <PenLine className="size-4" />
                                                            </Button>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label="Eliminar"
                                                                onClick={() =>
                                                                    eliminar('salud-ocupacional.examenes.destroy', e.id, '¿Eliminar este examen?')
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

                    {/* ------------------------------------------ profesiograma */}
                    {pestana === 'profesiograma' && (
                        <div>
                            {canManage && cargosSinPerfil.length > 0 && (
                                <div className="flex items-center justify-between gap-2 border-b p-3 text-sm">
                                    <span className="text-muted-foreground">
                                        Crea un cargo vacío por cada uno de la nómina que falte. Los exámenes de cada cargo los define el médico
                                        ocupacional.
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="shrink-0 gap-2"
                                        onClick={() => router.post(route('salud-ocupacional.perfiles.nomina'), {}, { preserveScroll: true })}
                                    >
                                        <ClipboardList className="size-4" />
                                        Traer cargos de la nómina ({cargosSinPerfil.length})
                                    </Button>
                                </div>
                            )}
                            {perfiles.length === 0 ? (
                                <p className="text-muted-foreground p-8 text-center text-sm">El profesiograma está vacío.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                            <tr>
                                                <th className="px-4 py-2.5 font-semibold">Cargo</th>
                                                <th className="px-4 py-2.5 font-semibold">Exámenes de ingreso</th>
                                                <th className="px-4 py-2.5 font-semibold">Periódicos</th>
                                                <th className="px-4 py-2.5 font-semibold">Cada</th>
                                                {canManage && <th className="px-4 py-2.5" />}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {perfiles.map((p) => {
                                                const de = (m: Momento) => codigos.filter((c) => p.examenes?.[c]?.[m]);
                                                const vacio = de('ingreso').length + de('periodico').length + de('retiro').length === 0;
                                                return (
                                                    <tr key={p.id} className="border-t align-top">
                                                        <td className="px-4 py-2.5">
                                                            <div className="font-medium">{p.cargo}</div>
                                                            {p.pve && <div className="text-muted-foreground text-xs">PVE: {p.pve}</div>}
                                                        </td>
                                                        {vacio ? (
                                                            <td colSpan={2} className="px-4 py-2.5 text-xs text-amber-600 dark:text-amber-400">
                                                                Sin exámenes marcados
                                                            </td>
                                                        ) : (
                                                            <>
                                                                <td className="px-4 py-2.5 text-xs">{resumenExamenes(de('ingreso'))}</td>
                                                                <td className="px-4 py-2.5 text-xs">{resumenExamenes(de('periodico'))}</td>
                                                            </>
                                                        )}
                                                        <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">{p.periodicidad_meses} meses</td>
                                                        {canManage && (
                                                            <td className="px-4 py-2.5">
                                                                <div className="flex justify-end gap-1">
                                                                    <Button
                                                                        size="icon"
                                                                        variant="ghost"
                                                                        onClick={() => abrirPer(p)}
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
                                                                                'salud-ocupacional.perfiles.destroy',
                                                                                p.id,
                                                                                `¿Quitar ${p.cargo} del profesiograma?`,
                                                                            )
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
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* ------------------------------------------------- diálogo: examen */}
            <Dialog open={dlgEx} onOpenChange={setDlgEx}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>{editEx ? 'Editar examen' : 'Registrar examen'}</DialogTitle>
                        <DialogDescription>
                            Solo el concepto de aptitud y las recomendaciones. El diagnóstico y la historia clínica los custodia la IPS.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={guardarEx} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Campo id="e-emp" label="Trabajador" error={fEx.errors.employee_id} className="sm:col-span-3">
                                <select
                                    id="e-emp"
                                    value={String(fEx.data.employee_id)}
                                    onChange={(e) => fEx.setData('employee_id', e.target.value)}
                                    className={SELECT}
                                >
                                    <option value="">— Elegir —</option>
                                    {empleados.map((e) => (
                                        <option key={e.id} value={e.id}>
                                            {e.apellidos} {e.nombres} {e.cargo ? `· ${e.cargo}` : ''}
                                        </option>
                                    ))}
                                </select>
                            </Campo>
                            <Campo id="e-fecha" label="Fecha" error={fEx.errors.fecha}>
                                <Input id="e-fecha" type="date" value={fEx.data.fecha} onChange={(e) => fEx.setData('fecha', e.target.value)} />
                            </Campo>
                            <Campo id="e-tipo" label="Tipo" error={fEx.errors.tipo}>
                                <select id="e-tipo" value={fEx.data.tipo} onChange={(e) => fEx.setData('tipo', e.target.value)} className={SELECT}>
                                    {catalogos.tipos.map((t) => (
                                        <option key={t} value={t}>
                                            {ETIQUETA_TIPO[t] ?? t}
                                        </option>
                                    ))}
                                </select>
                            </Campo>
                            <Campo id="e-ips" label="IPS" error={fEx.errors.ips}>
                                <Input id="e-ips" value={fEx.data.ips} onChange={(e) => fEx.setData('ips', e.target.value)} />
                            </Campo>
                        </div>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between gap-2">
                                <Label>Exámenes realizados</Label>
                                {exigidos.length > 0 && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            fEx.setData('examenes_realizados', Array.from(new Set([...fEx.data.examenes_realizados, ...exigidos])))
                                        }
                                    >
                                        Marcar los del profesiograma
                                    </Button>
                                )}
                            </div>
                            {fEx.data.employee_id !== '' && !perfilDelExamen && (
                                <p className="text-xs text-amber-600 dark:text-amber-400">
                                    El cargo de este trabajador no está en el profesiograma: no se puede comprobar qué le falta.
                                </p>
                            )}
                            <div className="grid gap-1 sm:grid-cols-2">
                                {codigos.map((c) => (
                                    <label key={c} className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            className="size-4"
                                            checked={fEx.data.examenes_realizados.includes(c)}
                                            onChange={() => toggleRealizado(c)}
                                        />
                                        <span className={cn(exigidos.includes(c) && 'font-medium')}>
                                            {catalogos.examenes[c]}
                                            {exigidos.includes(c) && <span className="text-muted-foreground text-xs"> · lo pide el cargo</span>}
                                        </span>
                                    </label>
                                ))}
                            </div>
                            {faltan.length > 0 && (
                                <p className="text-destructive text-xs">
                                    El profesiograma pide y no están marcados: {faltan.map((c) => catalogos.examenes[c]).join(', ')}.
                                </p>
                            )}
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Campo id="e-con" label="Concepto de aptitud" error={fEx.errors.concepto}>
                                <select
                                    id="e-con"
                                    value={fEx.data.concepto}
                                    onChange={(e) => fEx.setData('concepto', e.target.value)}
                                    className={SELECT}
                                >
                                    {catalogos.conceptos.map((c) => (
                                        <option key={c} value={c}>
                                            {ETIQUETA_CONCEPTO[c] ?? c}
                                        </option>
                                    ))}
                                </select>
                            </Campo>
                            <Campo id="e-prox" label="Próximo examen (vacío = según el cargo)" error={fEx.errors.proximo_examen}>
                                <Input
                                    id="e-prox"
                                    type="date"
                                    value={fEx.data.proximo_examen}
                                    onChange={(e) => fEx.setData('proximo_examen', e.target.value)}
                                />
                            </Campo>
                        </div>
                        {(fEx.data.concepto === 'apto_con_restricciones' || fEx.data.restricciones) && (
                            <Campo id="e-res" label="Restricciones" error={fEx.errors.restricciones}>
                                <textarea
                                    id="e-res"
                                    rows={2}
                                    value={fEx.data.restricciones}
                                    onChange={(e) => fEx.setData('restricciones', e.target.value)}
                                    className={AREA}
                                />
                            </Campo>
                        )}
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Campo id="e-rp" label="Recomendaciones personales" error={fEx.errors.recomendaciones_personales}>
                                <textarea
                                    id="e-rp"
                                    rows={2}
                                    value={fEx.data.recomendaciones_personales}
                                    onChange={(e) => fEx.setData('recomendaciones_personales', e.target.value)}
                                    className={AREA}
                                />
                            </Campo>
                            <Campo id="e-rs" label="Recomendaciones SST" error={fEx.errors.recomendaciones_sst}>
                                <textarea
                                    id="e-rs"
                                    rows={2}
                                    value={fEx.data.recomendaciones_sst}
                                    onChange={(e) => fEx.setData('recomendaciones_sst', e.target.value)}
                                    className={AREA}
                                />
                            </Campo>
                            <Campo id="e-rm" label="Recomendaciones médicas" error={fEx.errors.recomendaciones_medicas}>
                                <textarea
                                    id="e-rm"
                                    rows={2}
                                    value={fEx.data.recomendaciones_medicas}
                                    onChange={(e) => fEx.setData('recomendaciones_medicas', e.target.value)}
                                    className={AREA}
                                />
                            </Campo>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="flex items-end pb-2">
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        className="size-4"
                                        checked={fEx.data.carta_entregada}
                                        onChange={(e) => fEx.setData('carta_entregada', e.target.checked)}
                                    />
                                    Carta de recomendaciones entregada
                                </label>
                            </div>
                            <Campo id="e-fc" label="Fecha de entrega de la carta" error={fEx.errors.fecha_carta}>
                                <Input
                                    id="e-fc"
                                    type="date"
                                    value={fEx.data.fecha_carta}
                                    onChange={(e) => fEx.setData('fecha_carta', e.target.value)}
                                />
                            </Campo>
                            <Campo id="e-pve" label="PVE" error={fEx.errors.pve}>
                                <Input id="e-pve" value={fEx.data.pve} onChange={(e) => fEx.setData('pve', e.target.value)} />
                            </Campo>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Campo id="e-plan" label="Plan de acción" error={fEx.errors.plan_accion}>
                                <textarea
                                    id="e-plan"
                                    rows={2}
                                    value={fEx.data.plan_accion}
                                    onChange={(e) => fEx.setData('plan_accion', e.target.value)}
                                    className={AREA}
                                />
                            </Campo>
                            <Campo id="e-seg" label="Seguimiento" error={fEx.errors.seguimiento}>
                                <textarea
                                    id="e-seg"
                                    rows={2}
                                    value={fEx.data.seguimiento}
                                    onChange={(e) => fEx.setData('seguimiento', e.target.value)}
                                    className={AREA}
                                />
                            </Campo>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDlgEx(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={fEx.processing}>
                                Guardar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* -------------------------------------------------- diálogo: cargo */}
            <Dialog open={dlgPer} onOpenChange={setDlgPer}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>{editPer ? `Profesiograma: ${editPer.cargo}` : 'Agregar cargo'}</DialogTitle>
                        <DialogDescription>
                            Marca qué exámenes pide el cargo en el ingreso, en los periódicos y en el retiro. Lo firma un profesional con licencia en
                            salud ocupacional.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={guardarPer} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Campo id="p-cargo" label="Cargo" error={fPer.errors.cargo} className="sm:col-span-2">
                                <Input id="p-cargo" value={fPer.data.cargo} onChange={(e) => fPer.setData('cargo', e.target.value)} />
                            </Campo>
                            <Campo id="p-per" label="Periódico cada (meses)" error={fPer.errors.periodicidad_meses}>
                                <Input
                                    id="p-per"
                                    type="number"
                                    min={1}
                                    max={60}
                                    value={fPer.data.periodicidad_meses}
                                    onChange={(e) => fPer.setData('periodicidad_meses', e.target.value)}
                                />
                            </Campo>
                        </div>
                        <Campo id="p-fr" label="Factores de riesgo de exposición" error={fPer.errors.factores_riesgo}>
                            <textarea
                                id="p-fr"
                                rows={2}
                                value={fPer.data.factores_riesgo}
                                onChange={(e) => fPer.setData('factores_riesgo', e.target.value)}
                                className={AREA}
                            />
                        </Campo>
                        <Campo id="p-pve" label="PVE que aplican" error={fPer.errors.pve}>
                            <Input
                                id="p-pve"
                                placeholder="Cardiovascular, desórdenes musculoesqueléticos…"
                                value={fPer.data.pve}
                                onChange={(e) => fPer.setData('pve', e.target.value)}
                            />
                        </Campo>

                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground bg-muted/40 text-xs">
                                    <tr>
                                        <th className="px-3 py-2 text-left font-semibold">Examen</th>
                                        {catalogos.momentos.map((m) => (
                                            <th key={m} className="w-24 px-3 py-2 text-center font-semibold">
                                                {ETIQUETA_MOMENTO[m]}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {codigos.map((c) => (
                                        <tr key={c} className="border-t">
                                            <td className="px-3 py-1.5">{catalogos.examenes[c]}</td>
                                            {catalogos.momentos.map((m) => (
                                                <td key={m} className="px-3 py-1.5 text-center">
                                                    <input
                                                        type="checkbox"
                                                        className="size-4"
                                                        aria-label={`${catalogos.examenes[c]} – ${ETIQUETA_MOMENTO[m]}`}
                                                        checked={!!fPer.data.examenes[c]?.[m]}
                                                        onChange={() => toggleMatriz(c, m)}
                                                    />
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <Campo id="p-otros" label="Otros exámenes" error={fPer.errors.otros_examenes}>
                                <Input
                                    id="p-otros"
                                    value={fPer.data.otros_examenes}
                                    onChange={(e) => fPer.setData('otros_examenes', e.target.value)}
                                />
                            </Campo>
                            <Campo id="p-rev" label="Revisado por" error={fPer.errors.revisado_por}>
                                <Input id="p-rev" value={fPer.data.revisado_por} onChange={(e) => fPer.setData('revisado_por', e.target.value)} />
                            </Campo>
                            <Campo id="p-lic" label="Licencia en SO" error={fPer.errors.licencia_so}>
                                <Input id="p-lic" value={fPer.data.licencia_so} onChange={(e) => fPer.setData('licencia_so', e.target.value)} />
                            </Campo>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDlgPer(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={fPer.processing}>
                                Guardar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ModuloPage>
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
