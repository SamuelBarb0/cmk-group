import { CodigoSig } from '@/components/codigo-sig';
import InputError from '@/components/input-error';
import { Notice, SinCliente, StatCard, useNotice } from '@/components/pesv/shared';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CircleAlert, Pencil, Plus, ReceiptText, Trash2, Users } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Infraccion {
    id: number;
    employee_id: number;
    pesv_vehicle_id: number | null;
    codigo: string;
    descripcion: string | null;
    estado: string;
    registrada_simit: boolean;
    acciones: string | null;
    fecha: string;
    valor: number | null;
    conductor: string;
    placa: string | null;
}
type Props =
    | { needsClient: true }
    | {
          needsClient: false;
          anio: number;
          infracciones: Infraccion[];
          porCodigo: { codigo: string; descripcion: string | null; cantidad: number }[];
          stats: { total: number; abiertas: number; conductores: number; valor: number };
          conductores: { id: number; nombre: string }[];
          vehiculos: { id: number; placa: string }[];
          estados: Record<string, string>;
      };

const breadcrumbs = [
    { title: 'PESV', href: '/pesv' },
    { title: 'Infracciones de tránsito', href: '/pesv/infracciones' },
];

const pesos = (v: number) => '$ ' + v.toLocaleString('es-CO', { maximumFractionDigits: 0 });

export default function PesvInfracciones(props: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const notice = useNotice(usePage<SharedData>().props.flash?.success);
    const [abierto, setAbierto] = useState(false);
    const [editando, setEditando] = useState<Infraccion | null>(null);
    const hoy = new Date().toISOString().slice(0, 10);
    const form = useForm({
        employee_id: '',
        pesv_vehicle_id: '',
        fecha: hoy,
        codigo: '',
        descripcion: '',
        valor: '',
        estado: 'pendiente',
        registrada_simit: true as boolean,
        acciones: '',
    });

    if (props.needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Infracciones de tránsito" />
                <SinCliente titulo="Infracciones de tránsito" descripcion="Seguimiento a comparendos de los conductores." />
            </AppLayout>
        );
    }
    const { anio, infracciones, porCodigo, stats, conductores, vehiculos, estados } = props;

    function abrir(i: Infraccion | null) {
        setEditando(i);
        form.clearErrors();
        form.setData({
            employee_id: i ? String(i.employee_id) : '',
            pesv_vehicle_id: i?.pesv_vehicle_id ? String(i.pesv_vehicle_id) : '',
            fecha: i?.fecha ?? hoy,
            codigo: i?.codigo ?? '',
            descripcion: i?.descripcion ?? '',
            valor: i?.valor !== null && i?.valor !== undefined ? String(i.valor) : '',
            estado: i?.estado ?? 'pendiente',
            registrada_simit: i?.registrada_simit ?? true,
            acciones: i?.acciones ?? '',
        });
        setAbierto(true);
    }
    const guardar: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setAbierto(false) };
        if (editando) form.put(`/pesv/infracciones/${editando.id}`, opts);
        else form.post('/pesv/infracciones', opts);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Infracciones de tránsito" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            <CodigoSig className="mr-2" />
                            Infracciones de tránsito
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Seguimiento a comparendos (RE-SST-52). El reporte de autogestión pide el número por código de infracción.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <select
                            aria-label="Año"
                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            value={anio}
                            onChange={(e) => router.get('/pesv/infracciones', { anio: e.target.value }, { preserveScroll: true })}
                        >
                            {[0, 1, 2, 3].map((d) => (
                                <option key={d}>{new Date().getFullYear() - d}</option>
                            ))}
                        </select>
                        {canManage && (
                            <Button className="gap-2" onClick={() => abrir(null)} disabled={conductores.length === 0}>
                                <Plus className="size-4" /> Registrar infracción
                            </Button>
                        )}
                    </div>
                </div>
                {conductores.length === 0 && (
                    <p className="text-sm text-amber-700 dark:text-amber-400">
                        No hay conductores activos. Marca como conductores a los colaboradores en{' '}
                        <Link href="/pesv/colaboradores" className="underline">
                            Colaboradores
                        </Link>
                        .
                    </p>
                )}

                <Notice mensaje={notice} />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label={`Infracciones ${anio}`} value={stats.total} icon={ReceiptText} />
                    <StatCard label="Sin cerrar" value={stats.abiertas} icon={CircleAlert} danger={stats.abiertas > 0} />
                    <StatCard label="Conductores con infracciones" value={stats.conductores} icon={Users} />
                    <StatCard label="Valor total" value={pesos(stats.valor)} icon={ReceiptText} />
                </div>

                {porCodigo.length > 0 && (
                    <Card>
                        <CardContent className="p-5">
                            <h2 className="mb-2 font-semibold">Por código de infracción</h2>
                            <div className="flex flex-wrap gap-2">
                                {porCodigo.map((c) => (
                                    <span key={c.codigo} className="rounded-md border px-2 py-1 text-sm">
                                        <span className="font-mono font-semibold">{c.codigo}</span>
                                        {c.descripcion ? ` ${c.descripcion}` : ''} · <span className="font-semibold">{c.cantidad}</span>
                                    </span>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card className="overflow-hidden">
                    {infracciones.length === 0 ? (
                        <p className="text-muted-foreground p-6 text-center text-sm">Sin infracciones registradas en {anio}.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground border-b text-left">
                                    <tr>
                                        <th className="p-3 font-medium">Fecha</th>
                                        <th className="p-3 font-medium">Conductor</th>
                                        <th className="p-3 font-medium">Código</th>
                                        <th className="p-3 font-medium">Vehículo</th>
                                        <th className="p-3 font-medium">Valor</th>
                                        <th className="p-3 font-medium">Estado</th>
                                        <th className="p-3 font-medium">SIMIT</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {infracciones.map((i) => (
                                        <tr key={i.id}>
                                            <td className="p-3 whitespace-nowrap">{i.fecha}</td>
                                            <td className="p-3">
                                                <Link href={`/pesv/conductores/${i.employee_id}`} className="hover:underline">
                                                    {i.conductor}
                                                </Link>
                                            </td>
                                            <td className="p-3">
                                                <span className="font-mono font-semibold">{i.codigo}</span>
                                                {i.descripcion && <div className="text-muted-foreground text-xs">{i.descripcion}</div>}
                                            </td>
                                            <td className="p-3">{i.placa ?? '—'}</td>
                                            <td className="p-3 whitespace-nowrap">{i.valor !== null ? pesos(i.valor) : '—'}</td>
                                            <td className="p-3">{estados[i.estado] ?? i.estado}</td>
                                            <td className="p-3">{i.registrada_simit ? 'Sí' : 'No'}</td>
                                            <td className="p-3 text-right whitespace-nowrap">
                                                {canManage && (
                                                    <>
                                                        <Button variant="ghost" size="icon" aria-label="Editar infracción" onClick={() => abrir(i)}>
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label="Eliminar infracción"
                                                            onClick={() =>
                                                                confirm('¿Eliminar esta infracción?') &&
                                                                router.delete(`/pesv/infracciones/${i.id}`, { preserveScroll: true })
                                                            }
                                                        >
                                                            <Trash2 className="size-4 text-red-600" />
                                                        </Button>
                                                    </>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </div>

            <Dialog open={abierto} onOpenChange={setAbierto}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-lg">
                    <form onSubmit={guardar}>
                        <DialogHeader>
                            <DialogTitle>{editando ? 'Editar infracción' : 'Registrar infracción'}</DialogTitle>
                        </DialogHeader>
                        <div className="grid gap-4 py-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="i_conductor">Conductor</Label>
                                <select
                                    id="i_conductor"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={form.data.employee_id}
                                    onChange={(e) => form.setData('employee_id', e.target.value)}
                                >
                                    <option value="">—</option>
                                    {conductores.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.nombre}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={form.errors.employee_id} />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="i_fecha">Fecha</Label>
                                    <Input
                                        id="i_fecha"
                                        type="date"
                                        max={hoy}
                                        value={form.data.fecha}
                                        onChange={(e) => form.setData('fecha', e.target.value)}
                                    />
                                    <InputError message={form.errors.fecha} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="i_codigo">Código</Label>
                                    <Input
                                        id="i_codigo"
                                        className="font-mono uppercase"
                                        placeholder="C29"
                                        value={form.data.codigo}
                                        onChange={(e) => form.setData('codigo', e.target.value)}
                                    />
                                    <InputError message={form.errors.codigo} />
                                </div>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="i_desc">Descripción</Label>
                                <Input
                                    id="i_desc"
                                    placeholder="Exceso de velocidad…"
                                    value={form.data.descripcion}
                                    onChange={(e) => form.setData('descripcion', e.target.value)}
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="i_vehiculo">Vehículo</Label>
                                    <select
                                        id="i_vehiculo"
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                        value={form.data.pesv_vehicle_id}
                                        onChange={(e) => form.setData('pesv_vehicle_id', e.target.value)}
                                    >
                                        <option value="">—</option>
                                        {vehiculos.map((v) => (
                                            <option key={v.id} value={v.id}>
                                                {v.placa}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="i_valor">Valor</Label>
                                    <Input
                                        id="i_valor"
                                        type="number"
                                        min={0}
                                        value={form.data.valor}
                                        onChange={(e) => form.setData('valor', e.target.value)}
                                    />
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="i_estado">Estado</Label>
                                    <select
                                        id="i_estado"
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                        value={form.data.estado}
                                        onChange={(e) => form.setData('estado', e.target.value)}
                                    >
                                        {Object.entries(estados).map(([v, l]) => (
                                            <option key={v} value={v}>
                                                {l}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <label className="flex items-end gap-2 pb-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.registrada_simit}
                                        onChange={(e) => form.setData('registrada_simit', e.target.checked)}
                                    />
                                    Registrada en SIMIT
                                </label>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="i_acciones">Acciones tomadas</Label>
                                <textarea
                                    id="i_acciones"
                                    rows={2}
                                    placeholder="Reinducción, acta de compromiso, curso pedagógico…"
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                    value={form.data.acciones}
                                    onChange={(e) => form.setData('acciones', e.target.value)}
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setAbierto(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Guardar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
