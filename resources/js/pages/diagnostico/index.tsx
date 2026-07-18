import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Building2, CheckCircle2, ClipboardCheck, Save } from 'lucide-react';
import { useMemo, useState } from 'react';

type Estado = 'cumple' | 'no_cumple' | 'no_aplica' | 'pendiente';

interface Standard {
    id: number;
    codigo: string;
    ciclo: string;
    grupo: string;
    peso_grupo: number;
    item: string;
    valor: number;
    estado: Estado;
    justificacion: string;
}

interface Props {
    standards: Standard[];
    diagnostico: { id: number; fecha: string | null; evaluador: string | null; puntaje: number; clasificacion: string | null } | null;
    needsClient: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Diagnóstico SG-SST', href: '/diagnostico' },
];

const OPCIONES: { v: Estado; label: string; on: string }[] = [
    { v: 'cumple', label: 'Cumple', on: 'bg-green-600 text-white border-green-600' },
    { v: 'no_cumple', label: 'No cumple', on: 'bg-red-600 text-white border-red-600' },
    { v: 'no_aplica', label: 'No aplica', on: 'bg-slate-500 text-white border-slate-500' },
];

function clasificar(p: number): { label: string; cls: string } {
    if (p < 60) return { label: 'Crítico', cls: 'bg-red-600 text-white' };
    if (p <= 85) return { label: 'Moderadamente aceptable', cls: 'bg-amber-500 text-white' };
    return { label: 'Aceptable', cls: 'bg-green-600 text-white' };
}

export default function DiagnosticoIndex({ standards, diagnostico, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const page = usePage<SharedData>();
    const tenant = page.props.tenant as { id: number; name: string } | null;

    const [resp, setResp] = useState<Record<number, { estado: Estado; justificacion: string }>>(() =>
        Object.fromEntries(standards.map((s) => [s.id, { estado: s.estado, justificacion: s.justificacion }])),
    );
    const [saving, setSaving] = useState(false);

    // Puntaje en vivo: "cumple" y "no aplica" suman su valor (Res. 0312).
    const puntaje = useMemo(
        () => standards.reduce((acc, s) => acc + (['cumple', 'no_aplica'].includes(resp[s.id]?.estado) ? s.valor : 0), 0),
        [standards, resp],
    );
    const diligenciados = useMemo(() => standards.filter((s) => resp[s.id]?.estado !== 'pendiente').length, [standards, resp]);
    const clase = clasificar(puntaje);

    function setEstado(id: number, estado: Estado) {
        setResp((r) => ({ ...r, [id]: { ...r[id], estado } }));
    }
    function setJust(id: number, justificacion: string) {
        setResp((r) => ({ ...r, [id]: { ...r[id], justificacion } }));
    }

    function guardar() {
        router.post(
            route('diagnostico.save'),
            { respuestas: standards.map((s) => ({ standard_id: s.id, estado: resp[s.id].estado, justificacion: resp[s.id].justificacion })) },
            { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) },
        );
    }

    if (needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Diagnóstico SG-SST" />
                <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Diagnóstico de Estándares Mínimos</h1>
                        <p className="text-muted-foreground text-sm">Autoevaluación del SG-SST según Resolución 0312 de 2019.</p>
                    </div>
                    <Card>
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <Building2 className="size-7" />
                            </div>
                            <p className="font-medium">Selecciona un cliente para diligenciar su diagnóstico</p>
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

    // Subtotales por grupo (obtenido / posible) para mostrar avance.
    const grupos = useMemo(() => {
        const m = new Map<string, { posible: number; obtenido: number; peso: number }>();
        for (const s of standards) {
            const g = m.get(s.grupo) ?? { posible: 0, obtenido: 0, peso: s.peso_grupo };
            g.posible += s.valor;
            if (['cumple', 'no_aplica'].includes(resp[s.id]?.estado)) g.obtenido += s.valor;
            m.set(s.grupo, g);
        }
        return m;
    }, [standards, resp]);

    let lastCiclo = '';
    let lastGrupo = '';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Diagnóstico SG-SST" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Encabezado */}
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Diagnóstico de Estándares Mínimos</h1>
                        <p className="text-muted-foreground text-sm">
                            SG-SST (Resolución 0312 de 2019){tenant ? <> · <span className="font-medium">{tenant.name}</span></> : null}
                        </p>
                    </div>
                    {canManage && (
                        <Button onClick={guardar} disabled={saving} className="gap-2">
                            <Save className="size-4" /> {saving ? 'Guardando…' : 'Guardar diagnóstico'}
                        </Button>
                    )}
                </div>

                {/* Tarjeta de puntaje */}
                <Card>
                    <CardContent className="flex flex-wrap items-center gap-6 p-6">
                        <div className="flex flex-col">
                            <span className="text-5xl font-bold tabular-nums">{puntaje.toFixed(2)}%</span>
                            <span className="text-muted-foreground text-sm">Cumplimiento</span>
                        </div>
                        <div className="flex flex-col gap-2">
                            <Badge className={cn('w-fit text-sm', clase.cls)}>{clase.label}</Badge>
                            <span className="text-muted-foreground text-sm">{diligenciados} de {standards.length} estándares diligenciados</span>
                        </div>
                        <div className="ml-auto min-w-48 flex-1">
                            <div className="bg-muted h-3 w-full overflow-hidden rounded-full">
                                <div
                                    className={cn('h-full rounded-full transition-all', puntaje < 60 ? 'bg-red-600' : puntaje <= 85 ? 'bg-amber-500' : 'bg-green-600')}
                                    style={{ width: `${puntaje}%` }}
                                />
                            </div>
                            <div className="text-muted-foreground mt-1 flex justify-between text-xs">
                                <span>Crítico &lt;60</span>
                                <span>Moderado 60–85</span>
                                <span>Aceptable &gt;85</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Listado de estándares agrupado por ciclo y grupo */}
                <div className="space-y-4">
                    {standards.map((s) => {
                        const nuevoCiclo = s.ciclo !== lastCiclo;
                        const nuevoGrupo = s.grupo !== lastGrupo;
                        lastCiclo = s.ciclo;
                        lastGrupo = s.grupo;
                        const g = grupos.get(s.grupo);
                        const estado = resp[s.id]?.estado ?? 'pendiente';

                        return (
                            <div key={s.id}>
                                {nuevoCiclo && (
                                    <h2 className="font-brand text-primary mt-6 mb-2 text-lg font-bold tracking-tight first:mt-0">{s.ciclo}</h2>
                                )}
                                {nuevoGrupo && (
                                    <div className="text-muted-foreground mb-2 flex items-center justify-between border-b pb-1 text-sm font-semibold">
                                        <span>
                                            {s.grupo} <span className="font-normal">({s.peso_grupo}%)</span>
                                        </span>
                                        {g && (
                                            <span className="tabular-nums">
                                                {g.obtenido.toFixed(2)} / {g.posible.toFixed(2)}
                                            </span>
                                        )}
                                    </div>
                                )}
                                <Card className="mb-2">
                                    <CardContent className="flex flex-wrap items-start gap-4 p-4">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-primary font-mono text-xs font-semibold">{s.codigo}</span>
                                                <span className="text-muted-foreground text-xs">· vale {s.valor}</span>
                                            </div>
                                            <p className="text-sm">{s.item}</p>
                                            {(estado === 'no_cumple' || estado === 'no_aplica') && (
                                                <textarea
                                                    value={resp[s.id]?.justificacion ?? ''}
                                                    onChange={(e) => setJust(s.id, e.target.value)}
                                                    disabled={!canManage}
                                                    placeholder={estado === 'no_aplica' ? 'Justificación de no aplicabilidad…' : 'Observación / hallazgo…'}
                                                    rows={2}
                                                    className="border-input bg-background mt-2 w-full rounded-md border px-3 py-1.5 text-sm disabled:opacity-60"
                                                />
                                            )}
                                        </div>
                                        <div className="flex shrink-0 overflow-hidden rounded-md border">
                                            {OPCIONES.map((o) => (
                                                <button
                                                    key={o.v}
                                                    type="button"
                                                    disabled={!canManage}
                                                    onClick={() => setEstado(s.id, o.v)}
                                                    className={cn(
                                                        'border-l px-3 py-1.5 text-xs font-medium transition-colors first:border-l-0 disabled:cursor-not-allowed',
                                                        estado === o.v ? o.on : 'bg-background hover:bg-muted',
                                                    )}
                                                >
                                                    {o.label}
                                                </button>
                                            ))}
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        );
                    })}
                </div>

                {canManage && (
                    <div className="flex justify-end">
                        <Button onClick={guardar} disabled={saving} className="gap-2">
                            <Save className="size-4" /> {saving ? 'Guardando…' : 'Guardar diagnóstico'}
                        </Button>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
