import InputError from '@/components/input-error';
import { Notice, SinCliente, StatCard, textoVencimiento, useNotice } from '@/components/pesv/shared';
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
import { Car, Pencil, Plus, Trash2, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Alerta {
    documento: string;
    vence: string;
    dias: number;
}

interface Vehiculo {
    id: number;
    placa: string;
    tipo: string;
    marca: string | null;
    linea: string | null;
    modelo: number | null;
    propiedad: string;
    propietario: string | null;
    soat_vence: string | null;
    tecnomecanica_vence: string | null;
    poliza_vence: string | null;
    kilometraje: number | null;
    ultimo_mantenimiento: string | null;
    proximo_mantenimiento: string | null;
    observaciones: string | null;
    is_active: boolean;
    alertas: Alerta[];
}

interface Props {
    needsClient: boolean;
    vehiculos: Vehiculo[];
    stats: { total: number; activos?: number; alertas: number };
    tipos: string[];
    propiedades: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'PESV', href: '/pesv' },
    { title: 'Vehículos', href: '/pesv/vehiculos' },
];

const vacio = {
    placa: '',
    tipo: 'automovil',
    marca: '',
    linea: '',
    modelo: '' as number | string,
    propiedad: 'propio',
    propietario: '',
    soat_vence: '',
    tecnomecanica_vence: '',
    poliza_vence: '',
    kilometraje: '' as number | string,
    ultimo_mantenimiento: '',
    proximo_mantenimiento: '',
    observaciones: '',
    is_active: true,
};

export default function PesvVehiculos({ needsClient, vehiculos, stats, tipos, propiedades }: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const page = usePage<SharedData>();
    const notice = useNotice(page.props.flash?.success);

    const [open, setOpen] = useState(false);
    const [editando, setEditando] = useState<Vehiculo | null>(null);
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({ ...vacio });

    if (needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Vehículos PESV" />
                <SinCliente titulo="Vehículos" descripcion="Flota de la empresa (PESV, Paso 5)." />
            </AppLayout>
        );
    }

    function abrirNuevo() {
        setEditando(null);
        clearErrors();
        setData({ ...vacio });
        setOpen(true);
    }

    function abrirEdicion(v: Vehiculo) {
        setEditando(v);
        clearErrors();
        setData({
            placa: v.placa,
            tipo: v.tipo,
            marca: v.marca ?? '',
            linea: v.linea ?? '',
            modelo: v.modelo ?? '',
            propiedad: v.propiedad,
            propietario: v.propietario ?? '',
            soat_vence: v.soat_vence ?? '',
            tecnomecanica_vence: v.tecnomecanica_vence ?? '',
            poliza_vence: v.poliza_vence ?? '',
            kilometraje: v.kilometraje ?? '',
            ultimo_mantenimiento: v.ultimo_mantenimiento ?? '',
            proximo_mantenimiento: v.proximo_mantenimiento ?? '',
            observaciones: v.observaciones ?? '',
            is_active: v.is_active,
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
            put(`/pesv/vehiculos/${editando.id}`, { preserveScroll: true, onSuccess: ok });
        } else {
            post('/pesv/vehiculos', { preserveScroll: true, onSuccess: ok });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Vehículos PESV" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Vehículos</h1>
                        <p className="text-muted-foreground text-sm">Flota de la empresa · caracterización del Paso 5 del PESV.</p>
                    </div>
                    {canManage && (
                        <Button onClick={abrirNuevo} className="gap-2">
                            <Plus className="size-4" /> Nuevo vehículo
                        </Button>
                    )}
                </div>

                <Notice mensaje={notice} />

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard label="Vehículos" value={stats.total} icon={Car} />
                    <StatCard label="Activos" value={stats.activos ?? 0} icon={Car} />
                    <StatCard label="Con documentos por vencer" value={stats.alertas} icon={TriangleAlert} danger={stats.alertas > 0} />
                </div>

                <Card>
                    <CardContent className="p-0">
                        {vehiculos.length === 0 ? (
                            <p className="text-muted-foreground p-8 text-center text-sm">
                                No hay vehículos registrados. La flota determina el nivel del PESV y alimenta los pasos 16 y 17.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="p-3 font-medium">Placa</th>
                                            <th className="p-3 font-medium">Tipo</th>
                                            <th className="p-3 font-medium">Marca / línea</th>
                                            <th className="p-3 font-medium">Propiedad</th>
                                            <th className="p-3 font-medium">Vencimientos</th>
                                            {canManage && <th className="p-3" />}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {vehiculos.map((v) => (
                                            <tr key={v.id} className={cn(!v.is_active && 'opacity-50')}>
                                                <td className="p-3 font-medium tabular-nums">{v.placa}</td>
                                                <td className="p-3 capitalize">{v.tipo}</td>
                                                <td className="p-3">
                                                    {[v.marca, v.linea, v.modelo].filter(Boolean).join(' ') || '—'}
                                                </td>
                                                <td className="p-3 capitalize">{v.propiedad}</td>
                                                <td className="p-3">
                                                    {v.alertas.length === 0 ? (
                                                        <span className="text-muted-foreground">Al día</span>
                                                    ) : (
                                                        <div className="flex flex-wrap gap-1">
                                                            {v.alertas.map((a) => (
                                                                <Badge
                                                                    key={a.documento}
                                                                    variant="secondary"
                                                                    className={cn(
                                                                        a.dias < 0
                                                                            ? 'bg-red-600/15 text-red-700'
                                                                            : 'bg-amber-500/15 text-amber-700',
                                                                    )}
                                                                >
                                                                    {a.documento} {textoVencimiento(a.dias)}
                                                                </Badge>
                                                            ))}
                                                        </div>
                                                    )}
                                                </td>
                                                {canManage && (
                                                    <td className="p-3 text-right whitespace-nowrap">
                                                        <Button variant="ghost" size="icon" onClick={() => abrirEdicion(v)}>
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                router.delete(`/pesv/vehiculos/${v.id}`, { preserveScroll: true })
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
                <DialogContent className="sm:max-w-2xl">
                    <form onSubmit={enviar}>
                        <DialogHeader>
                            <DialogTitle>{editando ? `Vehículo ${editando.placa}` : 'Nuevo vehículo'}</DialogTitle>
                        </DialogHeader>

                        <div className="grid gap-4 py-4 md:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="placa">Placa</Label>
                                <Input id="placa" value={data.placa} onChange={(e) => setData('placa', e.target.value.toUpperCase())} />
                                <InputError message={errors.placa} />
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
                                            {t}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="propiedad">Propiedad</Label>
                                <select
                                    id="propiedad"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm capitalize"
                                    value={data.propiedad}
                                    onChange={(e) => setData('propiedad', e.target.value)}
                                >
                                    {propiedades.map((p) => (
                                        <option key={p} value={p}>
                                            {p}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="marca">Marca</Label>
                                <Input id="marca" value={data.marca} onChange={(e) => setData('marca', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="linea">Línea</Label>
                                <Input id="linea" value={data.linea} onChange={(e) => setData('linea', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="modelo">Modelo (año)</Label>
                                <Input
                                    id="modelo"
                                    type="number"
                                    value={data.modelo}
                                    onChange={(e) => setData('modelo', e.target.value)}
                                />
                                <InputError message={errors.modelo} />
                            </div>

                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="propietario">Propietario</Label>
                                <Input
                                    id="propietario"
                                    value={data.propietario}
                                    onChange={(e) => setData('propietario', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="kilometraje">Kilometraje</Label>
                                <Input
                                    id="kilometraje"
                                    type="number"
                                    value={data.kilometraje}
                                    onChange={(e) => setData('kilometraje', e.target.value)}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="soat_vence">SOAT vence</Label>
                                <Input
                                    id="soat_vence"
                                    type="date"
                                    value={data.soat_vence}
                                    onChange={(e) => setData('soat_vence', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="tecnomecanica_vence">Tecnomecánica vence</Label>
                                <Input
                                    id="tecnomecanica_vence"
                                    type="date"
                                    value={data.tecnomecanica_vence}
                                    onChange={(e) => setData('tecnomecanica_vence', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="poliza_vence">Póliza vence</Label>
                                <Input
                                    id="poliza_vence"
                                    type="date"
                                    value={data.poliza_vence}
                                    onChange={(e) => setData('poliza_vence', e.target.value)}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="ultimo_mantenimiento">Último mantenimiento</Label>
                                <Input
                                    id="ultimo_mantenimiento"
                                    type="date"
                                    value={data.ultimo_mantenimiento}
                                    onChange={(e) => setData('ultimo_mantenimiento', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="proximo_mantenimiento">Próximo mantenimiento</Label>
                                <Input
                                    id="proximo_mantenimiento"
                                    type="date"
                                    value={data.proximo_mantenimiento}
                                    onChange={(e) => setData('proximo_mantenimiento', e.target.value)}
                                />
                            </div>
                            <label className="flex items-end gap-2 pb-2 text-sm">
                                <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', v === true)} />
                                Activo en la flota
                            </label>

                            <div className="grid gap-2 md:col-span-3">
                                <Label htmlFor="observaciones">Observaciones</Label>
                                <textarea
                                    id="observaciones"
                                    rows={2}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                    value={data.observaciones}
                                    onChange={(e) => setData('observaciones', e.target.value)}
                                />
                            </div>
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
