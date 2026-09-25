import { selectCls, textareaCls } from '@/components/control-documental/etiquetas';
import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowDownToLine, BriefcaseBusiness, CalendarPlus, FileOutput, GraduationCap, Pencil, Plus, Trash2, UserX } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type TipoRequisito = 'educacion' | 'formacion' | 'experiencia' | 'habilidad';
type EstadoCelda = 'cumple' | 'vencido' | 'falta' | 'no_cumple' | 'sin_evaluar';

interface Requisito {
    id: number;
    tipo: TipoRequisito;
    descripcion: string;
    training_topic_id: number | null;
    topic: { id: number; codigo: string | null; titulo: string } | null;
}

interface Celda {
    estado: EstadoCelda;
    detalle: string | null;
    evidencia: string | null;
    fuente: 'capacitacion' | 'evaluacion' | null;
}

interface Cargo {
    id: number;
    nombre: string;
    process_id: number | null;
    reporta_a: string | null;
    objetivo: string | null;
    funciones: string | null;
    responsabilidades_sig: string | null;
    autoridad: string | null;
    process: { id: number; sigla: string; nombre: string } | null;
    requisitos: Requisito[];
    empleados: { id: number; nombre: string; documento: string | null }[];
    celdas: Record<string, Celda>;
}

interface FilaResumen {
    id: number;
    nombre: string;
    proceso: string | null;
    perfil_completo: boolean;
    trabajadores: number;
    requisitos: number;
    celdas: number;
    cumple: number;
    porcentaje: number | null;
}

interface Brecha {
    cargo: string;
    job_position_id: number;
    requisito_id: number;
    requisito: string;
    tema: string | null;
    training_topic_id: number | null;
    personas: { id: number; nombre: string; estado: EstadoCelda }[];
}

interface Documento {
    clave: string;
    titulo: string;
    id: number | null;
    codigo: string | null;
    estado: string | null;
}

interface Props {
    needsClient: boolean;
    resumen: { cargos: FilaResumen[]; brechas: Brecha[]; sin_cargo: number };
    cargo: Cargo | null;
    procesos: { id: number; sigla: string; nombre: string }[];
    temas: { id: number; codigo: string | null; titulo: string }[];
    cargosNomina: { nombre: string; trabajadores: number }[];
    documentos: Documento[];
    tipos: Record<TipoRequisito, string>;
    estados: Record<EstadoCelda, string>;
}

const CELDA: Record<EstadoCelda, string> = {
    cumple: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    vencido: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    falta: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    no_cumple: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    sin_evaluar: 'bg-muted text-muted-foreground',
};

type Pestana = 'perfil' | 'requisitos' | 'matriz';

export default function Cargos({ needsClient, resumen, cargo, procesos, temas, cargosNomina, documentos, tipos, estados }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const errores = usePage<SharedData>().props.errors as Record<string, string | undefined>;
    const [pestana, setPestana] = useState<Pestana>('matriz');
    const [editar, setEditar] = useState<Cargo | 'nuevo' | null>(null);
    const [evaluar, setEvaluar] = useState<{ empleado: Cargo['empleados'][number]; requisito: Requisito } | null>(null);

    const celdas = resumen.cargos.reduce((s, c) => s + c.celdas, 0);
    const cumplimiento = celdas ? Math.round((100 * resumen.cargos.reduce((s, c) => s + c.cumple, 0)) / celdas) : null;
    const conBrecha = new Set(resumen.brechas.flatMap((b) => b.personas.map((p) => p.id))).size;
    const nominaPendiente = cargosNomina.reduce((s, c) => s + c.trabajadores, 0);

    const seleccionar = (id: number) => router.get('/cargos', { cargo: id }, { preserveState: true, preserveScroll: true });

    return (
        <ModuloPage
            titulo="Perfiles de cargo y competencias"
            descripcion="Funciones, responsabilidades en el SIG y la competencia que exige cada cargo, contra lo que demuestra cada trabajador (ISO 5.3 y 7.2, Dec. 1072 2.2.4.6.8 y 2.2.4.6.11)"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <div className="flex flex-wrap gap-2">
                        {cargosNomina.length > 0 && (
                            <Button
                                variant="outline"
                                className="gap-2"
                                title={cargosNomina.map((c) => `${c.nombre} (${c.trabajadores})`).join('\n')}
                                onClick={() => router.post('/cargos/desde-nomina', {}, { preserveScroll: true })}
                            >
                                <ArrowDownToLine className="size-4" /> Traer cargos de la nómina ({cargosNomina.length})
                            </Button>
                        )}
                        <Button className="gap-2" onClick={() => setEditar('nuevo')}>
                            <Plus className="size-4" /> Nuevo cargo
                        </Button>
                    </div>
                ) : undefined
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Cargos con perfil" value={resumen.cargos.length} icon={BriefcaseBusiness} />
                <StatCard label="Trabajadores con cargo sin perfil" value={resumen.sin_cargo} icon={UserX} alerta={resumen.sin_cargo > 0} />
                <StatCard label="Cumplimiento de la matriz" value={cumplimiento === null ? '—' : `${cumplimiento} %`} icon={GraduationCap} />
                <StatCard label="Personas con brechas de formación" value={conBrecha} icon={CalendarPlus} alerta={conBrecha > 0} />
            </div>

            {resumen.cargos.length === 0 ? (
                <Card>
                    <CardContent className="text-muted-foreground p-8 text-center text-sm">
                        {nominaPendiente > 0
                            ? `Todavía no hay perfiles. En la nómina hay ${cargosNomina.length} cargos distintos: tráelos y completa su perfil.`
                            : 'Todavía no hay perfiles de cargo.'}
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-4 lg:grid-cols-[18rem_1fr]">
                    <Card className="h-fit">
                        <CardContent className="p-1.5">
                            {resumen.cargos.map((c) => (
                                <button
                                    key={c.id}
                                    type="button"
                                    onClick={() => seleccionar(c.id)}
                                    className={cn(
                                        'hover:bg-muted flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left text-sm',
                                        cargo?.id === c.id && 'bg-muted font-medium',
                                    )}
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate">{c.nombre}</span>
                                        <span className="text-muted-foreground block text-xs font-normal">
                                            {c.trabajadores} {c.trabajadores === 1 ? 'persona' : 'personas'} · {c.requisitos} requisitos
                                            {!c.perfil_completo && <span className="text-amber-700 dark:text-amber-400"> · perfil incompleto</span>}
                                        </span>
                                    </span>
                                    <span className="text-muted-foreground shrink-0 text-xs tabular-nums">
                                        {c.porcentaje === null ? '—' : `${c.porcentaje} %`}
                                    </span>
                                </button>
                            ))}
                        </CardContent>
                    </Card>

                    {cargo && (
                        <Card className="min-w-0">
                            <CardHeader className="flex flex-row items-start justify-between gap-3 pb-2">
                                <div className="min-w-0">
                                    <CardTitle className="text-base">{cargo.nombre}</CardTitle>
                                    <p className="text-muted-foreground text-xs">
                                        {cargo.process ? `${cargo.process.sigla} · ${cargo.process.nombre}` : 'Sin proceso'}
                                        {cargo.reporta_a && ` · reporta a ${cargo.reporta_a}`}
                                    </p>
                                </div>
                                {canManage && (
                                    <div className="flex shrink-0 gap-1">
                                        <Button size="icon" variant="ghost" onClick={() => setEditar(cargo)} aria-label="Editar perfil">
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label="Eliminar cargo"
                                            onClick={() =>
                                                confirm(`¿Eliminar el cargo «${cargo.nombre}» con sus requisitos y evaluaciones?`) &&
                                                router.delete(`/cargos/${cargo.id}`)
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                )}
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex gap-1 border-b">
                                    {(
                                        [
                                            ['matriz', `Matriz (${cargo.empleados.length})`],
                                            ['requisitos', `Requisitos (${cargo.requisitos.length})`],
                                            ['perfil', 'Perfil'],
                                        ] as const
                                    ).map(([k, label]) => (
                                        <button
                                            key={k}
                                            type="button"
                                            onClick={() => setPestana(k)}
                                            className={cn(
                                                '-mb-px border-b-2 px-4 py-2 text-sm',
                                                pestana === k ? 'border-primary font-medium' : 'text-muted-foreground border-transparent',
                                            )}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>

                                {pestana === 'perfil' && <Perfil cargo={cargo} />}
                                {pestana === 'requisitos' && <Requisitos cargo={cargo} temas={temas} tipos={tipos} canManage={canManage} />}
                                {pestana === 'matriz' && (
                                    <Matriz
                                        cargo={cargo}
                                        estados={estados}
                                        tipos={tipos}
                                        onCelda={canManage ? (empleado, requisito) => setEvaluar({ empleado, requisito }) : undefined}
                                    />
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Necesidades de formación</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        {errores.requisito && <p className="text-destructive text-xs">{errores.requisito}</p>}
                        {resumen.brechas.length === 0 ? (
                            <p className="text-muted-foreground text-xs">
                                Sin brechas. Salen de los requisitos de formación que alguien del cargo no cumple, ya venció o no ha tomado.
                            </p>
                        ) : (
                            resumen.brechas.map((b) => (
                                <div
                                    key={b.requisito_id}
                                    className="flex flex-wrap items-start justify-between gap-3 border-t pt-3 first:border-0 first:pt-0"
                                >
                                    <div className="min-w-0">
                                        <div>
                                            {b.requisito} <span className="text-muted-foreground">· {b.cargo}</span>
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {b.personas.map((p) => `${p.nombre}${p.estado === 'vencido' ? ' (vencida)' : ''}`).join(', ')}
                                        </div>
                                    </div>
                                    {canManage &&
                                        (b.training_topic_id ? (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="shrink-0 gap-1.5"
                                                onClick={() => router.post(`/cargos/${b.job_position_id}/requisitos/${b.requisito_id}/programar`)}
                                            >
                                                <CalendarPlus className="size-3.5" /> Programar capacitación
                                            </Button>
                                        ) : (
                                            <span className="text-muted-foreground shrink-0 text-xs">Sin tema de capacitación asociado</span>
                                        ))}
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>

                <Card className="h-fit">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Documentos</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        {documentos.length === 0 && (
                            <p className="text-muted-foreground text-xs">El catálogo del SIG no está cargado en esta instalación.</p>
                        )}
                        {documentos.map((d) => (
                            <div key={d.clave} className="flex items-center justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="truncate" title={d.titulo}>
                                        {d.titulo}
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        {d.codigo ? (
                                            <Link href={`/control-documental/${d.id}`} className="underline underline-offset-2">
                                                {d.codigo} · {d.estado?.replace('_', ' ')}
                                            </Link>
                                        ) : (
                                            'Aún no está en el control documental'
                                        )}
                                    </div>
                                </div>
                                {canManage && (
                                    <Button
                                        size="icon"
                                        variant="outline"
                                        className="shrink-0"
                                        title="Enviar como borrador al control documental"
                                        aria-label={`Enviar ${d.titulo}`}
                                        onClick={() => router.post('/cargos/enviar', { documento: d.clave }, { preserveScroll: true })}
                                    >
                                        <FileOutput className="size-4" />
                                    </Button>
                                )}
                            </div>
                        ))}
                        {errores.documento && <p className="text-destructive text-xs">{errores.documento}</p>}
                    </CardContent>
                </Card>
            </div>

            {editar && <CargoDialog cargo={editar === 'nuevo' ? null : editar} procesos={procesos} onClose={() => setEditar(null)} />}
            {evaluar && cargo && (
                <EvaluarDialog
                    cargo={cargo}
                    empleado={evaluar.empleado}
                    requisito={evaluar.requisito}
                    celda={cargo.celdas[`${evaluar.empleado.id}-${evaluar.requisito.id}`]}
                    estados={estados}
                    onClose={() => setEvaluar(null)}
                />
            )}
        </ModuloPage>
    );
}

function Perfil({ cargo }: { cargo: Cargo }) {
    const bloques: [string, string | null][] = [
        ['Objetivo del cargo', cargo.objetivo],
        ['Funciones', cargo.funciones],
        ['Responsabilidades en el SIG', cargo.responsabilidades_sig],
        ['Autoridad', cargo.autoridad],
    ];

    return (
        <div className="grid gap-4 text-sm sm:grid-cols-2">
            {bloques.map(([titulo, texto]) => (
                <div key={titulo}>
                    <div className="text-muted-foreground mb-1 text-xs font-semibold">{titulo}</div>
                    {texto ? <p className="whitespace-pre-line">{texto}</p> : <p className="text-amber-700 dark:text-amber-400">Sin definir</p>}
                </div>
            ))}
        </div>
    );
}

function Requisitos({ cargo, temas, tipos, canManage }: { cargo: Cargo; temas: Props['temas']; tipos: Props['tipos']; canManage: boolean }) {
    const [editando, setEditando] = useState<Requisito | 'nuevo' | null>(null);

    return (
        <div className="space-y-3">
            {cargo.requisitos.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    Sin requisitos. Define qué educación, formación, experiencia y habilidades necesita quien ocupe este cargo.
                </p>
            ) : (
                (Object.keys(tipos) as TipoRequisito[]).map((tipo) => {
                    const lista = cargo.requisitos.filter((r) => r.tipo === tipo);
                    if (lista.length === 0) return null;
                    return (
                        <div key={tipo}>
                            <div className="text-muted-foreground mb-1 text-xs font-semibold">{tipos[tipo]}</div>
                            <ul className="space-y-1">
                                {lista.map((r) => (
                                    <li key={r.id} className="flex items-start justify-between gap-2 text-sm">
                                        <span>
                                            {r.descripcion}
                                            {r.topic && (
                                                <Badge variant="outline" className="ml-2 font-normal">
                                                    Capacitación: {r.topic.titulo}
                                                </Badge>
                                            )}
                                        </span>
                                        {canManage && (
                                            <span className="flex shrink-0 gap-1">
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    className="size-7"
                                                    onClick={() => setEditando(r)}
                                                    aria-label="Editar requisito"
                                                >
                                                    <Pencil className="size-3.5" />
                                                </Button>
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    className="size-7"
                                                    aria-label="Eliminar requisito"
                                                    onClick={() =>
                                                        confirm('¿Eliminar este requisito y sus evaluaciones?') &&
                                                        router.delete(`/cargos/${cargo.id}/requisitos/${r.id}`, { preserveScroll: true })
                                                    }
                                                >
                                                    <Trash2 className="size-3.5" />
                                                </Button>
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    );
                })
            )}
            {canManage && (
                <Button size="sm" variant="outline" className="gap-1.5" onClick={() => setEditando('nuevo')}>
                    <Plus className="size-3.5" /> Agregar requisito
                </Button>
            )}
            {editando && (
                <RequisitoDialog
                    cargo={cargo}
                    requisito={editando === 'nuevo' ? null : editando}
                    temas={temas}
                    tipos={tipos}
                    onClose={() => setEditando(null)}
                />
            )}
        </div>
    );
}

function Matriz({
    cargo,
    estados,
    tipos,
    onCelda,
}: {
    cargo: Cargo;
    estados: Props['estados'];
    tipos: Props['tipos'];
    onCelda?: (empleado: Cargo['empleados'][number], requisito: Requisito) => void;
}) {
    if (cargo.empleados.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                Ningún trabajador activo tiene el cargo «{cargo.nombre}». El cargo se toma de la ficha del empleado, escrito igual (sin importar
                mayúsculas).
            </p>
        );
    }
    if (cargo.requisitos.length === 0) {
        return <p className="text-muted-foreground text-sm">Agrega los requisitos del cargo para evaluar a las personas.</p>;
    }

    return (
        <div className="space-y-2">
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                        <tr>
                            <th className="px-3 py-2 font-semibold">Trabajador</th>
                            {cargo.requisitos.map((r) => (
                                <th key={r.id} className="min-w-28 px-2 py-2 align-bottom font-semibold" title={r.descripcion}>
                                    <div className="text-[10px] font-normal uppercase">{tipos[r.tipo]}</div>
                                    <div className="line-clamp-2">{r.descripcion}</div>
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {cargo.empleados.map((e) => (
                            <tr key={e.id} className="border-t">
                                <td className="px-3 py-2 whitespace-nowrap">{e.nombre}</td>
                                {cargo.requisitos.map((r) => {
                                    const c = cargo.celdas[`${e.id}-${r.id}`];
                                    return (
                                        <td key={r.id} className="px-2 py-1.5">
                                            <button
                                                type="button"
                                                disabled={!onCelda}
                                                onClick={() => onCelda?.(e, r)}
                                                title={[c.detalle, c.evidencia].filter(Boolean).join(' · ') || undefined}
                                                className={cn(
                                                    'w-full rounded px-2 py-1 text-xs font-medium',
                                                    CELDA[c.estado],
                                                    onCelda && 'hover:ring-ring hover:ring-1',
                                                )}
                                            >
                                                {estados[c.estado]}
                                            </button>
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <p className="text-muted-foreground text-xs">
                Un requisito de formación con tema de capacitación se cumple solo con una capacitación realizada, aprobada y vigente de ese tema. Los
                demás se evalúan a mano con su evidencia: haz clic en la celda.
            </p>
        </div>
    );
}

type CargoForm = {
    nombre: string;
    process_id: number | '';
    reporta_a: string;
    objetivo: string;
    funciones: string;
    responsabilidades_sig: string;
    autoridad: string;
};

function CargoDialog({ cargo, procesos, onClose }: { cargo: Cargo | null; procesos: Props['procesos']; onClose: () => void }) {
    const { data, setData, post, put, processing, errors } = useForm<CargoForm>({
        nombre: cargo?.nombre ?? '',
        process_id: cargo?.process_id ?? '',
        reporta_a: cargo?.reporta_a ?? '',
        objetivo: cargo?.objetivo ?? '',
        funciones: cargo?.funciones ?? '',
        responsabilidades_sig: cargo?.responsabilidades_sig ?? '',
        autoridad: cargo?.autoridad ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (cargo) put(`/cargos/${cargo.id}`, opts);
        else post('/cargos', opts);
    };

    const area = (campo: 'objetivo' | 'funciones' | 'responsabilidades_sig' | 'autoridad', label: string, filas: number, ayuda?: string) => (
        <div className="grid gap-2">
            <Label htmlFor={campo}>{label}</Label>
            <textarea
                id={campo}
                rows={filas}
                value={data[campo]}
                placeholder={ayuda}
                onChange={(e) => setData(campo, e.target.value)}
                className={textareaCls}
            />
            <InputError message={errors[campo]} />
        </div>
    );

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{cargo ? 'Editar perfil del cargo' : 'Nuevo cargo'}</DialogTitle>
                    <DialogDescription>
                        El nombre debe coincidir con el cargo escrito en la ficha de los empleados: así se unen con la matriz.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="nombre">Nombre del cargo</Label>
                            <Input id="nombre" value={data.nombre} onChange={(e) => setData('nombre', e.target.value)} />
                            <InputError message={errors.nombre} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="proceso">Proceso</Label>
                            <select
                                id="proceso"
                                value={data.process_id}
                                onChange={(e) => setData('process_id', e.target.value ? Number(e.target.value) : '')}
                                className={selectCls}
                            >
                                <option value="">Sin proceso</option>
                                {procesos.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.sigla} · {p.nombre}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="reporta_a">Reporta a</Label>
                        <Input id="reporta_a" value={data.reporta_a} onChange={(e) => setData('reporta_a', e.target.value)} />
                    </div>
                    {area('objetivo', 'Objetivo del cargo', 2)}
                    {area('funciones', 'Funciones', 5, 'Una por línea.')}
                    {area(
                        'responsabilidades_sig',
                        'Responsabilidades en el SIG',
                        4,
                        'Una por línea: en SST, calidad, ambiente y seguridad vial (Dec. 1072 2.2.4.6.8, ISO 5.3).',
                    )}
                    {area('autoridad', 'Autoridad', 3, 'Qué puede decidir o detener: p. ej. suspender una tarea insegura.')}
                    <DialogFooter className="gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RequisitoDialog({
    cargo,
    requisito,
    temas,
    tipos,
    onClose,
}: {
    cargo: Cargo;
    requisito: Requisito | null;
    temas: Props['temas'];
    tipos: Props['tipos'];
    onClose: () => void;
}) {
    const { data, setData, post, put, processing, errors } = useForm<{ tipo: TipoRequisito; descripcion: string; training_topic_id: number | '' }>({
        tipo: requisito?.tipo ?? 'formacion',
        descripcion: requisito?.descripcion ?? '',
        training_topic_id: requisito?.training_topic_id ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (requisito) put(`/cargos/${cargo.id}/requisitos/${requisito.id}`, opts);
        else post(`/cargos/${cargo.id}/requisitos`, opts);
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{requisito ? 'Editar requisito' : 'Nuevo requisito'}</DialogTitle>
                    <DialogDescription>{cargo.nombre}</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-2">
                        <Label htmlFor="req-tipo">Tipo</Label>
                        <select
                            id="req-tipo"
                            value={data.tipo}
                            onChange={(e) => setData('tipo', e.target.value as TipoRequisito)}
                            className={selectCls}
                        >
                            {(Object.keys(tipos) as TipoRequisito[]).map((t) => (
                                <option key={t} value={t}>
                                    {tipos[t]}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="req-desc">Requisito</Label>
                        <Input
                            id="req-desc"
                            value={data.descripcion}
                            placeholder={
                                data.tipo === 'educacion'
                                    ? 'Técnico o tecnólogo en…'
                                    : data.tipo === 'experiencia'
                                      ? '1 año en cargos similares'
                                      : data.tipo === 'habilidad'
                                        ? 'Trabajo en equipo'
                                        : 'Curso de trabajo seguro en alturas'
                            }
                            onChange={(e) => setData('descripcion', e.target.value)}
                        />
                        <InputError message={errors.descripcion} />
                    </div>
                    {data.tipo === 'formacion' && (
                        <div className="grid gap-2">
                            <Label htmlFor="req-tema">Tema de capacitación (opcional)</Label>
                            <select
                                id="req-tema"
                                value={data.training_topic_id}
                                onChange={(e) => setData('training_topic_id', e.target.value ? Number(e.target.value) : '')}
                                className={selectCls}
                            >
                                <option value="">Ninguno: se evalúa a mano</option>
                                {temas.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.codigo ? `${t.codigo} · ` : ''}
                                        {t.titulo}
                                    </option>
                                ))}
                            </select>
                            <p className="text-muted-foreground text-xs">
                                Con tema, quien haya tomado y aprobado esa capacitación (y la tenga vigente) cumple solo.
                            </p>
                        </div>
                    )}
                    <DialogFooter className="gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EvaluarDialog({
    cargo,
    empleado,
    requisito,
    celda,
    estados,
    onClose,
}: {
    cargo: Cargo;
    empleado: Cargo['empleados'][number];
    requisito: Requisito;
    celda: Celda;
    estados: Props['estados'];
    onClose: () => void;
}) {
    const manual = celda.fuente === 'evaluacion';
    const { data, setData, post, processing, errors, transform } = useForm<{ cumple: '1' | '0' | ''; evidencia: string }>({
        cumple: manual ? (celda.estado === 'cumple' ? '1' : '0') : '',
        evidencia: celda.evidencia ?? '',
    });

    transform((d) => ({
        employee_id: empleado.id,
        job_position_requirement_id: requisito.id,
        cumple: d.cumple === '' ? null : d.cumple === '1',
        evidencia: d.evidencia,
    }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/cargos/${cargo.id}/evaluar`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{empleado.nombre}</DialogTitle>
                    <DialogDescription>{requisito.descripcion}</DialogDescription>
                </DialogHeader>
                <div className="flex items-center gap-2 text-sm">
                    <span className={cn('rounded px-2 py-0.5 text-xs font-medium', CELDA[celda.estado])}>{estados[celda.estado]}</span>
                    {celda.detalle && <span className="text-muted-foreground text-xs">{celda.detalle}</span>}
                </div>
                {celda.fuente === 'capacitacion' && celda.estado === 'cumple' && (
                    <p className="text-muted-foreground text-xs">Ya cumple por la capacitación: una evaluación a mano no cambia la celda.</p>
                )}
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-2">
                        <Label htmlFor="ev-cumple">Evaluación</Label>
                        <select
                            id="ev-cumple"
                            value={data.cumple}
                            onChange={(e) => setData('cumple', e.target.value as '1' | '0' | '')}
                            className={selectCls}
                        >
                            <option value="">Sin evaluación a mano</option>
                            <option value="1">Cumple</option>
                            <option value="0">No cumple</option>
                        </select>
                        <InputError message={(errors as Record<string, string | undefined>).employee_id ?? errors.cumple} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="ev-evidencia">Evidencia</Label>
                        <Input
                            id="ev-evidencia"
                            value={data.evidencia}
                            placeholder="Diploma, certificado laboral, observación en campo…"
                            onChange={(e) => setData('evidencia', e.target.value)}
                        />
                        <InputError message={errors.evidencia} />
                    </div>
                    <DialogFooter className="gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
