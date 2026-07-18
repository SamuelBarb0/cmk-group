import InputError from '@/components/input-error';
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
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Building2, Plus, Save, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

type Sentido = 'asc' | 'desc';
interface MesData {
    numerador: number;
    denominador: number;
    valor: number | null;
}
interface Indicator {
    id: number;
    codigo: string;
    nombre: string;
    categoria: string;
    numerador_label: string;
    denominador_label: string;
    constante: number;
    unidad: string;
    sentido: Sentido;
    meta: number;
    es_legal: boolean;
    propio: boolean;
    meses: Record<number, MesData>;
    promedio: number | null;
    cumple: boolean | null;
}

interface Props {
    indicators: Indicator[];
    anio: number;
    needsClient: boolean;
}

const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Indicadores', href: '/indicadores' },
];

function cumpleMes(valor: number | null, sentido: Sentido, meta: number): boolean | null {
    if (valor === null) return null;
    return sentido === 'asc' ? valor >= meta : valor <= meta;
}
function fmt(v: number | null, unidad: string): string {
    if (v === null) return '—';
    return unidad === '%' ? `${v.toFixed(1)}%` : v.toFixed(1);
}

export default function IndicadoresIndex({ indicators, anio, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const page = usePage<SharedData>();
    const tenant = page.props.tenant as { id: number; name: string } | null;

    const [entryFor, setEntryFor] = useState<Indicator | null>(null);
    const [entry, setEntry] = useState<Record<number, { numerador: string; denominador: string }>>({});
    const [savingEntry, setSavingEntry] = useState(false);
    const [newOpen, setNewOpen] = useState(false);

    function openEntry(ind: Indicator) {
        const e: Record<number, { numerador: string; denominador: string }> = {};
        for (let m = 1; m <= 12; m++) {
            const d = ind.meses[m];
            e[m] = { numerador: d && d.numerador ? String(d.numerador) : '', denominador: d && d.denominador ? String(d.denominador) : '' };
        }
        setEntry(e);
        setEntryFor(ind);
    }

    function saveEntry() {
        if (!entryFor) return;
        router.post(
            route('indicadores.save'),
            {
                indicator_id: entryFor.id,
                anio,
                lecturas: Array.from({ length: 12 }, (_, i) => ({
                    mes: i + 1,
                    numerador: parseFloat(entry[i + 1]?.numerador || '0') || 0,
                    denominador: parseFloat(entry[i + 1]?.denominador || '0') || 0,
                })),
            },
            { preserveScroll: true, onStart: () => setSavingEntry(true), onFinish: () => setSavingEntry(false), onSuccess: () => setEntryFor(null) },
        );
    }

    function eliminar(ind: Indicator) {
        if (!confirm(`¿Eliminar el indicador «${ind.nombre}»?`)) return;
        router.delete(route('indicadores.destroy', ind.id), { preserveScroll: true });
    }

    // Valor calculado en vivo dentro del diálogo de captura.
    const valorVivo = (m: number): number | null => {
        if (!entryFor) return null;
        const num = parseFloat(entry[m]?.numerador || '');
        const den = parseFloat(entry[m]?.denominador || '');
        if (!den || isNaN(num) || isNaN(den)) return null;
        return Math.round((num / den) * entryFor.constante * 100) / 100;
    };

    if (needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Indicadores" />
                <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Dashboard de Indicadores</h1>
                        <p className="text-muted-foreground text-sm">Indicadores del SG-SST (Resolución 0312 / Decreto 1072).</p>
                    </div>
                    <Card>
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <Building2 className="size-7" />
                            </div>
                            <p className="font-medium">Selecciona un cliente para ver sus indicadores</p>
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
            <Head title="Indicadores" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Encabezado */}
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Dashboard de Indicadores</h1>
                        <p className="text-muted-foreground text-sm">
                            SG-SST · {anio}
                            {tenant ? (
                                <>
                                    {' '}
                                    · <span className="font-medium">{tenant.name}</span>
                                </>
                            ) : null}
                        </p>
                    </div>
                    {canManage && (
                        <Button onClick={() => setNewOpen(true)} variant="outline" className="gap-2">
                            <Plus className="size-4" /> Nuevo indicador
                        </Button>
                    )}
                </div>

                {/* Rejilla de indicadores */}
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {indicators.map((ind) => {
                        const semaforo = ind.cumple === null ? 'bg-muted-foreground/30' : ind.cumple ? 'bg-green-600' : 'bg-red-600';
                        const maxVal = Math.max(ind.meta, ...Object.values(ind.meses).map((d) => d.valor ?? 0), 1);

                        return (
                            <Card key={ind.id} className="overflow-hidden">
                                <CardContent className="flex flex-col gap-3 p-4">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-2">
                                                <span className="text-primary font-mono text-xs font-semibold">{ind.codigo}</span>
                                                <Badge variant="outline" className="text-[10px]">
                                                    {ind.categoria}
                                                </Badge>
                                                {ind.es_legal && (
                                                    <span className="rounded bg-blue-500/10 px-1.5 py-0.5 text-[10px] font-medium text-blue-700 dark:text-blue-400">Ley</span>
                                                )}
                                            </div>
                                            <p className="mt-0.5 text-sm font-semibold leading-tight">{ind.nombre}</p>
                                        </div>
                                        {ind.propio && canManage && (
                                            <button onClick={() => eliminar(ind)} className="text-muted-foreground hover:text-red-600" title="Eliminar indicador">
                                                <Trash2 className="size-4" />
                                            </button>
                                        )}
                                    </div>

                                    <div className="flex items-end justify-between">
                                        <div className="flex items-center gap-2">
                                            <span className={cn('size-3 rounded-full', semaforo)} />
                                            <span className="text-3xl font-bold tabular-nums">{fmt(ind.promedio, ind.unidad)}</span>
                                        </div>
                                        <span className="text-muted-foreground text-xs">
                                            Meta {ind.sentido === 'asc' ? '≥' : '≤'} {fmt(ind.meta, ind.unidad)}
                                        </span>
                                    </div>

                                    {/* Sparkline mensual */}
                                    <div className="flex items-end gap-0.5">
                                        {MESES.map((_, i) => {
                                            const d = ind.meses[i + 1];
                                            const ok = cumpleMes(d?.valor ?? null, ind.sentido, ind.meta);
                                            const h = d?.valor != null ? Math.max((d.valor / maxVal) * 100, 4) : 0;
                                            return (
                                                <div key={i} className="flex flex-1 flex-col items-center gap-0.5" title={`${MESES[i]}: ${fmt(d?.valor ?? null, ind.unidad)}`}>
                                                    <div className="bg-muted flex h-10 w-full items-end overflow-hidden rounded-sm">
                                                        <div
                                                            className={cn('w-full rounded-sm', ok === null ? 'bg-muted' : ok ? 'bg-green-600' : 'bg-red-500')}
                                                            style={{ height: `${h}%` }}
                                                        />
                                                    </div>
                                                    <span className="text-muted-foreground text-[9px]">{MESES[i][0]}</span>
                                                </div>
                                            );
                                        })}
                                    </div>

                                    {canManage && (
                                        <Button variant="secondary" size="sm" className="mt-1 gap-2" onClick={() => openEntry(ind)}>
                                            <Save className="size-3.5" /> Registrar datos
                                        </Button>
                                    )}
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            </div>

            {/* Diálogo de captura mensual */}
            <Dialog open={entryFor !== null} onOpenChange={(o) => !o && setEntryFor(null)}>
                <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                    {entryFor && (
                        <>
                            <DialogHeader>
                                <DialogTitle>{entryFor.nombre}</DialogTitle>
                                <DialogDescription>
                                    {entryFor.numerador_label} / {entryFor.denominador_label} × {entryFor.constante.toLocaleString('es')} · {anio}
                                </DialogDescription>
                            </DialogHeader>
                            <div className="space-y-1">
                                <div className="text-muted-foreground grid grid-cols-[3rem_1fr_1fr_4rem] gap-2 px-1 text-[11px] font-semibold">
                                    <span>Mes</span>
                                    <span>Numerador</span>
                                    <span>Denominador</span>
                                    <span className="text-right">Valor</span>
                                </div>
                                {MESES.map((mes, i) => {
                                    const m = i + 1;
                                    const v = valorVivo(m);
                                    return (
                                        <div key={m} className="grid grid-cols-[3rem_1fr_1fr_4rem] items-center gap-2">
                                            <span className="text-sm">{mes}</span>
                                            <Input
                                                type="number"
                                                min={0}
                                                value={entry[m]?.numerador ?? ''}
                                                onChange={(e) => setEntry((s) => ({ ...s, [m]: { ...s[m], numerador: e.target.value } }))}
                                                className="h-8"
                                            />
                                            <Input
                                                type="number"
                                                min={0}
                                                value={entry[m]?.denominador ?? ''}
                                                onChange={(e) => setEntry((s) => ({ ...s, [m]: { ...s[m], denominador: e.target.value } }))}
                                                className="h-8"
                                            />
                                            <span className="text-right text-sm tabular-nums">{fmt(v, entryFor.unidad)}</span>
                                        </div>
                                    );
                                })}
                            </div>
                            <DialogFooter>
                                <Button variant="outline" onClick={() => setEntryFor(null)}>
                                    Cancelar
                                </Button>
                                <Button onClick={saveEntry} disabled={savingEntry} className="gap-2">
                                    <Save className="size-4" /> {savingEntry ? 'Guardando…' : 'Guardar'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>

            {/* Diálogo nuevo indicador */}
            <NuevoIndicadorDialog open={newOpen} onClose={() => setNewOpen(false)} />
        </AppLayout>
    );
}

function NuevoIndicadorDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        codigo: '',
        nombre: '',
        categoria: 'SST',
        numerador_label: '',
        denominador_label: '',
        constante: 100,
        unidad: '%',
        sentido: 'asc',
        meta: 90,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(route('indicadores.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Nuevo indicador</DialogTitle>
                        <DialogDescription>Define un indicador propio del cliente: valor = (numerador / denominador) × constante.</DialogDescription>
                    </DialogHeader>
                    <div className="grid grid-cols-2 gap-3 py-2">
                        <div>
                            <Label htmlFor="codigo">Código</Label>
                            <Input id="codigo" value={data.codigo} onChange={(e) => setData('codigo', e.target.value)} placeholder="IND-01" />
                            <InputError message={errors.codigo} />
                        </div>
                        <div>
                            <Label htmlFor="categoria">Categoría</Label>
                            <select
                                id="categoria"
                                value={data.categoria}
                                onChange={(e) => setData('categoria', e.target.value)}
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option>SST</option>
                                <option>HSEQ</option>
                                <option>PESV</option>
                                <option>Proceso</option>
                            </select>
                        </div>
                        <div className="col-span-2">
                            <Label htmlFor="nombre">Nombre</Label>
                            <Input id="nombre" value={data.nombre} onChange={(e) => setData('nombre', e.target.value)} placeholder="Cobertura de inspecciones" />
                            <InputError message={errors.nombre} />
                        </div>
                        <div className="col-span-2">
                            <Label htmlFor="num">Numerador</Label>
                            <Input id="num" value={data.numerador_label} onChange={(e) => setData('numerador_label', e.target.value)} placeholder="N.° de inspecciones realizadas" />
                            <InputError message={errors.numerador_label} />
                        </div>
                        <div className="col-span-2">
                            <Label htmlFor="den">Denominador</Label>
                            <Input id="den" value={data.denominador_label} onChange={(e) => setData('denominador_label', e.target.value)} placeholder="N.° de inspecciones programadas" />
                            <InputError message={errors.denominador_label} />
                        </div>
                        <div>
                            <Label htmlFor="constante">Constante</Label>
                            <Input id="constante" type="number" min={1} value={data.constante} onChange={(e) => setData('constante', Number(e.target.value))} />
                        </div>
                        <div>
                            <Label htmlFor="unidad">Unidad</Label>
                            <select
                                id="unidad"
                                value={data.unidad}
                                onChange={(e) => setData('unidad', e.target.value)}
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option value="%">%</option>
                                <option value="tasa">tasa</option>
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="sentido">Sentido</Label>
                            <select
                                id="sentido"
                                value={data.sentido}
                                onChange={(e) => setData('sentido', e.target.value)}
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            >
                                <option value="asc">Mayor es mejor</option>
                                <option value="desc">Menor es mejor</option>
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="meta">Meta</Label>
                            <Input id="meta" type="number" min={0} step="0.01" value={data.meta} onChange={(e) => setData('meta', Number(e.target.value))} />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing} className="gap-2">
                            <Plus className="size-4" /> Crear indicador
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
