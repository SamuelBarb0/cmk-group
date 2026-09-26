import { CodigoSig } from '@/components/codigo-sig';
import InputError from '@/components/input-error';
import { Notice, SinCliente, useNotice } from '@/components/pesv/shared';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type FilaCronograma = {
    actividad: string;
    costo: number | null;
    programados: number[];
    ejecutados: number[];
};
interface Via {
    id: number;
    nombre: string;
    descripcion: string | null;
    riesgos_criticos: string | null;
    km: number | null;
    tiempo_min: number | null;
    frecuente: boolean;
    veces_mes: number | null;
    plan_accion: string | null;
    activa: boolean;
    anio_cronograma: number | null;
    cronograma: FilaCronograma[];
    cumplimiento: { programados: number; ejecutados: number; porcentaje: number | null };
}
type Props = { needsClient: true } | { needsClient: false; anio: number; vias: Via[]; actividades: string[]; inspecciones: number };

const breadcrumbs = [
    { title: 'PESV', href: '/pesv' },
    { title: 'Vías internas', href: '/pesv/vias-internas' },
];
const MESES = ['E', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];

export default function PesvViasInternas(props: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const notice = useNotice(usePage<SharedData>().props.flash?.success);
    const [abierto, setAbierto] = useState(false);
    const [editando, setEditando] = useState<Via | null>(null);
    const anioActual = new Date().getFullYear();
    const form = useForm<{
        nombre: string;
        descripcion: string;
        riesgos_criticos: string;
        km: string;
        tiempo_min: string;
        frecuente: boolean;
        veces_mes: string;
        plan_accion: string;
        activa: boolean;
        anio_cronograma: number;
        cronograma: FilaCronograma[];
    }>({
        nombre: '',
        descripcion: '',
        riesgos_criticos: '',
        km: '',
        tiempo_min: '',
        frecuente: true,
        veces_mes: '',
        plan_accion: '',
        activa: true,
        anio_cronograma: anioActual,
        cronograma: [],
    });

    if (props.needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Vías internas" />
                <SinCliente titulo="Vías seguras administradas" descripcion="Paso 14 del PESV." />
            </AppLayout>
        );
    }
    const { vias, actividades, inspecciones } = props;

    function abrir(v: Via | null) {
        setEditando(v);
        form.clearErrors();
        form.setData({
            nombre: v?.nombre ?? '',
            descripcion: v?.descripcion ?? '',
            riesgos_criticos: v?.riesgos_criticos ?? '',
            km: v?.km !== null && v?.km !== undefined ? String(v.km) : '',
            tiempo_min: v?.tiempo_min ? String(v.tiempo_min) : '',
            frecuente: v?.frecuente ?? true,
            veces_mes: v?.veces_mes ? String(v.veces_mes) : '',
            plan_accion: v?.plan_accion ?? '',
            activa: v?.activa ?? true,
            anio_cronograma: v?.anio_cronograma ?? anioActual,
            // Las 12 actividades de CMK siempre a la vista; se guardan las que tienen algo.
            cronograma: actividades.map(
                (a) => v?.cronograma.find((f) => f.actividad === a) ?? { actividad: a, costo: null, programados: [], ejecutados: [] },
            ),
        });
        setAbierto(true);
    }
    function marcar(i: number, campo: 'programados' | 'ejecutados', mes: number) {
        const filas = form.data.cronograma.map((f, j) => {
            if (j !== i) return f;
            const lista = f[campo].includes(mes) ? f[campo].filter((m) => m !== mes) : [...f[campo], mes];
            return { ...f, [campo]: lista };
        });
        form.setData('cronograma', filas);
    }
    const guardar: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, cronograma: d.cronograma.filter((f) => f.programados.length || f.ejecutados.length || f.costo) }));
        const opts = { preserveScroll: true, onSuccess: () => setAbierto(false) };
        if (editando) form.put(`/pesv/vias-internas/${editando.id}`, opts);
        else form.post('/pesv/vias-internas', opts);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Vías internas" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            <CodigoSig className="mr-2" />
                            Vías seguras administradas
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Paso 14 · Zonas de conflicto de las vías internas y cronograma de mantenimiento (RE-SST-68). {inspecciones} inspección(es)
                            de vías (FT-INS-VIAS) este año —{' '}
                            <Link href="/formatos" className="underline">
                                diligenciar en Formatos
                            </Link>
                            .
                        </p>
                    </div>
                    {canManage && (
                        <Button className="gap-2" onClick={() => abrir(null)}>
                            <Plus className="size-4" /> Agregar vía
                        </Button>
                    )}
                </div>

                <Notice mensaje={notice} />

                {vias.length === 0 ? (
                    <Card>
                        <CardContent className="text-muted-foreground p-6 text-center text-sm">
                            Sin vías internas. Si la empresa no administra vías ni zonas de circulación, marca las preguntas del paso 14 como «no
                            aplica».
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {vias.map((v) => (
                            <Card key={v.id} className={cn(!v.activa && 'opacity-60')}>
                                <CardContent className="space-y-2 p-5">
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <h2 className="font-semibold">{v.nombre}</h2>
                                            <p className="text-muted-foreground text-xs">
                                                {[
                                                    v.km ? `${v.km} km` : null,
                                                    v.tiempo_min ? `${v.tiempo_min} min` : null,
                                                    v.frecuente ? 'Frecuente' : 'Ocasional',
                                                    v.veces_mes ? `${v.veces_mes} veces/mes` : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </div>
                                        {canManage && (
                                            <div className="flex">
                                                <Button variant="ghost" size="icon" aria-label="Editar vía" onClick={() => abrir(v)}>
                                                    <Pencil className="size-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label="Eliminar vía"
                                                    onClick={() =>
                                                        confirm(`¿Eliminar «${v.nombre}»?`) &&
                                                        router.delete(`/pesv/vias-internas/${v.id}`, { preserveScroll: true })
                                                    }
                                                >
                                                    <Trash2 className="size-4 text-red-600" />
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                    {v.riesgos_criticos && (
                                        <p className="text-sm">
                                            <span className="font-medium">Riesgos críticos:</span> {v.riesgos_criticos}
                                        </p>
                                    )}
                                    {v.plan_accion && (
                                        <p className="text-sm">
                                            <span className="font-medium">Plan de acción:</span> {v.plan_accion}
                                        </p>
                                    )}
                                    <p className="text-muted-foreground text-sm">
                                        Cronograma {v.anio_cronograma ?? '—'}:{' '}
                                        {v.cumplimiento.porcentaje === null
                                            ? 'sin actividades programadas'
                                            : `${v.cumplimiento.ejecutados} de ${v.cumplimiento.programados} actividades-mes ejecutadas (${v.cumplimiento.porcentaje} %)`}
                                    </p>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>

            <Dialog open={abierto} onOpenChange={setAbierto}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                    <form onSubmit={guardar}>
                        <DialogHeader>
                            <DialogTitle>{editando ? 'Editar vía interna' : 'Agregar vía interna'}</DialogTitle>
                        </DialogHeader>
                        <div className="grid gap-4 py-4 sm:grid-cols-2">
                            <div className="grid gap-1.5 sm:col-span-2">
                                <Label htmlFor="v_nombre">Nombre / descripción de la ruta interna</Label>
                                <Input id="v_nombre" value={form.data.nombre} onChange={(e) => form.setData('nombre', e.target.value)} />
                                <InputError message={form.errors.nombre} />
                            </div>
                            <div className="grid gap-1.5 sm:col-span-2">
                                <Label htmlFor="v_riesgos">Riesgos críticos (zonas de conflicto)</Label>
                                <textarea
                                    id="v_riesgos"
                                    rows={2}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                    value={form.data.riesgos_criticos}
                                    onChange={(e) => form.setData('riesgos_criticos', e.target.value)}
                                />
                            </div>
                            <div className="grid grid-cols-3 gap-3 sm:col-span-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="v_km">Km</Label>
                                    <Input
                                        id="v_km"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        value={form.data.km}
                                        onChange={(e) => form.setData('km', e.target.value)}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="v_min">Tiempo (min)</Label>
                                    <Input
                                        id="v_min"
                                        type="number"
                                        min={0}
                                        value={form.data.tiempo_min}
                                        onChange={(e) => form.setData('tiempo_min', e.target.value)}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="v_veces">Veces al mes</Label>
                                    <Input
                                        id="v_veces"
                                        type="number"
                                        min={0}
                                        value={form.data.veces_mes}
                                        onChange={(e) => form.setData('veces_mes', e.target.value)}
                                    />
                                </div>
                            </div>
                            <div className="grid gap-1.5 sm:col-span-2">
                                <Label htmlFor="v_plan">Plan de acción</Label>
                                <textarea
                                    id="v_plan"
                                    rows={2}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                    value={form.data.plan_accion}
                                    onChange={(e) => form.setData('plan_accion', e.target.value)}
                                />
                            </div>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={form.data.frecuente} onChange={(e) => form.setData('frecuente', e.target.checked)} />
                                Ruta frecuente
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={form.data.activa} onChange={(e) => form.setData('activa', e.target.checked)} />
                                Activa
                            </label>
                            <div className="space-y-2 sm:col-span-2">
                                <div className="flex items-center gap-2">
                                    <Label htmlFor="v_anio">Cronograma de mantenimiento</Label>
                                    <Input
                                        id="v_anio"
                                        type="number"
                                        className="h-8 w-24"
                                        value={form.data.anio_cronograma}
                                        onChange={(e) => form.setData('anio_cronograma', Number(e.target.value))}
                                    />
                                    <span className="text-muted-foreground text-xs">P = programado · E = ejecutado</span>
                                </div>
                                <div className="overflow-x-auto rounded-lg border">
                                    <table className="w-full text-xs">
                                        <thead className="bg-muted/50">
                                            <tr>
                                                <th className="p-1.5 text-left font-medium">Actividad</th>
                                                {MESES.map((m, i) => (
                                                    <th key={i} className="p-1 text-center font-medium">
                                                        {m}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {form.data.cronograma.map((f, i) => (
                                                <tr key={f.actividad}>
                                                    <td className="p-1.5">{f.actividad}</td>
                                                    {MESES.map((_, m) => (
                                                        <td key={m} className="p-0.5 text-center">
                                                            <div className="flex flex-col gap-0.5">
                                                                <button
                                                                    type="button"
                                                                    aria-label={`${f.actividad}: programado mes ${m + 1}`}
                                                                    aria-pressed={f.programados.includes(m + 1)}
                                                                    onClick={() => marcar(i, 'programados', m + 1)}
                                                                    className={cn(
                                                                        'rounded border px-1 leading-4',
                                                                        f.programados.includes(m + 1)
                                                                            ? 'bg-blue-600 text-white'
                                                                            : 'text-muted-foreground',
                                                                    )}
                                                                >
                                                                    P
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    aria-label={`${f.actividad}: ejecutado mes ${m + 1}`}
                                                                    aria-pressed={f.ejecutados.includes(m + 1)}
                                                                    onClick={() => marcar(i, 'ejecutados', m + 1)}
                                                                    className={cn(
                                                                        'rounded border px-1 leading-4',
                                                                        f.ejecutados.includes(m + 1)
                                                                            ? 'bg-emerald-600 text-white'
                                                                            : 'text-muted-foreground',
                                                                    )}
                                                                >
                                                                    E
                                                                </button>
                                                            </div>
                                                        </td>
                                                    ))}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setAbierto(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Guardar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
