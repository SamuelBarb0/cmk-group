import { Notice, SinCliente, useNotice } from '@/components/pesv/shared';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';

interface Nivel {
    siniestros: number;
    tsv: number | null;
    costo: number;
}
type Props =
    | { needsClient: true }
    | {
          needsClient: false;
          anio: number;
          niveles: Record<string, { nombre: string; personas: string; costos: string }>;
          desplazamientos: Record<string, string>;
          piramide: Record<string, Record<string, number>>;
          lineaBase: Record<string, Record<string, number>>;
          trimestres: { trimestre: number | 'año'; km: number | null; niveles: Record<string, Nivel> }[];
          porMes: { actual: number[]; anterior: number[] };
          km: Record<string, number>;
      };

const breadcrumbs = [
    { title: 'PESV', href: '/pesv' },
    { title: 'Análisis estadístico', href: '/pesv/estadistica' },
];
const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
const COLOR: Record<string, string> = { '4': 'bg-red-600', '3': 'bg-orange-500', '2': 'bg-amber-400', '1': 'bg-slate-400' };
const pesos = (v: number) => '$ ' + v.toLocaleString('es-CO', { maximumFractionDigits: 0 });

export default function PesvEstadistica(props: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const notice = useNotice(usePage<SharedData>().props.flash?.success);
    const kmForm = useForm<{ anio: number; km: Record<string, string> }>({
        anio: props.needsClient ? 0 : props.anio,
        km: props.needsClient ? {} : Object.fromEntries([1, 2, 3, 4].map((t) => [t, props.km[t] !== undefined ? String(props.km[t]) : ''])),
    });

    if (props.needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Análisis estadístico de siniestros" />
                <SinCliente titulo="Análisis estadístico de siniestros viales" descripcion="Paso 21 del PESV." />
            </AppLayout>
        );
    }
    const { anio, niveles, desplazamientos, piramide, lineaBase, trimestres, porMes } = props;
    const total = (p: Record<string, Record<string, number>>, n: string) => Object.values(p[n] ?? {}).reduce((a, b) => a + b, 0);
    const max = Math.max(1, ...porMes.actual, ...porMes.anterior);
    const ordenNiveles = ['4', '3', '2', '1'];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Análisis estadístico de siniestros" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Análisis estadístico de siniestros viales</h1>
                        <p className="text-muted-foreground text-sm">
                            Paso 21 · Indicadores 1 (TSV) y 2 ($SV) de la Res. 40595 por nivel de pérdida, con la línea base del año anterior.
                        </p>
                    </div>
                    <select
                        aria-label="Año"
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={anio}
                        onChange={(e) => router.get('/pesv/estadistica', { anio: e.target.value })}
                    >
                        {[0, 1, 2, 3].map((d) => (
                            <option key={d}>{new Date().getFullYear() - d}</option>
                        ))}
                    </select>
                </div>

                <Notice mensaje={notice} />

                {/* Pirámide */}
                <Card>
                    <CardContent className="space-y-3 p-5">
                        <h2 className="font-semibold">Siniestros {anio} por nivel de pérdida</h2>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground text-left">
                                    <tr>
                                        <th className="p-2 font-medium">Nivel</th>
                                        {Object.values(desplazamientos).map((d) => (
                                            <th key={d} className="p-2 text-center font-medium">
                                                {d}
                                            </th>
                                        ))}
                                        <th className="p-2 text-center font-medium">Total</th>
                                        <th className="p-2 text-center font-medium">Línea base {anio - 1}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {ordenNiveles.map((n) => (
                                        <tr key={n}>
                                            <td className="p-2">
                                                <span className={cn('mr-2 inline-block size-2.5 rounded-full', COLOR[n])} />
                                                {n} · {niveles[n].nombre}{' '}
                                                <span className="text-muted-foreground text-xs">({niveles[n].personas})</span>
                                            </td>
                                            {Object.keys(desplazamientos).map((d) => (
                                                <td key={d} className="p-2 text-center tabular-nums">
                                                    {piramide[n]?.[d] ?? 0}
                                                </td>
                                            ))}
                                            <td className="p-2 text-center font-semibold tabular-nums">{total(piramide, n)}</td>
                                            <td className="text-muted-foreground p-2 text-center tabular-nums">{total(lineaBase, n)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* TSV y costos */}
                <Card>
                    <CardContent className="space-y-3 p-5">
                        <h2 className="font-semibold">Tasa de siniestros viales TSV(n) y costos $SV(n)</h2>
                        <p className="text-muted-foreground text-sm">
                            TSV(n) = siniestros del trimestre con nivel de pérdida n × 1.000.000 / kilómetros recorridos por toda la flota en el
                            trimestre. Sin kilómetros del trimestre no se calcula.
                        </p>
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                kmForm.put('/pesv/estadistica/km', { preserveScroll: true });
                            }}
                        >
                            {[1, 2, 3, 4].map((t) => (
                                <label key={t} className="grid gap-1 text-xs">
                                    Km flota T{t}
                                    <Input
                                        type="number"
                                        min={0}
                                        disabled={!canManage}
                                        className="w-36"
                                        value={kmForm.data.km[t] ?? ''}
                                        onChange={(e) => kmForm.setData('km', { ...kmForm.data.km, [t]: e.target.value })}
                                    />
                                </label>
                            ))}
                            {canManage && (
                                <Button type="submit" size="sm" disabled={kmForm.processing}>
                                    Guardar kilómetros
                                </Button>
                            )}
                        </form>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground text-left">
                                    <tr>
                                        <th className="p-2 font-medium">Periodo</th>
                                        <th className="p-2 text-right font-medium">Km</th>
                                        {ordenNiveles.map((n) => (
                                            <th key={n} className="p-2 text-center font-medium">
                                                TSV nivel {n}
                                            </th>
                                        ))}
                                        <th className="p-2 text-right font-medium">$SV total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {trimestres.map((t) => (
                                        <tr key={String(t.trimestre)} className={cn(t.trimestre === 'año' && 'font-semibold')}>
                                            <td className="p-2">{t.trimestre === 'año' ? `Acumulado ${anio}` : `Trimestre ${t.trimestre}`}</td>
                                            <td className="p-2 text-right tabular-nums">{t.km === null ? '—' : t.km.toLocaleString('es-CO')}</td>
                                            {ordenNiveles.map((n) => (
                                                <td key={n} className="p-2 text-center tabular-nums">
                                                    {t.niveles[n].tsv === null ? '—' : t.niveles[n].tsv}
                                                    <span className="text-muted-foreground text-xs"> ({t.niveles[n].siniestros})</span>
                                                </td>
                                            ))}
                                            <td className="p-2 text-right tabular-nums">
                                                {pesos(ordenNiveles.reduce((a, n) => a + t.niveles[n].costo, 0))}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Tendencia */}
                <Card>
                    <CardContent className="space-y-3 p-5">
                        <h2 className="font-semibold">Tendencia mensual</h2>
                        <div className="flex h-40 items-end gap-1.5">
                            {MESES.map((m, i) => (
                                <div key={m} className="flex flex-1 flex-col items-center gap-1">
                                    <div className="flex h-32 w-full items-end justify-center gap-0.5">
                                        <div
                                            className="bg-muted-foreground/30 w-1/2 rounded-t"
                                            style={{ height: `${(porMes.anterior[i] / max) * 100}%` }}
                                            title={`${anio - 1}: ${porMes.anterior[i]}`}
                                        />
                                        <div
                                            className="bg-primary w-1/2 rounded-t"
                                            style={{ height: `${(porMes.actual[i] / max) * 100}%` }}
                                            title={`${anio}: ${porMes.actual[i]}`}
                                        />
                                    </div>
                                    <span className="text-muted-foreground text-[10px]">{m}</span>
                                </div>
                            ))}
                        </div>
                        <p className="text-muted-foreground flex gap-4 text-xs">
                            <span className="flex items-center gap-1">
                                <span className="bg-primary inline-block size-2.5 rounded-sm" /> {anio}
                            </span>
                            <span className="flex items-center gap-1">
                                <span className="bg-muted-foreground/30 inline-block size-2.5 rounded-sm" /> {anio - 1}
                            </span>
                        </p>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
