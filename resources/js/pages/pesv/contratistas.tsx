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
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Briefcase, Car, ClipboardCheck, Pencil, Plus, Trash2, Users } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Contratista {
    id: number;
    nombre: string;
    nit: string | null;
    tipo: string;
    actividad: string | null;
    contacto_nombre: string | null;
    contacto_telefono: string | null;
    contacto_email: string | null;
    num_conductores: number | null;
    num_vehiculos: number | null;
    tiene_pesv: boolean;
    evaluado_at: string | null;
    calificacion: number | null;
    is_active: boolean;
}

interface Props {
    needsClient: boolean;
    contratistas: Contratista[];
    stats: { total: number; sin_evaluar: number; con_pesv?: number; conductores?: number; vehiculos?: number };
    tipos: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'PESV', href: '/pesv' },
    { title: 'Contratistas', href: '/pesv/contratistas' },
];

const vacio = {
    nombre: '',
    nit: '',
    tipo: 'contratista',
    actividad: '',
    contacto_nombre: '',
    contacto_telefono: '',
    contacto_email: '',
    num_conductores: '' as number | string,
    num_vehiculos: '' as number | string,
    tiene_pesv: false,
    evaluado_at: '',
    calificacion: '' as number | string,
    is_active: true,
};

export default function PesvContratistas({ needsClient, contratistas, stats, tipos }: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const page = usePage<SharedData>();
    const notice = useNotice(page.props.flash?.success);

    const [open, setOpen] = useState(false);
    const [editando, setEditando] = useState<Contratista | null>(null);
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({ ...vacio });

    if (needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Contratistas PESV" />
                <SinCliente titulo="Contratistas" descripcion="Contratistas y terceros con impacto en el PESV (Paso 5)." />
            </AppLayout>
        );
    }

    function abrirNuevo() {
        setEditando(null);
        clearErrors();
        setData({ ...vacio });
        setOpen(true);
    }

    function abrirEdicion(c: Contratista) {
        setEditando(c);
        clearErrors();
        setData({
            nombre: c.nombre,
            nit: c.nit ?? '',
            tipo: c.tipo,
            actividad: c.actividad ?? '',
            contacto_nombre: c.contacto_nombre ?? '',
            contacto_telefono: c.contacto_telefono ?? '',
            contacto_email: c.contacto_email ?? '',
            num_conductores: c.num_conductores ?? '',
            num_vehiculos: c.num_vehiculos ?? '',
            tiene_pesv: c.tiene_pesv,
            evaluado_at: c.evaluado_at ?? '',
            calificacion: c.calificacion ?? '',
            is_active: c.is_active,
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
            put(`/pesv/contratistas/${editando.id}`, { preserveScroll: true, onSuccess: ok });
        } else {
            post('/pesv/contratistas', { preserveScroll: true, onSuccess: ok });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Contratistas PESV" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            <CodigoSig className="mr-2" />
                            Contratistas y terceros
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Con impacto en el PESV · alimenta los pasos 11 (evaluación de terceros) y 18 (gestión del cambio).
                        </p>
                    </div>
                    {canManage && (
                        <Button onClick={abrirNuevo} className="gap-2">
                            <Plus className="size-4" /> Nuevo contratista
                        </Button>
                    )}
                </div>

                <Notice mensaje={notice} />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Contratistas" value={stats.total} icon={Briefcase} />
                    <StatCard label="Sin evaluar" value={stats.sin_evaluar} icon={ClipboardCheck} danger={stats.sin_evaluar > 0} />
                    <StatCard label="Conductores aportados" value={stats.conductores ?? 0} icon={Users} />
                    <StatCard label="Vehículos aportados" value={stats.vehiculos ?? 0} icon={Car} />
                </div>

                <Card>
                    <CardContent className="p-0">
                        {contratistas.length === 0 ? (
                            <p className="text-muted-foreground p-8 text-center text-sm">
                                Sin contratistas registrados. La norma pide la lista de contratistas, subcontratistas y terceros —incluidos
                                conductores y propietarios de vehículos— que inciden en el PESV.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="p-3 font-medium">Contratista</th>
                                            <th className="p-3 font-medium">Tipo</th>
                                            <th className="p-3 font-medium">Aporta</th>
                                            <th className="p-3 font-medium">PESV propio</th>
                                            <th className="p-3 font-medium">Evaluación</th>
                                            {canManage && <th className="p-3" />}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {contratistas.map((c) => (
                                            <tr key={c.id} className={cn(!c.is_active && 'opacity-50')}>
                                                <td className="p-3">
                                                    <div className="font-medium">{c.nombre}</div>
                                                    <div className="text-muted-foreground text-xs">
                                                        {c.nit ? `NIT ${c.nit}` : ''}
                                                        {c.actividad ? ` · ${c.actividad}` : ''}
                                                    </div>
                                                </td>
                                                <td className="p-3 capitalize">{c.tipo.replace('_', ' ')}</td>
                                                <td className="p-3">
                                                    {c.num_conductores ?? 0} cond. / {c.num_vehiculos ?? 0} veh.
                                                </td>
                                                <td className="p-3">
                                                    {c.tiene_pesv ? (
                                                        <Badge variant="secondary" className="bg-emerald-600/15 text-emerald-700">
                                                            Sí
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-muted-foreground">No</span>
                                                    )}
                                                </td>
                                                <td className="p-3">
                                                    {c.evaluado_at ? (
                                                        <>
                                                            {c.evaluado_at}
                                                            {c.calificacion !== null && (
                                                                <span className="text-muted-foreground"> · {c.calificacion}/100</span>
                                                            )}
                                                        </>
                                                    ) : (
                                                        <span className="text-amber-700">Pendiente</span>
                                                    )}
                                                </td>
                                                {canManage && (
                                                    <td className="p-3 text-right whitespace-nowrap">
                                                        <Button variant="ghost" size="icon" onClick={() => abrirEdicion(c)}>
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() => router.delete(`/pesv/contratistas/${c.id}`, { preserveScroll: true })}
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
                            <DialogTitle>{editando ? editando.nombre : 'Nuevo contratista'}</DialogTitle>
                        </DialogHeader>

                        <div className="grid gap-4 py-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="nombre">Nombre o razón social</Label>
                                <Input id="nombre" value={data.nombre} onChange={(e) => setData('nombre', e.target.value)} />
                                <InputError message={errors.nombre} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="nit">NIT</Label>
                                <Input id="nit" value={data.nit} onChange={(e) => setData('nit', e.target.value)} />
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
                                <Label htmlFor="actividad">Actividad</Label>
                                <Input id="actividad" value={data.actividad} onChange={(e) => setData('actividad', e.target.value)} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="contacto_nombre">Contacto</Label>
                                <Input
                                    id="contacto_nombre"
                                    value={data.contacto_nombre}
                                    onChange={(e) => setData('contacto_nombre', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="contacto_telefono">Teléfono</Label>
                                <Input
                                    id="contacto_telefono"
                                    value={data.contacto_telefono}
                                    onChange={(e) => setData('contacto_telefono', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="contacto_email">Correo</Label>
                                <Input
                                    id="contacto_email"
                                    type="email"
                                    value={data.contacto_email}
                                    onChange={(e) => setData('contacto_email', e.target.value)}
                                />
                                <InputError message={errors.contacto_email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="num_conductores">Conductores que aporta</Label>
                                <Input
                                    id="num_conductores"
                                    type="number"
                                    value={data.num_conductores}
                                    onChange={(e) => setData('num_conductores', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="num_vehiculos">Vehículos que aporta</Label>
                                <Input
                                    id="num_vehiculos"
                                    type="number"
                                    value={data.num_vehiculos}
                                    onChange={(e) => setData('num_vehiculos', e.target.value)}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="evaluado_at">Fecha de evaluación</Label>
                                <Input
                                    id="evaluado_at"
                                    type="date"
                                    value={data.evaluado_at}
                                    onChange={(e) => setData('evaluado_at', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="calificacion">Calificación (0-100)</Label>
                                <Input
                                    id="calificacion"
                                    type="number"
                                    value={data.calificacion}
                                    onChange={(e) => setData('calificacion', e.target.value)}
                                />
                                <InputError message={errors.calificacion} />
                            </div>

                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={data.tiene_pesv} onCheckedChange={(v) => setData('tiene_pesv', v === true)} />
                                Tiene PESV propio
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', v === true)} />
                                Activo
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
