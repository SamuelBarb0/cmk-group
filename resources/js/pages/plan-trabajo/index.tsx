import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Building2, CheckCircle2, PenLine, Save, Target } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';

interface Activity {
    id: number;
    codigo: string;
    fase: string;
    nombre: string;
    normas: string[];
    soporte: string | null;
    /** ¿La actividad aplica (fue seleccionada) para este plan? */
    aplica: boolean;
    programados: number[];
    ejecutados: number[];
    responsable: string;
    observaciones: string;
}

interface Firma {
    nombre: string;
    cc: string | null;
    fecha: string;
}

interface Props {
    activities: Activity[];
    plan: {
        id: number;
        anio: number;
        responsable: string | null;
        cumplimiento: number;
        metas: string | null;
        objetivos: string | null;
        recursos: string | null;
        firma_rep: Firma | null;
        firma_resp: Firma | null;
    } | null;
    firmantes: {
        representante: { nombre: string | null; cc: string | null };
        responsable: { nombre: string | null; cc: string | null };
    } | null;
    needsClient: boolean;
}

const MESES = ['E', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];
const MESES_LARGOS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

// Estado por celda: 0 = sin programar, 1 = programado, 2 = ejecutado.
type Fila = { estados: Record<number, 0 | 1 | 2>; responsable: string };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Plan de Trabajo', href: '/plan-trabajo' },
];

function estadosIniciales(a: Activity): Record<number, 0 | 1 | 2> {
    const e: Record<number, 0 | 1 | 2> = {};
    for (let m = 1; m <= 12; m++) e[m] = a.programados.includes(m) ? (a.ejecutados.includes(m) ? 2 : 1) : 0;
    return e;
}

export default function PlanTrabajoIndex({ activities, plan, firmantes, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const page = usePage<SharedData>();
    const tenant = page.props.tenant as { id: number; name: string } | null;

    const [filas, setFilas] = useState<Record<number, Fila>>(() =>
        Object.fromEntries(activities.map((a) => [a.id, { estados: estadosIniciales(a), responsable: a.responsable }])),
    );
    const [responsable, setResponsable] = useState(plan?.responsable ?? '');
    const [metas, setMetas] = useState(plan?.metas ?? '');
    const [objetivos, setObjetivos] = useState(plan?.objetivos ?? '');
    const [recursos, setRecursos] = useState(plan?.recursos ?? '');
    // Selección de actividades que aplican al plan de esta empresa.
    const [aplican, setAplican] = useState<Record<number, boolean>>(() => Object.fromEntries(activities.map((a) => [a.id, a.aplica])));
    const [saving, setSaving] = useState(false);
    // Datos de la firma en edición (prellenados desde Organización).
    const [firmaRep, setFirmaRep] = useState({ nombre: firmantes?.representante.nombre ?? '', cc: firmantes?.representante.cc ?? '' });
    const [firmaResp, setFirmaResp] = useState({ nombre: firmantes?.responsable.nombre ?? '', cc: firmantes?.responsable.cc ?? '' });

    const seleccionadasCount = useMemo(() => activities.filter((a) => aplican[a.id]).length, [activities, aplican]);

    // Totales en vivo (solo actividades que aplican): programado = estado>=1 ; ejecutado = estado===2.
    const { programados, ejecutados, porMes } = useMemo(() => {
        let p = 0;
        let e = 0;
        const pm = Array.from({ length: 13 }, () => ({ p: 0, e: 0 }));
        for (const a of activities) {
            if (!aplican[a.id]) continue;
            const est = filas[a.id]?.estados ?? {};
            for (let m = 1; m <= 12; m++) {
                if (est[m] >= 1) {
                    p++;
                    pm[m].p++;
                }
                if (est[m] === 2) {
                    e++;
                    pm[m].e++;
                }
            }
        }
        return { programados: p, ejecutados: e, porMes: pm };
    }, [activities, filas, aplican]);

    const cumplimiento = programados > 0 ? (ejecutados / programados) * 100 : 0;

    function ciclar(id: number, mes: number) {
        if (!canManage || !aplican[id]) return;
        setFilas((f) => {
            const est = { ...(f[id]?.estados ?? {}) };
            est[mes] = (((est[mes] ?? 0) + 1) % 3) as 0 | 1 | 2;
            return { ...f, [id]: { ...f[id], estados: est } };
        });
    }
    function setResp(id: number, r: string) {
        setFilas((f) => ({ ...f, [id]: { ...f[id], responsable: r } }));
    }

    function guardar() {
        router.post(
            route('plan-trabajo.save'),
            {
                responsable,
                metas,
                objetivos,
                recursos,
                seleccionadas: activities.filter((a) => aplican[a.id]).map((a) => a.id),
                items: activities
                    .filter((a) => aplican[a.id])
                    .map((a) => {
                        const est = filas[a.id]?.estados ?? {};
                        const prog: number[] = [];
                        const ejec: number[] = [];
                        for (let m = 1; m <= 12; m++) {
                            if (est[m] >= 1) prog.push(m);
                            if (est[m] === 2) ejec.push(m);
                        }
                        return {
                            activity_id: a.id,
                            programados: prog,
                            ejecutados: ejec,
                            responsable: filas[a.id]?.responsable || null,
                            observaciones: a.observaciones || null,
                        };
                    }),
            },
            { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) },
        );
    }

    function toggleAplica(id: number) {
        if (!canManage) return;
        setAplican((s) => ({ ...s, [id]: !s[id] }));
    }

    function firmar(rol: 'representante' | 'responsable') {
        const firma = rol === 'representante' ? firmaRep : firmaResp;
        router.post(route('plan-trabajo.firmar'), { rol, nombre: firma.nombre, cc: firma.cc || null }, { preserveScroll: true });
    }

    function quitarFirma(rol: 'representante' | 'responsable') {
        if (confirm('¿Retirar esta firma del plan?')) {
            router.post(route('plan-trabajo.quitar-firma'), { rol }, { preserveScroll: true });
        }
    }

    if (needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Plan de Trabajo" />
                <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Plan de Trabajo Anual del SGI</h1>
                        <p className="text-muted-foreground text-sm">Cronograma de actividades por cláusulas ISO 4→10.</p>
                    </div>
                    <Card>
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <Building2 className="size-7" />
                            </div>
                            <p className="font-medium">Selecciona un cliente para diligenciar su plan de trabajo</p>
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

    let lastFase = '';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Plan de Trabajo" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Encabezado */}
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Plan de Trabajo Anual del SGI</h1>
                        <p className="text-muted-foreground text-sm">
                            Cronograma {plan?.anio}
                            {tenant ? (
                                <>
                                    {' '}
                                    · <span className="font-medium">{tenant.name}</span>
                                </>
                            ) : null}
                        </p>
                    </div>
                    {canManage && (
                        <Button onClick={guardar} disabled={saving} className="gap-2">
                            <Save className="size-4" /> {saving ? 'Guardando…' : 'Guardar plan'}
                        </Button>
                    )}
                </div>

                {/* Tarjeta de cumplimiento */}
                <Card>
                    <CardContent className="flex flex-wrap items-center gap-6 p-6">
                        <div className="flex flex-col">
                            <span className="text-5xl font-bold tabular-nums">{cumplimiento.toFixed(1)}%</span>
                            <span className="text-muted-foreground text-sm">Cumplimiento</span>
                        </div>
                        <div className="flex flex-col gap-1 text-sm">
                            <span>
                                <span className="font-semibold tabular-nums">{ejecutados}</span> ejecutadas /{' '}
                                <span className="font-semibold tabular-nums">{programados}</span> programadas
                            </span>
                            <span className="text-muted-foreground">
                                {seleccionadasCount} de {activities.length} actividades del SGI aplican
                            </span>
                        </div>
                        {/* Mini-cronograma por mes: programado (base) vs ejecutado (relleno) */}
                        <div className="ml-auto flex items-end gap-1">
                            {porMes.slice(1).map((m, i) => {
                                const h = Math.max(m.p, 1);
                                return (
                                    <div key={i} className="flex flex-col items-center gap-1" title={`${MESES_LARGOS[i]}: ${m.e}/${m.p}`}>
                                        <div className="bg-muted relative flex h-12 w-4 items-end overflow-hidden rounded-sm">
                                            <div
                                                className="w-full rounded-sm bg-blue-500/30"
                                                style={{ height: `${(m.p / (h + 0.0001)) * 100 || 0}%` }}
                                            />
                                            <div
                                                className="absolute bottom-0 w-full rounded-sm bg-green-600"
                                                style={{ height: `${(m.e / h) * 100 || 0}%` }}
                                            />
                                        </div>
                                        <span className="text-muted-foreground text-[10px]">{MESES[i]}</span>
                                    </div>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {/* Metas, objetivos y recursos del plan */}
                <Card>
                    <CardContent className="space-y-4 p-5">
                        <div className="text-muted-foreground flex items-center gap-2 text-xs font-semibold tracking-wide uppercase">
                            <Target className="size-4" /> Metas, objetivos y recursos
                        </div>
                        <div className="grid gap-4 lg:grid-cols-3">
                            <label className="grid gap-1.5 text-sm">
                                <span className="font-medium">Objetivos</span>
                                <textarea
                                    value={objetivos}
                                    onChange={(e) => setObjetivos(e.target.value)}
                                    disabled={!canManage}
                                    rows={4}
                                    placeholder="Objetivos del SGI para el año…"
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm disabled:opacity-60"
                                />
                            </label>
                            <label className="grid gap-1.5 text-sm">
                                <span className="font-medium">Metas</span>
                                <textarea
                                    value={metas}
                                    onChange={(e) => setMetas(e.target.value)}
                                    disabled={!canManage}
                                    rows={4}
                                    placeholder="Metas medibles del plan…"
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm disabled:opacity-60"
                                />
                            </label>
                            <label className="grid gap-1.5 text-sm">
                                <span className="font-medium">Recursos</span>
                                <textarea
                                    value={recursos}
                                    onChange={(e) => setRecursos(e.target.value)}
                                    disabled={!canManage}
                                    rows={4}
                                    placeholder="Recursos humanos, técnicos y financieros asignados…"
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm disabled:opacity-60"
                                />
                            </label>
                        </div>
                    </CardContent>
                </Card>

                {/* Responsable general + leyenda */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <label className="flex items-center gap-2 text-sm">
                        <span className="text-muted-foreground">Responsable del plan:</span>
                        <input
                            value={responsable}
                            onChange={(e) => setResponsable(e.target.value)}
                            disabled={!canManage}
                            placeholder="Nombre del responsable"
                            className="border-input bg-background w-56 rounded-md border px-3 py-1.5 text-sm disabled:opacity-60"
                        />
                    </label>
                    <div className="text-muted-foreground flex items-center gap-4 text-xs">
                        <span className="flex items-center gap-1.5">
                            <span className="inline-block size-3 rounded-sm bg-blue-500" /> Programado
                        </span>
                        <span className="flex items-center gap-1.5">
                            <span className="inline-block size-3 rounded-sm bg-green-600" /> Ejecutado
                        </span>
                        <span className="hidden sm:inline">
                            Clic en cada mes para alternar · desmarca «Aplica» si la actividad no va en este plan.
                        </span>
                    </div>
                </div>

                {/* Cronograma */}
                <Card className="overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-sm">
                            <thead>
                                <tr className="bg-muted/50 text-muted-foreground text-xs">
                                    <th className="w-12 px-2 py-2 text-center font-semibold" title="¿La actividad aplica a este plan?">
                                        Aplica
                                    </th>
                                    <th className="px-3 py-2 text-left font-semibold">Actividad</th>
                                    <th className="px-2 py-2 text-left font-semibold">Responsable</th>
                                    {MESES.map((m, i) => (
                                        <th key={i} className="w-7 px-0 py-2 text-center font-semibold" title={MESES_LARGOS[i]}>
                                            {m}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {activities.map((a) => {
                                    const nuevaFase = a.fase !== lastFase;
                                    lastFase = a.fase;
                                    const est = filas[a.id]?.estados ?? {};

                                    return (
                                        <Fragment key={a.id}>
                                            {nuevaFase && (
                                                <tr className="bg-primary/5">
                                                    <td colSpan={16} className="text-primary px-3 py-1.5 text-xs font-bold tracking-tight">
                                                        {a.fase}
                                                    </td>
                                                </tr>
                                            )}
                                            <tr className={cn('border-t align-top', !aplican[a.id] && 'opacity-45')}>
                                                <td className="px-2 py-2 text-center">
                                                    <input
                                                        type="checkbox"
                                                        checked={aplican[a.id] ?? true}
                                                        onChange={() => toggleAplica(a.id)}
                                                        disabled={!canManage}
                                                        className="accent-primary size-4 cursor-pointer disabled:cursor-not-allowed"
                                                        title={
                                                            aplican[a.id]
                                                                ? 'La actividad aplica a este plan'
                                                                : 'No aplica (excluida del cumplimiento)'
                                                        }
                                                    />
                                                </td>
                                                <td className="max-w-md px-3 py-2">
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-primary font-mono text-xs font-semibold">{a.codigo}</span>
                                                        <span className="leading-tight">{a.nombre}</span>
                                                    </div>
                                                    <div className="mt-1 flex flex-wrap items-center gap-1">
                                                        {a.normas.map((n) => (
                                                            <span
                                                                key={n}
                                                                className="bg-primary/10 text-primary rounded px-1 py-0.5 text-[10px] font-medium"
                                                            >
                                                                {n}
                                                            </span>
                                                        ))}
                                                        {a.soporte && (
                                                            <span className="text-muted-foreground text-[11px]" title={a.soporte}>
                                                                · soportes
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <input
                                                        value={filas[a.id]?.responsable ?? ''}
                                                        onChange={(e) => setResp(a.id, e.target.value)}
                                                        disabled={!canManage || !aplican[a.id]}
                                                        placeholder="—"
                                                        className="border-input bg-background w-28 rounded border px-2 py-1 text-xs disabled:opacity-60"
                                                    />
                                                </td>
                                                {MESES.map((_, i) => {
                                                    const mes = i + 1;
                                                    const s = est[mes] ?? 0;
                                                    return (
                                                        <td key={i} className="px-0.5 py-2 text-center">
                                                            <button
                                                                type="button"
                                                                disabled={!canManage || !aplican[a.id]}
                                                                onClick={() => ciclar(a.id, mes)}
                                                                title={`${MESES_LARGOS[i]}: ${s === 2 ? 'Ejecutado' : s === 1 ? 'Programado' : 'Sin programar'}`}
                                                                className={cn(
                                                                    'size-5 rounded-sm border transition-colors disabled:cursor-not-allowed',
                                                                    s === 2
                                                                        ? 'border-green-600 bg-green-600'
                                                                        : s === 1
                                                                          ? 'border-blue-500 bg-blue-500'
                                                                          : 'border-input bg-background hover:bg-muted',
                                                                )}
                                                            />
                                                        </td>
                                                    );
                                                })}
                                            </tr>
                                        </Fragment>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </Card>

                {canManage && (
                    <div className="flex justify-end">
                        <Button onClick={guardar} disabled={saving} className="gap-2">
                            <Save className="size-4" /> {saving ? 'Guardando…' : 'Guardar plan'}
                        </Button>
                    </div>
                )}

                {/* Firmas del plan: representante legal + responsable del SG-SST */}
                <Card>
                    <CardContent className="space-y-4 p-5">
                        <div className="text-muted-foreground flex items-center gap-2 text-xs font-semibold tracking-wide uppercase">
                            <PenLine className="size-4" /> Firmas del plan {plan?.anio}
                        </div>
                        <p className="text-muted-foreground text-xs">
                            La firma registra nombre, cédula y fecha/hora como sello del sistema. Guarda el plan antes de firmar.
                        </p>
                        <div className="grid gap-4 md:grid-cols-2">
                            {[
                                {
                                    rol: 'representante' as const,
                                    titulo: 'Representante legal',
                                    firma: plan?.firma_rep,
                                    estado: firmaRep,
                                    setEstado: setFirmaRep,
                                },
                                {
                                    rol: 'responsable' as const,
                                    titulo: 'Responsable del SG-SST',
                                    firma: plan?.firma_resp,
                                    estado: firmaResp,
                                    setEstado: setFirmaResp,
                                },
                            ].map(({ rol, titulo, firma, estado, setEstado }) => (
                                <div key={rol} className="rounded-lg border p-4">
                                    <div className="mb-3 text-sm font-semibold">{titulo}</div>
                                    {firma ? (
                                        <div className="space-y-1.5">
                                            <div className="flex items-center gap-2 text-sm font-medium text-green-700 dark:text-green-400">
                                                <CheckCircle2 className="size-4" /> Firmado
                                            </div>
                                            <div className="font-brand text-lg">{firma.nombre}</div>
                                            <div className="text-muted-foreground text-xs">
                                                {firma.cc ? `C.C. ${firma.cc} · ` : ''}
                                                {firma.fecha}
                                            </div>
                                            {canManage && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-destructive hover:text-destructive mt-1 h-7 px-2 text-xs"
                                                    onClick={() => quitarFirma(rol)}
                                                >
                                                    Quitar firma
                                                </Button>
                                            )}
                                        </div>
                                    ) : canManage ? (
                                        <div className="space-y-2">
                                            <input
                                                value={estado.nombre}
                                                onChange={(e) => setEstado({ ...estado, nombre: e.target.value })}
                                                placeholder="Nombre completo"
                                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                                            />
                                            <input
                                                value={estado.cc}
                                                onChange={(e) => setEstado({ ...estado, cc: e.target.value })}
                                                placeholder="Cédula (C.C.)"
                                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                                            />
                                            <Button size="sm" className="gap-2" disabled={!estado.nombre.trim()} onClick={() => firmar(rol)}>
                                                <PenLine className="size-4" /> Firmar
                                            </Button>
                                        </div>
                                    ) : (
                                        <div className="text-muted-foreground text-sm">Pendiente de firma.</div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
