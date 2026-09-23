import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { AlarmClock, CircleAlert, ClipboardList, PenLine, Plus, Trash2, Truck, Wrench, X } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

type Pestana = 'activos' | 'vencimientos' | 'registros';
type Estado = 'al_dia' | 'por_vencer' | 'vencido' | 'sin_registro' | 'sin_lectura' | 'sin_frecuencia';
type Unidad = 'dias' | 'km' | 'horas';

interface ItemPlan {
    id: number;
    actividad: string;
    frecuencia_valor: number | null;
    frecuencia_unidad: Unidad | null;
    responsable: string | null;
    estado: Estado;
    proxima_fecha: string | null;
    proxima_lectura: number | null;
    restante: number | null;
    ultimo: string | null;
}

interface Activo {
    id: number;
    tipo: string;
    nombre: string;
    codigo: string | null;
    marca: string | null;
    modelo: string | null;
    serie: string | null;
    ubicacion: string | null;
    pesv_vehicle_id: number | null;
    placa_pesv: string | null;
    unidad_lectura: 'km' | 'horas' | null;
    lectura_actual: number | null;
    fecha_ingreso: string | null;
    responsable: string | null;
    activo: boolean;
    observaciones: string | null;
    registros: number;
    plan: ItemPlan[];
}

interface Registro {
    id: number;
    maintenance_asset_id: number;
    maintenance_plan_item_id: number | null;
    fecha: string;
    tipo: 'preventivo' | 'correctivo';
    descripcion: string;
    lectura: number | null;
    realizado_por: string | null;
    proveedor_idoneo: boolean | null;
    factura: string | null;
    valor: string | null;
    verificado_por: string | null;
    hallazgos: string | null;
    estado: 'abierta' | 'cerrada';
    asset: { id: number; nombre: string; codigo: string | null } | null;
    plan_item: { id: number; actividad: string } | null;
}

interface Props {
    needsClient: boolean;
    anio: number;
    activos: Activo[];
    registros: Registro[];
    vehiculosSinEnlazar: number;
    stats: {
        activos: number;
        vencidos: number;
        por_vencer: number;
        sin_registro: number;
        correctivos: number;
        preventivos: number;
        costo: number;
        abiertos: number;
        cobertura: number | null;
    };
    catalogos: {
        tipos: Record<string, string>;
        planes: Record<string, { nombre: string; items: { actividad: string; valor: number | null; unidad: Unidad | null }[] }>;
    };
}

const ESTADO: Record<Estado, { label: string; cls: string }> = {
    vencido: { label: 'Vencido', cls: 'border-red-600/40 bg-red-600/10 text-red-700 dark:text-red-400' },
    por_vencer: { label: 'Por vencer', cls: 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-400' },
    al_dia: { label: 'Al día', cls: 'border-green-600/40 bg-green-600/10 text-green-700 dark:text-green-400' },
    sin_registro: { label: 'Sin registro', cls: 'text-muted-foreground' },
    sin_lectura: { label: 'Falta la lectura', cls: 'text-muted-foreground' },
    sin_frecuencia: { label: 'A demanda', cls: 'text-muted-foreground' },
};
const UNIDAD: Record<Unidad, string> = { dias: 'días', km: 'km', horas: 'horas' };
const miles = (n: number) => n.toLocaleString('es-CO');
const pesos = (n: number) => n.toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });

function cuando(p: ItemPlan, unidad: string | null): string {
    if (p.estado === 'sin_frecuencia') return 'Sin frecuencia';
    if (p.estado === 'sin_registro') return 'Nunca registrado';
    if (p.estado === 'sin_lectura') return `Falta la lectura en ${unidad ?? 'km/horas'}`;
    if (p.proxima_fecha) {
        const d = p.restante ?? 0;
        return d < 0 ? `Vencido hace ${-d} días (${p.proxima_fecha})` : `En ${d} días (${p.proxima_fecha})`;
    }
    const r = p.restante ?? 0;
    return r <= 0 ? `Pasado por ${miles(-r)} ${unidad}` : `Faltan ${miles(r)} ${unidad} (a los ${miles(p.proxima_lectura ?? 0)})`;
}

/** Fracción del intervalo que falta (negativa si ya se pasó). */
const urgencia = (p: ItemPlan) => (p.restante ?? 0) / (p.frecuencia_valor || 1);

function EstadoBadge({ estado }: { estado: Estado }) {
    return (
        <span className={cn('inline-flex rounded-full border px-2 py-0.5 text-[11px] whitespace-nowrap', ESTADO[estado].cls)}>
            {ESTADO[estado].label}
        </span>
    );
}

type PlanForm = { id: number | null; actividad: string; frecuencia_valor: string; frecuencia_unidad: Unidad | ''; responsable: string };

const activoVacio = {
    tipo: 'equipo',
    nombre: '',
    codigo: '',
    marca: '',
    modelo: '',
    serie: '',
    ubicacion: '',
    pesv_vehicle_id: null as number | null,
    unidad_lectura: '' as '' | 'km' | 'horas',
    lectura_actual: '',
    fecha_ingreso: '',
    responsable: '',
    activo: true,
    observaciones: '',
    plan: [] as PlanForm[],
};

const hoy = () => new Date().toISOString().slice(0, 10);
const registroVacio = {
    maintenance_asset_id: '' as number | '',
    maintenance_plan_item_id: '' as number | '',
    fecha: hoy(),
    tipo: 'preventivo' as 'preventivo' | 'correctivo',
    descripcion: '',
    lectura: '',
    realizado_por: '',
    proveedor_idoneo: '' as '' | '1' | '0',
    factura: '',
    valor: '',
    verificado_por: '',
    hallazgos: '',
    estado: 'cerrada' as 'abierta' | 'cerrada',
};

const selectCls = 'border-input bg-background h-9 w-full rounded-md border px-2 text-sm';

export default function Mantenimiento({ needsClient, activos, registros, vehiculosSinEnlazar, stats, catalogos, anio }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const [pestana, setPestana] = useState<Pestana>('activos');
    const [editActivo, setEditActivo] = useState<Activo | null>(null);
    const [abiertoActivo, setAbiertoActivo] = useState(false);
    const [editRegistro, setEditRegistro] = useState<Registro | null>(null);
    const [abiertoRegistro, setAbiertoRegistro] = useState(false);
    const [filtroActivo, setFiltroActivo] = useState<number | ''>('');

    const fa = useForm({ ...activoVacio });
    const fr = useForm({ ...registroVacio });

    const vencimientos = useMemo(
        () =>
            activos
                .filter((a) => a.activo)
                .flatMap((a) => a.plan.filter((p) => p.estado === 'vencido' || p.estado === 'por_vencer').map((p) => ({ a, p })))
                // Primero lo vencido; dentro de cada grupo, lo más urgente. Se compara la
                // FRACCIÓN del intervalo que queda, no el número: 3 días y 400 km no se
                // pueden restar entre sí.
                .sort((x, y) => (x.p.estado === y.p.estado ? urgencia(x.p) - urgencia(y.p) : x.p.estado === 'vencido' ? -1 : 1)),
        [activos],
    );

    const abrirActivo = (a?: Activo) => {
        fa.clearErrors();
        setEditActivo(a ?? null);
        fa.setData(
            a
                ? {
                      tipo: a.tipo,
                      nombre: a.nombre,
                      codigo: a.codigo ?? '',
                      marca: a.marca ?? '',
                      modelo: a.modelo ?? '',
                      serie: a.serie ?? '',
                      ubicacion: a.ubicacion ?? '',
                      pesv_vehicle_id: a.pesv_vehicle_id,
                      unidad_lectura: a.unidad_lectura ?? '',
                      lectura_actual: a.lectura_actual?.toString() ?? '',
                      fecha_ingreso: a.fecha_ingreso ?? '',
                      responsable: a.responsable ?? '',
                      activo: a.activo,
                      observaciones: a.observaciones ?? '',
                      plan: a.plan.map((p) => ({
                          id: p.id,
                          actividad: p.actividad,
                          frecuencia_valor: p.frecuencia_valor?.toString() ?? '',
                          frecuencia_unidad: p.frecuencia_unidad ?? '',
                          responsable: p.responsable ?? '',
                      })),
                  }
                : { ...activoVacio, plan: [] },
        );
        setAbiertoActivo(true);
    };

    const setPlan = (i: number, campo: keyof PlanForm, v: string) =>
        fa.setData(
            'plan',
            fa.data.plan.map((p, j) => (j === i ? { ...p, [campo]: v } : p)),
        );

    const cargarPlanTipo = (clave: string) => {
        const plan = catalogos.planes[clave];
        if (!plan) return;
        const nuevos: PlanForm[] = plan.items.map((i) => ({
            id: null,
            actividad: i.actividad,
            frecuencia_valor: i.valor?.toString() ?? '',
            frecuencia_unidad: i.unidad ?? '',
            responsable: '',
        }));
        fa.setData((d) => ({
            ...d,
            plan: [...d.plan, ...nuevos],
            // Un plan por km exige que el activo lleve la lectura en km.
            unidad_lectura: nuevos.some((n) => n.frecuencia_unidad === 'km') && !d.unidad_lectura ? 'km' : d.unidad_lectura,
        }));
    };

    const guardarActivo: FormEventHandler = (e) => {
        e.preventDefault();
        fa.transform((d) => ({
            ...d,
            unidad_lectura: d.unidad_lectura || null,
            lectura_actual: d.lectura_actual === '' ? null : Number(d.lectura_actual),
            fecha_ingreso: d.fecha_ingreso || null,
            plan: d.plan
                .filter((p) => p.actividad.trim() !== '')
                .map((p) => ({
                    ...p,
                    frecuencia_valor: p.frecuencia_valor === '' ? null : Number(p.frecuencia_valor),
                    frecuencia_unidad: p.frecuencia_unidad || null,
                })),
        }));
        const opts = { preserveScroll: true, onSuccess: () => setAbiertoActivo(false) };
        if (editActivo) fa.put(`/mantenimiento/activos/${editActivo.id}`, opts);
        else fa.post('/mantenimiento/activos', opts);
    };

    const abrirRegistro = (r?: Registro, activo?: Activo, item?: ItemPlan) => {
        fr.clearErrors();
        setEditRegistro(r ?? null);
        fr.setData(
            r
                ? {
                      maintenance_asset_id: r.maintenance_asset_id,
                      maintenance_plan_item_id: r.maintenance_plan_item_id ?? '',
                      fecha: r.fecha,
                      tipo: r.tipo,
                      descripcion: r.descripcion,
                      lectura: r.lectura?.toString() ?? '',
                      realizado_por: r.realizado_por ?? '',
                      proveedor_idoneo: r.proveedor_idoneo === null ? '' : r.proveedor_idoneo ? '1' : '0',
                      factura: r.factura ?? '',
                      valor: r.valor ?? '',
                      verificado_por: r.verificado_por ?? '',
                      hallazgos: r.hallazgos ?? '',
                      estado: r.estado,
                  }
                : {
                      ...registroVacio,
                      fecha: hoy(),
                      maintenance_asset_id: activo?.id ?? '',
                      maintenance_plan_item_id: item?.id ?? '',
                      descripcion: item?.actividad ?? '',
                      lectura: activo?.lectura_actual?.toString() ?? '',
                  },
        );
        setAbiertoRegistro(true);
    };

    const guardarRegistro: FormEventHandler = (e) => {
        e.preventDefault();
        fr.transform((d) => ({
            ...d,
            maintenance_plan_item_id: d.maintenance_plan_item_id === '' ? null : d.maintenance_plan_item_id,
            lectura: d.lectura === '' ? null : Number(d.lectura),
            valor: d.valor === '' ? null : Number(d.valor),
            proveedor_idoneo: d.proveedor_idoneo === '' ? null : d.proveedor_idoneo === '1',
        }));
        const opts = { preserveScroll: true, onSuccess: () => setAbiertoRegistro(false) };
        if (editRegistro) fr.put(`/mantenimiento/registros/${editRegistro.id}`, opts);
        else fr.post('/mantenimiento/registros', opts);
    };

    const activoDelRegistro = activos.find((a) => a.id === fr.data.maintenance_asset_id);
    const registrosFiltrados = filtroActivo === '' ? registros : registros.filter((r) => r.maintenance_asset_id === filtroActivo);

    const PESTANAS: { key: Pestana; label: string; n: number }[] = [
        { key: 'activos', label: 'Activos', n: activos.length },
        { key: 'vencimientos', label: 'Vencimientos', n: vencimientos.length },
        { key: 'registros', label: 'Registros', n: registros.length },
    ];

    return (
        <ModuloPage
            titulo="Mantenimiento"
            descripcion="Inventario de activos, plan de mantenimiento y registro de lo realizado (PASO 17 del PESV)"
            needsClient={needsClient}
            accion={
                canManage && (
                    <div className="flex flex-wrap gap-2">
                        {vehiculosSinEnlazar > 0 && (
                            <Button
                                variant="outline"
                                className="gap-2"
                                onClick={() => router.post('/mantenimiento/activos/importar-vehiculos', {}, { preserveScroll: true })}
                            >
                                <Truck className="size-4" /> Traer {vehiculosSinEnlazar} vehículo(s) del PESV
                            </Button>
                        )}
                        <Button variant="outline" className="gap-2" onClick={() => abrirActivo()}>
                            <Plus className="size-4" /> Activo
                        </Button>
                        <Button className="gap-2" onClick={() => abrirRegistro()} disabled={activos.length === 0}>
                            <Wrench className="size-4" /> Registrar mantenimiento
                        </Button>
                    </div>
                )
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Activos en uso" value={stats.activos} icon={ClipboardList} />
                <StatCard label="Mantenimientos vencidos" value={stats.vencidos} icon={CircleAlert} alerta={stats.vencidos > 0} />
                <StatCard label="Por vencer" value={stats.por_vencer} icon={AlarmClock} />
                <StatCard
                    label={`Cobertura ${anio} (activos con plan atendidos)`}
                    value={stats.cobertura ?? '—'}
                    sufijo={stats.cobertura !== null ? ' %' : ''}
                    icon={Wrench}
                />
            </div>
            <p className="text-muted-foreground -mt-2 text-xs">
                {anio}: {stats.preventivos} preventivos · {stats.correctivos} correctivos · {pesos(stats.costo)} en mantenimiento
                {stats.abiertos > 0 && <> · {stats.abiertos} orden(es) abierta(s)</>}
                {stats.sin_registro > 0 && <> · {stats.sin_registro} ítem(s) del plan nunca registrados</>}
            </p>

            <div className="flex flex-wrap gap-1 rounded-lg border p-1">
                {PESTANAS.map((p) => (
                    <button
                        key={p.key}
                        type="button"
                        onClick={() => setPestana(p.key)}
                        className={cn('rounded-md px-3 py-1.5 text-sm', pestana === p.key ? 'bg-primary text-primary-foreground' : 'hover:bg-muted')}
                    >
                        {p.label} <span className="tabular-nums opacity-70">({p.n})</span>
                    </button>
                ))}
            </div>

            {pestana === 'activos' && (
                <Card>
                    <CardContent className="p-0">
                        {activos.length === 0 ? (
                            <div className="text-muted-foreground p-8 text-center text-sm">
                                No hay activos todavía.
                                {vehiculosSinEnlazar > 0 && ' Puedes traer los vehículos que ya están en el PESV.'}
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[46rem] text-sm">
                                    <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                        <tr>
                                            <th className="px-4 py-2.5 font-medium">Activo</th>
                                            <th className="px-4 py-2.5 font-medium">Ubicación / lectura</th>
                                            <th className="px-4 py-2.5 font-medium">Plan</th>
                                            <th className="w-32 px-4 py-2.5" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {activos.map((a) => {
                                            const n = (e: Estado) => a.plan.filter((p) => p.estado === e).length;
                                            return (
                                                <tr key={a.id} className={cn('border-t', !a.activo && 'opacity-60')}>
                                                    <td className="px-4 py-3">
                                                        <div className="font-medium">{a.nombre}</div>
                                                        <div className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-2 text-xs">
                                                            <Badge variant="outline">{catalogos.tipos[a.tipo] ?? a.tipo}</Badge>
                                                            {a.codigo && <span className="font-mono">{a.codigo}</span>}
                                                            {a.placa_pesv && <span>· enlazado al PESV</span>}
                                                            {!a.activo && <span>· fuera de uso</span>}
                                                        </div>
                                                    </td>
                                                    <td className="text-muted-foreground px-4 py-3 text-xs">
                                                        {a.ubicacion ?? '—'}
                                                        {a.unidad_lectura && (
                                                            <div className="tabular-nums">
                                                                {a.lectura_actual !== null
                                                                    ? `${miles(a.lectura_actual)} ${a.unidad_lectura}`
                                                                    : `sin lectura (${a.unidad_lectura})`}
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {a.plan.length === 0 ? (
                                                            <span className="text-muted-foreground text-xs">Sin plan</span>
                                                        ) : (
                                                            <div className="flex flex-wrap gap-1.5">
                                                                {(['vencido', 'por_vencer', 'al_dia', 'sin_registro'] as Estado[])
                                                                    .filter((e) => n(e) > 0)
                                                                    .map((e) => (
                                                                        <span
                                                                            key={e}
                                                                            className={cn(
                                                                                'rounded-full border px-2 py-0.5 text-[11px]',
                                                                                ESTADO[e].cls,
                                                                            )}
                                                                        >
                                                                            {n(e)} {ESTADO[e].label.toLowerCase()}
                                                                        </span>
                                                                    ))}
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-right whitespace-nowrap">
                                                        {canManage && (
                                                            <>
                                                                <Button
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    onClick={() => abrirRegistro(undefined, a)}
                                                                    title="Registrar mantenimiento"
                                                                >
                                                                    <Wrench className="size-4" />
                                                                </Button>
                                                                <Button
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    onClick={() => abrirActivo(a)}
                                                                    title="Editar activo y plan"
                                                                >
                                                                    <PenLine className="size-4" />
                                                                </Button>
                                                                <Button
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    title="Eliminar activo"
                                                                    onClick={() =>
                                                                        confirm(
                                                                            `¿Eliminar «${a.nombre}» con su plan y sus ${a.registros} registro(s)?`,
                                                                        ) && router.delete(`/mantenimiento/activos/${a.id}`, { preserveScroll: true })
                                                                    }
                                                                >
                                                                    <Trash2 className="size-4" />
                                                                </Button>
                                                            </>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            )}

            {pestana === 'vencimientos' && (
                <Card>
                    <CardContent className="p-0">
                        {vencimientos.length === 0 ? (
                            <div className="text-muted-foreground p-8 text-center text-sm">No hay mantenimientos vencidos ni por vencer.</div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[40rem] text-sm">
                                    <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                        <tr>
                                            <th className="px-4 py-2.5 font-medium">Activo</th>
                                            <th className="px-4 py-2.5 font-medium">Actividad</th>
                                            <th className="px-4 py-2.5 font-medium">Cuándo</th>
                                            <th className="w-16 px-4 py-2.5" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {vencimientos.map(({ a, p }) => (
                                            <tr key={p.id} className="border-t">
                                                <td className="px-4 py-2.5">
                                                    {a.nombre}
                                                    {a.codigo && <span className="text-muted-foreground font-mono text-xs"> · {a.codigo}</span>}
                                                </td>
                                                <td className="px-4 py-2.5">{p.actividad}</td>
                                                <td className="px-4 py-2.5">
                                                    <div className="flex items-center gap-2">
                                                        <EstadoBadge estado={p.estado} />
                                                        <span className="text-muted-foreground text-xs">{cuando(p, a.unidad_lectura)}</span>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-2.5 text-right">
                                                    {canManage && (
                                                        <Button size="sm" variant="outline" onClick={() => abrirRegistro(undefined, a, p)}>
                                                            Registrar
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            )}

            {pestana === 'registros' && (
                <Card>
                    <div className="flex items-center gap-2 border-b px-4 py-3">
                        <Label htmlFor="filtro-activo" className="text-muted-foreground text-xs">
                            Activo
                        </Label>
                        <select
                            id="filtro-activo"
                            value={filtroActivo}
                            onChange={(e) => setFiltroActivo(e.target.value === '' ? '' : Number(e.target.value))}
                            className="border-input bg-background h-8 max-w-72 rounded-md border px-2 text-sm"
                        >
                            <option value="">Todos</option>
                            {activos.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.nombre}
                                    {a.codigo ? ` · ${a.codigo}` : ''}
                                </option>
                            ))}
                        </select>
                    </div>
                    <CardContent className="p-0">
                        {registrosFiltrados.length === 0 ? (
                            <div className="text-muted-foreground p-8 text-center text-sm">Sin mantenimientos registrados.</div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[48rem] text-sm">
                                    <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                        <tr>
                                            <th className="px-4 py-2.5 font-medium">Fecha</th>
                                            <th className="px-4 py-2.5 font-medium">Activo</th>
                                            <th className="px-4 py-2.5 font-medium">Mantenimiento</th>
                                            <th className="px-4 py-2.5 font-medium">Proveedor</th>
                                            <th className="px-4 py-2.5 text-right font-medium">Valor</th>
                                            <th className="w-24 px-4 py-2.5" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {registrosFiltrados.map((r) => (
                                            <tr key={r.id} className="border-t align-top">
                                                <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">{r.fecha}</td>
                                                <td className="px-4 py-2.5">
                                                    {r.asset?.nombre}
                                                    {r.lectura !== null && (
                                                        <div className="text-muted-foreground text-xs tabular-nums">a los {miles(r.lectura)}</div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        <Badge variant={r.tipo === 'correctivo' ? 'destructive' : 'outline'}>{r.tipo}</Badge>
                                                        {r.estado === 'abierta' && <Badge variant="secondary">abierta</Badge>}
                                                    </div>
                                                    <div className="mt-1">{r.descripcion}</div>
                                                    {r.plan_item && r.plan_item.actividad !== r.descripcion && (
                                                        <div className="text-muted-foreground text-xs">Plan: {r.plan_item.actividad}</div>
                                                    )}
                                                </td>
                                                <td className="text-muted-foreground px-4 py-2.5 text-xs">
                                                    {r.realizado_por ?? '—'}
                                                    {r.proveedor_idoneo === false && (
                                                        <div className="text-red-700 dark:text-red-400">proveedor no idóneo</div>
                                                    )}
                                                    {r.factura && <div>Factura {r.factura}</div>}
                                                </td>
                                                <td className="px-4 py-2.5 text-right tabular-nums">
                                                    {r.valor !== null ? pesos(Number(r.valor)) : '—'}
                                                </td>
                                                <td className="px-4 py-2.5 text-right whitespace-nowrap">
                                                    {canManage && (
                                                        <>
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() => abrirRegistro(r)}
                                                                title="Editar registro"
                                                            >
                                                                <PenLine className="size-4" />
                                                            </Button>
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                title="Eliminar registro"
                                                                onClick={() =>
                                                                    confirm('¿Eliminar este registro de mantenimiento?') &&
                                                                    router.delete(`/mantenimiento/registros/${r.id}`, { preserveScroll: true })
                                                                }
                                                            >
                                                                <Trash2 className="size-4" />
                                                            </Button>
                                                        </>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            )}

            {/* ------------------------------------------------ activo + plan */}
            <Dialog open={abiertoActivo} onOpenChange={setAbiertoActivo}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <form onSubmit={guardarActivo} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>{editActivo ? 'Editar activo' : 'Nuevo activo'}</DialogTitle>
                            <DialogDescription>
                                El plan dice qué se le hace y cada cuánto. Por días, o por uso (km u horas) si el activo lleva lectura.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="tipo">Tipo</Label>
                                <select id="tipo" value={fa.data.tipo} onChange={(e) => fa.setData('tipo', e.target.value)} className={selectCls}>
                                    {Object.entries(catalogos.tipos).map(([k, v]) => (
                                        <option key={k} value={k}>
                                            {v}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label htmlFor="nombre">Nombre</Label>
                                <Input id="nombre" value={fa.data.nombre} onChange={(e) => fa.setData('nombre', e.target.value)} />
                                <InputError message={fa.errors.nombre} />
                            </div>
                            {(
                                [
                                    ['codigo', 'Código o placa'],
                                    ['marca', 'Marca'],
                                    ['modelo', 'Modelo'],
                                    ['serie', 'Serie'],
                                    ['ubicacion', 'Ubicación'],
                                    ['responsable', 'Responsable'],
                                ] as const
                            ).map(([k, l]) => (
                                <div key={k} className="space-y-1.5">
                                    <Label htmlFor={k}>{l}</Label>
                                    <Input id={k} value={fa.data[k]} onChange={(e) => fa.setData(k, e.target.value)} />
                                </div>
                            ))}
                            <div className="space-y-1.5">
                                <Label htmlFor="unidad_lectura">Lectura por uso</Label>
                                <select
                                    id="unidad_lectura"
                                    value={fa.data.unidad_lectura}
                                    onChange={(e) => fa.setData('unidad_lectura', e.target.value as '' | 'km' | 'horas')}
                                    className={selectCls}
                                >
                                    <option value="">No lleva</option>
                                    <option value="km">Kilómetros</option>
                                    <option value="horas">Horas de uso</option>
                                </select>
                                <InputError message={fa.errors.unidad_lectura} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="lectura_actual">Lectura actual</Label>
                                <Input
                                    id="lectura_actual"
                                    type="number"
                                    min={0}
                                    disabled={!fa.data.unidad_lectura}
                                    value={fa.data.lectura_actual}
                                    onChange={(e) => fa.setData('lectura_actual', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="fecha_ingreso">Fecha de ingreso</Label>
                                <Input
                                    id="fecha_ingreso"
                                    type="date"
                                    value={fa.data.fecha_ingreso}
                                    onChange={(e) => fa.setData('fecha_ingreso', e.target.value)}
                                />
                            </div>
                            <label className="flex items-center gap-2 self-end pb-2 text-sm">
                                <input type="checkbox" checked={fa.data.activo} onChange={(e) => fa.setData('activo', e.target.checked)} />
                                En uso
                            </label>
                        </div>
                        <InputError message={fa.errors.pesv_vehicle_id} />

                        <div className="rounded-lg border">
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-2">
                                <span className="text-sm font-medium">Plan de mantenimiento</span>
                                <div className="flex flex-wrap items-center gap-2">
                                    <select
                                        aria-label="Cargar plan tipo"
                                        value=""
                                        onChange={(e) => cargarPlanTipo(e.target.value)}
                                        className="border-input bg-background h-8 rounded-md border px-2 text-xs"
                                    >
                                        <option value="">Cargar plan tipo…</option>
                                        {Object.entries(catalogos.planes).map(([k, p]) => (
                                            <option key={k} value={k}>
                                                {p.nombre}
                                            </option>
                                        ))}
                                    </select>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        className="h-8 gap-1"
                                        onClick={() =>
                                            fa.setData('plan', [
                                                ...fa.data.plan,
                                                { id: null, actividad: '', frecuencia_valor: '', frecuencia_unidad: 'dias', responsable: '' },
                                            ])
                                        }
                                    >
                                        <Plus className="size-3.5" /> Ítem
                                    </Button>
                                </div>
                            </div>
                            {fa.data.plan.length === 0 ? (
                                <p className="text-muted-foreground p-3 text-xs">
                                    Sin ítems. Sin plan, el activo solo lleva registros de correctivos.
                                </p>
                            ) : (
                                <div className="divide-y">
                                    {fa.data.plan.map((p, i) => (
                                        <div key={i} className="grid grid-cols-[1fr_auto_auto_auto] items-start gap-2 px-3 py-2">
                                            <div>
                                                <Input
                                                    value={p.actividad}
                                                    placeholder="Actividad"
                                                    aria-label="Actividad del plan"
                                                    onChange={(e) => setPlan(i, 'actividad', e.target.value)}
                                                    className="h-8 text-sm"
                                                />
                                                <InputError message={(fa.errors as Record<string, string>)[`plan.${i}.actividad`]} />
                                                <InputError message={(fa.errors as Record<string, string>)[`plan.${i}.frecuencia_valor`]} />
                                            </div>
                                            <span className="text-muted-foreground pt-1.5 text-xs">cada</span>
                                            <div className="flex gap-1">
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    value={p.frecuencia_valor}
                                                    placeholder="—"
                                                    aria-label="Frecuencia"
                                                    onChange={(e) => setPlan(i, 'frecuencia_valor', e.target.value)}
                                                    className="h-8 w-24 text-sm"
                                                />
                                                <select
                                                    aria-label="Unidad de la frecuencia"
                                                    value={p.frecuencia_unidad}
                                                    onChange={(e) => setPlan(i, 'frecuencia_unidad', e.target.value)}
                                                    className="border-input bg-background h-8 rounded-md border px-1 text-xs"
                                                >
                                                    <option value="">a demanda</option>
                                                    {(Object.keys(UNIDAD) as Unidad[]).map((u) => (
                                                        <option key={u} value={u}>
                                                            {UNIDAD[u]}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <button
                                                type="button"
                                                aria-label="Quitar ítem"
                                                onClick={() =>
                                                    fa.setData(
                                                        'plan',
                                                        fa.data.plan.filter((_, j) => j !== i),
                                                    )
                                                }
                                                className="text-muted-foreground hover:text-destructive pt-1.5"
                                            >
                                                <X className="size-4" />
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setAbiertoActivo(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={fa.processing}>
                                Guardar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ------------------------------------------------------ registro */}
            <Dialog open={abiertoRegistro} onOpenChange={setAbiertoRegistro}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <form onSubmit={guardarRegistro} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>{editRegistro ? 'Editar mantenimiento' : 'Registrar mantenimiento'}</DialogTitle>
                            <DialogDescription>Si corresponde a un ítem del plan, elígelo: de ahí sale cuándo toca el siguiente.</DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="r-activo">Activo</Label>
                                <select
                                    id="r-activo"
                                    value={fr.data.maintenance_asset_id}
                                    onChange={(e) => {
                                        const id = e.target.value === '' ? '' : Number(e.target.value);
                                        const a = activos.find((x) => x.id === id);
                                        fr.setData((d) => ({
                                            ...d,
                                            maintenance_asset_id: id,
                                            maintenance_plan_item_id: '',
                                            lectura: a?.lectura_actual?.toString() ?? '',
                                        }));
                                    }}
                                    className={selectCls}
                                >
                                    <option value="">Elige un activo</option>
                                    {activos.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.nombre}
                                            {a.codigo ? ` · ${a.codigo}` : ''}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={fr.errors.maintenance_asset_id} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="r-item">Ítem del plan</Label>
                                <select
                                    id="r-item"
                                    value={fr.data.maintenance_plan_item_id}
                                    onChange={(e) => {
                                        const id = e.target.value === '' ? '' : Number(e.target.value);
                                        const item = activoDelRegistro?.plan.find((p) => p.id === id);
                                        fr.setData((d) => ({
                                            ...d,
                                            maintenance_plan_item_id: id,
                                            tipo: id === '' ? d.tipo : 'preventivo',
                                            descripcion: d.descripcion || item?.actividad || '',
                                        }));
                                    }}
                                    className={selectCls}
                                    disabled={!activoDelRegistro}
                                >
                                    <option value="">Fuera del plan</option>
                                    {activoDelRegistro?.plan.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.actividad}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={fr.errors.maintenance_plan_item_id} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="r-fecha">Fecha</Label>
                                <Input
                                    id="r-fecha"
                                    type="date"
                                    value={fr.data.fecha}
                                    max={hoy()}
                                    onChange={(e) => fr.setData('fecha', e.target.value)}
                                />
                                <InputError message={fr.errors.fecha} />
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="r-tipo">Tipo</Label>
                                    <select
                                        id="r-tipo"
                                        value={fr.data.tipo}
                                        onChange={(e) => fr.setData('tipo', e.target.value as 'preventivo' | 'correctivo')}
                                        className={selectCls}
                                    >
                                        <option value="preventivo">Preventivo</option>
                                        <option value="correctivo">Correctivo</option>
                                    </select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="r-estado">Orden</Label>
                                    <select
                                        id="r-estado"
                                        value={fr.data.estado}
                                        onChange={(e) => fr.setData('estado', e.target.value as 'abierta' | 'cerrada')}
                                        className={selectCls}
                                    >
                                        <option value="cerrada">Cerrada</option>
                                        <option value="abierta">Abierta</option>
                                    </select>
                                </div>
                            </div>
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label htmlFor="r-desc">Descripción de lo realizado</Label>
                                <textarea
                                    id="r-desc"
                                    rows={2}
                                    value={fr.data.descripcion}
                                    onChange={(e) => fr.setData('descripcion', e.target.value)}
                                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                                />
                                <InputError message={fr.errors.descripcion} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="r-lectura">
                                    Lectura {activoDelRegistro?.unidad_lectura ? `(${activoDelRegistro.unidad_lectura})` : ''}
                                </Label>
                                <Input
                                    id="r-lectura"
                                    type="number"
                                    min={0}
                                    disabled={!activoDelRegistro?.unidad_lectura}
                                    value={fr.data.lectura}
                                    onChange={(e) => fr.setData('lectura', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="r-realizado">Realizado por (proveedor o persona)</Label>
                                <Input id="r-realizado" value={fr.data.realizado_por} onChange={(e) => fr.setData('realizado_por', e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="r-idoneo">¿Proveedor idóneo?</Label>
                                <select
                                    id="r-idoneo"
                                    value={fr.data.proveedor_idoneo}
                                    onChange={(e) => fr.setData('proveedor_idoneo', e.target.value as '' | '1' | '0')}
                                    className={selectCls}
                                >
                                    <option value="">Sin evaluar</option>
                                    <option value="1">Cumple los requisitos</option>
                                    <option value="0">No cumple</option>
                                </select>
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="r-factura">Factura</Label>
                                    <Input id="r-factura" value={fr.data.factura} onChange={(e) => fr.setData('factura', e.target.value)} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="r-valor">Valor</Label>
                                    <Input
                                        id="r-valor"
                                        type="number"
                                        min={0}
                                        value={fr.data.valor}
                                        onChange={(e) => fr.setData('valor', e.target.value)}
                                    />
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="r-verificado">Verificado por</Label>
                                <Input
                                    id="r-verificado"
                                    value={fr.data.verificado_por}
                                    onChange={(e) => fr.setData('verificado_por', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1.5 sm:col-span-2">
                                <Label htmlFor="r-hallazgos">Hallazgos u observaciones</Label>
                                <textarea
                                    id="r-hallazgos"
                                    rows={2}
                                    value={fr.data.hallazgos}
                                    onChange={(e) => fr.setData('hallazgos', e.target.value)}
                                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setAbiertoRegistro(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={fr.processing}>
                                Guardar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ModuloPage>
    );
}
