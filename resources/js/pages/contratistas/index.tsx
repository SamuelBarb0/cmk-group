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
import { Link, useForm } from '@inertiajs/react';
import { CalendarClock, CircleAlert, FileWarning, Handshake, Plus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { type Catalogos, COLOR_RESULTADO, puntajeTexto, type Resumen, type Situacion, TIPO_LABEL } from './tipos';

interface Contratista {
    id: number;
    nombre: string;
    nit: string | null;
    tipo: string;
    persona: 'juridica' | 'natural' | null;
    actividad: string | null;
    ciudad: string | null;
    supervisor: string | null;
    is_active: boolean;
    situacion: Situacion;
}

interface Props {
    needsClient: boolean;
    anio: number;
    contratistas: Contratista[];
    stats: { activos: number; sin_evaluar: number; evaluacion_vencida: number; no_confiables: number; documentos_vencidos: number } | null;
    catalogos: Catalogos;
}

const selectCls = 'border-input bg-background h-9 w-full rounded-md border px-2 text-sm';

export default function ContratistasIndex({ needsClient, contratistas, stats, catalogos }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const [abierto, setAbierto] = useState(false);
    const form = useForm({ nombre: '', nit: '', tipo: 'contratista', persona: 'juridica', actividad: '', is_active: true });

    const crear: FormEventHandler = (e) => {
        e.preventDefault();
        form.post('/contratistas');
    };

    const Resultado = ({ r }: { r: Resumen | null }) => {
        if (!r) return <span className="text-muted-foreground text-xs">—</span>;
        const f = catalogos.formatos[r.formato];
        return (
            <div className="flex flex-col items-start gap-0.5">
                <span className={cn('rounded-full border px-2 py-0.5 text-[11px] whitespace-nowrap', COLOR_RESULTADO[r.resultado])}>
                    {catalogos.resultados[r.resultado] ?? r.resultado}
                </span>
                <span className="text-muted-foreground text-[11px] tabular-nums">
                    {f?.escala === 'chequeo'
                        ? r.incumplimientos > 0
                            ? `${r.incumplimientos} incumplimiento(s)`
                            : r.fecha
                        : `${puntajeTexto(f?.escala, r.puntaje, r.porcentaje)} · ${r.fecha}`}
                </span>
            </div>
        );
    };

    return (
        <ModuloPage
            titulo="Contratistas"
            descripcion="Contratistas y proveedores: documentos, selección, requisitos SST y evaluación (estándar 2.10.1)"
            needsClient={needsClient}
            accion={
                canManage && (
                    <Button className="gap-2" onClick={() => setAbierto(true)}>
                        <Plus className="size-4" /> Contratista
                    </Button>
                )
            }
        >
            {stats && (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Contratistas activos" value={stats.activos} icon={Handshake} />
                    <StatCard label="Sin evaluar" value={stats.sin_evaluar} icon={CircleAlert} alerta={stats.sin_evaluar > 0} />
                    <StatCard
                        label={`Evaluación vencida (cada ${catalogos.umbrales.meses} meses)`}
                        value={stats.evaluacion_vencida}
                        icon={CalendarClock}
                        alerta={stats.evaluacion_vencida > 0}
                    />
                    <StatCard
                        label="Documentos vencidos"
                        value={stats.documentos_vencidos}
                        icon={FileWarning}
                        alerta={stats.documentos_vencidos > 0}
                    />
                </div>
            )}

            <Card>
                <CardContent className="p-0">
                    {contratistas.length === 0 ? (
                        <div className="text-muted-foreground p-8 text-center text-sm">No hay contratistas registrados.</div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[56rem] text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2.5 font-medium">Contratista</th>
                                        <th className="px-4 py-2.5 font-medium">Selección</th>
                                        <th className="px-4 py-2.5 font-medium">Requisitos SST</th>
                                        <th className="px-4 py-2.5 font-medium">Última evaluación</th>
                                        <th className="px-4 py-2.5 font-medium">Documentos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {contratistas.map((c) => {
                                        const s = c.situacion;
                                        return (
                                            <tr key={c.id} className={cn('hover:bg-muted/30 border-t align-top', !c.is_active && 'opacity-60')}>
                                                <td className="px-4 py-3">
                                                    <Link href={`/contratistas/${c.id}`} className="font-medium hover:underline">
                                                        {c.nombre}
                                                    </Link>
                                                    <div className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-2 text-xs">
                                                        <Badge variant="outline">{TIPO_LABEL[c.tipo] ?? c.tipo}</Badge>
                                                        {c.persona && (
                                                            <span>{c.persona === 'juridica' ? 'Persona jurídica' : 'Persona natural'}</span>
                                                        )}
                                                        {c.nit && <span className="font-mono">{c.nit}</span>}
                                                        {!c.is_active && <span>· inactivo</span>}
                                                    </div>
                                                    {c.actividad && <div className="text-muted-foreground mt-0.5 text-xs">{c.actividad}</div>}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Resultado r={s.seleccion} />
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Resultado r={s.requisitos_sst} />
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Resultado r={s.evaluacion} />
                                                    {s.proxima_evaluacion && (
                                                        <div
                                                            className={cn(
                                                                'mt-0.5 text-[11px]',
                                                                s.evaluacion_vencida ? 'text-red-700 dark:text-red-400' : 'text-muted-foreground',
                                                            )}
                                                        >
                                                            {s.evaluacion_vencida ? 'Vencida desde' : 'Próxima'} {s.proxima_evaluacion}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-xs">
                                                    {s.documentos_vencidos + s.documentos_por_vencer + s.documentos_pendientes === 0 ? (
                                                        <span className="text-muted-foreground">En regla</span>
                                                    ) : (
                                                        <div className="flex flex-col gap-0.5">
                                                            {s.documentos_vencidos > 0 && (
                                                                <span className="text-red-700 dark:text-red-400">
                                                                    {s.documentos_vencidos} vencido(s)
                                                                </span>
                                                            )}
                                                            {s.documentos_por_vencer > 0 && (
                                                                <span className="text-amber-700 dark:text-amber-400">
                                                                    {s.documentos_por_vencer} por vencer
                                                                </span>
                                                            )}
                                                            {s.documentos_pendientes > 0 && (
                                                                <span className="text-muted-foreground">{s.documentos_pendientes} sin entregar</span>
                                                            )}
                                                        </div>
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
            <p className="text-muted-foreground -mt-2 text-xs">
                Es el mismo registro de contratistas del PESV: lo que se registre aquí aparece allá, y al revés.
            </p>

            <Dialog open={abierto} onOpenChange={setAbierto}>
                <DialogContent>
                    <form onSubmit={crear} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Nuevo contratista</DialogTitle>
                            <DialogDescription>
                                Arranca con la lista de documentos que pide la hoja de selección según el tipo de persona.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-1.5">
                            <Label htmlFor="nombre">Nombre o razón social</Label>
                            <Input id="nombre" value={form.data.nombre} onChange={(e) => form.setData('nombre', e.target.value)} />
                            <InputError message={form.errors.nombre} />
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="nit">NIT o cédula</Label>
                                <Input id="nit" value={form.data.nit} onChange={(e) => form.setData('nit', e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="persona">Tipo de persona</Label>
                                <select
                                    id="persona"
                                    value={form.data.persona}
                                    onChange={(e) => form.setData('persona', e.target.value)}
                                    className={selectCls}
                                >
                                    <option value="juridica">Jurídica</option>
                                    <option value="natural">Natural</option>
                                </select>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="tipo">Relación</Label>
                                <select id="tipo" value={form.data.tipo} onChange={(e) => form.setData('tipo', e.target.value)} className={selectCls}>
                                    {catalogos.tipos.map((t) => (
                                        <option key={t} value={t}>
                                            {TIPO_LABEL[t] ?? t}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="actividad">Bien o servicio que presta</Label>
                                <Input id="actividad" value={form.data.actividad} onChange={(e) => form.setData('actividad', e.target.value)} />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setAbierto(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Crear y abrir la ficha
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ModuloPage>
    );
}
