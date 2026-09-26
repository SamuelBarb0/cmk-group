import { CodigoSig } from '@/components/codigo-sig';
import InputError from '@/components/input-error';
import { Notice, SinCliente, StatCard, useNotice } from '@/components/pesv/shared';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Milestone, Pencil, Plus, Route, Trash2, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Ruta {
    id: number;
    nombre: string;
    origen: string | null;
    destino: string | null;
    tipo_via: string | null;
    distancia_km: string | number | null;
    duracion_min: number | null;
    frecuencia: string | null;
    horario: string | null;
    peligros: string | null;
    controles: string | null;
    nivel_riesgo: string | null;
    is_active: boolean;
}

interface Props {
    needsClient: boolean;
    rutas: Ruta[];
    stats: { total: number; criticas: number; km?: number };
    nivelesRiesgo: string[];
    tiposVia: string[];
    frecuencias: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'PESV', href: '/pesv' },
    { title: 'Rutas', href: '/pesv/rutas' },
];

const RIESGO_CLASS: Record<string, string> = {
    critico: 'bg-red-600/15 text-red-700',
    alto: 'bg-orange-500/15 text-orange-700',
    medio: 'bg-amber-500/15 text-amber-700',
    bajo: 'bg-emerald-600/15 text-emerald-700',
};

const vacio = {
    nombre: '',
    origen: '',
    destino: '',
    tipo_via: '',
    distancia_km: '' as number | string,
    duracion_min: '' as number | string,
    frecuencia: '',
    horario: '',
    peligros: '',
    controles: '',
    nivel_riesgo: '',
    is_active: true,
};

export default function PesvRutas({ needsClient, rutas, stats, nivelesRiesgo, tiposVia, frecuencias }: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const page = usePage<SharedData>();
    const notice = useNotice(page.props.flash?.success);

    const [open, setOpen] = useState(false);
    const [editando, setEditando] = useState<Ruta | null>(null);
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({ ...vacio });

    if (needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Rutas PESV" />
                <SinCliente titulo="Rutas" descripcion="Rutas y desplazamientos habituales (PESV, Paso 5)." />
            </AppLayout>
        );
    }

    function abrirNuevo() {
        setEditando(null);
        clearErrors();
        setData({ ...vacio });
        setOpen(true);
    }

    function abrirEdicion(r: Ruta) {
        setEditando(r);
        clearErrors();
        setData({
            nombre: r.nombre,
            origen: r.origen ?? '',
            destino: r.destino ?? '',
            tipo_via: r.tipo_via ?? '',
            distancia_km: r.distancia_km ?? '',
            duracion_min: r.duracion_min ?? '',
            frecuencia: r.frecuencia ?? '',
            horario: r.horario ?? '',
            peligros: r.peligros ?? '',
            controles: r.controles ?? '',
            nivel_riesgo: r.nivel_riesgo ?? '',
            is_active: r.is_active,
        });
        setOpen(true);
    }

    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        const ok = () => {
            setOpen(false);
            reset();
        };
        if (editando) {
            put(`/pesv/rutas/${editando.id}`, { preserveScroll: true, onSuccess: ok });
        } else {
            post('/pesv/rutas', { preserveScroll: true, onSuccess: ok });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Rutas PESV" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            <CodigoSig className="mr-2" />
                            Rutas
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Desplazamientos habituales · alimenta los pasos 14 (vías seguras) y 15 (planificación de desplazamientos).
                        </p>
                    </div>
                    {canManage && (
                        <Button onClick={abrirNuevo} className="gap-2">
                            <Plus className="size-4" /> Nueva ruta
                        </Button>
                    )}
                </div>

                <Notice mensaje={notice} />

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard label="Rutas" value={stats.total} icon={Route} />
                    <StatCard label="Riesgo alto o crítico" value={stats.criticas} icon={TriangleAlert} danger={stats.criticas > 0} />
                    <StatCard label="Kilómetros caracterizados" value={stats.km ?? 0} icon={Milestone} />
                </div>

                <Card>
                    <CardContent className="p-0">
                        {rutas.length === 0 ? (
                            <p className="text-muted-foreground p-8 text-center text-sm">
                                Sin rutas caracterizadas. Son la base para valorar el riesgo vial y planificar los desplazamientos.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="p-3 font-medium">Ruta</th>
                                            <th className="p-3 font-medium">Trayecto</th>
                                            <th className="p-3 font-medium">Vía</th>
                                            <th className="p-3 font-medium">Frecuencia</th>
                                            <th className="p-3 font-medium">Riesgo</th>
                                            {canManage && <th className="p-3" />}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {rutas.map((r) => (
                                            <tr key={r.id} className={cn(!r.is_active && 'opacity-50')}>
                                                <td className="p-3">
                                                    <div className="font-medium">{r.nombre}</div>
                                                    <Link href={`/pesv/rutas/${r.id}/plan`} className="text-primary text-xs hover:underline">
                                                        Plan de desplazamiento
                                                    </Link>
                                                    {r.peligros && <div className="text-muted-foreground line-clamp-1 text-xs">{r.peligros}</div>}
                                                </td>
                                                <td className="p-3">
                                                    {r.origen && r.destino ? `${r.origen} → ${r.destino}` : '—'}
                                                    {r.distancia_km && <div className="text-muted-foreground text-xs">{r.distancia_km} km</div>}
                                                </td>
                                                <td className="p-3 capitalize">{r.tipo_via ?? '—'}</td>
                                                <td className="p-3 capitalize">{r.frecuencia ?? '—'}</td>
                                                <td className="p-3">
                                                    {r.nivel_riesgo ? (
                                                        <Badge variant="secondary" className={cn('capitalize', RIESGO_CLASS[r.nivel_riesgo])}>
                                                            {r.nivel_riesgo}
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-muted-foreground">Sin valorar</span>
                                                    )}
                                                </td>
                                                {canManage && (
                                                    <td className="p-3 text-right whitespace-nowrap">
                                                        <Button variant="ghost" size="icon" onClick={() => abrirEdicion(r)}>
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() => router.delete(`/pesv/rutas/${r.id}`, { preserveScroll: true })}
                                                        >
                                                            <Trash2 className="size-4 text-red-600" />
                                                        </Button>
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
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-xl">
                    <form onSubmit={enviar}>
                        <DialogHeader>
                            <DialogTitle>{editando ? editando.nombre : 'Nueva ruta'}</DialogTitle>
                        </DialogHeader>

                        <div className="grid gap-4 py-4 md:grid-cols-2">
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="nombre">Nombre de la ruta</Label>
                                <Input id="nombre" value={data.nombre} onChange={(e) => setData('nombre', e.target.value)} />
                                <InputError message={errors.nombre} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="origen">Origen</Label>
                                <Input id="origen" value={data.origen} onChange={(e) => setData('origen', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="destino">Destino</Label>
                                <Input id="destino" value={data.destino} onChange={(e) => setData('destino', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="distancia_km">Distancia (km)</Label>
                                <Input
                                    id="distancia_km"
                                    type="number"
                                    step="0.01"
                                    value={data.distancia_km}
                                    onChange={(e) => setData('distancia_km', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="duracion_min">Duración (min)</Label>
                                <Input
                                    id="duracion_min"
                                    type="number"
                                    value={data.duracion_min}
                                    onChange={(e) => setData('duracion_min', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="tipo_via">Tipo de vía</Label>
                                <select
                                    id="tipo_via"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm capitalize"
                                    value={data.tipo_via}
                                    onChange={(e) => setData('tipo_via', e.target.value)}
                                >
                                    <option value="">Sin especificar</option>
                                    {tiposVia.map((t) => (
                                        <option key={t} value={t}>
                                            {t}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="frecuencia">Frecuencia</Label>
                                <select
                                    id="frecuencia"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm capitalize"
                                    value={data.frecuencia}
                                    onChange={(e) => setData('frecuencia', e.target.value)}
                                >
                                    <option value="">Sin especificar</option>
                                    {frecuencias.map((f) => (
                                        <option key={f} value={f}>
                                            {f}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="horario">Horario</Label>
                                <Input id="horario" value={data.horario} onChange={(e) => setData('horario', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="nivel_riesgo">Nivel de riesgo</Label>
                                <select
                                    id="nivel_riesgo"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm capitalize"
                                    value={data.nivel_riesgo}
                                    onChange={(e) => setData('nivel_riesgo', e.target.value)}
                                >
                                    <option value="">Sin valorar</option>
                                    {nivelesRiesgo.map((n) => (
                                        <option key={n} value={n}>
                                            {n}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="peligros">Peligros de la ruta</Label>
                                <textarea
                                    id="peligros"
                                    rows={2}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                    placeholder="Tramos sin señalización, zonas escolares, curvas cerradas, condiciones climáticas…"
                                    value={data.peligros}
                                    onChange={(e) => setData('peligros', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="controles">Controles</Label>
                                <textarea
                                    id="controles"
                                    rows={2}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                    value={data.controles}
                                    onChange={(e) => setData('controles', e.target.value)}
                                />
                            </div>

                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', v === true)} />
                                Ruta activa
                            </label>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {editando ? 'Guardar' : 'Agregar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
