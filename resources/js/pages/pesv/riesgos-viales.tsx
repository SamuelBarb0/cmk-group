import { CodigoSig } from '@/components/codigo-sig';
import InputError from '@/components/input-error';
import { Notice, SinCliente, StatCard, useNotice } from '@/components/pesv/shared';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Flame, ListChecks, Pencil, Plus, Trash2, TrendingDown } from 'lucide-react';
import { FormEventHandler, Fragment, useState } from 'react';

interface Riesgo {
    id: number;
    desempeno: string;
    factor: string;
    perfil: string | null;
    cargo: string | null;
    rol_via: string | null;
    tipo_vehiculo: string | null;
    exposicion: number;
    probabilidad: number;
    valor: number;
    nivel: string;
    accion: string | null;
    eficaz: boolean | null;
    observaciones: string | null;
    controles: Record<string, string>;
    lineas: string[];
    fecha_identificacion: string;
    fecha_cierre: string | null;
}
interface Catalogo {
    factores: Record<string, [string, string[]]>;
    roles: Record<string, string>;
    exposicion: Record<string, string>;
    probabilidad: Record<string, string>;
    acciones: Record<string, string>;
    controles: Record<string, string>;
    lineas: Record<string, string>;
}
type Props =
    | { needsClient: true }
    | {
          needsClient: false;
          anio: number;
          riesgos: Riesgo[];
          indicadores: {
              ri_inicio: number;
              ri_fin: number;
              rsvi: number;
              rva_inicio: number;
              rva_fin: number;
              grv: number;
              criticos_abiertos: number;
          };
          catalogo: Catalogo;
      };

const breadcrumbs = [
    { title: 'PESV', href: '/pesv' },
    { title: 'Matriz de riesgos viales', href: '/pesv/riesgos-viales' },
];

const NIVEL: Record<string, { texto: string; clase: string }> = {
    critico: { texto: 'Crítico', clase: 'bg-red-600 text-white' },
    moderado: { texto: 'Moderado', clase: 'bg-amber-500 text-white' },
    bajo: { texto: 'Bajo', clase: 'bg-emerald-600 text-white' },
};
const nivelDe = (v: number) => (v >= 6 ? 'critico' : v >= 3 ? 'moderado' : 'bajo');
const conSigno = (n: number) => (n > 0 ? `+${n}` : String(n));

export default function PesvRiesgosViales(props: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const notice = useNotice(usePage<SharedData>().props.flash?.success);
    const [abierto, setAbierto] = useState(false);
    const [editando, setEditando] = useState<Riesgo | null>(null);
    const hoy = new Date().toISOString().slice(0, 10);
    const form = useForm<{
        desempeno: string;
        factor: string;
        perfil: string;
        cargo: string;
        rol_via: string;
        tipo_vehiculo: string;
        exposicion: string;
        probabilidad: string;
        accion: string;
        controles: Record<string, string>;
        lineas: string[];
        eficaz: string;
        fecha_identificacion: string;
        fecha_cierre: string;
        observaciones: string;
    }>({
        desempeno: 'humano',
        factor: '',
        perfil: '',
        cargo: '',
        rol_via: '',
        tipo_vehiculo: '',
        exposicion: '2',
        probabilidad: '2',
        accion: '',
        controles: {},
        lineas: [],
        eficaz: '',
        fecha_identificacion: hoy,
        fecha_cierre: '',
        observaciones: '',
    });

    if (props.needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Matriz de riesgos viales" />
                <SinCliente titulo="Matriz de riesgos viales" descripcion="Paso 6 del PESV." />
            </AppLayout>
        );
    }
    const { anio, riesgos, indicadores, catalogo } = props;
    const abiertos = riesgos.filter((r) => !r.fecha_cierre);

    function abrir(r: Riesgo | null) {
        setEditando(r);
        form.clearErrors();
        form.setData({
            desempeno: r?.desempeno ?? 'humano',
            factor: r?.factor ?? '',
            perfil: r?.perfil ?? '',
            cargo: r?.cargo ?? '',
            rol_via: r?.rol_via ?? '',
            tipo_vehiculo: r?.tipo_vehiculo ?? '',
            exposicion: String(r?.exposicion ?? 2),
            probabilidad: String(r?.probabilidad ?? 2),
            accion: r?.accion ?? '',
            controles: { ...(r?.controles ?? {}) },
            lineas: [...(r?.lineas ?? [])],
            eficaz: r?.eficaz === null || r?.eficaz === undefined ? '' : r.eficaz ? '1' : '0',
            fecha_identificacion: r?.fecha_identificacion ?? hoy,
            fecha_cierre: r?.fecha_cierre ?? '',
            observaciones: r?.observaciones ?? '',
        });
        setAbierto(true);
    }
    const guardar: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setAbierto(false) };
        form.transform((d) => ({ ...d, eficaz: d.eficaz === '' ? null : d.eficaz === '1' }));
        if (editando) form.put(`/pesv/riesgos-viales/${editando.id}`, opts);
        else form.post('/pesv/riesgos-viales', opts);
    };
    const valor = Number(form.data.exposicion) * Number(form.data.probabilidad);
    const sel = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Matriz de riesgos viales" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            <CodigoSig className="mr-2" />
                            Matriz de riesgos viales
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Paso 6 · RE-SST-45. Nivel de riesgo = exposición × probabilidad (1 a 3), mapa de calor de la Res. 40595.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <select
                            aria-label="Año de los indicadores"
                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            value={anio}
                            onChange={(e) => router.get('/pesv/riesgos-viales', { anio: e.target.value }, { preserveScroll: true })}
                        >
                            {[0, 1, 2].map((d) => (
                                <option key={d}>{new Date().getFullYear() - d}</option>
                            ))}
                        </select>
                        {canManage && (
                            <Button className="gap-2" onClick={() => abrir(null)}>
                                <Plus className="size-4" /> Agregar riesgo
                            </Button>
                        )}
                    </div>
                </div>

                <Notice mensaje={notice} />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Riesgos en la matriz (abiertos)" value={abiertos.length} icon={ListChecks} />
                    <StatCard
                        label="Críticos abiertos"
                        value={indicadores.criticos_abiertos}
                        icon={Flame}
                        danger={indicadores.criticos_abiertos > 0}
                    />
                    <StatCard
                        label={`RSVI ${anio} (${indicadores.ri_inicio} → ${indicadores.ri_fin})`}
                        value={conSigno(indicadores.rsvi)}
                        icon={ListChecks}
                    />
                    <StatCard
                        label={`GRV ${anio} (${indicadores.rva_inicio} → ${indicadores.rva_fin} críticos)`}
                        value={conSigno(indicadores.grv)}
                        icon={TrendingDown}
                    />
                </div>

                {/* Mapa de calor */}
                <Card>
                    <CardContent className="flex flex-wrap items-start gap-6 p-5">
                        <div>
                            <h2 className="mb-2 font-semibold">Mapa de calor (riesgos abiertos)</h2>
                            <div className="grid grid-cols-[auto_repeat(3,3.5rem)] gap-1 text-center text-xs">
                                <span />
                                {[1, 2, 3].map((p) => (
                                    <span key={p} className="text-muted-foreground">
                                        P{p}
                                    </span>
                                ))}
                                {[3, 2, 1].map((e) => (
                                    <Fragment key={e}>
                                        <span className="text-muted-foreground self-center pr-1">E{e}</span>
                                        {[1, 2, 3].map((p) => {
                                            const n = abiertos.filter((r) => r.exposicion === e && r.probabilidad === p).length;
                                            return (
                                                <span
                                                    key={`${e}-${p}`}
                                                    className={cn(
                                                        'flex h-12 flex-col items-center justify-center rounded',
                                                        NIVEL[nivelDe(e * p)].clase,
                                                    )}
                                                >
                                                    <span className="text-base font-bold">{n}</span>
                                                    <span className="opacity-80">{e * p}</span>
                                                </span>
                                            );
                                        })}
                                    </Fragment>
                                ))}
                            </div>
                        </div>
                        <div className="text-muted-foreground max-w-md space-y-1 text-xs">
                            <p>
                                <strong>RSVI</strong> = riesgos identificados al final del año − al inicio. <strong>GRV</strong> = riesgos de
                                valoración alta (críticos) al final − al inicio: un número negativo indica que se están controlando.
                            </p>
                            <p>Un riesgo cuenta mientras está en la matriz: desde su fecha de identificación hasta su fecha de cierre.</p>
                        </div>
                    </CardContent>
                </Card>

                <Card className="overflow-hidden">
                    {riesgos.length === 0 ? (
                        <p className="text-muted-foreground p-6 text-center text-sm">
                            La matriz está vacía. Agrega los riesgos a partir de la encuesta de movilidad y las rutas.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground border-b text-left">
                                    <tr>
                                        <th className="p-3 font-medium">Factor</th>
                                        <th className="p-3 font-medium">Rol / cargo</th>
                                        <th className="p-3 text-center font-medium">E × P</th>
                                        <th className="p-3 font-medium">Nivel</th>
                                        <th className="p-3 font-medium">Controles</th>
                                        <th className="p-3 font-medium">Estado</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {riesgos.map((r) => (
                                        <tr key={r.id} className={cn(r.fecha_cierre && 'opacity-60')}>
                                            <td className="p-3">
                                                <div className="font-medium">{r.factor}</div>
                                                <div className="text-muted-foreground text-xs">{catalogo.factores[r.desempeno]?.[0]}</div>
                                            </td>
                                            <td className="p-3">
                                                {r.rol_via ? catalogo.roles[r.rol_via] : '—'}
                                                {r.cargo && <div className="text-muted-foreground text-xs">{r.cargo}</div>}
                                            </td>
                                            <td className="p-3 text-center tabular-nums">
                                                {r.exposicion} × {r.probabilidad} = {r.valor}
                                            </td>
                                            <td className="p-3">
                                                <span className={cn('rounded px-1.5 py-0.5 text-xs font-semibold', NIVEL[r.nivel].clase)}>
                                                    {NIVEL[r.nivel].texto}
                                                </span>
                                            </td>
                                            <td className="text-muted-foreground p-3 text-xs">
                                                {Object.keys(r.controles)
                                                    .map((k) => catalogo.controles[k])
                                                    .join(', ') || '—'}
                                            </td>
                                            <td className="p-3 text-xs">
                                                {r.fecha_cierre ? `Cerrado ${r.fecha_cierre}` : 'Abierto'}
                                                {r.eficaz !== null && <div>{r.eficaz ? 'Eficaz' : 'No eficaz'}</div>}
                                            </td>
                                            <td className="p-3 text-right whitespace-nowrap">
                                                {canManage && (
                                                    <>
                                                        <Button variant="ghost" size="icon" aria-label="Editar riesgo" onClick={() => abrir(r)}>
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label="Eliminar riesgo"
                                                            onClick={() =>
                                                                confirm(
                                                                    '¿Eliminar este riesgo? Para sacarlo de la matriz conviene cerrarlo: así cuenta en el GRV.',
                                                                ) && router.delete(`/pesv/riesgos-viales/${r.id}`, { preserveScroll: true })
                                                            }
                                                        >
                                                            <Trash2 className="size-4 text-red-600" />
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
                </Card>
            </div>

            <Dialog open={abierto} onOpenChange={setAbierto}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
                    <form onSubmit={guardar}>
                        <DialogHeader>
                            <DialogTitle>{editando ? 'Editar riesgo vial' : 'Agregar riesgo vial'}</DialogTitle>
                        </DialogHeader>
                        <div className="grid gap-4 py-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="r_desempeno">Factor de desempeño</Label>
                                <select
                                    id="r_desempeno"
                                    className={sel}
                                    value={form.data.desempeno}
                                    onChange={(e) => form.setData('desempeno', e.target.value)}
                                >
                                    {Object.entries(catalogo.factores).map(([v, [l]]) => (
                                        <option key={v} value={v}>
                                            {l}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="r_factor">Factor de seguridad vial</Label>
                                <Input
                                    id="r_factor"
                                    list="r_factores"
                                    value={form.data.factor}
                                    onChange={(e) => form.setData('factor', e.target.value)}
                                    placeholder="Elige o escribe"
                                />
                                <datalist id="r_factores">
                                    {catalogo.factores[form.data.desempeno]?.[1].map((f) => <option key={f} value={f} />)}
                                </datalist>
                                <InputError message={form.errors.factor} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="r_rol">Rol en la vía</Label>
                                <select
                                    id="r_rol"
                                    className={sel}
                                    value={form.data.rol_via}
                                    onChange={(e) => form.setData('rol_via', e.target.value)}
                                >
                                    <option value="">—</option>
                                    {Object.entries(catalogo.roles).map(([v, l]) => (
                                        <option key={v} value={v}>
                                            {l}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="r_cargo">Cargo / perfil expuesto</Label>
                                <Input id="r_cargo" value={form.data.cargo} onChange={(e) => form.setData('cargo', e.target.value)} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="r_exp">Nivel de exposición</Label>
                                <select
                                    id="r_exp"
                                    className={sel}
                                    value={form.data.exposicion}
                                    onChange={(e) => form.setData('exposicion', e.target.value)}
                                >
                                    {Object.entries(catalogo.exposicion)
                                        .reverse()
                                        .map(([v, l]) => (
                                            <option key={v} value={v}>
                                                {v} · {l}
                                            </option>
                                        ))}
                                </select>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="r_prob">Nivel de probabilidad</Label>
                                <select
                                    id="r_prob"
                                    className={sel}
                                    value={form.data.probabilidad}
                                    onChange={(e) => form.setData('probabilidad', e.target.value)}
                                >
                                    {Object.entries(catalogo.probabilidad)
                                        .reverse()
                                        .map(([v, l]) => (
                                            <option key={v} value={v}>
                                                {v} · {l}
                                            </option>
                                        ))}
                                </select>
                            </div>
                            <p className="text-sm sm:col-span-2">
                                Valor {valor} ·{' '}
                                <span className={cn('rounded px-1.5 py-0.5 text-xs font-semibold', NIVEL[nivelDe(valor)].clase)}>
                                    {NIVEL[nivelDe(valor)].texto}
                                </span>
                            </p>
                            <div className="grid gap-1.5 sm:col-span-2">
                                <Label htmlFor="r_accion">Acción</Label>
                                <select
                                    id="r_accion"
                                    className={sel}
                                    value={form.data.accion}
                                    onChange={(e) => form.setData('accion', e.target.value)}
                                >
                                    <option value="">—</option>
                                    {Object.entries(catalogo.acciones).map(([v, l]) => (
                                        <option key={v} value={v}>
                                            {l}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2 sm:col-span-2">
                                <Label>Medidas de control</Label>
                                {Object.entries(catalogo.controles).map(([k, l]) => (
                                    <Input
                                        key={k}
                                        aria-label={l}
                                        placeholder={l}
                                        value={form.data.controles[k] ?? ''}
                                        onChange={(e) => form.setData('controles', { ...form.data.controles, [k]: e.target.value })}
                                    />
                                ))}
                            </div>
                            <div className="grid gap-1.5 sm:col-span-2">
                                <Label>Líneas de acción del PESV</Label>
                                <div className="flex flex-wrap gap-3">
                                    {Object.entries(catalogo.lineas).map(([k, l]) => (
                                        <label key={k} className="flex items-center gap-1.5 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={form.data.lineas.includes(k)}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'lineas',
                                                        e.target.checked ? [...form.data.lineas, k] : form.data.lineas.filter((x) => x !== k),
                                                    )
                                                }
                                            />
                                            {l}
                                        </label>
                                    ))}
                                </div>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="r_ident">Fecha de identificación</Label>
                                <Input
                                    id="r_ident"
                                    type="date"
                                    max={hoy}
                                    value={form.data.fecha_identificacion}
                                    onChange={(e) => form.setData('fecha_identificacion', e.target.value)}
                                />
                                <InputError message={form.errors.fecha_identificacion} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="r_cierre">Fecha de cierre</Label>
                                <Input
                                    id="r_cierre"
                                    type="date"
                                    max={hoy}
                                    value={form.data.fecha_cierre}
                                    onChange={(e) => form.setData('fecha_cierre', e.target.value)}
                                />
                                <InputError message={form.errors.fecha_cierre} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="r_eficaz">Eficacia de los controles</Label>
                                <select
                                    id="r_eficaz"
                                    className={sel}
                                    value={form.data.eficaz}
                                    onChange={(e) => form.setData('eficaz', e.target.value)}
                                >
                                    <option value="">Sin evaluar</option>
                                    <option value="1">Eficaz</option>
                                    <option value="0">No eficaz</option>
                                </select>
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
