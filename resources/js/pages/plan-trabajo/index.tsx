import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Building2, CalendarRange, Save } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';

interface Activity {
    id: number;
    codigo: string;
    fase: string;
    nombre: string;
    normas: string[];
    soporte: string | null;
    programados: number[];
    ejecutados: number[];
    responsable: string;
    observaciones: string;
}

interface Props {
    activities: Activity[];
    plan: { id: number; anio: number; responsable: string | null; cumplimiento: number } | null;
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

export default function PlanTrabajoIndex({ activities, plan, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const page = usePage<SharedData>();
    const tenant = page.props.tenant as { id: number; name: string } | null;

    const [filas, setFilas] = useState<Record<number, Fila>>(() =>
        Object.fromEntries(activities.map((a) => [a.id, { estados: estadosIniciales(a), responsable: a.responsable }])),
    );
    const [responsable, setResponsable] = useState(plan?.responsable ?? '');
    const [saving, setSaving] = useState(false);

    // Totales en vivo: programado = estado>=1 ; ejecutado = estado===2.
    const { programados, ejecutados, porMes } = useMemo(() => {
        let p = 0;
        let e = 0;
        const pm = Array.from({ length: 13 }, () => ({ p: 0, e: 0 }));
        for (const a of activities) {
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
    }, [activities, filas]);

    const cumplimiento = programados > 0 ? (ejecutados / programados) * 100 : 0;

    function ciclar(id: number, mes: number) {
        if (!canManage) return;
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
                items: activities.map((a) => {
                    const est = filas[a.id]?.estados ?? {};
                    const prog: number[] = [];
                    const ejec: number[] = [];
                    for (let m = 1; m <= 12; m++) {
                        if (est[m] >= 1) prog.push(m);
                        if (est[m] === 2) ejec.push(m);
                    }
                    return { activity_id: a.id, programados: prog, ejecutados: ejec, responsable: filas[a.id]?.responsable || null, observaciones: a.observaciones || null };
                }),
            },
            { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) },
        );
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
                            <span className="text-muted-foreground">{activities.length} actividades del SGI</span>
                        </div>
                        {/* Mini-cronograma por mes: programado (base) vs ejecutado (relleno) */}
                        <div className="ml-auto flex items-end gap-1">
                            {porMes.slice(1).map((m, i) => {
                                const h = Math.max(m.p, 1);
                                return (
                                    <div key={i} className="flex flex-col items-center gap-1" title={`${MESES_LARGOS[i]}: ${m.e}/${m.p}`}>
                                        <div className="bg-muted relative flex h-12 w-4 items-end overflow-hidden rounded-sm">
                                            <div className="w-full rounded-sm bg-blue-500/30" style={{ height: `${(m.p / (h + 0.0001)) * 100 || 0}%` }} />
                                            <div className="absolute bottom-0 w-full rounded-sm bg-green-600" style={{ height: `${(m.e / h) * 100 || 0}%` }} />
                                        </div>
                                        <span className="text-muted-foreground text-[10px]">{MESES[i]}</span>
                                    </div>
                                );
                            })}
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
                        <span className="hidden sm:inline">Clic en cada mes para alternar.</span>
                    </div>
                </div>

                {/* Cronograma */}
                <Card className="overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-sm">
                            <thead>
                                <tr className="bg-muted/50 text-muted-foreground text-xs">
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
                                                    <td colSpan={15} className="text-primary px-3 py-1.5 text-xs font-bold tracking-tight">
                                                        {a.fase}
                                                    </td>
                                                </tr>
                                            )}
                                            <tr className="border-t align-top">
                                                <td className="max-w-md px-3 py-2">
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-primary font-mono text-xs font-semibold">{a.codigo}</span>
                                                        <span className="leading-tight">{a.nombre}</span>
                                                    </div>
                                                    <div className="mt-1 flex flex-wrap items-center gap-1">
                                                        {a.normas.map((n) => (
                                                            <span key={n} className="bg-primary/10 text-primary rounded px-1 py-0.5 text-[10px] font-medium">
                                                                {n}
                                                            </span>
                                                        ))}
                                                        {a.soporte && <span className="text-muted-foreground text-[11px]" title={a.soporte}>· soportes</span>}
                                                    </div>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <input
                                                        value={filas[a.id]?.responsable ?? ''}
                                                        onChange={(e) => setResp(a.id, e.target.value)}
                                                        disabled={!canManage}
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
                                                                disabled={!canManage}
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
            </div>
        </AppLayout>
    );
}
