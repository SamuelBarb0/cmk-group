import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { BookOpen, CheckCircle2, Pencil, Percent, Plus, Scale, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Cumplimiento = 'cumple' | 'parcial' | 'no_cumple';

interface Requisito {
    id: number;
    norma: string;
    anio: number | null;
    articulo: string | null;
    tema: string | null;
    entidad: string | null;
    requisito: string;
    aplica: boolean;
    justificacion_no_aplica: string | null;
    cumplimiento: Cumplimiento;
    forma_cumplimiento: string | null;
    evidencia: string | null;
    responsable: string | null;
    fecha_verificacion: string | null;
    observaciones: string | null;
}

interface Props {
    requisitos: Requisito[];
    stats: { total: number; aplicables: number; cumplidos: number; porcentaje: number };
    catalogos: { cumplimientos: Cumplimiento[] };
    needsClient: boolean;
}

const ETIQUETA: Record<Cumplimiento, string> = {
    cumple: 'Cumple',
    parcial: 'Parcial',
    no_cumple: 'No cumple',
};

const CLS: Record<Cumplimiento, string> = {
    cumple: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    parcial: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    no_cumple: 'bg-destructive/15 text-destructive',
};

const emptyForm = {
    norma: '',
    anio: '' as number | string,
    articulo: '',
    tema: '',
    entidad: '',
    requisito: '',
    aplica: true as boolean,
    justificacion_no_aplica: '',
    cumplimiento: 'no_cumple' as Cumplimiento,
    forma_cumplimiento: '',
    evidencia: '',
    responsable: '',
    fecha_verificacion: '',
    observaciones: '',
};

export default function RequisitosLegalesIndex({ requisitos, stats, catalogos, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Requisito | null>(null);

    const { data, setData, post, put, processing, errors, clearErrors } = useForm({ ...emptyForm });

    function openCreate() {
        setEditing(null);
        clearErrors();
        setData({ ...emptyForm });
        setOpen(true);
    }

    function openEdit(r: Requisito) {
        setEditing(r);
        clearErrors();
        setData({
            norma: r.norma,
            anio: r.anio ?? '',
            articulo: r.articulo ?? '',
            tema: r.tema ?? '',
            entidad: r.entidad ?? '',
            requisito: r.requisito,
            aplica: r.aplica,
            justificacion_no_aplica: r.justificacion_no_aplica ?? '',
            cumplimiento: r.cumplimiento,
            forma_cumplimiento: r.forma_cumplimiento ?? '',
            evidencia: r.evidencia ?? '',
            responsable: r.responsable ?? '',
            fecha_verificacion: r.fecha_verificacion ?? '',
            observaciones: r.observaciones ?? '',
        });
        setOpen(true);
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) put(route('requisitos-legales.update', editing.id), opts);
        else post(route('requisitos-legales.store'), opts);
    };

    function eliminar(r: Requisito) {
        if (confirm(`¿Eliminar el requisito de ${r.norma}?`)) {
            router.delete(route('requisitos-legales.destroy', r.id), { preserveScroll: true });
        }
    }

    return (
        <ModuloPage
            titulo="Requisitos legales"
            descripcion="Matriz de requisitos legales aplicables y su cumplimiento"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button onClick={openCreate} className="gap-2">
                        <Plus className="size-4" /> Nuevo requisito
                    </Button>
                ) : undefined
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="En la matriz" value={stats.total} icon={BookOpen} />
                <StatCard label="Le aplican" value={stats.aplicables} icon={Scale} />
                <StatCard label="Cumplidos" value={stats.cumplidos} icon={CheckCircle2} />
                {/* Es el indicador CUMP-LEG, calculado igual que en Indicadores. */}
                <StatCard
                    label="Cumplimiento legal"
                    value={stats.porcentaje}
                    sufijo="%"
                    icon={Percent}
                    alerta={stats.porcentaje < 100}
                />
            </div>

            <Card>
                <CardContent className="p-0">
                    {requisitos.length === 0 ? (
                        <p className="text-muted-foreground p-8 text-center text-sm">
                            La matriz está vacía. Los Excel de CMK traen una matriz base que se puede cargar aquí.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2.5 font-semibold">Norma</th>
                                        <th className="px-4 py-2.5 font-semibold">Artículo</th>
                                        <th className="px-4 py-2.5 font-semibold">Requisito</th>
                                        <th className="px-4 py-2.5 font-semibold">Aplica</th>
                                        <th className="px-4 py-2.5 font-semibold">Cumplimiento</th>
                                        {canManage && <th className="px-4 py-2.5" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {requisitos.map((r) => (
                                        <tr key={r.id} className={cn('border-t', !r.aplica && 'opacity-60')}>
                                            <td className="px-4 py-2.5">
                                                <div className="font-medium">{r.norma}</div>
                                                {r.tema && <div className="text-muted-foreground text-xs">{r.tema}</div>}
                                            </td>
                                            <td className="px-4 py-2.5">{r.articulo ?? '—'}</td>
                                            <td className="max-w-md px-4 py-2.5">
                                                <div className="truncate">{r.requisito}</div>
                                            </td>
                                            <td className="px-4 py-2.5">{r.aplica ? 'Sí' : 'No'}</td>
                                            <td className="px-4 py-2.5">
                                                {/* Un requisito que no aplica no lleva veredicto: mostrarlo como
                                                    «no cumple» haría pensar que hay un incumplimiento. */}
                                                {r.aplica ? (
                                                    <Badge className={cn('font-normal', CLS[r.cumplimiento])}>
                                                        {ETIQUETA[r.cumplimiento]}
                                                    </Badge>
                                                ) : (
                                                    <span className="text-muted-foreground text-xs">no aplica</span>
                                                )}
                                            </td>
                                            {canManage && (
                                                <td className="px-4 py-2.5">
                                                    <div className="flex justify-end gap-1">
                                                        <Button size="icon" variant="ghost" onClick={() => openEdit(r)} aria-label="Editar">
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button size="icon" variant="ghost" onClick={() => eliminar(r)} aria-label="Eliminar">
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    </div>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </CardContent>
            </Card>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Editar requisito' : 'Nuevo requisito legal'}</DialogTitle>
                        <DialogDescription>
                            Lo que le aplica a la empresa y cómo lo cumple, con su evidencia.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-4">
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="norma">Norma</Label>
                                <Input
                                    id="norma"
                                    value={data.norma}
                                    onChange={(e) => setData('norma', e.target.value)}
                                    placeholder="Decreto 1072 de 2015"
                                />
                                <InputError message={errors.norma} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="anio">Año</Label>
                                <Input id="anio" type="number" value={data.anio} onChange={(e) => setData('anio', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="articulo">Artículo</Label>
                                <Input id="articulo" value={data.articulo} onChange={(e) => setData('articulo', e.target.value)} />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="tema">Tema</Label>
                                <Input id="tema" value={data.tema} onChange={(e) => setData('tema', e.target.value)} placeholder="SG-SST, alturas, PESV…" />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="entidad">Entidad que la expide</Label>
                                <Input id="entidad" value={data.entidad} onChange={(e) => setData('entidad', e.target.value)} placeholder="MinTrabajo" />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="requisito">Qué exige</Label>
                            <textarea
                                id="requisito"
                                value={data.requisito}
                                onChange={(e) => setData('requisito', e.target.value)}
                                rows={2}
                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                            />
                            <InputError message={errors.requisito} />
                        </div>

                        <label className="flex cursor-pointer items-center gap-3">
                            <Checkbox checked={data.aplica} onCheckedChange={(v) => setData('aplica', v === true)} />
                            <span className="text-sm">Le aplica a esta empresa</span>
                        </label>

                        {/* Si no aplica hay que sustentarlo: en una auditoría no basta con omitirlo. */}
                        {!data.aplica ? (
                            <div className="grid gap-2">
                                <Label htmlFor="justificacion_no_aplica">Por qué no le aplica</Label>
                                <textarea
                                    id="justificacion_no_aplica"
                                    value={data.justificacion_no_aplica}
                                    onChange={(e) => setData('justificacion_no_aplica', e.target.value)}
                                    rows={2}
                                    placeholder="La empresa no ejecuta trabajo en alturas."
                                    className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                                />
                                <InputError message={errors.justificacion_no_aplica} />
                            </div>
                        ) : (
                            <>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="grid gap-2">
                                        <Label htmlFor="cumplimiento">Cumplimiento</Label>
                                        <select
                                            id="cumplimiento"
                                            value={data.cumplimiento}
                                            onChange={(e) => setData('cumplimiento', e.target.value as Cumplimiento)}
                                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                        >
                                            {catalogos.cumplimientos.map((c) => (
                                                <option key={c} value={c}>
                                                    {ETIQUETA[c]}
                                                </option>
                                            ))}
                                        </select>
                                        <p className="text-muted-foreground text-xs">
                                            «Parcial» no cuenta como cumplido en el indicador.
                                        </p>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="responsable">Responsable</Label>
                                        <Input id="responsable" value={data.responsable} onChange={(e) => setData('responsable', e.target.value)} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="fecha_verificacion">Verificado el</Label>
                                        <Input
                                            id="fecha_verificacion"
                                            type="date"
                                            value={data.fecha_verificacion}
                                            onChange={(e) => setData('fecha_verificacion', e.target.value)}
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="forma_cumplimiento">Cómo lo cumple</Label>
                                    <textarea
                                        id="forma_cumplimiento"
                                        value={data.forma_cumplimiento}
                                        onChange={(e) => setData('forma_cumplimiento', e.target.value)}
                                        rows={2}
                                        className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="evidencia">Dónde está la evidencia</Label>
                                    <textarea
                                        id="evidencia"
                                        value={data.evidencia}
                                        onChange={(e) => setData('evidencia', e.target.value)}
                                        rows={2}
                                        placeholder="Documento, acta o registro que lo demuestra."
                                        className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                                    />
                                </div>
                            </>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="observaciones">Observaciones</Label>
                            <textarea
                                id="observaciones"
                                value={data.observaciones}
                                onChange={(e) => setData('observaciones', e.target.value)}
                                rows={2}
                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                            />
                        </div>

                        <DialogFooter className="gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {editing ? 'Guardar' : 'Agregar requisito'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ModuloPage>
    );
}
