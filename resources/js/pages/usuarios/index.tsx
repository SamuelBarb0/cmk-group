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
import { AlertCircle, Building2, CheckCircle2, Pencil, Plus, ShieldCheck, Trash2, Users } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface UserRow {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    role: string | null;
    role_label: string;
    tenant_id: number | null;
    tenant: string | null;
}
interface RoleOption { value: string; label: string; scope: 'cmk' | 'client' }
interface TenantOption { id: number; name: string }

interface Props {
    users: UserRow[];
    roles: RoleOption[];
    tenants: TenantOption[];
    stats: { total: number; cmk: number; client: number };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Usuarios', href: '/usuarios' },
];

const emptyForm = {
    name: '',
    email: '',
    password: '',
    role: 'consultor_operativo',
    tenant_id: '' as number | '' | string,
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

export default function UsuariosIndex({ users, roles, tenants, stats }: Props) {
    const { can } = usePermissions();
    const canManage = can('users.manage');
    const page = usePage<SharedData>();
    const flash = page.props.flash;
    const authId = (page.props.auth as unknown as { user?: { id?: number } })?.user?.id;

    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<UserRow | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [problem, setProblem] = useState<string | null>(null);

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({ ...emptyForm });

    const scopeOf = (roleValue: string) => roles.find((r) => r.value === roleValue)?.scope ?? 'cmk';
    const needsTenant = scopeOf(data.role) === 'client';

    useEffect(() => {
        if (flash?.success) {
            setNotice(flash.success);
            const t = setTimeout(() => setNotice(null), 3500);
            return () => clearTimeout(t);
        }
    }, [flash?.success]);
    useEffect(() => {
        if (flash?.error) {
            setProblem(flash.error);
            const t = setTimeout(() => setProblem(null), 4500);
            return () => clearTimeout(t);
        }
    }, [flash?.error]);

    function openCreate() {
        setEditing(null);
        clearErrors();
        reset();
        setData({ ...emptyForm });
        setOpen(true);
    }

    function openEdit(u: UserRow) {
        setEditing(u);
        clearErrors();
        setData({
            name: u.name,
            email: u.email,
            password: '',
            role: u.role ?? 'consultor_operativo',
            tenant_id: u.tenant_id ?? '',
            is_active: u.is_active,
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
            put(route('usuarios.update', editing.id), { preserveScroll: true, onSuccess });
        } else {
            post(route('usuarios.store'), { preserveScroll: true, onSuccess });
        }
    };

    function destroy(u: UserRow) {
        if (confirm(`¿Eliminar al usuario "${u.name}"? Esta acción no se puede deshacer.`)) {
            router.delete(route('usuarios.destroy', u.id), { preserveScroll: true });
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Usuarios" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Encabezado */}
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Usuarios</h1>
                        <p className="text-muted-foreground text-sm">Equipo de CMK y usuarios de las empresas cliente.</p>
                    </div>
                    {canManage && (
                        <Button onClick={openCreate} className="gap-2">
                            <Plus className="size-4" /> Nuevo usuario
                        </Button>
                    )}
                </div>

                {/* Flash */}
                {notice && (
                    <div className="flex items-center gap-2 rounded-lg border border-green-600/30 bg-green-600/10 px-4 py-2.5 text-sm text-green-700 dark:text-green-400">
                        <CheckCircle2 className="size-4" /> {notice}
                    </div>
                )}
                {problem && (
                    <div className="text-destructive flex items-center gap-2 rounded-lg border border-red-600/30 bg-red-600/10 px-4 py-2.5 text-sm">
                        <AlertCircle className="size-4" /> {problem}
                    </div>
                )}

                {/* Métricas */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard label="Usuarios" value={stats.total} icon={Users} />
                    <StatCard label="Equipo CMK" value={stats.cmk} icon={ShieldCheck} />
                    <StatCard label="Usuarios cliente" value={stats.client} icon={Building2} />
                </div>

                {/* Tabla */}
                <Card className="overflow-hidden">
                    {users.length === 0 ? (
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <Users className="size-7" />
                            </div>
                            <p className="font-medium">Aún no hay usuarios registrados</p>
                            {canManage && (
                                <Button variant="outline" onClick={openCreate} className="gap-2">
                                    <Plus className="size-4" /> Crear el primero
                                </Button>
                            )}
                        </CardContent>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground border-b text-left">
                                    <tr>
                                        <th className="px-5 py-3 font-medium">Usuario</th>
                                        <th className="px-5 py-3 font-medium">Rol</th>
                                        <th className="px-5 py-3 font-medium">Cliente</th>
                                        <th className="px-5 py-3 text-center font-medium">Estado</th>
                                        {canManage && <th className="px-5 py-3 text-right font-medium">Acciones</th>}
                                    </tr>
                                </thead>
                                <tbody className="divide-border divide-y">
                                    {users.map((u) => (
                                        <tr key={u.id} className="hover:bg-muted/40 transition-colors">
                                            <td className="px-5 py-3">
                                                <div className="font-medium">{u.name}</div>
                                                <div className="text-muted-foreground text-xs">{u.email}</div>
                                            </td>
                                            <td className="px-5 py-3">
                                                <Badge variant="outline">{u.role_label}</Badge>
                                            </td>
                                            <td className="text-muted-foreground px-5 py-3">
                                                {u.tenant ?? <span className="text-xs italic">Equipo CMK</span>}
                                            </td>
                                            <td className="px-5 py-3 text-center">
                                                <Badge variant={u.is_active ? 'default' : 'secondary'}>
                                                    {u.is_active ? 'Activo' : 'Inactivo'}
                                                </Badge>
                                            </td>
                                            {canManage && (
                                                <td className="px-5 py-3">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Button variant="ghost" size="icon" onClick={() => openEdit(u)} aria-label="Editar">
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        {u.id !== authId && (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                onClick={() => destroy(u)}
                                                                aria-label="Eliminar"
                                                                className="text-destructive hover:text-destructive"
                                                            >
                                                                <Trash2 className="size-4" />
                                                            </Button>
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
                        <DialogTitle className="font-brand">{editing ? 'Editar usuario' : 'Nuevo usuario'}</DialogTitle>
                        <DialogDescription>
                            {editing ? 'Actualiza los datos y el rol del usuario.' : 'Crea un usuario del equipo CMK o de una empresa cliente.'}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Nombre completo *</Label>
                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required autoFocus />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Correo *</Label>
                            <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} required />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">Contraseña {editing ? '(dejar en blanco para no cambiar)' : '*'}</Label>
                            <Input
                                id="password"
                                type="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder={editing ? '••••••••' : 'Mínimo 8 caracteres'}
                                autoComplete="new-password"
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="role">Rol *</Label>
                                <select
                                    id="role"
                                    value={data.role}
                                    onChange={(e) => setData('role', e.target.value)}
                                    className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                >
                                    {roles.map((r) => (
                                        <option key={r.value} value={r.value}>
                                            {r.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.role} />
                            </div>

                            {needsTenant && (
                                <div className="grid gap-2">
                                    <Label htmlFor="tenant_id">Cliente *</Label>
                                    <select
                                        id="tenant_id"
                                        value={data.tenant_id === null ? '' : String(data.tenant_id)}
                                        onChange={(e) => setData('tenant_id', e.target.value)}
                                        className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                                    >
                                        <option value="">— Seleccionar —</option>
                                        {tenants.map((t) => (
                                            <option key={t.id} value={t.id}>
                                                {t.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.tenant_id} />
                                </div>
                            )}
                        </div>
                        {needsTenant && tenants.length === 0 && (
                            <p className="text-muted-foreground text-xs">
                                No hay clientes registrados. Crea uno en <span className="font-medium">Clientes</span> antes de asignar este rol.
                            </p>
                        )}

                        <label className="flex cursor-pointer items-center gap-3 pt-1">
                            <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', v === true)} />
                            <span className="text-sm">Usuario activo</span>
                        </label>

                        <DialogFooter className="gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {editing ? 'Guardar cambios' : 'Crear usuario'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
