import InputError from '@/components/input-error';
import { Notice, SinCliente, useNotice } from '@/components/pesv/shared';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Sede {
    id: number;
    nombre: string;
    direccion: string | null;
    ciudad: string | null;
    departamento: string | null;
    telefono: string | null;
    responsable: string | null;
    num_trabajadores: number | null;
    es_principal: boolean;
}

interface Props {
    needsClient: boolean;
    sedes: Sede[];
    sugerencias: string[];
    empresa: { direccion: string | null; ciudad: string | null; num_trabajadores: number | null } | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'PESV', href: '/pesv' },
    { title: 'Sedes', href: '/pesv/sedes' },
];

const vacio = {
    nombre: '',
    direccion: '',
    ciudad: '',
    departamento: '',
    telefono: '',
    responsable: '',
    num_trabajadores: '' as number | string,
    es_principal: false,
};

export default function PesvSedes({ needsClient, sedes, sugerencias, empresa }: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const page = usePage<SharedData>();
    const notice = useNotice(page.props.flash?.success);

    const [open, setOpen] = useState(false);
    const [editando, setEditando] = useState<Sede | null>(null);
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({ ...vacio });

    if (needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Sedes PESV" />
                <SinCliente titulo="Sedes" descripcion="Centros de trabajo de la empresa (PESV, Paso 5)." />
            </AppLayout>
        );
    }

    function abrirNuevo(nombre = '') {
        setEditando(null);
        clearErrors();
        setData({
            ...vacio,
            nombre,
            // La primera sede se propone con la dirección que ya está en
            // Organización: es el dato que la empresa ya dio.
            direccion: sedes.length === 0 ? (empresa?.direccion ?? '') : '',
            ciudad: sedes.length === 0 ? (empresa?.ciudad ?? '') : '',
            es_principal: sedes.length === 0,
        });
        setOpen(true);
    }

    function abrirEdicion(s: Sede) {
        setEditando(s);
        clearErrors();
        setData({
            nombre: s.nombre,
            direccion: s.direccion ?? '',
            ciudad: s.ciudad ?? '',
            departamento: s.departamento ?? '',
            telefono: s.telefono ?? '',
            responsable: s.responsable ?? '',
            num_trabajadores: s.num_trabajadores ?? '',
            es_principal: s.es_principal,
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
            put(`/pesv/sedes/${editando.id}`, { preserveScroll: true, onSuccess: ok });
        } else {
            post('/pesv/sedes', { preserveScroll: true, onSuccess: ok });
        }
    };

    // Sedes escritas en la ficha de los empleados que todavía no están aquí.
    const pendientes = sugerencias.filter((s) => !sedes.some((sede) => sede.nombre.toLowerCase() === s.toLowerCase()));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sedes PESV" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Sedes</h1>
                        <p className="text-muted-foreground text-sm">Centros de trabajo · caracterización del Paso 5 del PESV.</p>
                    </div>
                    {canManage && (
                        <Button onClick={() => abrirNuevo()} className="gap-2">
                            <Plus className="size-4" /> Nueva sede
                        </Button>
                    )}
                </div>

                <Notice mensaje={notice} />

                {canManage && pendientes.length > 0 && (
                    <Card>
                        <CardContent className="space-y-2 p-4">
                            <p className="text-sm font-medium">Sedes que ya aparecen en la ficha de los empleados</p>
                            <p className="text-muted-foreground text-sm">
                                Están escritas en Empleados pero no registradas aquí. Un clic las agrega.
                            </p>
                            <div className="flex flex-wrap gap-2 pt-1">
                                {pendientes.map((s) => (
                                    <Button key={s} variant="outline" size="sm" className="gap-1" onClick={() => abrirNuevo(s)}>
                                        <Plus className="size-3" /> {s}
                                    </Button>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardContent className="p-0">
                        {sedes.length === 0 ? (
                            <p className="text-muted-foreground p-8 text-center text-sm">
                                Sin sedes registradas. La empresa figura en {empresa?.ciudad ?? 'su ciudad'}
                                {empresa?.direccion ? ` (${empresa.direccion})` : ''}.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="p-3 font-medium">Sede</th>
                                            <th className="p-3 font-medium">Ubicación</th>
                                            <th className="p-3 font-medium">Responsable</th>
                                            <th className="p-3 font-medium">Trabajadores</th>
                                            {canManage && <th className="p-3" />}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {sedes.map((s) => (
                                            <tr key={s.id}>
                                                <td className="p-3">
                                                    <span className="font-medium">{s.nombre}</span>
                                                    {s.es_principal && (
                                                        <Badge variant="secondary" className="ml-2">
                                                            Principal
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="p-3">
                                                    {[s.direccion, s.ciudad, s.departamento].filter(Boolean).join(', ') || '—'}
                                                </td>
                                                <td className="p-3">{s.responsable ?? '—'}</td>
                                                <td className="p-3 tabular-nums">{s.num_trabajadores ?? '—'}</td>
                                                {canManage && (
                                                    <td className="p-3 text-right whitespace-nowrap">
                                                        <Button variant="ghost" size="icon" onClick={() => abrirEdicion(s)}>
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() => router.delete(`/pesv/sedes/${s.id}`, { preserveScroll: true })}
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
                <DialogContent className="sm:max-w-lg">
                    <form onSubmit={enviar}>
                        <DialogHeader>
                            <DialogTitle>{editando ? `Sede ${editando.nombre}` : 'Nueva sede'}</DialogTitle>
                        </DialogHeader>

                        <div className="grid gap-4 py-4">
                            <div className="grid gap-2">
                                <Label htmlFor="nombre">Nombre</Label>
                                <Input id="nombre" value={data.nombre} onChange={(e) => setData('nombre', e.target.value)} />
                                <InputError message={errors.nombre} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="direccion">Dirección</Label>
                                <Input id="direccion" value={data.direccion} onChange={(e) => setData('direccion', e.target.value)} />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="ciudad">Ciudad</Label>
                                    <Input id="ciudad" value={data.ciudad} onChange={(e) => setData('ciudad', e.target.value)} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="departamento">Departamento</Label>
                                    <Input
                                        id="departamento"
                                        value={data.departamento}
                                        onChange={(e) => setData('departamento', e.target.value)}
                                    />
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="responsable">Responsable</Label>
                                    <Input
                                        id="responsable"
                                        value={data.responsable}
                                        onChange={(e) => setData('responsable', e.target.value)}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="telefono">Teléfono</Label>
                                    <Input id="telefono" value={data.telefono} onChange={(e) => setData('telefono', e.target.value)} />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="num_trabajadores">Trabajadores en la sede</Label>
                                <Input
                                    id="num_trabajadores"
                                    type="number"
                                    value={data.num_trabajadores}
                                    onChange={(e) => setData('num_trabajadores', e.target.value)}
                                />
                            </div>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={data.es_principal} onCheckedChange={(v) => setData('es_principal', v === true)} />
                                Es la sede principal
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
