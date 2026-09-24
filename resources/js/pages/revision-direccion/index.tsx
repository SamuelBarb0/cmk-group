import { SistemaChips, SistemasPicker, type Sistema } from '@/components/control-documental/etiquetas';
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
import { CalendarClock, ListTodo, Plus, Presentation } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Revision {
    id: number;
    codigo: string;
    periodo_desde: string;
    periodo_hasta: string;
    fecha_reunion: string | null;
    sistemas: Sistema[];
    estado: 'borrador' | 'cerrada';
    decisions_count: number;
}

interface Props {
    needsClient: boolean;
    revisiones: Revision[];
    stats: { total: number; pendientes: number; vencidas: number };
}

const hoy = () => new Date().toISOString().slice(0, 10);
const inicioDeAnio = () => `${new Date().getFullYear()}-01-01`;

export default function RevisionDireccionIndex({ needsClient, revisiones, stats }: Props) {
    const { can } = usePermissions();
    const canManage = can('reports.generate');
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        periodo_desde: inicioDeAnio(),
        periodo_hasta: hoy(),
        sistemas: ['sst', 'pesv'] as Sistema[],
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/revision-direccion');
    };

    return (
        <ModuloPage
            titulo="Revisión por la dirección"
            descripcion="La gerencia revisa el sistema con los datos del periodo y deja decisiones con seguimiento"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button className="gap-2" onClick={() => setOpen(true)}>
                        <Plus className="size-4" /> Nueva revisión
                    </Button>
                ) : undefined
            }
        >
            <div className="grid gap-4 sm:grid-cols-3">
                <StatCard label="Revisiones" value={stats.total} icon={Presentation} />
                <StatCard label="Decisiones abiertas" value={stats.pendientes} icon={ListTodo} />
                <StatCard label="Decisiones vencidas" value={stats.vencidas} icon={CalendarClock} alerta={stats.vencidas > 0} />
            </div>

            <Card>
                <CardContent className="p-0">
                    {revisiones.length === 0 ? (
                        <p className="text-muted-foreground p-8 text-center text-sm">
                            Todavía no hay revisiones. La norma pide al menos una al año, con los resultados del periodo.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                <tr>
                                    <th className="px-4 py-2.5 font-semibold">Código</th>
                                    <th className="px-4 py-2.5 font-semibold">Periodo</th>
                                    <th className="px-4 py-2.5 font-semibold">Reunión</th>
                                    <th className="px-4 py-2.5 font-semibold">Normas</th>
                                    <th className="px-4 py-2.5 font-semibold">Decisiones</th>
                                    <th className="px-4 py-2.5 font-semibold">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                {revisiones.map((r) => (
                                    <tr key={r.id} className="hover:bg-muted/30 border-t">
                                        <td className="px-4 py-2.5 font-medium tabular-nums">
                                            <Link href={`/revision-direccion/${r.id}`} className="hover:underline">
                                                {r.codigo}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">
                                            {r.periodo_desde} a {r.periodo_hasta}
                                        </td>
                                        <td className="px-4 py-2.5 tabular-nums">{r.fecha_reunion ?? '—'}</td>
                                        <td className="px-4 py-2.5">
                                            <SistemaChips sistemas={r.sistemas} />
                                        </td>
                                        <td className="px-4 py-2.5 tabular-nums">{r.decisions_count}</td>
                                        <td className="px-4 py-2.5">
                                            <Badge
                                                className={cn(
                                                    'font-normal',
                                                    r.estado === 'cerrada'
                                                        ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                                                        : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {r.estado === 'cerrada' ? 'Cerrada' : 'Borrador'}
                                            </Badge>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </CardContent>
            </Card>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Nueva revisión por la dirección</DialogTitle>
                        <DialogDescription>
                            Elige el periodo que se revisa y las normas. Después se recopilan los datos de los módulos para ese periodo.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="periodo_desde">Desde</Label>
                                <Input
                                    id="periodo_desde"
                                    type="date"
                                    value={data.periodo_desde}
                                    onChange={(e) => setData('periodo_desde', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="periodo_hasta">Hasta</Label>
                                <Input
                                    id="periodo_hasta"
                                    type="date"
                                    value={data.periodo_hasta}
                                    onChange={(e) => setData('periodo_hasta', e.target.value)}
                                />
                                <InputError message={errors.periodo_hasta} />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label>Normas</Label>
                            <SistemasPicker value={data.sistemas} onChange={(v) => setData('sistemas', v)} />
                            <InputError message={errors.sistemas} />
                        </div>
                        <DialogFooter className="gap-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                Crear revisión
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ModuloPage>
    );
}
