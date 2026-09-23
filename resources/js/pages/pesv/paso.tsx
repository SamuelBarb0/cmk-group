import InputError from '@/components/input-error';
import { EstadoBadge, Notice, SinCliente, useNotice } from '@/components/pesv/shared';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, CheckCircle2, CircleAlert, CircleDashed, Download, ExternalLink, Loader2, Paperclip, Trash2 } from 'lucide-react';
import { FormEventHandler, useRef, useState } from 'react';

interface Insumo {
    etiqueta: string;
    estado: string;
    detalle: string;
    url: string | null;
    cantidad: number | null;
}

const NIVELES: Record<string, string> = { basico: 'Básico', estandar: 'Estándar', avanzado: 'Avanzado' };

interface Evidencia {
    id: number;
    nombre: string;
    bytes: number;
    subido_por: string | null;
    fecha: string | null;
}

interface Criterio {
    id: number;
    codigo: string;
    pregunta: string;
    niveles: string[];
    aplica: boolean;
    estado: string;
    observaciones: string | null;
    verificado_at: string | null;
    verificado_por: string | null;
    evidencias: Evidencia[];
}

/** Las cuatro respuestas de la Tabla 16. */
const RESPUESTAS: { valor: string; label: string; activo: string }[] = [
    { valor: 'cumple', label: 'Cumple', activo: 'bg-emerald-600 text-white border-emerald-600' },
    { valor: 'no_cumple', label: 'No cumple', activo: 'bg-red-600 text-white border-red-600' },
    { valor: 'no_aplica', label: 'No aplica', activo: 'bg-slate-500 text-white border-slate-500' },
    { valor: 'no_verificado', label: 'No verificado', activo: 'bg-muted text-foreground border-foreground/30' },
];

const peso = (b: number) => (b < 1024 * 1024 ? `${Math.max(1, Math.round(b / 1024))} KB` : `${(b / 1024 / 1024).toFixed(1)} MB`);

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
    criterios?: Criterio[];
    evidencia?: { extensiones: string[]; maxBytes: number };
    vecinos?: { anterior: number | null; siguiente: number | null };
}

/** Icono y color por estado del insumo. */
const INSUMO_ICON = {
    ok: { icon: CheckCircle2, clase: 'text-emerald-600 bg-emerald-600/10' },
    parcial: { icon: CircleDashed, clase: 'text-amber-600 bg-amber-500/10' },
    falta: { icon: CircleAlert, clase: 'text-red-600 bg-red-600/10' },
} as const;

export default function PesvPaso({ needsClient, paso, insumos = [], criterios = [], evidencia, vecinos }: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const page = usePage<SharedData>();
    const notice = useNotice(page.props.flash?.success);

    const form = useForm({
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

                {/* Lista de verificación oficial */}
                <Card>
                    <CardContent className="space-y-4 p-5">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="font-semibold">Lista de verificación</h2>
                                <p className="text-muted-foreground text-sm">
                                    Preguntas de la Tabla 16 de la Res. 40595, las mismas de la visita de verificación. El estado del paso sale de
                                    ellas.
                                </p>
                            </div>
                            <div className="flex items-center gap-2 text-sm">
                                <span className="text-muted-foreground">
                                    {criterios.filter((c) => c.aplica && c.estado === 'cumple').length} de {criterios.filter((c) => c.aplica).length}{' '}
                                    cumplen
                                </span>
                                <EstadoBadge estado={paso.estado} />
                            </div>
                        </div>
                        <ol className="space-y-3">
                            {criterios.map((c) => (
                                <CriterioItem
                                    key={c.id}
                                    criterio={c}
                                    canManage={canManage}
                                    nivelPlan={paso.nivel_plan}
                                    extensiones={evidencia?.extensiones ?? []}
                                    maxBytes={evidencia?.maxBytes ?? 0}
                                />
                            ))}
                        </ol>
                    </CardContent>
                </Card>

                {/* Datos generales del paso */}
                <Card>
                    <CardContent className="p-5">
                        <form onSubmit={guardar} className="space-y-4">
                            <h2 className="font-semibold">Datos del paso</h2>

                            <div className="grid gap-4 md:grid-cols-2">
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
                                <Label htmlFor="observaciones">Observaciones generales</Label>
                                <textarea
                                    id="observaciones"
                                    rows={3}
                                    disabled={!canManage}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm disabled:opacity-60"
                                    placeholder="Notas del paso en conjunto. La evidencia va en cada pregunta."
                                    value={form.data.observaciones}
                                    onChange={(e) => form.setData('observaciones', e.target.value)}
                                />
                                <InputError message={form.errors.observaciones} />
                            </div>

                            {canManage && (
                                <div className="flex justify-end">
                                    <Button type="submit" disabled={form.processing}>
                                        Guardar datos del paso
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

function CriterioItem({
    criterio: c,
    canManage,
    nivelPlan,
    extensiones,
    maxBytes,
}: {
    criterio: Criterio;
    canManage: boolean;
    nivelPlan: string;
    extensiones: string[];
    maxBytes: number;
}) {
    const [obs, setObs] = useState(c.observaciones ?? '');
    const [abierta, setAbierta] = useState(!!c.observaciones);
    const [enviando, setEnviando] = useState<string | null>(null);
    const [subiendo, setSubiendo] = useState(false);
    const [aviso, setAviso] = useState<string | null>(null);
    const archivo = useRef<HTMLInputElement>(null);

    function responder(estado: string) {
        setAviso(null);
        router.post(
            `/pesv/criterio/${c.id}`,
            { estado, observaciones: obs },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setEnviando(estado),
                onFinish: () => setEnviando(null),
                onError: (errs) => setAviso(errs.estado ?? errs.observaciones ?? 'No se pudo guardar la respuesta.'),
            },
        );
    }

    function subir(f: File | null) {
        setAviso(null);
        if (!f) return;
        // El servidor descartaría el POST entero sin decir por qué: se avisa aquí.
        if (maxBytes && f.size > maxBytes) {
            setAviso(`El archivo pesa ${peso(f.size)} y el servidor admite hasta ${peso(maxBytes)}.`);
            return;
        }
        router.post(
            `/pesv/criterio/${c.id}/evidencias`,
            { archivo: f },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSubiendo(true),
                onError: (errs) => setAviso(errs.archivo ?? 'No se pudo subir el archivo.'),
                onFinish: () => {
                    setSubiendo(false);
                    if (archivo.current) archivo.current.value = '';
                },
            },
        );
    }

    return (
        <li className={cn('rounded-lg border p-4', !c.aplica && 'opacity-60')}>
            <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div className="min-w-0 flex-1">
                    <p className="text-sm">
                        <span className="text-primary font-mono font-semibold">{c.codigo}</span> {c.pregunta}
                    </p>
                    <p className="text-muted-foreground mt-1 text-xs">
                        {c.aplica
                            ? `Nivel ${c.niveles.map((n) => NIVELES[n] ?? n).join(', ')}`
                            : `No exigida en nivel ${(NIVELES[nivelPlan] ?? nivelPlan).toLowerCase()}`}
                        {c.verificado_at && ` · verificada el ${c.verificado_at}${c.verificado_por ? ` por ${c.verificado_por}` : ''}`}
                    </p>
                </div>
                <div className="flex shrink-0 flex-wrap gap-1" role="group" aria-label={`Respuesta a la pregunta ${c.codigo}`}>
                    {RESPUESTAS.map((r) => (
                        <button
                            key={r.valor}
                            type="button"
                            disabled={!canManage || enviando !== null}
                            aria-pressed={c.estado === r.valor}
                            onClick={() => c.estado !== r.valor && responder(r.valor)}
                            className={cn(
                                'rounded-md border px-2.5 py-1 text-xs font-medium transition-colors disabled:cursor-not-allowed',
                                c.estado === r.valor ? r.activo : 'text-muted-foreground hover:bg-muted',
                            )}
                        >
                            {enviando === r.valor ? <Loader2 className="size-3.5 animate-spin" /> : r.label}
                        </button>
                    ))}
                </div>
            </div>

            {/* Observación */}
            {abierta ? (
                <div className="mt-3 flex flex-col gap-2">
                    <textarea
                        aria-label={`Observación de la pregunta ${c.codigo}`}
                        rows={2}
                        disabled={!canManage}
                        className="border-input bg-background rounded-md border px-3 py-2 text-sm disabled:opacity-60"
                        placeholder="Qué se revisó y dónde está el soporte."
                        value={obs}
                        onChange={(e) => setObs(e.target.value)}
                    />
                    {canManage && obs !== (c.observaciones ?? '') && (
                        <Button size="sm" variant="secondary" className="self-end" disabled={enviando !== null} onClick={() => responder(c.estado)}>
                            Guardar observación
                        </Button>
                    )}
                </div>
            ) : (
                canManage && (
                    <button type="button" className="text-primary mt-2 text-xs hover:underline" onClick={() => setAbierta(true)}>
                        + Agregar observación
                    </button>
                )
            )}

            {/* Evidencias */}
            <div className="mt-3 flex flex-wrap items-center gap-2">
                {c.evidencias.map((e) => (
                    <span key={e.id} className="bg-muted/50 inline-flex max-w-full items-center gap-1.5 rounded-md border px-2 py-1 text-xs">
                        <a
                            href={`/pesv/evidencias/${e.id}`}
                            className="text-primary inline-flex min-w-0 items-center gap-1 hover:underline"
                            title={`${e.nombre} · ${peso(e.bytes)}${e.subido_por ? ` · ${e.subido_por}` : ''}`}
                        >
                            <Download className="size-3 shrink-0" />
                            <span className="truncate">{e.nombre}</span>
                        </a>
                        {canManage && (
                            <button
                                type="button"
                                aria-label={`Eliminar ${e.nombre}`}
                                onClick={() =>
                                    confirm(`¿Eliminar la evidencia «${e.nombre}»?`) &&
                                    router.delete(`/pesv/evidencias/${e.id}`, { preserveScroll: true, preserveState: true })
                                }
                            >
                                <Trash2 className="size-3 text-red-600" />
                            </button>
                        )}
                    </span>
                ))}
                {canManage && (
                    <>
                        <input
                            ref={archivo}
                            type="file"
                            className="hidden"
                            aria-label={`Evidencia de la pregunta ${c.codigo}`}
                            accept={extensiones.map((x) => '.' + x).join(',')}
                            onChange={(e) => subir(e.target.files?.[0] ?? null)}
                        />
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="h-7 gap-1.5 text-xs"
                            disabled={subiendo}
                            onClick={() => archivo.current?.click()}
                        >
                            {subiendo ? <Loader2 className="size-3.5 animate-spin" /> : <Paperclip className="size-3.5" />} Adjuntar evidencia
                        </Button>
                    </>
                )}
            </div>
            {aviso && <p className="text-destructive mt-1 text-xs">{aviso}</p>}
        </li>
    );
}
