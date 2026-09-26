import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePartes } from '@/hooks/use-partes';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { HardHat, PenLine, Plus, ShieldAlert, Trash2, Users } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Pestana = 'catalogo' | 'matriz' | 'entregas';

interface Item {
    id: number;
    nombre: string;
    categoria: string;
    norma: string | null;
    uso: string | null;
    vida_util: string | null;
    criterio_reposicion: string | null;
    activo: boolean;
}

interface Asignacion {
    id: number;
    ppe_item_id: number;
    cargo: string;
    area: string | null;
    requerimiento: string;
    observaciones: string | null;
    item?: { id: number; nombre: string; categoria: string } | null;
}

interface Entrega {
    id: number;
    employee_id: number;
    ppe_item_id: number;
    fecha_entrega: string;
    cantidad: number;
    talla: string | null;
    entregado_por: string | null;
    recibido_por: string | null;
    fecha_firma: string | null;
    motivo: string;
    observaciones: string | null;
    /** Calculado por el modelo: sin firma no es evidencia. */
    firmada: boolean;
    employee?: { id: number; nombres: string; apellidos: string; cargo: string | null } | null;
    item?: { id: number; nombre: string; categoria: string } | null;
}

interface EmpleadoRow {
    id: number;
    nombres: string;
    apellidos: string;
    cargo: string | null;
}

interface Props {
    items: Item[];
    matriz: Asignacion[];
    entregas: Entrega[];
    empleados: EmpleadoRow[];
    cargos: string[];
    stats: { items: number; cargos_cubiertos: number; entregas: number; sin_firmar: number };
    catalogos: { categorias: string[]; requerimientos: string[]; motivos: string[] };
    needsClient: boolean;
}

const ETIQUETA_CATEGORIA: Record<string, string> = {
    cabeza: 'Cabeza',
    visual_facial: 'Visual y facial',
    respiratoria: 'Respiratoria',
    auditiva: 'Auditiva',
    manos: 'Manos',
    pies: 'Pies',
    cuerpo: 'Cuerpo',
};

const ETIQUETA_REQUERIMIENTO: Record<string, string> = {
    requerido: 'Requerido',
    segun_necesidad: 'Según necesidad',
};

const hoy = () => new Date().toISOString().slice(0, 10);

const itemVacio = {
    nombre: '',
    categoria: 'cabeza',
    norma: '',
    uso: '',
    vida_util: '',
    criterio_reposicion: '',
    activo: true as boolean,
};

const asignacionVacia = {
    ppe_item_id: '' as number | string,
    cargo: '',
    area: '',
    requerimiento: 'requerido',
    observaciones: '',
};

const entregaVacia = {
    employee_id: '' as number | string,
    ppe_item_id: '' as number | string,
    fecha_entrega: hoy(),
    cantidad: 1,
    talla: '',
    entregado_por: '',
    recibido_por: '',
    fecha_firma: '',
    motivo: 'dotacion',
    observaciones: '',
};

export default function EppIndex({ items, matriz, entregas, empleados, cargos, stats, catalogos, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const { tiene } = usePartes('epp');
    const [pestana, setPestana] = useState<Pestana>('catalogo');

    const [dlgItem, setDlgItem] = useState(false);
    const [editItem, setEditItem] = useState<Item | null>(null);
    const [dlgAsig, setDlgAsig] = useState(false);
    const [dlgEntrega, setDlgEntrega] = useState(false);
    const [editEntrega, setEditEntrega] = useState<Entrega | null>(null);

    const fItem = useForm({ ...itemVacio });
    const fAsig = useForm({ ...asignacionVacia });
    const fEntrega = useForm({ ...entregaVacia });

    // ------------------------------------------------------------- catálogo
    function abrirItem(i?: Item) {
        setEditItem(i ?? null);
        fItem.clearErrors();
        fItem.setData(
            i
                ? {
                      nombre: i.nombre,
                      categoria: i.categoria,
                      norma: i.norma ?? '',
                      uso: i.uso ?? '',
                      vida_util: i.vida_util ?? '',
                      criterio_reposicion: i.criterio_reposicion ?? '',
                      activo: i.activo,
                  }
                : { ...itemVacio },
        );
        setDlgItem(true);
    }

    const guardarItem: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setDlgItem(false) };
        if (editItem) fItem.put(route('epp.items.update', editItem.id), opts);
        else fItem.post(route('epp.items.store'), opts);
    };

    // --------------------------------------------------------------- matriz
    const guardarAsig: FormEventHandler = (e) => {
        e.preventDefault();
        fAsig.post(route('epp.matriz.store'), { preserveScroll: true, onSuccess: () => setDlgAsig(false) });
    };

    // ------------------------------------------------------------- entregas
    function abrirEntrega(en?: Entrega) {
        setEditEntrega(en ?? null);
        fEntrega.clearErrors();
        fEntrega.setData(
            en
                ? {
                      employee_id: en.employee_id,
                      ppe_item_id: en.ppe_item_id,
                      fecha_entrega: en.fecha_entrega,
                      cantidad: en.cantidad,
                      talla: en.talla ?? '',
                      entregado_por: en.entregado_por ?? '',
                      recibido_por: en.recibido_por ?? '',
                      fecha_firma: en.fecha_firma ?? '',
                      motivo: en.motivo,
                      observaciones: en.observaciones ?? '',
                  }
                : { ...entregaVacia },
        );
        setDlgEntrega(true);
    }

    const guardarEntrega: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setDlgEntrega(false) };
        if (editEntrega) fEntrega.put(route('epp.entregas.update', editEntrega.id), opts);
        else fEntrega.post(route('epp.entregas.store'), opts);
    };

    /**
     * Al elegir el trabajador se proponen los EPP que la matriz le asigna a su
     * cargo. Es el punto de toda la matriz: que la entrega no dependa de que
     * quien la registra recuerde qué le toca a cada quien.
     */
    const sugeridos = (() => {
        const emp = empleados.find((e) => String(e.id) === String(fEntrega.data.employee_id));
        if (!emp?.cargo) return [];
        return matriz.filter((m) => m.cargo === emp.cargo);
    })();

    const PESTANAS = (
        [
            { key: 'catalogo', label: 'Catálogo', n: items.length },
            { key: 'matriz', label: 'Matriz por cargo', n: matriz.length },
            { key: 'entregas', label: 'Entregas', n: entregas.length },
        ] as { key: Pestana; label: string; n: number }[]
    ).filter((p) => p.key === 'catalogo' || tiene(p.key));

    return (
        <ModuloPage
            titulo="EPP"
            descripcion="Catálogo, matriz por cargo y entregas"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button
                        onClick={() => (pestana === 'catalogo' ? abrirItem() : pestana === 'matriz' ? setDlgAsig(true) : abrirEntrega())}
                        className="gap-2"
                    >
                        <Plus className="size-4" />
                        {pestana === 'catalogo' ? 'Nuevo elemento' : pestana === 'matriz' ? 'Asignar a un cargo' : 'Nueva entrega'}
                    </Button>
                ) : undefined
            }
            filtros={
                <div className="flex flex-wrap gap-1">
                    {PESTANAS.map((p) => (
                        <button
                            key={p.key}
                            type="button"
                            onClick={() => setPestana(p.key)}
                            className={cn(
                                'rounded-md px-3 py-1.5 text-sm',
                                pestana === p.key ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                            )}
                        >
                            {p.label} <span className="tabular-nums opacity-70">({p.n})</span>
                        </button>
                    ))}
                </div>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Elementos en catálogo" value={stats.items} icon={HardHat} />
                <StatCard label="Cargos con matriz" value={stats.cargos_cubiertos} icon={Users} />
                <StatCard label="Entregas registradas" value={stats.entregas} icon={PenLine} />
                {/* Sin firma, para una auditoría el EPP no se entregó. */}
                <StatCard label="Entregas sin firmar" value={stats.sin_firmar} icon={ShieldAlert} alerta={stats.sin_firmar > 0} />
            </div>

            <Card>
                <CardContent className="p-0">
                    {/* ------------------------------------------------ catálogo */}
                    {pestana === 'catalogo' &&
                        (items.length === 0 ? (
                            <p className="text-muted-foreground p-8 text-center text-sm">
                                El catálogo está vacío. La matriz de EPP de los Excel de CMK trae la lista base.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                        <tr>
                                            <th className="px-4 py-2.5 font-semibold">Elemento</th>
                                            <th className="px-4 py-2.5 font-semibold">Categoría</th>
                                            <th className="px-4 py-2.5 font-semibold">Norma</th>
                                            <th className="px-4 py-2.5 font-semibold">Vida útil</th>
                                            {canManage && <th className="px-4 py-2.5" />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {items.map((i) => (
                                            <tr key={i.id} className={cn('border-t', !i.activo && 'opacity-60')}>
                                                <td className="px-4 py-2.5 font-medium">{i.nombre}</td>
                                                <td className="px-4 py-2.5">{ETIQUETA_CATEGORIA[i.categoria] ?? i.categoria}</td>
                                                <td className="px-4 py-2.5">{i.norma ?? '—'}</td>
                                                <td className="px-4 py-2.5">{i.vida_util ?? '—'}</td>
                                                {canManage && (
                                                    <td className="px-4 py-2.5">
                                                        <div className="flex justify-end gap-1">
                                                            <Button size="icon" variant="ghost" onClick={() => abrirItem(i)} aria-label="Editar">
                                                                <PenLine className="size-4" />
                                                            </Button>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label="Eliminar"
                                                                onClick={() =>
                                                                    confirm(`¿Eliminar ${i.nombre} del catálogo?`) &&
                                                                    router.delete(route('epp.items.destroy', i.id), { preserveScroll: true })
                                                                }
                                                            >
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
                        ))}

                    {/* -------------------------------------------------- matriz */}
                    {pestana === 'matriz' &&
                        (matriz.length === 0 ? (
                            <p className="text-muted-foreground p-8 text-center text-sm">Ningún cargo tiene EPP asignado todavía.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                        <tr>
                                            <th className="px-4 py-2.5 font-semibold">Cargo</th>
                                            <th className="px-4 py-2.5 font-semibold">Elemento</th>
                                            <th className="px-4 py-2.5 font-semibold">Requerimiento</th>
                                            <th className="px-4 py-2.5 font-semibold">Área</th>
                                            {canManage && <th className="px-4 py-2.5" />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {matriz.map((m) => (
                                            <tr key={m.id} className="border-t">
                                                <td className="px-4 py-2.5 font-medium">{m.cargo}</td>
                                                <td className="px-4 py-2.5">{m.item?.nombre ?? '—'}</td>
                                                <td className="px-4 py-2.5">
                                                    <Badge
                                                        className={cn(
                                                            'font-normal',
                                                            m.requerimiento === 'requerido'
                                                                ? 'bg-primary/15 text-primary'
                                                                : 'bg-muted text-muted-foreground',
                                                        )}
                                                    >
                                                        {ETIQUETA_REQUERIMIENTO[m.requerimiento] ?? m.requerimiento}
                                                    </Badge>
                                                </td>
                                                <td className="px-4 py-2.5">{m.area ?? '—'}</td>
                                                {canManage && (
                                                    <td className="px-4 py-2.5">
                                                        <div className="flex justify-end">
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label="Eliminar"
                                                                onClick={() =>
                                                                    confirm('¿Quitar esta asignación?') &&
                                                                    router.delete(route('epp.matriz.destroy', m.id), { preserveScroll: true })
                                                                }
                                                            >
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
                        ))}

                    {/* ------------------------------------------------ entregas */}
                    {pestana === 'entregas' &&
                        (entregas.length === 0 ? (
                            <p className="text-muted-foreground p-8 text-center text-sm">Todavía no hay entregas registradas.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                        <tr>
                                            <th className="px-4 py-2.5 font-semibold">Fecha</th>
                                            <th className="px-4 py-2.5 font-semibold">Trabajador</th>
                                            <th className="px-4 py-2.5 font-semibold">Elemento</th>
                                            <th className="px-4 py-2.5 font-semibold">Cant.</th>
                                            <th className="px-4 py-2.5 font-semibold">Constancia</th>
                                            {canManage && <th className="px-4 py-2.5" />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {entregas.map((en) => (
                                            <tr key={en.id} className="border-t">
                                                <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">{en.fecha_entrega}</td>
                                                <td className="px-4 py-2.5">
                                                    {en.employee ? `${en.employee.apellidos} ${en.employee.nombres}` : '—'}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {en.item?.nombre ?? '—'}
                                                    {en.talla && <span className="text-muted-foreground text-xs"> · talla {en.talla}</span>}
                                                </td>
                                                <td className="px-4 py-2.5 tabular-nums">{en.cantidad}</td>
                                                <td className="px-4 py-2.5">
                                                    {en.firmada ? (
                                                        <span className="text-xs text-emerald-600 dark:text-emerald-500">
                                                            firmada por {en.recibido_por}
                                                        </span>
                                                    ) : (
                                                        <span className="text-destructive text-xs">sin firma</span>
                                                    )}
                                                </td>
                                                {canManage && (
                                                    <td className="px-4 py-2.5">
                                                        <div className="flex justify-end gap-1">
                                                            <Button size="icon" variant="ghost" onClick={() => abrirEntrega(en)} aria-label="Editar">
                                                                <PenLine className="size-4" />
                                                            </Button>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label="Eliminar"
                                                                onClick={() =>
                                                                    confirm('¿Eliminar esta entrega?') &&
                                                                    router.delete(route('epp.entregas.destroy', en.id), { preserveScroll: true })
                                                                }
                                                            >
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
                        ))}
                </CardContent>
            </Card>

            {/* ------------------------------------------------ diálogo: elemento */}
            <Dialog open={dlgItem} onOpenChange={setDlgItem}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>{editItem ? 'Editar elemento' : 'Nuevo elemento'}</DialogTitle>
                        <DialogDescription>Catálogo de EPP de esta empresa.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={guardarItem} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="nombre">Nombre</Label>
                                <Input id="nombre" value={fItem.data.nombre} onChange={(e) => fItem.setData('nombre', e.target.value)} />
                                <InputError message={fItem.errors.nombre} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="categoria">Categoría</Label>
                                <select
                                    id="categoria"
                                    value={fItem.data.categoria}
                                    onChange={(e) => fItem.setData('categoria', e.target.value)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    {catalogos.categorias.map((c) => (
                                        <option key={c} value={c}>
                                            {ETIQUETA_CATEGORIA[c] ?? c}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="norma">Norma</Label>
                                <Input
                                    id="norma"
                                    value={fItem.data.norma}
                                    onChange={(e) => fItem.setData('norma', e.target.value)}
                                    placeholder="NTC 1523, ANSI Z89.1"
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="vida_util">Vida útil</Label>
                                <Input
                                    id="vida_util"
                                    value={fItem.data.vida_util}
                                    onChange={(e) => fItem.setData('vida_util', e.target.value)}
                                    placeholder="Entre 1 a 2 años"
                                />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="uso">Para qué protege</Label>
                            <textarea
                                id="uso"
                                value={fItem.data.uso}
                                onChange={(e) => fItem.setData('uso', e.target.value)}
                                rows={2}
                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="criterio_reposicion">Cuándo se repone</Label>
                            <textarea
                                id="criterio_reposicion"
                                value={fItem.data.criterio_reposicion}
                                onChange={(e) => fItem.setData('criterio_reposicion', e.target.value)}
                                rows={2}
                                placeholder="Cuando se deforme, se rompa o pierda su capacidad de protección."
                                className="border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm"
                            />
                        </div>
                        <DialogFooter className="gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => setDlgItem(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={fItem.processing}>
                                {editItem ? 'Guardar' : 'Agregar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* -------------------------------------------------- diálogo: matriz */}
            <Dialog open={dlgAsig} onOpenChange={setDlgAsig}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Asignar un EPP a un cargo</DialogTitle>
                        <DialogDescription>La matriz se define por cargo, no por persona.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={guardarAsig} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="asig_cargo">Cargo</Label>
                            {/* Los cargos salen de la nómina para que no queden textos
                                que luego no casan con los de `employees`. */}
                            <input
                                id="asig_cargo"
                                list="cargos-nomina"
                                value={fAsig.data.cargo}
                                onChange={(e) => fAsig.setData('cargo', e.target.value)}
                                className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            />
                            <datalist id="cargos-nomina">
                                {cargos.map((c) => (
                                    <option key={c} value={c} />
                                ))}
                            </datalist>
                            <InputError message={fAsig.errors.cargo} />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="asig_item">Elemento</Label>
                                <select
                                    id="asig_item"
                                    value={fAsig.data.ppe_item_id}
                                    onChange={(e) => fAsig.setData('ppe_item_id', e.target.value)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    <option value="">Selecciona…</option>
                                    {items.map((i) => (
                                        <option key={i.id} value={i.id}>
                                            {i.nombre}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={fAsig.errors.ppe_item_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="asig_req">Requerimiento</Label>
                                <select
                                    id="asig_req"
                                    value={fAsig.data.requerimiento}
                                    onChange={(e) => fAsig.setData('requerimiento', e.target.value)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    {catalogos.requerimientos.map((r) => (
                                        <option key={r} value={r}>
                                            {ETIQUETA_REQUERIMIENTO[r] ?? r}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                        <DialogFooter className="gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => setDlgAsig(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={fAsig.processing}>
                                Asignar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ------------------------------------------------ diálogo: entrega */}
            <Dialog open={dlgEntrega} onOpenChange={setDlgEntrega}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>{editEntrega ? 'Editar entrega' : 'Nueva entrega'}</DialogTitle>
                        <DialogDescription>Sin la firma de quien recibe, la entrega no sirve como evidencia.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={guardarEntrega} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="ent_emp">Trabajador</Label>
                            <select
                                id="ent_emp"
                                value={fEntrega.data.employee_id}
                                onChange={(e) => fEntrega.setData('employee_id', e.target.value)}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                <option value="">Selecciona…</option>
                                {empleados.map((e) => (
                                    <option key={e.id} value={e.id}>
                                        {e.apellidos} {e.nombres}
                                        {e.cargo ? ` — ${e.cargo}` : ''}
                                    </option>
                                ))}
                            </select>
                            <InputError message={fEntrega.errors.employee_id} />
                            {sugeridos.length > 0 && (
                                <p className="text-muted-foreground text-xs">
                                    La matriz le asigna a este cargo:{' '}
                                    {sugeridos
                                        .map((s) => s.item?.nombre)
                                        .filter(Boolean)
                                        .join(', ')}
                                    .
                                </p>
                            )}
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="ent_item">Elemento</Label>
                                <select
                                    id="ent_item"
                                    value={fEntrega.data.ppe_item_id}
                                    onChange={(e) => fEntrega.setData('ppe_item_id', e.target.value)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    <option value="">Selecciona…</option>
                                    {items.map((i) => (
                                        <option key={i.id} value={i.id}>
                                            {i.nombre}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={fEntrega.errors.ppe_item_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="ent_cant">Cantidad</Label>
                                <Input
                                    id="ent_cant"
                                    type="number"
                                    min={1}
                                    value={fEntrega.data.cantidad}
                                    onChange={(e) => fEntrega.setData('cantidad', Number(e.target.value))}
                                />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="ent_fecha">Fecha de entrega</Label>
                                <Input
                                    id="ent_fecha"
                                    type="date"
                                    value={fEntrega.data.fecha_entrega}
                                    onChange={(e) => fEntrega.setData('fecha_entrega', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="ent_talla">Talla</Label>
                                <Input id="ent_talla" value={fEntrega.data.talla} onChange={(e) => fEntrega.setData('talla', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="ent_motivo">Motivo</Label>
                                <select
                                    id="ent_motivo"
                                    value={fEntrega.data.motivo}
                                    onChange={(e) => fEntrega.setData('motivo', e.target.value)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm capitalize"
                                >
                                    {catalogos.motivos.map((m) => (
                                        <option key={m} value={m}>
                                            {m}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div className="bg-muted/40 grid gap-3 rounded-md p-3 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="ent_entrega_por">Entregado por</Label>
                                <Input
                                    id="ent_entrega_por"
                                    value={fEntrega.data.entregado_por}
                                    onChange={(e) => fEntrega.setData('entregado_por', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="ent_recibido">Recibido por (firma)</Label>
                                <Input
                                    id="ent_recibido"
                                    value={fEntrega.data.recibido_por}
                                    onChange={(e) => fEntrega.setData('recibido_por', e.target.value)}
                                />
                                <p className="text-muted-foreground text-xs">Si lo dejas vacío, la entrega queda marcada como sin firma.</p>
                            </div>
                        </div>

                        <DialogFooter className="gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => setDlgEntrega(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={fEntrega.processing}>
                                {editEntrega ? 'Guardar' : 'Registrar entrega'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ModuloPage>
    );
}
