import InputError from '@/components/input-error';
import { EstadoBadge, Notice, SinCliente, StatCard, useNotice } from '@/components/pesv/shared';
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
import { CheckCircle2, CircleDashed, Plus, TrafficCone, Trash2, TriangleAlert, Users } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Paso {
    numero: number;
    fase: number;
    fase_nombre: string;
    titulo: string;
    descripcion: string | null;
    estado: string;
    responsable: string | null;
}

interface Fase {
    fase: number;
    nombre: string;
    pasos: Paso[];
    cumplidos: number;
    total: number;
}

interface Miembro {
    id: number;
    nombre: string;
    documento: string | null;
    cargo: string | null;
    rol_comite: string;
    es_representante_direccion: boolean;
}

interface Props {
    needsClient: boolean;
    plan: {
        nivel: string;
        periodo_inicio: number | null;
        periodo_fin: number | null;
        lider_nombre: string | null;
        lider_cargo: string | null;
        lider_documento: string | null;
        lider_designacion_fecha: string | null;
        avance: number;
    } | null;
    fases: Fase[];
    resumen: { total: number; cumple: number; en_proceso: number; no_cumple: number; no_aplica: number; pendiente: number } | null;
    comite?: Miembro[];
    niveles?: string[];
    nivelSugerido?: { nivel: string; vehiculos: number; conductores: number; contratistas: number; rutas: number };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'PESV', href: '/pesv' },
];

const NIVEL_LABEL: Record<string, string> = {
    basico: 'Básico',
    estandar: 'Estándar',
    avanzado: 'Avanzado',
};

export default function PesvIndex({ needsClient, plan, fases, resumen, comite = [], niveles = [], nivelSugerido }: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const page = usePage<SharedData>();
    const tenant = page.props.tenant as { id: number; name: string } | null;
    const notice = useNotice(page.props.flash?.success);

    const [planOpen, setPlanOpen] = useState(false);
    const [comiteOpen, setComiteOpen] = useState(false);

    const planForm = useForm({
        nivel: plan?.nivel ?? 'basico',
        periodo_inicio: plan?.periodo_inicio ?? new Date().getFullYear(),
        periodo_fin: plan?.periodo_fin ?? new Date().getFullYear() + 1,
        lider_nombre: plan?.lider_nombre ?? '',
        lider_cargo: plan?.lider_cargo ?? '',
        lider_documento: plan?.lider_documento ?? '',
        lider_designacion_fecha: plan?.lider_designacion_fecha ?? '',
    });

    const comiteForm = useForm({
        nombre: '',
        documento: '',
        cargo: '',
        rol_comite: 'integrante',
        es_representante_direccion: false as boolean,
    });

    if (needsClient || !plan || !resumen) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="PESV" />
                <SinCliente titulo="PESV" descripcion="Plan Estratégico de Seguridad Vial (Resolución 40595 de 2022)." />
            </AppLayout>
        );
    }

    const guardarPlan: FormEventHandler = (e) => {
        e.preventDefault();
        planForm.put('/pesv', { preserveScroll: true, onSuccess: () => setPlanOpen(false) });
    };

    const agregarMiembro: FormEventHandler = (e) => {
        e.preventDefault();
        comiteForm.post('/pesv/comite', {
            preserveScroll: true,
            onSuccess: () => {
                comiteForm.reset();
                setComiteOpen(false);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="PESV" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Plan Estratégico de Seguridad Vial</h1>
                        <p className="text-muted-foreground text-sm">
                            Resolución 40595 de 2022 · 24 pasos en 4 fases
                            {tenant ? (
                                <>
                                    {' '}
                                    · <span className="font-medium">{tenant.name}</span>
                                </>
                            ) : null}
                        </p>
                    </div>
                    {canManage && (
                        <Button variant="outline" onClick={() => setPlanOpen(true)}>
                            Editar plan y líder
                        </Button>
                    )}
                </div>

                <Notice mensaje={notice} />

                {/* Avance general */}
                <Card>
                    <CardContent className="flex flex-wrap items-center gap-6 p-5">
                        <div className="min-w-40">
                            <div className="text-3xl font-bold tabular-nums">{plan.avance}%</div>
                            <div className="text-muted-foreground text-sm">Avance del PESV</div>
                        </div>
                        <div className="min-w-56 flex-1">
                            <div className="bg-muted h-3 w-full overflow-hidden rounded-full">
                                <div
                                    className={cn(
                                        'h-full rounded-full transition-all',
                                        plan.avance >= 85 ? 'bg-emerald-600' : plan.avance >= 60 ? 'bg-amber-500' : 'bg-red-600',
                                    )}
                                    style={{ width: `${plan.avance}%` }}
                                />
                            </div>
                            <p className="text-muted-foreground mt-2 text-xs">
                                {resumen.cumple} de {resumen.total - resumen.no_aplica} pasos aplicables cumplidos
                                {resumen.no_aplica > 0 ? ` · ${resumen.no_aplica} marcados como no aplica` : ''}
                            </p>
                        </div>
                        <div className="text-right">
                            <div className="text-lg font-semibold">{NIVEL_LABEL[plan.nivel] ?? plan.nivel}</div>
                            <div className="text-muted-foreground text-sm">
                                Nivel {plan.periodo_inicio ? `· ${plan.periodo_inicio}–${plan.periodo_fin ?? ''}` : ''}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Pasos que cumplen" value={resumen.cumple} icon={CheckCircle2} />
                    <StatCard label="En proceso" value={resumen.en_proceso} icon={CircleDashed} />
                    <StatCard label="No cumplen" value={resumen.no_cumple} icon={TriangleAlert} danger={resumen.no_cumple > 0} />
                    <StatCard label="Pendientes" value={resumen.pendiente} icon={TrafficCone} />
                </div>

                {/* Nivel y líder */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardContent className="space-y-3 p-5">
                            <h2 className="font-semibold">Nivel del PESV</h2>
                            <p className="text-2xl font-bold">{NIVEL_LABEL[plan.nivel] ?? plan.nivel}</p>
                            {nivelSugerido && (
                                <p className="text-muted-foreground text-sm">
                                    Según lo cargado ({nivelSugerido.vehiculos} vehículos, {nivelSugerido.conductores} conductores,{' '}
                                    {nivelSugerido.contratistas} contratistas, {nivelSugerido.rutas} rutas), la caracterización apunta a{' '}
                                    <span className="font-medium">{NIVEL_LABEL[nivelSugerido.nivel]}</span>. Es una orientación: el nivel
                                    definitivo lo determina la misionalidad del transporte y lo fija el consultor.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="space-y-2 p-5">
                            <h2 className="font-semibold">Líder del PESV (Paso 1)</h2>
                            {plan.lider_nombre ? (
                                <>
                                    <p className="text-lg font-medium">{plan.lider_nombre}</p>
                                    <p className="text-muted-foreground text-sm">
                                        {plan.lider_cargo ?? 'Sin cargo registrado'}
                                        {plan.lider_documento ? ` · C.C. ${plan.lider_documento}` : ''}
                                    </p>
                                    {plan.lider_designacion_fecha && (
                                        <p className="text-muted-foreground text-sm">Designado el {plan.lider_designacion_fecha}</p>
                                    )}
                                </>
                            ) : (
                                <p className="text-muted-foreground text-sm">
                                    Sin líder designado. Es el primer paso de la norma y condiciona todo lo demás.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Comité de Seguridad Vial */}
                <Card>
                    <CardContent className="space-y-4 p-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="font-semibold">Comité de Seguridad Vial (Paso 2)</h2>
                                <p className="text-muted-foreground text-sm">{comite.length} integrantes registrados.</p>
                            </div>
                            {canManage && (
                                <Button variant="outline" size="sm" className="gap-2" onClick={() => setComiteOpen(true)}>
                                    <Plus className="size-4" /> Agregar integrante
                                </Button>
                            )}
                        </div>

                        {comite.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                Todavía no hay comité. Puedes armarlo con los colaboradores que ya están cargados en Empleados.
                            </p>
                        ) : (
                            <ul className="divide-border divide-y">
                                {comite.map((m) => (
                                    <li key={m.id} className="flex items-center justify-between gap-3 py-2">
                                        <div>
                                            <span className="font-medium">{m.nombre}</span>
                                            <span className="text-muted-foreground text-sm">
                                                {' '}
                                                · {m.rol_comite}
                                                {m.cargo ? ` · ${m.cargo}` : ''}
                                                {m.es_representante_direccion ? ' · representante de la dirección' : ''}
                                            </span>
                                        </div>
                                        {canManage && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                onClick={() => router.delete(`/pesv/comite/${m.id}`, { preserveScroll: true })}
                                            >
                                                <Trash2 className="size-4 text-red-600" />
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                {/* Las 4 fases con sus pasos */}
                {fases.map((fase) => (
                    <Card key={fase.fase}>
                        <CardContent className="space-y-3 p-5">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="font-semibold">
                                    Fase {fase.fase} · {fase.nombre}
                                </h2>
                                <span className="text-muted-foreground text-sm tabular-nums">
                                    {fase.cumplidos}/{fase.total} pasos
                                </span>
                            </div>

                            <div className="grid gap-2 md:grid-cols-2">
                                {fase.pasos.map((paso) => (
                                    <Link
                                        key={paso.numero}
                                        href={`/pesv/paso/${paso.numero}`}
                                        className="hover:bg-muted/60 flex items-start justify-between gap-3 rounded-lg border p-3 transition-colors"
                                    >
                                        <div className="min-w-0">
                                            <div className="font-medium">
                                                {paso.numero}. {paso.titulo}
                                            </div>
                                            {paso.responsable && <div className="text-muted-foreground text-xs">{paso.responsable}</div>}
                                        </div>
                                        <EstadoBadge estado={paso.estado} />
                                    </Link>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>

            {/* Diálogo: plan y líder */}
            <Dialog open={planOpen} onOpenChange={setPlanOpen}>
                <DialogContent className="sm:max-w-lg">
                    <form onSubmit={guardarPlan}>
                        <DialogHeader>
                            <DialogTitle>Plan y líder del PESV</DialogTitle>
                        </DialogHeader>

                        <div className="grid gap-4 py-4">
                            <div className="grid gap-2">
                                <Label htmlFor="nivel">Nivel</Label>
                                <select
                                    id="nivel"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={planForm.data.nivel}
                                    onChange={(e) => planForm.setData('nivel', e.target.value)}
                                >
                                    {niveles.map((n) => (
                                        <option key={n} value={n}>
                                            {NIVEL_LABEL[n] ?? n}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={planForm.errors.nivel} />
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="periodo_inicio">Periodo desde</Label>
                                    <Input
                                        id="periodo_inicio"
                                        type="number"
                                        value={planForm.data.periodo_inicio}
                                        onChange={(e) => planForm.setData('periodo_inicio', Number(e.target.value))}
                                    />
                                    <InputError message={planForm.errors.periodo_inicio} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="periodo_fin">Hasta</Label>
                                    <Input
                                        id="periodo_fin"
                                        type="number"
                                        value={planForm.data.periodo_fin}
                                        onChange={(e) => planForm.setData('periodo_fin', Number(e.target.value))}
                                    />
                                    <InputError message={planForm.errors.periodo_fin} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="lider_nombre">Líder del PESV</Label>
                                <Input
                                    id="lider_nombre"
                                    value={planForm.data.lider_nombre}
                                    onChange={(e) => planForm.setData('lider_nombre', e.target.value)}
                                />
                                <InputError message={planForm.errors.lider_nombre} />
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="lider_cargo">Cargo</Label>
                                    <Input
                                        id="lider_cargo"
                                        value={planForm.data.lider_cargo}
                                        onChange={(e) => planForm.setData('lider_cargo', e.target.value)}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="lider_documento">Documento</Label>
                                    <Input
                                        id="lider_documento"
                                        value={planForm.data.lider_documento}
                                        onChange={(e) => planForm.setData('lider_documento', e.target.value)}
                                    />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="lider_designacion_fecha">Fecha de designación</Label>
                                <Input
                                    id="lider_designacion_fecha"
                                    type="date"
                                    value={planForm.data.lider_designacion_fecha}
                                    onChange={(e) => planForm.setData('lider_designacion_fecha', e.target.value)}
                                />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setPlanOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={planForm.processing}>
                                Guardar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Diálogo: integrante del comité */}
            <Dialog open={comiteOpen} onOpenChange={setComiteOpen}>
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={agregarMiembro}>
                        <DialogHeader>
                            <DialogTitle>Integrante del comité</DialogTitle>
                        </DialogHeader>

                        <div className="grid gap-4 py-4">
                            <div className="grid gap-2">
                                <Label htmlFor="m_nombre">Nombre</Label>
                                <Input
                                    id="m_nombre"
                                    value={comiteForm.data.nombre}
                                    onChange={(e) => comiteForm.setData('nombre', e.target.value)}
                                />
                                <InputError message={comiteForm.errors.nombre} />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="m_documento">Documento</Label>
                                    <Input
                                        id="m_documento"
                                        value={comiteForm.data.documento}
                                        onChange={(e) => comiteForm.setData('documento', e.target.value)}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="m_cargo">Cargo</Label>
                                    <Input
                                        id="m_cargo"
                                        value={comiteForm.data.cargo}
                                        onChange={(e) => comiteForm.setData('cargo', e.target.value)}
                                    />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="m_rol">Rol en el comité</Label>
                                <select
                                    id="m_rol"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={comiteForm.data.rol_comite}
                                    onChange={(e) => comiteForm.setData('rol_comite', e.target.value)}
                                >
                                    <option value="presidente">Presidente</option>
                                    <option value="secretario">Secretario</option>
                                    <option value="integrante">Integrante</option>
                                </select>
                            </div>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={comiteForm.data.es_representante_direccion}
                                    onCheckedChange={(v) => comiteForm.setData('es_representante_direccion', v === true)}
                                />
                                Representante de la alta dirección
                            </label>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setComiteOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={comiteForm.processing} className="gap-2">
                                <Users className="size-4" /> Agregar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
