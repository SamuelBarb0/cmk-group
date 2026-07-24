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
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Briefcase, Building2, CheckCircle2, IdCard, Pencil, Plus, Trash2, UserRound, Users } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Employee {
    id: number;
    nombres: string;
    apellidos: string;
    tipo_documento: string;
    numero_documento: string;
    fecha_nacimiento: string | null;
    genero: string | null;
    grupo_sanguineo: string | null;
    telefono: string | null;
    email: string | null;
    direccion: string | null;
    ciudad: string | null;
    cargo: string | null;
    area: string | null;
    sede: string | null;
    fecha_ingreso: string | null;
    tipo_contrato: string | null;
    salario: string | null;
    eps: string | null;
    afp: string | null;
    arl: string | null;
    nivel_riesgo: string | null;
    is_active: boolean;
}

interface Props {
    employees: Employee[];
    stats: { total: number; active: number; areas: number };
    needsClient: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Empleados', href: '/empleados' },
];

const emptyForm = {
    nombres: '',
    apellidos: '',
    tipo_documento: 'CC',
    numero_documento: '',
    fecha_nacimiento: '',
    genero: '',
    grupo_sanguineo: '',
    telefono: '',
    email: '',
    direccion: '',
    ciudad: '',
    cargo: '',
    area: '',
    sede: '',
    fecha_ingreso: '',
    tipo_contrato: '',
    salario: '',
    eps: '',
    afp: '',
    arl: '',
    nivel_riesgo: '',
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

export default function EmpleadosIndex({ employees, stats, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const page = usePage<SharedData>();
    const flash = page.props.flash;
    const tenant = page.props.tenant as { id: number; name: string } | null;

    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Employee | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({ ...emptyForm });

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

    function openEdit(emp: Employee) {
        setEditing(emp);
        clearErrors();
        setData({
            nombres: emp.nombres,
            apellidos: emp.apellidos,
            tipo_documento: emp.tipo_documento,
            numero_documento: emp.numero_documento,
            fecha_nacimiento: emp.fecha_nacimiento ?? '',
            genero: emp.genero ?? '',
            grupo_sanguineo: emp.grupo_sanguineo ?? '',
            telefono: emp.telefono ?? '',
            email: emp.email ?? '',
            direccion: emp.direccion ?? '',
            ciudad: emp.ciudad ?? '',
            cargo: emp.cargo ?? '',
            area: emp.area ?? '',
            sede: emp.sede ?? '',
            fecha_ingreso: emp.fecha_ingreso ?? '',
            tipo_contrato: emp.tipo_contrato ?? '',
            salario: emp.salario ?? '',
            eps: emp.eps ?? '',
            afp: emp.afp ?? '',
            arl: emp.arl ?? '',
            nivel_riesgo: emp.nivel_riesgo ?? '',
            is_active: emp.is_active,
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
            put(route('empleados.update', editing.id), { preserveScroll: true, onSuccess });
        } else {
            post(route('empleados.store'), { preserveScroll: true, onSuccess });
        }
    };

    function destroy(emp: Employee) {
        if (confirm(`¿Eliminar a "${emp.nombres} ${emp.apellidos}"? Esta acción no se puede deshacer.`)) {
            router.delete(route('empleados.destroy', emp.id), { preserveScroll: true });
        }
    }

    // El consultor CMK aún no ha seleccionado un cliente.
    if (needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Empleados" />
                <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Empleados</h1>
                        <p className="text-muted-foreground text-sm">Nómina base del SGI de cada cliente.</p>
                    </div>
                    <Card>
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <Building2 className="size-7" />
                            </div>
                            <p className="font-medium">Selecciona un cliente para gestionar su nómina</p>
                            <p className="text-muted-foreground max-w-sm text-sm">
                                Los empleados pertenecen a una empresa cliente. Entra a un cliente para ver y registrar sus trabajadores.
                            </p>
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
            <Head title="Empleados" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Encabezado */}
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Empleados</h1>
                        <p className="text-muted-foreground text-sm">
                            {tenant ? (
                                <>
                                    Nómina de <span className="font-medium">{tenant.name}</span>.
                                </>
                            ) : (
                                'Nómina base del SGI.'
                            )}
                        </p>
                    </div>
                    {canManage && (
                        <Button onClick={openCreate} className="gap-2">
                            <Plus className="size-4" /> Nuevo empleado
                        </Button>
                    )}
                </div>

                {/* Confirmación flash */}
                {notice && (
                    <div className="flex items-center gap-2 rounded-lg border border-green-600/30 bg-green-600/10 px-4 py-2.5 text-sm text-green-700 dark:text-green-400">
                        <CheckCircle2 className="size-4" /> {notice}
                    </div>
                )}

                {/* Métricas */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard label="Empleados" value={stats.total} icon={Users} />
                    <StatCard label="Activos" value={stats.active} icon={CheckCircle2} />
                    <StatCard label="Áreas" value={stats.areas} icon={Briefcase} />
                </div>

                {/* Tabla / listado */}
                <Card className="overflow-hidden">
                    {employees.length === 0 ? (
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <UserRound className="size-7" />
                            </div>
                            <p className="font-medium">Aún no hay empleados registrados</p>
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
                                        <th className="px-5 py-3 font-medium">Empleado</th>
                                        <th className="px-5 py-3 font-medium">Documento</th>
                                        <th className="px-5 py-3 font-medium">Cargo / Área</th>
                                        <th className="px-5 py-3 font-medium">Ingreso</th>
                                        <th className="px-5 py-3 text-center font-medium">Estado</th>
                                        {canManage && <th className="px-5 py-3 text-right font-medium">Acciones</th>}
                                    </tr>
                                </thead>
                                <tbody className="divide-border divide-y">
                                    {employees.map((e) => (
                                        <tr key={e.id} className="hover:bg-muted/40 transition-colors">
                                            <td className="px-5 py-3">
                                                <div className="font-medium">
                                                    {e.nombres} {e.apellidos}
                                                </div>
                                                {e.email && <div className="text-muted-foreground text-xs">{e.email}</div>}
                                            </td>
                                            <td className="text-muted-foreground px-5 py-3">
                                                {e.tipo_documento} {e.numero_documento}
                                            </td>
                                            <td className="text-muted-foreground px-5 py-3">
                                                <div>{e.cargo ?? '—'}</div>
                                                {e.area && <div className="text-xs">{e.area}</div>}
                                            </td>
                                            <td className="text-muted-foreground px-5 py-3">{e.fecha_ingreso ?? '—'}</td>
                                            <td className="px-5 py-3 text-center">
                                                <Badge variant={e.is_active ? 'default' : 'secondary'}>{e.is_active ? 'Activo' : 'Retirado'}</Badge>
                                            </td>
                                            {canManage && (
                                                <td className="px-5 py-3">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Button variant="ghost" size="icon" onClick={() => openEdit(e)} aria-label="Editar">
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() => destroy(e)}
                                                            aria-label="Eliminar"
                                                            className="text-destructive hover:text-destructive"
                                                        >
                                                            <Trash2 className="size-4" />
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

            {/* Diálogo crear / editar */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle className="font-brand">{editing ? 'Editar empleado' : 'Nuevo empleado'}</DialogTitle>
                        <DialogDescription>
                            {editing ? 'Actualiza los datos del trabajador.' : 'Registra un trabajador de la empresa cliente.'}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-5">
                        {/* Identificación */}
                        <div className="space-y-3">
                            <div className="text-muted-foreground flex items-center gap-2 text-xs font-semibold tracking-wide uppercase">
                                <IdCard className="size-4" /> Identificación
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="nombres">Nombres *</Label>
                                    <Input
                                        id="nombres"
                                        value={data.nombres}
                                        onChange={(ev) => setData('nombres', ev.target.value)}
                                        required
                                        autoFocus
                                    />
                                    <InputError message={errors.nombres} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="apellidos">Apellidos *</Label>
                                    <Input id="apellidos" value={data.apellidos} onChange={(ev) => setData('apellidos', ev.target.value)} required />
                                    <InputError message={errors.apellidos} />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="tipo_documento">Tipo doc. *</Label>
                                    <select
                                        id="tipo_documento"
                                        value={data.tipo_documento}
                                        onChange={(ev) => setData('tipo_documento', ev.target.value)}
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    >
                                        <option value="CC">CC</option>
                                        <option value="CE">CE</option>
                                        <option value="TI">TI</option>
                                        <option value="PA">Pasaporte</option>
                                        <option value="PEP">PEP</option>
                                    </select>
                                    <InputError message={errors.tipo_documento} />
                                </div>
                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="numero_documento">Número de documento *</Label>
                                    <Input
                                        id="numero_documento"
                                        value={data.numero_documento}
                                        onChange={(ev) => setData('numero_documento', ev.target.value)}
                                        required
                                    />
                                    <InputError message={errors.numero_documento} />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="fecha_nacimiento">Fecha nacimiento</Label>
                                    <Input
                                        id="fecha_nacimiento"
                                        type="date"
                                        value={data.fecha_nacimiento}
                                        onChange={(ev) => setData('fecha_nacimiento', ev.target.value)}
                                    />
                                    <InputError message={errors.fecha_nacimiento} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="genero">Género</Label>
                                    <Input
                                        id="genero"
                                        value={data.genero}
                                        onChange={(ev) => setData('genero', ev.target.value)}
                                        placeholder="Masculino / Femenino"
                                    />
                                    <InputError message={errors.genero} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="grupo_sanguineo">Grupo sanguíneo (RH)</Label>
                                    <Input
                                        id="grupo_sanguineo"
                                        value={data.grupo_sanguineo}
                                        onChange={(ev) => setData('grupo_sanguineo', ev.target.value)}
                                        placeholder="O+"
                                    />
                                    <InputError message={errors.grupo_sanguineo} />
                                </div>
                            </div>
                        </div>

                        {/* Contacto */}
                        <div className="space-y-3">
                            <div className="text-muted-foreground flex items-center gap-2 text-xs font-semibold tracking-wide uppercase">
                                <UserRound className="size-4" /> Contacto
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="telefono">Teléfono</Label>
                                    <Input id="telefono" value={data.telefono} onChange={(ev) => setData('telefono', ev.target.value)} />
                                    <InputError message={errors.telefono} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="email">Correo</Label>
                                    <Input id="email" type="email" value={data.email} onChange={(ev) => setData('email', ev.target.value)} />
                                    <InputError message={errors.email} />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="direccion">Dirección</Label>
                                    <Input id="direccion" value={data.direccion} onChange={(ev) => setData('direccion', ev.target.value)} />
                                    <InputError message={errors.direccion} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="ciudad">Ciudad</Label>
                                    <Input id="ciudad" value={data.ciudad} onChange={(ev) => setData('ciudad', ev.target.value)} />
                                    <InputError message={errors.ciudad} />
                                </div>
                            </div>
                        </div>

                        {/* Datos laborales */}
                        <div className="space-y-3">
                            <div className="text-muted-foreground flex items-center gap-2 text-xs font-semibold tracking-wide uppercase">
                                <Briefcase className="size-4" /> Datos laborales
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="cargo">Cargo</Label>
                                    <Input id="cargo" value={data.cargo} onChange={(ev) => setData('cargo', ev.target.value)} />
                                    <InputError message={errors.cargo} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="area">Área</Label>
                                    <Input id="area" value={data.area} onChange={(ev) => setData('area', ev.target.value)} />
                                    <InputError message={errors.area} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="sede">Sede</Label>
                                    <Input id="sede" value={data.sede} onChange={(ev) => setData('sede', ev.target.value)} />
                                    <InputError message={errors.sede} />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="fecha_ingreso">Fecha ingreso</Label>
                                    <Input
                                        id="fecha_ingreso"
                                        type="date"
                                        value={data.fecha_ingreso}
                                        onChange={(ev) => setData('fecha_ingreso', ev.target.value)}
                                    />
                                    <InputError message={errors.fecha_ingreso} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="tipo_contrato">Tipo de contrato</Label>
                                    <Input
                                        id="tipo_contrato"
                                        value={data.tipo_contrato}
                                        onChange={(ev) => setData('tipo_contrato', ev.target.value)}
                                        placeholder="Indefinido / Fijo / Obra"
                                    />
                                    <InputError message={errors.tipo_contrato} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="salario">Salario</Label>
                                    <Input
                                        id="salario"
                                        type="number"
                                        min="0"
                                        step="1000"
                                        value={data.salario}
                                        onChange={(ev) => setData('salario', ev.target.value)}
                                    />
                                    <InputError message={errors.salario} />
                                </div>
                            </div>
                        </div>

                        {/* Seguridad social */}
                        <div className="space-y-3">
                            <div className="text-muted-foreground flex items-center gap-2 text-xs font-semibold tracking-wide uppercase">
                                <Building2 className="size-4" /> Seguridad social
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="eps">EPS</Label>
                                    <Input id="eps" value={data.eps} onChange={(ev) => setData('eps', ev.target.value)} />
                                    <InputError message={errors.eps} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="afp">Fondo de pensiones (AFP)</Label>
                                    <Input id="afp" value={data.afp} onChange={(ev) => setData('afp', ev.target.value)} />
                                    <InputError message={errors.afp} />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="arl">ARL</Label>
                                    <Input id="arl" value={data.arl} onChange={(ev) => setData('arl', ev.target.value)} />
                                    <InputError message={errors.arl} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="nivel_riesgo">Nivel de riesgo (I–V)</Label>
                                    <Input
                                        id="nivel_riesgo"
                                        value={data.nivel_riesgo}
                                        onChange={(ev) => setData('nivel_riesgo', ev.target.value)}
                                        placeholder="Ej: III"
                                    />
                                    <InputError message={errors.nivel_riesgo} />
                                </div>
                            </div>
                        </div>

                        <label className="flex cursor-pointer items-center gap-3 pt-1">
                            <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', v === true)} />
                            <span className="text-sm">Empleado activo</span>
                        </label>

                        <DialogFooter className="gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {editing ? 'Guardar cambios' : 'Registrar empleado'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
