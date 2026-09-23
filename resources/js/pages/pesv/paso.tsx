import InputError from '@/components/input-error';
import { ESTADO_LABEL, Notice, SinCliente, useNotice } from '@/components/pesv/shared';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, CheckCircle2, CircleAlert, CircleDashed, ExternalLink } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Insumo {
    etiqueta: string;
    estado: string;
    detalle: string;
    url: string | null;
    cantidad: number | null;
}

const NIVELES: Record<string, string> = { basico: 'Básico', estandar: 'Estándar', avanzado: 'Avanzado' };

interface Props {
    needsClient: boolean;
    paso: {
        numero: number;
        fase: number;
        fase_nombre: string;
        titulo: string;
        descripcion: string | null;
        aplica: boolean;
        niveles: string[];
        nivel_plan: string;
        estado: string;
        observaciones: string | null;
        responsable: string | null;
        fecha_cumplimiento: string | null;
    } | null;
    insumos?: Insumo[];
    estados?: string[];
    vecinos?: { anterior: number | null; siguiente: number | null };
}

/** Icono y color por estado del insumo. */
const INSUMO_ICON = {
    ok: { icon: CheckCircle2, clase: 'text-emerald-600 bg-emerald-600/10' },
    parcial: { icon: CircleDashed, clase: 'text-amber-600 bg-amber-500/10' },
    falta: { icon: CircleAlert, clase: 'text-red-600 bg-red-600/10' },
} as const;

export default function PesvPaso({ needsClient, paso, insumos = [], estados = [], vecinos }: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const page = usePage<SharedData>();
    const notice = useNotice(page.props.flash?.success);

    const form = useForm({
        estado: paso?.estado ?? 'pendiente',
        observaciones: paso?.observaciones ?? '',
        responsable: paso?.responsable ?? '',
        fecha_cumplimiento: paso?.fecha_cumplimiento ?? '',
    });

    if (needsClient || !paso) {
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Dashboard', href: '/dashboard' },
                    { title: 'PESV', href: '/pesv' },
                ]}
            >
                <Head title="PESV" />
                <SinCliente titulo="PESV" descripcion="Plan Estratégico de Seguridad Vial (Resolución 40595 de 2022)." />
            </AppLayout>
        );
    }

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'PESV', href: '/pesv' },
        { title: `Paso ${paso.numero}`, href: `/pesv/paso/${paso.numero}` },
    ];

    const guardar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(`/pesv/paso/${paso.numero}`, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Paso ${paso.numero} · ${paso.titulo}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-muted-foreground text-sm">
                            Fase {paso.fase} · {paso.fase_nombre}
                        </p>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            {paso.numero}. {paso.titulo}
                        </h1>
                        {paso.descripcion && <p className="text-muted-foreground mt-1 max-w-3xl text-sm">{paso.descripcion}</p>}
                        <p className="text-muted-foreground mt-1 text-xs">Exigible en nivel {paso.niveles.map((n) => NIVELES[n] ?? n).join(', ')}.</p>
                        {!paso.aplica && (
                            <p className="mt-2 max-w-3xl rounded-md border border-amber-600/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-300">
                                La Res. 40595 no exige este paso en nivel {(NIVELES[paso.nivel_plan] ?? paso.nivel_plan).toLowerCase()}: no cuenta en
                                el avance del plan. Puedes documentarlo igual si la empresa lo implementa.
                            </p>
                        )}
                    </div>
                    <div className="flex gap-2">
                        {vecinos?.anterior && (
                            <Button asChild variant="outline" size="sm" className="gap-1">
                                <Link href={`/pesv/paso/${vecinos.anterior}`}>
                                    <ArrowLeft className="size-4" /> Paso {vecinos.anterior}
                                </Link>
                            </Button>
                        )}
                        {vecinos?.siguiente && (
                            <Button asChild variant="outline" size="sm" className="gap-1">
                                <Link href={`/pesv/paso/${vecinos.siguiente}`}>
                                    Paso {vecinos.siguiente} <ArrowRight className="size-4" />
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <Notice mensaje={notice} />

                {/* Lo que la plataforma ya sabe para este paso */}
                {insumos.length > 0 && (
                    <Card>
                        <CardContent className="space-y-4 p-5">
                            <div>
                                <h2 className="font-semibold">Lo que ya tiene la plataforma</h2>
                                <p className="text-muted-foreground text-sm">
                                    Información que la empresa ya cargó en otros módulos y que sustenta este paso. No hay que volver a escribirla.
                                </p>
                            </div>

                            <ul className="grid gap-2 md:grid-cols-2">
                                {insumos.map((insumo, i) => {
                                    const cfg = INSUMO_ICON[insumo.estado as keyof typeof INSUMO_ICON] ?? INSUMO_ICON.falta;
                                    const Icon = cfg.icon;

                                    const contenido = (
                                        <div className="flex items-start gap-3 rounded-lg border p-3">
                                            <div className={cn('mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg', cfg.clase)}>
                                                <Icon className="size-4" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-1.5 font-medium">
                                                    {insumo.etiqueta}
                                                    {insumo.url && <ExternalLink className="text-muted-foreground size-3" />}
                                                </div>
                                                <p className="text-muted-foreground text-sm">{insumo.detalle}</p>
                                            </div>
                                        </div>
                                    );

                                    return (
                                        <li key={i}>
                                            {insumo.url ? (
                                                <Link href={insumo.url} className="hover:bg-muted/60 block rounded-lg transition-colors">
                                                    {contenido}
                                                </Link>
                                            ) : (
                                                contenido
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {/* Estado del paso */}
                <Card>
                    <CardContent className="p-5">
                        <form onSubmit={guardar} className="space-y-4">
                            <h2 className="font-semibold">Estado del paso</h2>

                            <div className="grid gap-4 md:grid-cols-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="estado">Cumplimiento</Label>
                                    <select
                                        id="estado"
                                        disabled={!canManage}
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm disabled:opacity-60"
                                        value={form.data.estado}
                                        onChange={(e) => form.setData('estado', e.target.value)}
                                    >
                                        {estados.map((e) => (
                                            <option key={e} value={e}>
                                                {ESTADO_LABEL[e] ?? e}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={form.errors.estado} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="responsable">Responsable</Label>
                                    <Input
                                        id="responsable"
                                        disabled={!canManage}
                                        value={form.data.responsable}
                                        onChange={(e) => form.setData('responsable', e.target.value)}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="fecha_cumplimiento">Fecha de cumplimiento</Label>
                                    <Input
                                        id="fecha_cumplimiento"
                                        type="date"
                                        disabled={!canManage}
                                        value={form.data.fecha_cumplimiento}
                                        onChange={(e) => form.setData('fecha_cumplimiento', e.target.value)}
                                    />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="observaciones">Observaciones y evidencia</Label>
                                <textarea
                                    id="observaciones"
                                    rows={4}
                                    disabled={!canManage}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm disabled:opacity-60"
                                    placeholder="Describe la evidencia que soporta este paso: actas, documentos, registros."
                                    value={form.data.observaciones}
                                    onChange={(e) => form.setData('observaciones', e.target.value)}
                                />
                            </div>

                            {canManage && (
                                <div className="flex justify-end">
                                    <Button type="submit" disabled={form.processing}>
                                        Guardar paso
                                    </Button>
                                </div>
                            )}
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
