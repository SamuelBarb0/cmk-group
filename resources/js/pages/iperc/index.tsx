import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Building2, CheckCircle2, Pencil, Plus, ShieldAlert, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Row {
    id: number;
    proceso: string;
    zona: string | null;
    actividad: string;
    tarea: string | null;
    rutinaria: boolean;
    clasificacion: string;
    peligro: string;
    efectos: string | null;
    control_fuente: string | null;
    control_medio: string | null;
    control_individuo: string | null;
    nd: number;
    ne: number;
    nc: number;
    np: number;
    nr: number;
    nivel_riesgo: string;
    aceptabilidad: string;
    medidas: string | null;
    expuestos: number | null;
}

interface Props {
    rows: Row[];
    stats: { total: number; no_aceptables: number };
    needsClient: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Matriz IPERC', href: '/iperc' },
];

const CLASIFICACIONES = ['Biológico', 'Físico', 'Químico', 'Biomecánico', 'Psicosocial', 'Condiciones de seguridad', 'Fenómenos naturales'];
const ND = [
    { v: 10, l: 'Muy Alto (10)' },
    { v: 6, l: 'Alto (6)' },
    { v: 2, l: 'Medio (2)' },
    { v: 0, l: 'Bajo (0)' },
];
const NE = [
    { v: 4, l: 'Continua (4)' },
    { v: 3, l: 'Frecuente (3)' },
    { v: 2, l: 'Ocasional (2)' },
    { v: 1, l: 'Esporádica (1)' },
];
const NC = [
    { v: 100, l: 'Mortal/Catastrófico (100)' },
    { v: 60, l: 'Muy grave (60)' },
    { v: 25, l: 'Grave (25)' },
    { v: 10, l: 'Leve (10)' },
];

function nivel(nr: number): string {
    if (nr >= 600) return 'I';
    if (nr >= 150) return 'II';
    if (nr >= 40) return 'III';
    return 'IV';
}
function aceptabilidad(niv: string): string {
    return { I: 'No Aceptable', II: 'No Aceptable / Aceptable con control', III: 'Mejorable', IV: 'Aceptable' }[niv] ?? 'Aceptable';
}
const NIVEL_CLS: Record<string, string> = {
    I: 'bg-red-600 text-white',
    II: 'bg-orange-500 text-white',
    III: 'bg-yellow-500 text-black',
    IV: 'bg-green-600 text-white',
};

const emptyForm = {
    proceso: '',
    zona: '',
    actividad: '',
    tarea: '',
    rutinaria: true as boolean,
    clasificacion: 'Físico',
    peligro: '',
    efectos: '',
    control_fuente: '',
    control_medio: '',
    control_individuo: '',
    nd: 2,
    ne: 2,
    nc: 25,
    medidas: '',
    expuestos: '' as number | string,
};

function StatCard({ label, value, icon: Icon, danger }: { label: string; value: number; icon: React.ElementType; danger?: boolean }) {
    return (
        <Card>
            <CardContent className="flex items-center gap-4 p-5">
                <div
                    className={cn(
                        'flex size-11 items-center justify-center rounded-lg',
                        danger ? 'bg-red-600/10 text-red-600' : 'bg-primary/10 text-primary',
                    )}
                >
                    <Icon className="size-5" />
                </div>
                <div>
                    <div className="text-2xl font-bold tabular-nums">{value}</div>
                    <div className="text-muted-foreground text-sm">{label}</div>
                </div>
            </CardContent>
        </Card>
    );
}

export default function IpercIndex({ rows, stats, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const page = usePage<SharedData>();
    const flash = page.props.flash;
    const tenant = page.props.tenant as { id: number; name: string } | null;

    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Row | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({ ...emptyForm });

    useEffect(() => {
        if (flash?.success) {
            setNotice(flash.success);
            const t = setTimeout(() => setNotice(null), 3500);
            return () => clearTimeout(t);
        }
    }, [flash?.success]);

    // Cálculo en vivo (GTC 45).
    const np = Number(data.nd) * Number(data.ne);
    const nr = np * Number(data.nc);
    const niv = nivel(nr);

    function openCreate() {
        setEditing(null);
        clearErrors();
        reset();
        setData({ ...emptyForm });
        setOpen(true);
    }
    function openEdit(r: Row) {
        setEditing(r);
        clearErrors();
        setData({
            proceso: r.proceso,
            zona: r.zona ?? '',
            actividad: r.actividad,
            tarea: r.tarea ?? '',
            rutinaria: r.rutinaria,
            clasificacion: r.clasificacion,
            peligro: r.peligro,
            efectos: r.efectos ?? '',
            control_fuente: r.control_fuente ?? '',
            control_medio: r.control_medio ?? '',
            control_individuo: r.control_individuo ?? '',
            nd: r.nd,
            ne: r.ne,
            nc: r.nc,
            medidas: r.medidas ?? '',
            expuestos: r.expuestos ?? '',
        });
        setOpen(true);
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const onSuccess = () => {
            setOpen(false);
            reset();
        };
        if (editing) put(route('iperc.update', editing.id), { preserveScroll: true, onSuccess });
        else post(route('iperc.store'), { preserveScroll: true, onSuccess });
    };

    function destroy(r: Row) {
        if (confirm(`¿Eliminar el peligro "${r.peligro}"?`)) {
            router.delete(route('iperc.destroy', r.id), { preserveScroll: true });
        }
    }

    if (needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Matriz IPERC" />
                <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Matriz IPERC</h1>
                        <p className="text-muted-foreground text-sm">Identificación de peligros y valoración de riesgos (GTC 45).</p>
                    </div>
                    <Card>
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <Building2 className="size-7" />
                            </div>
                            <p className="font-medium">Selecciona un cliente para gestionar su matriz IPERC</p>
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
            <Head title="Matriz IPERC" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Matriz IPERC</h1>
                        <p className="text-muted-foreground text-sm">
                            Identificación de peligros y valoración de riesgos (GTC 45)
                            {tenant ? (
                                <>
                                    {' '}
                                    · <span className="font-medium">{tenant.name}</span>
                                </>
                            ) : null}
                        </p>
                    </div>
                    {canManage && (
                        <Button onClick={openCreate} className="gap-2">
                            <Plus className="size-4" /> Nuevo peligro
                        </Button>
                    )}
                </div>

                {notice && (
                    <div className="flex items-center gap-2 rounded-lg border border-green-600/30 bg-green-600/10 px-4 py-2.5 text-sm text-green-700 dark:text-green-400">
                        <CheckCircle2 className="size-4" /> {notice}
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <StatCard label="Peligros identificados" value={stats.total} icon={TriangleAlert} />
                    <StatCard label="Riesgos no aceptables (I / II)" value={stats.no_aceptables} icon={ShieldAlert} danger />
                </div>

                <Card className="overflow-hidden">
                    {rows.length === 0 ? (
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <TriangleAlert className="text-muted-foreground size-8" />
                            <p className="font-medium">Aún no hay peligros identificados</p>
                            {canManage && (
                                <Button variant="outline" onClick={openCreate} className="gap-2">
                                    <Plus className="size-4" /> Agregar el primero
                                </Button>
                            )}
                        </CardContent>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground border-b text-left">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Proceso / Actividad</th>
                                        <th className="px-4 py-3 font-medium">Peligro</th>
                                        <th className="px-4 py-3 text-center font-medium">ND·NE=NP</th>
                                        <th className="px-4 py-3 text-center font-medium">NC → NR</th>
                                        <th className="px-4 py-3 text-center font-medium">Nivel</th>
                                        <th className="px-4 py-3 font-medium">Aceptabilidad</th>
                                        {canManage && <th className="px-4 py-3 text-right font-medium">Acciones</th>}
                                    </tr>
                                </thead>
                                <tbody className="divide-border divide-y">
                                    {rows.map((r) => (
                                        <tr key={r.id} className="hover:bg-muted/40 transition-colors">
                                            <td className="px-4 py-3">
                                                <div className="font-medium">{r.proceso}</div>
                                                <div className="text-muted-foreground text-xs">{r.actividad}</div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline" className="mb-1">
                                                    {r.clasificacion}
                                                </Badge>
                                                <div className="text-xs">{r.peligro}</div>
                                            </td>
                                            <td className="px-4 py-3 text-center tabular-nums">
                                                {r.nd}·{r.ne}={r.np}
                                            </td>
                                            <td className="px-4 py-3 text-center tabular-nums">
                                                {r.nc} → <span className="font-semibold">{r.nr}</span>
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <Badge className={cn(NIVEL_CLS[r.nivel_riesgo])}>{r.nivel_riesgo}</Badge>
                                            </td>
                                            <td className="text-muted-foreground px-4 py-3 text-xs">{r.aceptabilidad}</td>
                                            {canManage && (
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Button variant="ghost" size="icon" onClick={() => openEdit(r)} aria-label="Editar">
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() => destroy(r)}
                                                            aria-label="Eliminar"
                                                            className="text-destructive hover:text-destructive"
                                                        >
                                                            <TriangleAlert className="size-4" />
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
                </Card>
            </div>

            {/* Diálogo agregar / editar peligro */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle className="font-brand">{editing ? 'Editar peligro' : 'Nuevo peligro'}</DialogTitle>
                        <DialogDescription>Valoración del riesgo según GTC 45. El nivel se calcula automáticamente.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-5">
                        {/* Contexto */}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="proceso">Proceso *</Label>
                                <Input id="proceso" value={data.proceso} onChange={(e) => setData('proceso', e.target.value)} required autoFocus />
                                <InputError message={errors.proceso} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="zona">Zona / lugar</Label>
                                <Input id="zona" value={data.zona} onChange={(e) => setData('zona', e.target.value)} />
                                <InputError message={errors.zona} />
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="actividad">Actividad *</Label>
                                <Input id="actividad" value={data.actividad} onChange={(e) => setData('actividad', e.target.value)} required />
                                <InputError message={errors.actividad} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="tarea">Tarea</Label>
                                <Input id="tarea" value={data.tarea} onChange={(e) => setData('tarea', e.target.value)} />
                            </div>
                        </div>
                        <label className="flex cursor-pointer items-center gap-3">
                            <Checkbox checked={data.rutinaria} onCheckedChange={(v) => setData('rutinaria', v === true)} />
                            <span className="text-sm">Actividad rutinaria</span>
                        </label>

                        {/* Peligro */}
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="clasificacion">Clasificación *</Label>
                                <select
                                    id="clasificacion"
                                    value={data.clasificacion}
                                    onChange={(e) => setData('clasificacion', e.target.value)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    {CLASIFICACIONES.map((c) => (
                                        <option key={c} value={c}>
                                            {c}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="peligro">Peligro *</Label>
                                <Input id="peligro" value={data.peligro} onChange={(e) => setData('peligro', e.target.value)} required />
                                <InputError message={errors.peligro} />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="efectos">Efectos posibles</Label>
                            <Input id="efectos" value={data.efectos} onChange={(e) => setData('efectos', e.target.value)} />
                        </div>

                        {/* Valoración GTC 45 con cálculo en vivo */}
                        <div className="bg-muted/40 space-y-3 rounded-lg border p-4">
                            <div className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">Valoración del riesgo (GTC 45)</div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="nd">Nivel de Deficiencia</Label>
                                    <select
                                        id="nd"
                                        value={data.nd}
                                        onChange={(e) => setData('nd', Number(e.target.value))}
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    >
                                        {ND.map((o) => (
                                            <option key={o.v} value={o.v}>
                                                {o.l}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="ne">Nivel de Exposición</Label>
                                    <select
                                        id="ne"
                                        value={data.ne}
                                        onChange={(e) => setData('ne', Number(e.target.value))}
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    >
                                        {NE.map((o) => (
                                            <option key={o.v} value={o.v}>
                                                {o.l}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="nc">Nivel de Consecuencia</Label>
                                    <select
                                        id="nc"
                                        value={data.nc}
                                        onChange={(e) => setData('nc', Number(e.target.value))}
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    >
                                        {NC.map((o) => (
                                            <option key={o.v} value={o.v}>
                                                {o.l}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>
                            <div className="flex flex-wrap items-center gap-4 pt-1 text-sm">
                                <span className="tabular-nums">
                                    NP = {data.nd}·{data.ne} = <b>{np}</b>
                                </span>
                                <span className="tabular-nums">
                                    NR = {np}·{data.nc} = <b>{nr}</b>
                                </span>
                                <Badge className={cn(NIVEL_CLS[niv])}>Nivel {niv}</Badge>
                                <span className="text-muted-foreground">{aceptabilidad(niv)}</span>
                            </div>
                        </div>

                        {/* Medidas */}
                        <div className="grid gap-2">
                            <Label htmlFor="medidas">Medidas de intervención</Label>
                            <textarea
                                id="medidas"
                                value={data.medidas}
                                onChange={(e) => setData('medidas', e.target.value)}
                                rows={2}
                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                            />
                        </div>

                        <DialogFooter className="gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {editing ? 'Guardar' : 'Agregar peligro'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
