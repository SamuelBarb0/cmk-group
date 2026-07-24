import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Building2, CheckCircle2, ShieldAlert, Users } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Organizacion {
    id: number;
    name: string;
    legal_name: string | null;
    nit: string | null;
    email: string | null;
    phone: string | null;
    city: string | null;
    address: string | null;
    actividad_economica: string | null;
    codigo_ciiu: string | null;
    sector: string | null;
    nivel_riesgo: string | null;
    arl: string | null;
    tamano_empresa: string | null;
    num_trabajadores: number | null;
    representante_legal: string | null;
    representante_cc: string | null;
    responsable_sgsst: string | null;
    licencia_sgsst: string | null;
    licencia_sgsst_vence: string | null;
    curso_sst_horas: string | null;
    curso_sst_fecha: string | null;
}

interface Props {
    organizacion: Organizacion | null;
    needsClient: boolean;
    empleadosCount: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Organización', href: '/organizacion' },
];

export default function OrganizacionIndex({ organizacion, needsClient, empleadosCount }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const flash = usePage<SharedData>().props.flash;
    const [notice, setNotice] = useState<string | null>(null);

    const { data, setData, put, processing, errors } = useForm({
        actividad_economica: organizacion?.actividad_economica ?? '',
        codigo_ciiu: organizacion?.codigo_ciiu ?? '',
        sector: organizacion?.sector ?? '',
        nivel_riesgo: organizacion?.nivel_riesgo ?? '',
        arl: organizacion?.arl ?? '',
        tamano_empresa: organizacion?.tamano_empresa ?? '',
        num_trabajadores: organizacion?.num_trabajadores?.toString() ?? '',
        representante_legal: organizacion?.representante_legal ?? '',
        representante_cc: organizacion?.representante_cc ?? '',
        responsable_sgsst: organizacion?.responsable_sgsst ?? '',
        licencia_sgsst: organizacion?.licencia_sgsst ?? '',
        licencia_sgsst_vence: organizacion?.licencia_sgsst_vence ?? '',
        curso_sst_horas: organizacion?.curso_sst_horas ?? '',
        curso_sst_fecha: organizacion?.curso_sst_fecha ?? '',
    });

    useEffect(() => {
        if (flash?.success) {
            setNotice(flash.success);
            const t = setTimeout(() => setNotice(null), 3500);
            return () => clearTimeout(t);
        }
    }, [flash?.success]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('organizacion.update'), { preserveScroll: true });
    };

    // El consultor CMK aún no ha seleccionado un cliente.
    if (needsClient || !organizacion) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Organización" />
                <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Información de la Organización</h1>
                        <p className="text-muted-foreground text-sm">Contexto SGI de la empresa cliente.</p>
                    </div>
                    <Card>
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <Building2 className="size-7" />
                            </div>
                            <p className="font-medium">Selecciona un cliente para ver su organización</p>
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

    const declarado = data.num_trabajadores ? parseInt(data.num_trabajadores, 10) : null;
    const descuadre = declarado !== null && declarado !== empleadosCount;

    // Alerta de licencia SST: vencida (rojo) o vence en ≤60 días (ámbar).
    const licenciaAlerta = (() => {
        if (!data.licencia_sgsst_vence) return null;
        const vence = new Date(`${data.licencia_sgsst_vence}T00:00:00`);
        const dias = Math.ceil((vence.getTime() - Date.now()) / 86_400_000);
        if (dias < 0) return { vencida: true, mensaje: `La licencia SST del responsable está VENCIDA desde hace ${-dias} días.` };
        if (dias <= 60) return { vencida: false, mensaje: `La licencia SST del responsable vence en ${dias} días.` };
        return null;
    })();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Organización" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Encabezado */}
                <div>
                    <h1 className="font-brand text-2xl font-bold tracking-tight">Información de la Organización</h1>
                    <p className="text-muted-foreground text-sm">
                        Contexto SGI de <span className="font-medium">{organizacion.name}</span>.
                    </p>
                </div>

                {/* Confirmación flash */}
                {notice && (
                    <div className="flex items-center gap-2 rounded-lg border border-green-600/30 bg-green-600/10 px-4 py-2.5 text-sm text-green-700 dark:text-green-400">
                        <CheckCircle2 className="size-4" /> {notice}
                    </div>
                )}

                {/* Datos base del cliente (solo lectura; se editan en Clientes) */}
                <Card>
                    <CardContent className="grid gap-4 p-5 sm:grid-cols-3">
                        <div>
                            <div className="text-muted-foreground text-xs">Razón social</div>
                            <div className="font-medium">{organizacion.legal_name ?? organizacion.name}</div>
                        </div>
                        <div>
                            <div className="text-muted-foreground text-xs">NIT</div>
                            <div className="font-medium">{organizacion.nit ?? '—'}</div>
                        </div>
                        <div>
                            <div className="text-muted-foreground text-xs">Ciudad</div>
                            <div className="font-medium">{organizacion.city ?? '—'}</div>
                        </div>
                    </CardContent>
                </Card>

                <form onSubmit={submit} className="space-y-6">
                    {/* Contexto SGI */}
                    <Card>
                        <CardContent className="space-y-5 p-5">
                            <div className="text-muted-foreground flex items-center gap-2 text-xs font-semibold tracking-wide uppercase">
                                <Building2 className="size-4" /> Contexto de la empresa
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="actividad_economica">Actividad económica</Label>
                                    <Input
                                        id="actividad_economica"
                                        value={data.actividad_economica}
                                        onChange={(e) => setData('actividad_economica', e.target.value)}
                                        disabled={!canManage}
                                    />
                                    <InputError message={errors.actividad_economica} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="codigo_ciiu">Código CIIU</Label>
                                    <Input
                                        id="codigo_ciiu"
                                        value={data.codigo_ciiu}
                                        onChange={(e) => setData('codigo_ciiu', e.target.value)}
                                        placeholder="Ej: 4290"
                                        disabled={!canManage}
                                    />
                                    <InputError message={errors.codigo_ciiu} />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="sector">Sector</Label>
                                    <Input
                                        id="sector"
                                        value={data.sector}
                                        onChange={(e) => setData('sector', e.target.value)}
                                        placeholder="Construcción, Servicios…"
                                        disabled={!canManage}
                                    />
                                    <InputError message={errors.sector} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="tamano_empresa">Tamaño de empresa</Label>
                                    <select
                                        id="tamano_empresa"
                                        value={data.tamano_empresa}
                                        onChange={(e) => setData('tamano_empresa', e.target.value)}
                                        disabled={!canManage}
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm disabled:opacity-60"
                                    >
                                        <option value="">—</option>
                                        <option value="Micro">Micro</option>
                                        <option value="Pequeña">Pequeña</option>
                                        <option value="Mediana">Mediana</option>
                                        <option value="Grande">Grande</option>
                                    </select>
                                    <InputError message={errors.tamano_empresa} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="num_trabajadores">N.° de trabajadores</Label>
                                    <Input
                                        id="num_trabajadores"
                                        type="number"
                                        min="0"
                                        value={data.num_trabajadores}
                                        onChange={(e) => setData('num_trabajadores', e.target.value)}
                                        disabled={!canManage}
                                    />
                                    <InputError message={errors.num_trabajadores} />
                                </div>
                            </div>
                            {descuadre && (
                                <div className="flex items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-400">
                                    <ShieldAlert className="size-4" /> Declaras {declarado} trabajadores, pero hay {empleadosCount} registrados en el
                                    módulo Empleados.
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Riesgo y ARL */}
                    <Card>
                        <CardContent className="space-y-5 p-5">
                            <div className="text-muted-foreground flex items-center gap-2 text-xs font-semibold tracking-wide uppercase">
                                <ShieldAlert className="size-4" /> Riesgo y afiliación
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="nivel_riesgo">Nivel de riesgo (ARL)</Label>
                                    <select
                                        id="nivel_riesgo"
                                        value={data.nivel_riesgo}
                                        onChange={(e) => setData('nivel_riesgo', e.target.value)}
                                        disabled={!canManage}
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm disabled:opacity-60"
                                    >
                                        <option value="">—</option>
                                        <option value="I">I</option>
                                        <option value="II">II</option>
                                        <option value="III">III</option>
                                        <option value="IV">IV</option>
                                        <option value="V">V</option>
                                    </select>
                                    <InputError message={errors.nivel_riesgo} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="arl">ARL</Label>
                                    <Input
                                        id="arl"
                                        value={data.arl}
                                        onChange={(e) => setData('arl', e.target.value)}
                                        placeholder="Ej: Sura, Positiva…"
                                        disabled={!canManage}
                                    />
                                    <InputError message={errors.arl} />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Responsables */}
                    <Card>
                        <CardContent className="space-y-5 p-5">
                            <div className="text-muted-foreground flex items-center gap-2 text-xs font-semibold tracking-wide uppercase">
                                <Users className="size-4" /> Responsables
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="representante_legal">Representante legal</Label>
                                    <Input
                                        id="representante_legal"
                                        value={data.representante_legal}
                                        onChange={(e) => setData('representante_legal', e.target.value)}
                                        disabled={!canManage}
                                    />
                                    <InputError message={errors.representante_legal} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="representante_cc">Cédula representante</Label>
                                    <Input
                                        id="representante_cc"
                                        value={data.representante_cc}
                                        onChange={(e) => setData('representante_cc', e.target.value)}
                                        placeholder="C.C. N.°"
                                        disabled={!canManage}
                                    />
                                    <InputError message={errors.representante_cc} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="responsable_sgsst">Responsable SG-SST</Label>
                                    <Input
                                        id="responsable_sgsst"
                                        value={data.responsable_sgsst}
                                        onChange={(e) => setData('responsable_sgsst', e.target.value)}
                                        disabled={!canManage}
                                    />
                                    <InputError message={errors.responsable_sgsst} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="licencia_sgsst">Licencia SST (N.°)</Label>
                                    <Input
                                        id="licencia_sgsst"
                                        value={data.licencia_sgsst}
                                        onChange={(e) => setData('licencia_sgsst', e.target.value)}
                                        disabled={!canManage}
                                    />
                                    <InputError message={errors.licencia_sgsst} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="licencia_sgsst_vence">Expiración de la licencia</Label>
                                    <Input
                                        id="licencia_sgsst_vence"
                                        type="date"
                                        value={data.licencia_sgsst_vence}
                                        onChange={(e) => setData('licencia_sgsst_vence', e.target.value)}
                                        disabled={!canManage}
                                    />
                                    <InputError message={errors.licencia_sgsst_vence} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="curso_sst_horas">Curso SG-SST del responsable</Label>
                                    <select
                                        id="curso_sst_horas"
                                        value={data.curso_sst_horas}
                                        onChange={(e) => setData('curso_sst_horas', e.target.value)}
                                        disabled={!canManage}
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm disabled:opacity-60"
                                    >
                                        <option value="">—</option>
                                        <option value="50">Curso de 50 horas</option>
                                        <option value="20">Actualización de 20 horas</option>
                                    </select>
                                    <InputError message={errors.curso_sst_horas} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="curso_sst_fecha">Fecha del curso</Label>
                                    <Input
                                        id="curso_sst_fecha"
                                        type="date"
                                        value={data.curso_sst_fecha}
                                        onChange={(e) => setData('curso_sst_fecha', e.target.value)}
                                        disabled={!canManage}
                                    />
                                    <InputError message={errors.curso_sst_fecha} />
                                </div>
                            </div>
                            {licenciaAlerta && (
                                <div
                                    className={
                                        licenciaAlerta.vencida
                                            ? 'flex items-center gap-2 rounded-lg border border-red-600/30 bg-red-600/10 px-3 py-2 text-xs text-red-700 dark:text-red-400'
                                            : 'flex items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-400'
                                    }
                                >
                                    <ShieldAlert className="size-4" /> {licenciaAlerta.mensaje}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {canManage && (
                        <div className="flex justify-end">
                            <Button type="submit" disabled={processing}>
                                Guardar información
                            </Button>
                        </div>
                    )}
                </form>
            </div>
        </AppLayout>
    );
}
