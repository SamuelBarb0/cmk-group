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
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Activity, HeartPulse, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Siniestro {
    id: number;
    fecha: string;
    hora: string | null;
    lugar: string | null;
    tipo: string;
    gravedad: string;
    pesv_vehicle_id: number | null;
    employee_id: number | null;
    descripcion: string | null;
    causa_probable: string | null;
    lesionados: number;
    fallecidos: number;
    dias_incapacidad: number | null;
    costo: string | number | null;
    investigado: boolean;
    acciones: string | null;
    vehiculo?: { id: number; placa: string } | null;
    conductor?: { id: number; nombres: string; apellidos: string } | null;
}

interface Props {
    needsClient: boolean;
    siniestros: Siniestro[];
    stats: {
        total: number;
        sin_investigar: number;
        con_heridos: number;
        fatales: number;
        lesionados: number;
        dias_incapacidad: number;
        por_mes: number[];
    } | null;
    vehiculos: { id: number; placa: string }[];
    conductores: { id: number; nombres: string; apellidos: string }[];
    tipos: string[];
    gravedades: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'PESV', href: '/pesv' },
    { title: 'Siniestros viales', href: '/pesv/siniestros' },
];

const GRAVEDAD_LABEL: Record<string, string> = {
    solo_danos: 'Solo daños',
    con_heridos: 'Con heridos',
    fatal: 'Fatal',
};

const GRAVEDAD_CLASS: Record<string, string> = {
    solo_danos: 'bg-amber-500/15 text-amber-700',
    con_heridos: 'bg-orange-500/15 text-orange-700',
    fatal: 'bg-red-600/15 text-red-700',
};

const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

const vacio = {
    fecha: '',
    hora: '',
    lugar: '',
    tipo: 'choque',
    gravedad: 'solo_danos',
    pesv_vehicle_id: '' as number | string,
    employee_id: '' as number | string,
    descripcion: '',
    causa_probable: '',
    lesionados: 0,
    fallecidos: 0,
    dias_incapacidad: '' as number | string,
    costo: '' as number | string,
    investigado: false,
    acciones: '',
};

export default function PesvSiniestros({ needsClient, siniestros, stats, vehiculos, conductores, tipos, gravedades }: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const page = usePage<SharedData>();
    const notice = useNotice(page.props.flash?.success);

    const [open, setOpen] = useState(false);
    const [editando, setEditando] = useState<Siniestro | null>(null);
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({ ...vacio });

    if (needsClient || !stats) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Siniestros viales" />
                <SinCliente titulo="Siniestros viales" descripcion="Registro e investigación de siniestros (PESV, pasos 13 y 21)." />
            </AppLayout>
        );
    }

    function abrirNuevo() {
        setEditando(null);
        clearErrors();
        setData({ ...vacio });
        setOpen(true);
    }

    function abrirEdicion(s: Siniestro) {
        setEditando(s);
        clearErrors();
        setData({
            fecha: s.fecha,
            hora: s.hora?.slice(0, 5) ?? '',
            lugar: s.lugar ?? '',
            tipo: s.tipo,
            gravedad: s.gravedad,
            pesv_vehicle_id: s.pesv_vehicle_id ?? '',
            employee_id: s.employee_id ?? '',
            descripcion: s.descripcion ?? '',
            causa_probable: s.causa_probable ?? '',
            lesionados: s.lesionados,
            fallecidos: s.fallecidos,
            dias_incapacidad: s.dias_incapacidad ?? '',
            costo: s.costo ?? '',
            investigado: s.investigado,
            acciones: s.acciones ?? '',
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
            put(`/pesv/siniestros/${editando.id}`, { preserveScroll: true, onSuccess: ok });
        } else {
            post('/pesv/siniestros', { preserveScroll: true, onSuccess: ok });
        }
    };

    const maxMes = Math.max(1, ...stats.por_mes);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Siniestros viales" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Siniestros viales</h1>
                        <p className="text-muted-foreground text-sm">
                            Registro e investigación · alimenta los pasos 13 y 21, y el reporte de autogestión.
                        </p>
                    </div>
                    {canManage && (
                        <Button onClick={abrirNuevo} className="gap-2">
                            <Plus className="size-4" /> Registrar siniestro
                        </Button>
                    )}
                </div>

                <Notice mensaje={notice} />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Siniestros" value={stats.total} icon={Activity} />
                    <StatCard label="Sin investigar" value={stats.sin_investigar} icon={Search} danger={stats.sin_investigar > 0} />
                    <StatCard label="Con heridos" value={stats.con_heridos} icon={HeartPulse} danger={stats.con_heridos > 0} />
                    <StatCard label="Fatales" value={stats.fatales} icon={HeartPulse} danger={stats.fatales > 0} />
                </div>

                {stats.total > 0 && (
                    <Card>
                        <CardContent className="space-y-3 p-5">
                            <h2 className="font-semibold">Siniestralidad del año en curso</h2>
                            <div className="flex items-end gap-2">
                                {stats.por_mes.map((n, i) => (
                                    <div key={i} className="flex flex-1 flex-col items-center gap-1">
                                        <span className="text-xs tabular-nums">{n > 0 ? n : ''}</span>
                                        <div
                                            className={cn('w-full rounded-t', n > 0 ? 'bg-primary' : 'bg-muted')}
                                            style={{ height: `${Math.max(4, (n / maxMes) * 80)}px` }}
                                        />
                                        <span className="text-muted-foreground text-xs">{MESES[i]}</span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardContent className="p-0">
                        {siniestros.length === 0 ? (
                            <p className="text-muted-foreground p-8 text-center text-sm">
                                Sin siniestros registrados. Un histórico vacío también es un dato válido, pero debe ser real: el paso 21 se
                                construye sobre él.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="p-3 font-medium">Fecha</th>
                                            <th className="p-3 font-medium">Tipo</th>
                                            <th className="p-3 font-medium">Gravedad</th>
                                            <th className="p-3 font-medium">Vehículo / conductor</th>
                                            <th className="p-3 font-medium">Investigado</th>
                                            {canManage && <th className="p-3" />}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {siniestros.map((s) => (
                                            <tr key={s.id}>
                                                <td className="p-3">
                                                    <div className="tabular-nums">{s.fecha}</div>
                                                    {s.lugar && <div className="text-muted-foreground text-xs">{s.lugar}</div>}
                                                </td>
                                                <td className="p-3 capitalize">{s.tipo.replace('_', ' ')}</td>
                                                <td className="p-3">
                                                    <Badge variant="secondary" className={cn(GRAVEDAD_CLASS[s.gravedad])}>
                                                        {GRAVEDAD_LABEL[s.gravedad] ?? s.gravedad}
                                                    </Badge>
                                                    {s.lesionados > 0 && (
                                                        <div className="text-muted-foreground text-xs">{s.lesionados} lesionados</div>
                                                    )}
                                                </td>
                                                <td className="p-3">
                                                    {s.vehiculo?.placa ?? '—'}
                                                    {s.conductor && (
                                                        <div className="text-muted-foreground text-xs">
                                                            {s.conductor.nombres} {s.conductor.apellidos}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="p-3">
                                                    {s.investigado ? (
                                                        <Badge variant="secondary" className="bg-emerald-600/15 text-emerald-700">
                                                            Sí
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-amber-700">Pendiente</span>
                                                    )}
                                                </td>
                                                {canManage && (
                                                    <td className="p-3 text-right whitespace-nowrap">
                                                        <Button variant="ghost" size="icon" onClick={() => abrirEdicion(s)}>
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                router.delete(`/pesv/siniestros/${s.id}`, { preserveScroll: true })
                                                            }
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
                            <DialogTitle>{editando ? 'Siniestro vial' : 'Registrar siniestro'}</DialogTitle>
                        </DialogHeader>

                        <div className="grid gap-4 py-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="fecha">Fecha</Label>
                                <Input id="fecha" type="date" value={data.fecha} onChange={(e) => setData('fecha', e.target.value)} />
                                <InputError message={errors.fecha} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="hora">Hora</Label>
                                <Input id="hora" type="time" value={data.hora} onChange={(e) => setData('hora', e.target.value)} />
                                <InputError message={errors.hora} />
                            </div>
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="lugar">Lugar</Label>
                                <Input id="lugar" value={data.lugar} onChange={(e) => setData('lugar', e.target.value)} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="tipo">Tipo</Label>
                                <select
                                    id="tipo"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm capitalize"
                                    value={data.tipo}
                                    onChange={(e) => setData('tipo', e.target.value)}
                                >
                                    {tipos.map((t) => (
                                        <option key={t} value={t}>
                                            {t.replace('_', ' ')}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="gravedad">Gravedad</Label>
                                <select
                                    id="gravedad"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={data.gravedad}
                                    onChange={(e) => setData('gravedad', e.target.value)}
                                >
                                    {gravedades.map((g) => (
                                        <option key={g} value={g}>
                                            {GRAVEDAD_LABEL[g] ?? g}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="pesv_vehicle_id">Vehículo</Label>
                                <select
                                    id="pesv_vehicle_id"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={data.pesv_vehicle_id}
                                    onChange={(e) => setData('pesv_vehicle_id', e.target.value)}
                                >
                                    <option value="">Sin especificar</option>
                                    {vehiculos.map((v) => (
                                        <option key={v.id} value={v.id}>
                                            {v.placa}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="employee_id">Conductor</Label>
                                <select
                                    id="employee_id"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={data.employee_id}
                                    onChange={(e) => setData('employee_id', e.target.value)}
                                >
                                    <option value="">Sin especificar</option>
                                    {conductores.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.nombres} {c.apellidos}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="lesionados">Lesionados</Label>
                                <Input
                                    id="lesionados"
                                    type="number"
                                    value={data.lesionados}
                                    onChange={(e) => setData('lesionados', Number(e.target.value))}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fallecidos">Fallecidos</Label>
                                <Input
                                    id="fallecidos"
                                    type="number"
                                    value={data.fallecidos}
                                    onChange={(e) => setData('fallecidos', Number(e.target.value))}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="dias_incapacidad">Días de incapacidad</Label>
                                <Input
                                    id="dias_incapacidad"
                                    type="number"
                                    value={data.dias_incapacidad}
                                    onChange={(e) => setData('dias_incapacidad', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="costo">Costo estimado</Label>
                                <Input
                                    id="costo"
                                    type="number"
                                    step="0.01"
                                    value={data.costo}
                                    onChange={(e) => setData('costo', e.target.value)}
                                />
                            </div>

                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="descripcion">Descripción</Label>
                                <textarea
                                    id="descripcion"
                                    rows={2}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                    value={data.descripcion}
                                    onChange={(e) => setData('descripcion', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="causa_probable">Causa probable</Label>
                                <textarea
                                    id="causa_probable"
                                    rows={2}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                    value={data.causa_probable}
                                    onChange={(e) => setData('causa_probable', e.target.value)}
                                />
                            </div>

                            <label className="flex items-center gap-2 text-sm md:col-span-2">
                                <Checkbox checked={data.investigado} onCheckedChange={(v) => setData('investigado', v === true)} />
                                Investigación cerrada
                            </label>

                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="acciones">Acciones derivadas</Label>
                                <textarea
                                    id="acciones"
                                    rows={2}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                    value={data.acciones}
                                    onChange={(e) => setData('acciones', e.target.value)}
                                />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {editando ? 'Guardar' : 'Registrar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
