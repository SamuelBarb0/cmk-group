import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Building2, CheckCircle2, Pencil, Plus, Trash2, Users } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Client {
    id: number;
    name: string;
    legal_name: string | null;
    nit: string | null;
    email: string | null;
    phone: string | null;
    city: string | null;
    address: string | null;
    is_active: boolean;
    users_count: number;
}

interface Props {
    clients: Client[];
    stats: { total: number; active: number; users: number };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Clientes', href: '/clientes' },
];

const emptyForm = {
    name: '',
    legal_name: '',
    nit: '',
    email: '',
    phone: '',
    city: '',
    address: '',
    is_active: true as boolean,
};

function StatCard({ label, value, icon: Icon }: { label: string; value: number; icon: React.ElementType }) {
    return (
        <Card>
            <CardContent className="flex items-center gap-4 p-5">
                <div className="bg-primary/10 text-primary flex size-11 items-center justify-center rounded-lg">
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

export default function ClientesIndex({ clients, stats }: Props) {
    const { can } = usePermissions();
    const canManage = can('clients.manage');
    const page = usePage<SharedData>();
    const flash = page.props.flash;
    const isCmk = Boolean((page.props.auth as { is_cmk?: boolean })?.is_cmk);
    const activeTenant = (page.props as unknown as { tenant: { id: number; name: string } | null }).tenant;

    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Client | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({ ...emptyForm });

    // Muestra la confirmación flash del backend unos segundos.
    useEffect(() => {
        if (flash?.success) {
            setNotice(flash.success);
            const t = setTimeout(() => setNotice(null), 3500);
            return () => clearTimeout(t);
        }
    }, [flash?.success]);

    function openCreate() {
        setEditing(null);
        clearErrors();
        reset();
        setData({ ...emptyForm });
        setOpen(true);
    }

    function openEdit(client: Client) {
        setEditing(client);
        clearErrors();
        setData({
            name: client.name,
            legal_name: client.legal_name ?? '',
            nit: client.nit ?? '',
            email: client.email ?? '',
            phone: client.phone ?? '',
            city: client.city ?? '',
            address: client.address ?? '',
            is_active: client.is_active,
        });
        setOpen(true);
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const onSuccess = () => {
            setOpen(false);
            reset();
        };
        if (editing) {
            put(route('clientes.update', editing.id), { preserveScroll: true, onSuccess });
        } else {
            post(route('clientes.store'), { preserveScroll: true, onSuccess });
        }
    };

    function destroy(client: Client) {
        if (confirm(`¿Eliminar el cliente "${client.name}"? Esta acción no se puede deshacer.`)) {
            router.delete(route('clientes.destroy', client.id), { preserveScroll: true });
        }
    }

    // Selecciona / limpia el cliente activo con el que trabaja el consultor.
    function selectClient(client: Client) {
        router.post(route('clientes.select', client.id), {}, { preserveScroll: true });
    }
    function clearClient() {
        router.post(route('clientes.clear'), {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Clientes" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Encabezado */}
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Clientes</h1>
                        <p className="text-muted-foreground text-sm">Empresas cliente gestionadas por CMK GROUP.</p>
                    </div>
                    {canManage && (
                        <Button onClick={openCreate} className="gap-2">
                            <Plus className="size-4" /> Nuevo cliente
                        </Button>
                    )}
                </div>

                {/* Cliente activo (consultor CMK) */}
                {isCmk && activeTenant && (
                    <div className="border-primary/30 bg-primary/5 flex flex-wrap items-center justify-between gap-2 rounded-lg border px-4 py-2.5 text-sm">
                        <span className="flex items-center gap-2">
                            <Building2 className="text-primary size-4" />
                            Trabajando con <span className="font-semibold">{activeTenant.name}</span>
                        </span>
                        <Button variant="outline" size="sm" onClick={clearClient}>
                            Salir a vista consolidada
                        </Button>
                    </div>
                )}

                {/* Confirmación flash */}
                {notice && (
                    <div className="flex items-center gap-2 rounded-lg border border-green-600/30 bg-green-600/10 px-4 py-2.5 text-sm text-green-700 dark:text-green-400">
                        <CheckCircle2 className="size-4" /> {notice}
                    </div>
                )}

                {/* Métricas */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard label="Clientes" value={stats.total} icon={Building2} />
                    <StatCard label="Clientes activos" value={stats.active} icon={CheckCircle2} />
                    <StatCard label="Usuarios cliente" value={stats.users} icon={Users} />
                </div>

                {/* Tabla / listado */}
                <Card className="overflow-hidden">
                    {clients.length === 0 ? (
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <Building2 className="size-7" />
                            </div>
                            <p className="font-medium">Aún no hay clientes registrados</p>
                            {canManage && (
                                <Button variant="outline" onClick={openCreate} className="gap-2">
                                    <Plus className="size-4" /> Registrar el primero
                                </Button>
                            )}
                        </CardContent>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground border-b text-left">
                                    <tr>
                                        <th className="px-5 py-3 font-medium">Empresa</th>
                                        <th className="px-5 py-3 font-medium">NIT</th>
                                        <th className="px-5 py-3 font-medium">Ciudad</th>
                                        <th className="px-5 py-3 font-medium">Contacto</th>
                                        <th className="px-5 py-3 text-center font-medium">Usuarios</th>
                                        <th className="px-5 py-3 text-center font-medium">Estado</th>
                                        {(canManage || isCmk) && <th className="px-5 py-3 text-right font-medium">Acciones</th>}
                                    </tr>
                                </thead>
                                <tbody className="divide-border divide-y">
                                    {clients.map((c) => (
                                        <tr key={c.id} className="hover:bg-muted/40 transition-colors">
                                            <td className="px-5 py-3">
                                                <div className="font-medium">{c.name}</div>
                                                {c.legal_name && <div className="text-muted-foreground text-xs">{c.legal_name}</div>}
                                            </td>
                                            <td className="text-muted-foreground px-5 py-3">{c.nit ?? '—'}</td>
                                            <td className="text-muted-foreground px-5 py-3">{c.city ?? '—'}</td>
                                            <td className="text-muted-foreground px-5 py-3">
                                                <div>{c.email ?? '—'}</div>
                                                {c.phone && <div className="text-xs">{c.phone}</div>}
                                            </td>
                                            <td className="px-5 py-3 text-center tabular-nums">{c.users_count}</td>
                                            <td className="px-5 py-3 text-center">
                                                <Badge variant={c.is_active ? 'default' : 'secondary'}>
                                                    {c.is_active ? 'Activo' : 'Inactivo'}
                                                </Badge>
                                            </td>
                                            {(canManage || isCmk) && (
                                                <td className="px-5 py-3">
                                                    <div className="flex items-center justify-end gap-1">
                                                        {isCmk &&
                                                            (activeTenant?.id === c.id ? (
                                                                <Badge variant="outline" className="gap-1">
                                                                    <CheckCircle2 className="size-3.5" /> Activo
                                                                </Badge>
                                                            ) : (
                                                                <Button variant="outline" size="sm" onClick={() => selectClient(c)}>
                                                                    Trabajar
                                                                </Button>
                                                            ))}
                                                        {canManage && (
                                                            <>
                                                                <Button variant="ghost" size="icon" onClick={() => openEdit(c)} aria-label="Editar">
                                                                    <Pencil className="size-4" />
                                                                </Button>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    onClick={() => destroy(c)}
                                                                    aria-label="Eliminar"
                                                                    className="text-destructive hover:text-destructive"
                                                                >
                                                                    <Trash2 className="size-4" />
                                                                </Button>
                                                            </>
                                                        )}
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

            {/* Diálogo crear / editar */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="font-brand">{editing ? 'Editar cliente' : 'Nuevo cliente'}</DialogTitle>
                        <DialogDescription>
                            {editing ? 'Actualiza los datos de la empresa cliente.' : 'Registra una nueva empresa cliente gestionada por CMK.'}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Nombre comercial *</Label>
                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required autoFocus />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="legal_name">Razón social</Label>
                            <Input id="legal_name" value={data.legal_name} onChange={(e) => setData('legal_name', e.target.value)} />
                            <InputError message={errors.legal_name} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="nit">NIT</Label>
                                <Input id="nit" value={data.nit} onChange={(e) => setData('nit', e.target.value)} placeholder="900.123.456-7" />
                                <InputError message={errors.nit} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="city">Ciudad</Label>
                                <Input id="city" value={data.city} onChange={(e) => setData('city', e.target.value)} />
                                <InputError message={errors.city} />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Correo</Label>
                                <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                                <InputError message={errors.email} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="phone">Teléfono</Label>
                                <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                                <InputError message={errors.phone} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="address">Dirección</Label>
                            <Input id="address" value={data.address} onChange={(e) => setData('address', e.target.value)} />
                            <InputError message={errors.address} />
                        </div>

                        <label className="flex cursor-pointer items-center gap-3 pt-1">
                            <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', v === true)} />
                            <span className="text-sm">Cliente activo</span>
                        </label>

                        <DialogFooter className="gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {editing ? 'Guardar cambios' : 'Crear cliente'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
