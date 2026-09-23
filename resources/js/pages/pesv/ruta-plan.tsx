import { Notice, useNotice } from '@/components/pesv/shared';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Plus, Printer, Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';

type Fila = Record<string, string>;
type Plan = {
    campos: Record<string, string | number | boolean>;
    tablas: Record<string, Fila[]>;
};
interface Props {
    ruta: {
        id: number;
        nombre: string;
        origen: string | null;
        destino: string | null;
        tipo_via: string | null;
        distancia_km: number | null;
        duracion_min: number | null;
        frecuencia: string | null;
        horario: string | null;
        peligros: string | null;
        nivel_riesgo: string | null;
    };
    plan: Plan;
    campos: Record<string, [string, 'hora' | 'numero' | 'texto' | 'linea' | 'si_no']>;
    tablas: Record<string, { titulo: string; columnas: Record<string, string>; filas_fijas?: string[] }>;
    completo: boolean;
}

/** Planificación del desplazamiento de una ruta (paso 15, RE-SST-69). Se puede imprimir para llevar en el vehículo. */
export default function PesvRutaPlan({ ruta, plan, campos, tablas, completo }: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const notice = useNotice(usePage<SharedData>().props.flash?.success);
    const form = useForm<Plan>({
        campos: { ...(plan.campos ?? {}) },
        tablas: Object.fromEntries(Object.keys(tablas).map((k) => [k, (plan.tablas?.[k] ?? []).map((f) => ({ ...f }))])),
    });

    const setCampo = (k: string, v: string | boolean) => form.setData('campos', { ...form.data.campos, [k]: v });
    const setCelda = (tabla: string, i: number, col: string, v: string) =>
        form.setData('tablas', { ...form.data.tablas, [tabla]: form.data.tablas[tabla].map((f, j) => (j === i ? { ...f, [col]: v } : f)) });
    const agregar = (tabla: string) => form.setData('tablas', { ...form.data.tablas, [tabla]: [...form.data.tablas[tabla], {}] });
    const quitar = (tabla: string, i: number) =>
        form.setData('tablas', { ...form.data.tablas, [tabla]: form.data.tablas[tabla].filter((_, j) => j !== i) });

    const guardar: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(`/pesv/rutas/${ruta.id}/plan`, { preserveScroll: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'PESV', href: '/pesv' },
                { title: 'Rutas', href: '/pesv/rutas' },
                { title: ruta.nombre, href: `/pesv/rutas/${ruta.id}/plan` },
            ]}
        >
            <Head title={`Plan de desplazamiento · ${ruta.nombre}`} />
            <form onSubmit={guardar} className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-muted-foreground text-sm">PESV · Paso 15 · Planificación de desplazamientos laborales (RE-SST-69)</p>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">{ruta.nombre}</h1>
                        <p className="text-muted-foreground text-sm">
                            {[
                                ruta.origen && ruta.destino ? `${ruta.origen} → ${ruta.destino}` : null,
                                ruta.distancia_km ? `${ruta.distancia_km} km` : null,
                                ruta.duracion_min ? `${ruta.duracion_min} min` : null,
                                ruta.tipo_via,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                        <p className={completo ? 'text-sm text-emerald-700' : 'text-sm text-amber-700'}>
                            {completo
                                ? 'El plan tiene horario, límites de velocidad y apoyo de emergencia.'
                                : 'Faltan datos mínimos: horario de salida, límites de velocidad y apoyo o directorio de emergencia.'}
                        </p>
                    </div>
                    <div className="flex gap-2 print:hidden">
                        <Button type="button" variant="outline" size="sm" className="gap-1" onClick={() => window.print()}>
                            <Printer className="size-4" /> Imprimir
                        </Button>
                        <Button asChild variant="outline" size="sm" className="gap-1">
                            <Link href="/pesv/rutas">
                                <ArrowLeft className="size-4" /> Rutas
                            </Link>
                        </Button>
                    </div>
                </div>

                <Notice mensaje={notice} />

                <Card>
                    <CardContent className="grid gap-4 p-5 sm:grid-cols-2">
                        {Object.entries(campos).map(([k, [etq, tipo]]) =>
                            tipo === 'si_no' ? (
                                <label key={k} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        disabled={!canManage}
                                        checked={form.data.campos[k] === true}
                                        onChange={(e) => setCampo(k, e.target.checked)}
                                    />
                                    {etq}
                                </label>
                            ) : (
                                <div key={k} className={tipo === 'texto' ? 'grid gap-1.5 sm:col-span-2' : 'grid gap-1.5'}>
                                    <Label htmlFor={`c_${k}`}>{etq}</Label>
                                    {tipo === 'texto' ? (
                                        <textarea
                                            id={`c_${k}`}
                                            rows={2}
                                            disabled={!canManage}
                                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                            value={String(form.data.campos[k] ?? '')}
                                            onChange={(e) => setCampo(k, e.target.value)}
                                        />
                                    ) : (
                                        <Input
                                            id={`c_${k}`}
                                            type={tipo === 'hora' ? 'time' : tipo === 'numero' ? 'number' : 'text'}
                                            min={tipo === 'numero' ? 0 : undefined}
                                            disabled={!canManage}
                                            value={String(form.data.campos[k] ?? '')}
                                            onChange={(e) => setCampo(k, e.target.value)}
                                        />
                                    )}
                                </div>
                            ),
                        )}
                    </CardContent>
                </Card>

                {Object.entries(tablas).map(([clave, def]) => (
                    <Card key={clave}>
                        <CardContent className="space-y-2 p-5">
                            <div className="flex items-center justify-between">
                                <h2 className="font-semibold">{def.titulo}</h2>
                                {canManage && !def.filas_fijas && (
                                    <Button type="button" variant="outline" size="sm" className="gap-1 print:hidden" onClick={() => agregar(clave)}>
                                        <Plus className="size-4" /> Fila
                                    </Button>
                                )}
                            </div>
                            {form.data.tablas[clave].length === 0 ? (
                                <p className="text-muted-foreground text-sm">Sin registros.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead className="text-muted-foreground text-left">
                                            <tr>
                                                {Object.values(def.columnas).map((c) => (
                                                    <th key={c} className="p-1.5 font-medium">
                                                        {c}
                                                    </th>
                                                ))}
                                                <th />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {form.data.tablas[clave].map((fila, i) => (
                                                <tr key={i}>
                                                    {Object.entries(def.columnas).map(([col, etq]) => (
                                                        <td key={col} className="p-1">
                                                            {def.filas_fijas && col === 'zona' ? (
                                                                <span className="px-1">{fila.zona}</span>
                                                            ) : (
                                                                <Input
                                                                    aria-label={`${def.titulo}: ${etq}`}
                                                                    disabled={!canManage}
                                                                    className="h-8 min-w-24"
                                                                    value={fila[col] ?? ''}
                                                                    onChange={(e) => setCelda(clave, i, col, e.target.value)}
                                                                />
                                                            )}
                                                        </td>
                                                    ))}
                                                    <td className="p-1 text-right">
                                                        {canManage && !def.filas_fijas && (
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon"
                                                                aria-label="Quitar fila"
                                                                className="print:hidden"
                                                                onClick={() => quitar(clave, i)}
                                                            >
                                                                <Trash2 className="size-4 text-red-600" />
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
                ))}

                {canManage && (
                    <div className="flex justify-end print:hidden">
                        <Button type="submit" disabled={form.processing}>
                            Guardar plan de desplazamiento
                        </Button>
                    </div>
                )}
            </form>
        </AppLayout>
    );
}
