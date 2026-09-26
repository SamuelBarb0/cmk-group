import { CodigoSig } from '@/components/codigo-sig';
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
import { CheckCircle2, CircleDashed, Pencil, Plus, TrafficCone, Trash2, TriangleAlert, Users } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Paso {
    numero: number;
    fase: number;
    fase_nombre: string;
    titulo: string;
    descripcion: string | null;
    estado: string;
    responsable: string | null;
    /** false = la norma no lo exige en el nivel del plan (no cuenta en el avance). */
    aplica: boolean;
    /** Preguntas de la lista de verificación que aplican al nivel, y cuántas cumplen. */
    criterios: number;
    criterios_cumple: number;
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
    employee_id: number | null;
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
        misionalidad: number | null;
        periodo_inicio: number | null;
        periodo_fin: number | null;
        lider_nombre: string | null;
        lider_cargo: string | null;
        lider_documento: string | null;
        lider_designacion_fecha: string | null;
        avance: number;
    } | null;
    fases: Fase[];
    resumen: {
        total: number;
        cumple: number;
        en_proceso: number;
        no_cumple: number;
        no_aplica: number;
        pendiente: number;
        no_exigidos: number;
    } | null;
    comite?: Miembro[];
    empleados?: { id: number; nombre: string; documento: string | null; cargo: string | null }[];
    niveles?: string[];
    misionalidades?: Record<string, string>;
    nivelSugerido?: {
        nivel: string | null;
        calculado: boolean;
        obligada: boolean | null;
        flota: number;
        vehiculos: number;
        vehiculos_contratistas: number;
        conductores: number;
        conductores_propios: number;
        conductores_contratistas: number;
        contratistas: number;
        rutas: number;
    };
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

export default function PesvIndex({
    needsClient,
    plan,
    fases,
    resumen,
    comite = [],
    empleados = [],
    niveles = [],
    misionalidades = {},
    nivelSugerido,
}: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const page = usePage<SharedData>();
    const tenant = page.props.tenant as { id: number; name: string } | null;
    const notice = useNotice(page.props.flash?.success);

    const [planOpen, setPlanOpen] = useState(false);
    const [comiteOpen, setComiteOpen] = useState(false);
    // null = agregando; un integrante = editándolo.
    const [editando, setEditando] = useState<Miembro | null>(null);

    const planForm = useForm({
        nivel: plan?.nivel ?? 'basico',
        misionalidad: plan?.misionalidad ? String(plan.misionalidad) : '',
        periodo_inicio: plan?.periodo_inicio ?? new Date().getFullYear(),
        periodo_fin: plan?.periodo_fin ?? new Date().getFullYear() + 1,
        lider_nombre: plan?.lider_nombre ?? '',
        lider_cargo: plan?.lider_cargo ?? '',
        lider_documento: plan?.lider_documento ?? '',
        lider_designacion_fecha: plan?.lider_designacion_fecha ?? '',
    });

    const comiteForm = useForm({
        employee_id: '',
        nombre: '',
        documento: '',
        cargo: '',
        rol_comite: 'integrante',
        es_representante_direccion: false as boolean,
    });

    function abrirComite(m: Miembro | null) {
        setEditando(m);
        comiteForm.clearErrors();
        comiteForm.setData({
            employee_id: m?.employee_id ? String(m.employee_id) : '',
            nombre: m?.nombre ?? '',
            documento: m?.documento ?? '',
            cargo: m?.cargo ?? '',
            rol_comite: m?.rol_comite ?? 'integrante',
            es_representante_direccion: m?.es_representante_direccion ?? false,
        });
        setComiteOpen(true);
    }

    /** Elegir un empleado trae su nombre, documento y cargo; «otra persona» los deja libres. */
    function elegirEmpleado(id: string) {
        const e = empleados.find((x) => String(x.id) === id);
        comiteForm.setData((d) => ({
            ...d,
            employee_id: id,
            ...(e ? { nombre: e.nombre, documento: e.documento ?? '', cargo: e.cargo ?? '' } : {}),
        }));
    }

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

    const guardarMiembro: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                comiteForm.reset();
                setComiteOpen(false);
            },
        };
        if (editando) comiteForm.put(`/pesv/comite/${editando.id}`, opts);
        else comiteForm.post('/pesv/comite', opts);
    };

    const nivelTexto = NIVEL_LABEL[plan.nivel] ?? plan.nivel;
    const comiteExigido = plan.nivel !== 'basico';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="PESV" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            <CodigoSig className="mr-2" />
                            Plan Estratégico de Seguridad Vial
                        </h1>
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
                                {resumen.cumple} de {resumen.total - resumen.no_aplica} pasos aplicables cumplidos · el avance se mide sobre las
                                preguntas de la lista de verificación
                                {resumen.no_aplica > 0 ? ` · ${resumen.no_aplica} marcados como no aplica` : ''}
                                {resumen.no_exigidos > 0 ? ` · ${resumen.no_exigidos} no exigidos en nivel ${nivelTexto.toLowerCase()}` : ''}
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
                            <p className="text-2xl font-bold">{nivelTexto}</p>
                            {plan.misionalidad && <p className="text-muted-foreground text-sm">Misionalidad: {misionalidades[plan.misionalidad]}</p>}
                            {nivelSugerido && (
                                <>
                                    <p className="text-muted-foreground text-sm">
                                        Flota: <span className="text-foreground font-medium">{nivelSugerido.flota}</span> vehículos (
                                        {nivelSugerido.vehiculos} en el inventario
                                        {nivelSugerido.vehiculos_contratistas > 0
                                            ? ` + ${nivelSugerido.vehiculos_contratistas} declarados por contratistas`
                                            : ''}
                                        ) · Conductores: <span className="text-foreground font-medium">{nivelSugerido.conductores}</span> (
                                        {nivelSugerido.conductores_propios} propios
                                        {nivelSugerido.conductores_contratistas > 0
                                            ? ` + ${nivelSugerido.conductores_contratistas} de contratistas`
                                            : ''}
                                        ).
                                    </p>
                                    {!nivelSugerido.calculado ? (
                                        <p className="text-sm text-amber-700 dark:text-amber-400">
                                            Define la misionalidad en «Editar plan y líder» para calcular el nivel que exige la Res. 40595.
                                        </p>
                                    ) : nivelSugerido.nivel === null ? (
                                        <p className="text-sm text-amber-700 dark:text-amber-400">
                                            Con lo cargado, la empresa está por debajo del umbral de la Res. 40595 (desde 11 vehículos o 2
                                            conductores): no estaría obligada a tener PESV. Revisa que la flota y los conductores estén completos.
                                        </p>
                                    ) : nivelSugerido.nivel !== plan.nivel ? (
                                        <p className="text-sm text-amber-700 dark:text-amber-400">
                                            La Res. 40595 exige nivel <span className="font-semibold">{NIVEL_LABEL[nivelSugerido.nivel]}</span> para
                                            esta misionalidad y tamaño, pero el plan está en {nivelTexto.toLowerCase()}. Si el inventario está
                                            completo, cambia el nivel.
                                        </p>
                                    ) : (
                                        <p className="text-sm text-green-700 dark:text-green-400">
                                            Coincide con el nivel que exige la Res. 40595 para esta misionalidad y tamaño.
                                        </p>
                                    )}
                                </>
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
                                <p className="text-muted-foreground text-sm">
                                    {comite.length} integrantes registrados.
                                    {!comiteExigido && ' No es exigible en nivel básico (aplica a estándar y avanzado).'}
                                </p>
                            </div>
                            {canManage && (
                                <Button variant="outline" size="sm" className="gap-2" onClick={() => abrirComite(null)}>
                                    <Plus className="size-4" /> Agregar integrante
                                </Button>
                            )}
                        </div>
                        {comiteExigido && comite.length < 3 && (
                            <p className="text-sm text-amber-700 dark:text-amber-400">
                                La Res. 40595 pide al menos 3 personas con poder de decisión, designadas por el nivel directivo.
                            </p>
                        )}

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
                                            <div className="flex">
                                                <Button variant="ghost" size="icon" aria-label="Editar integrante" onClick={() => abrirComite(m)}>
                                                    <Pencil className="size-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="Retirar integrante"
                                                    onClick={() =>
                                                        confirm(`¿Retirar a ${m.nombre} del comité?`) &&
                                                        router.delete(`/pesv/comite/${m.id}`, { preserveScroll: true })
                                                    }
                                                >
                                                    <Trash2 className="size-4 text-red-600" />
                                                </Button>
                                            </div>
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
                                        className={cn(
                                            'hover:bg-muted/60 flex items-start justify-between gap-3 rounded-lg border p-3 transition-colors',
                                            !paso.aplica && 'opacity-60',
                                        )}
                                    >
                                        <div className="min-w-0">
                                            <div className="font-medium">
                                                {paso.numero}. {paso.titulo}
                                            </div>
                                            {paso.aplica && paso.criterios > 0 && (
                                                <div className="text-muted-foreground text-xs">
                                                    {paso.criterios_cumple} de {paso.criterios} preguntas cumplen
                                                </div>
                                            )}
                                            {paso.responsable && <div className="text-muted-foreground text-xs">{paso.responsable}</div>}
                                        </div>
                                        {paso.aplica ? (
                                            <EstadoBadge estado={paso.estado} />
                                        ) : (
                                            <span className="text-muted-foreground shrink-0 rounded border px-1.5 py-0.5 text-[11px]">
                                                No exigido en {nivelTexto.toLowerCase()}
                                            </span>
                                        )}
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

                            <div className="grid gap-2">
                                <Label htmlFor="misionalidad">Misionalidad (Res. 40595)</Label>
                                <select
                                    id="misionalidad"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={planForm.data.misionalidad}
                                    onChange={(e) => planForm.setData('misionalidad', e.target.value)}
                                >
                                    <option value="">Sin definir</option>
                                    {Object.entries(misionalidades).map(([v, l]) => (
                                        <option key={v} value={v}>
                                            {l}
                                        </option>
                                    ))}
                                </select>
                                <p className="text-muted-foreground text-xs">
                                    Con la flota y los conductores define el nivel exigido. Las empresas de transporte suben de nivel antes.
                                </p>
                                <InputError message={planForm.errors.misionalidad} />
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
                    <form onSubmit={guardarMiembro}>
                        <DialogHeader>
                            <DialogTitle>{editando ? 'Editar integrante' : 'Integrante del comité'}</DialogTitle>
                        </DialogHeader>

                        <div className="grid gap-4 py-4">
                            <div className="grid gap-2">
                                <Label htmlFor="m_empleado">Colaborador</Label>
                                <select
                                    id="m_empleado"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={comiteForm.data.employee_id}
                                    onChange={(e) => elegirEmpleado(e.target.value)}
                                >
                                    <option value="">Otra persona (no está en Empleados)</option>
                                    {empleados.map((e) => (
                                        <option key={e.id} value={e.id}>
                                            {e.nombre}
                                            {e.cargo ? ` — ${e.cargo}` : ''}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={comiteForm.errors.employee_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="m_nombre">Nombre</Label>
                                <Input id="m_nombre" value={comiteForm.data.nombre} onChange={(e) => comiteForm.setData('nombre', e.target.value)} />
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
                                    <Input id="m_cargo" value={comiteForm.data.cargo} onChange={(e) => comiteForm.setData('cargo', e.target.value)} />
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
                                <Users className="size-4" /> {editando ? 'Guardar' : 'Agregar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
