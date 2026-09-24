import { SistemaChips, SistemasPicker, selectCls, textareaCls, type Sistema } from '@/components/control-documental/etiquetas';
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
import { type SharedData } from '@/types';
import { router, useForm, usePage } from '@inertiajs/react';
import { CircleAlert, Inbox, Library, Megaphone, MessagesSquare, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Tipo = 'interna' | 'externa';

interface Item {
    id: number;
    tipo: Tipo;
    que: string;
    cuando: string;
    a_quien: string;
    como: string;
    responsable: string;
    sistemas: Sistema[];
}

interface Registro {
    id: number;
    fecha: string;
    tipo: Tipo;
    direccion: 'entrante' | 'saliente';
    parte_interesada: string;
    asunto: string;
    medio: string | null;
    responsable: string | null;
    detalle: string | null;
    requiere_respuesta: boolean;
    fecha_limite_respuesta: string | null;
    fecha_respuesta: string | null;
    respuesta: string | null;
    communication_plan_id: number | null;
    pendiente: boolean;
    respuesta_vencida: boolean;
}

interface Props {
    needsClient: boolean;
    matriz: Item[];
    registro: Registro[];
    stats: { matriz: number; registradas: number; pendientes: number; vencidas: number };
}

// Fecha LOCAL: toISOString() da la de UTC, que en Colombia pasa al día
// siguiente desde las 7 p. m.
const hoy = () => new Date().toLocaleDateString('en-CA');

export default function ComunicacionesIndex({ needsClient, matriz, registro, stats }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const errores = usePage<SharedData>().props.errors as Record<string, string | undefined>;
    const [pestana, setPestana] = useState<'matriz' | 'registro'>('matriz');
    const [item, setItem] = useState<Item | 'nuevo' | null>(null);
    const [log, setLog] = useState<Registro | 'nuevo' | null>(null);

    return (
        <ModuloPage
            titulo="Comunicaciones"
            descripcion="Qué se comunica, cuándo, a quién, cómo y quién lo hace, y el registro de lo comunicado"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <div className="flex gap-2">
                        {matriz.length === 0 && (
                            <Button
                                variant="outline"
                                className="gap-2"
                                onClick={() => router.post('/comunicaciones/matriz/base', {}, { preserveScroll: true })}
                            >
                                <Library className="size-4" /> Cargar matriz base
                            </Button>
                        )}
                        <Button className="gap-2" onClick={() => (pestana === 'matriz' ? setItem('nuevo') : setLog('nuevo'))}>
                            <Plus className="size-4" /> {pestana === 'matriz' ? 'Agregar a la matriz' : 'Registrar comunicación'}
                        </Button>
                    </div>
                ) : undefined
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="En la matriz" value={stats.matriz} icon={Megaphone} />
                <StatCard label="Registradas" value={stats.registradas} icon={MessagesSquare} />
                <StatCard label="Esperando respuesta" value={stats.pendientes} icon={Inbox} />
                <StatCard label="Respuestas vencidas" value={stats.vencidas} icon={CircleAlert} alerta={stats.vencidas > 0} />
            </div>

            {errores.matriz && <p className="text-destructive text-sm">{errores.matriz}</p>}

            <div className="flex gap-1 border-b">
                {(['matriz', 'registro'] as const).map((p) => (
                    <button
                        key={p}
                        type="button"
                        onClick={() => setPestana(p)}
                        className={cn(
                            '-mb-px border-b-2 px-4 py-2 text-sm',
                            pestana === p ? 'border-primary font-medium' : 'text-muted-foreground border-transparent',
                        )}
                    >
                        {p === 'matriz' ? 'Matriz de comunicaciones' : 'Registro'}
                    </button>
                ))}
            </div>

            {pestana === 'matriz' ? (
                <Card>
                    <CardContent className="p-0">
                        {matriz.length === 0 ? (
                            <p className="text-muted-foreground p-8 text-center text-sm">
                                La empresa no tiene matriz de comunicaciones. Carga la base y ajústala, o agrega las comunicaciones una a una.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                        <tr>
                                            <th className="px-4 py-2.5 font-semibold">Qué</th>
                                            <th className="px-4 py-2.5 font-semibold">Cuándo</th>
                                            <th className="px-4 py-2.5 font-semibold">A quién</th>
                                            <th className="px-4 py-2.5 font-semibold">Cómo</th>
                                            <th className="px-4 py-2.5 font-semibold">Quién</th>
                                            {canManage && <th className="px-4 py-2.5" />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {matriz.map((m) => (
                                            <tr key={m.id} className="border-t align-top">
                                                <td className="max-w-xs px-4 py-2.5">
                                                    <div>{m.que}</div>
                                                    <div className="mt-1 flex flex-wrap items-center gap-1">
                                                        <Badge variant="outline" className="font-normal capitalize">
                                                            {m.tipo}
                                                        </Badge>
                                                        <SistemaChips sistemas={m.sistemas} />
                                                    </div>
                                                </td>
                                                <td className="px-4 py-2.5">{m.cuando}</td>
                                                <td className="px-4 py-2.5">{m.a_quien}</td>
                                                <td className="px-4 py-2.5">{m.como}</td>
                                                <td className="px-4 py-2.5">{m.responsable}</td>
                                                {canManage && (
                                                    <td className="px-4 py-2.5">
                                                        <div className="flex justify-end gap-1">
                                                            <Button size="icon" variant="ghost" onClick={() => setItem(m)} aria-label="Editar">
                                                                <Pencil className="size-4" />
                                                            </Button>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label="Quitar"
                                                                onClick={() =>
                                                                    confirm('¿Quitar esta comunicación de la matriz?') &&
                                                                    router.delete(`/comunicaciones/matriz/${m.id}`, { preserveScroll: true })
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
                        )}
                    </CardContent>
                </Card>
            ) : (
                <Card>
                    <CardContent className="p-0">
                        {registro.length === 0 ? (
                            <p className="text-muted-foreground p-8 text-center text-sm">
                                Todavía no hay comunicaciones registradas. Registra sobre todo las externas: requerimientos de autoridades, ARL,
                                clientes o comunidad, y su respuesta.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                        <tr>
                                            <th className="px-4 py-2.5 font-semibold">Fecha</th>
                                            <th className="px-4 py-2.5 font-semibold">Parte interesada</th>
                                            <th className="px-4 py-2.5 font-semibold">Asunto</th>
                                            <th className="px-4 py-2.5 font-semibold">Respuesta</th>
                                            {canManage && <th className="px-4 py-2.5" />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {registro.map((r) => (
                                            <tr key={r.id} className="border-t align-top">
                                                <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">{r.fecha}</td>
                                                <td className="px-4 py-2.5">
                                                    {r.parte_interesada}
                                                    <div className="text-muted-foreground text-xs capitalize">
                                                        {r.tipo} · {r.direccion === 'entrante' ? 'recibida' : 'enviada'}
                                                    </div>
                                                </td>
                                                <td className="max-w-md px-4 py-2.5">
                                                    <div className="truncate">{r.asunto}</div>
                                                    {r.medio && <div className="text-muted-foreground text-xs">{r.medio}</div>}
                                                </td>
                                                <td className="px-4 py-2.5 text-xs">
                                                    {!r.requiere_respuesta ? (
                                                        <span className="text-muted-foreground">No requiere</span>
                                                    ) : r.fecha_respuesta ? (
                                                        <span className="text-emerald-700 dark:text-emerald-400">
                                                            Respondida el {r.fecha_respuesta}
                                                        </span>
                                                    ) : (
                                                        <span
                                                            className={
                                                                r.respuesta_vencida ? 'text-destructive' : 'text-amber-600 dark:text-amber-500'
                                                            }
                                                        >
                                                            {r.respuesta_vencida ? 'Vencida' : 'Pendiente'}
                                                            {r.fecha_limite_respuesta && ` · plazo ${r.fecha_limite_respuesta}`}
                                                        </span>
                                                    )}
                                                </td>
                                                {canManage && (
                                                    <td className="px-4 py-2.5">
                                                        <div className="flex justify-end gap-1">
                                                            <Button size="icon" variant="ghost" onClick={() => setLog(r)} aria-label="Editar">
                                                                <Pencil className="size-4" />
                                                            </Button>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label="Eliminar"
                                                                onClick={() =>
                                                                    confirm('¿Eliminar este registro?') &&
                                                                    router.delete(`/comunicaciones/registro/${r.id}`, { preserveScroll: true })
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
                        )}
                    </CardContent>
                </Card>
            )}

            {item && <ItemDialog item={item === 'nuevo' ? null : item} onClose={() => setItem(null)} />}
            {log && <LogDialog registro={log === 'nuevo' ? null : log} matriz={matriz} onClose={() => setLog(null)} />}
        </ModuloPage>
    );
}

function ItemDialog({ item, onClose }: { item: Item | null; onClose: () => void }) {
    const { data, setData, post, put, processing, errors } = useForm({
        tipo: item?.tipo ?? ('interna' as Tipo),
        que: item?.que ?? '',
        cuando: item?.cuando ?? '',
        a_quien: item?.a_quien ?? '',
        como: item?.como ?? '',
        responsable: item?.responsable ?? '',
        sistemas: item?.sistemas ?? (['sst'] as Sistema[]),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (item) put(`/comunicaciones/matriz/${item.id}`, opts);
        else post('/comunicaciones/matriz', opts);
    };

    const campos: [keyof typeof data, string, string][] = [
        ['que', 'Qué se comunica', 'Resultados de indicadores del SG-SST'],
        ['cuando', 'Cuándo', 'Trimestral'],
        ['a_quien', 'A quién', 'Alta dirección y COPASST'],
        ['como', 'Cómo', 'Informe y reunión'],
        ['responsable', 'Quién comunica', 'Responsable del SG-SST'],
    ];

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{item ? 'Editar comunicación' : 'Agregar a la matriz'}</DialogTitle>
                    <DialogDescription>Una fila de la matriz: qué, cuándo, a quién, cómo y quién.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-2">
                        <Label htmlFor="tipo">Tipo</Label>
                        <select id="tipo" value={data.tipo} onChange={(e) => setData('tipo', e.target.value as Tipo)} className={selectCls}>
                            <option value="interna">Interna</option>
                            <option value="externa">Externa</option>
                        </select>
                    </div>
                    {campos.map(([campo, label, placeholder]) => (
                        <div key={campo} className="grid gap-2">
                            <Label htmlFor={campo}>{label}</Label>
                            <Input
                                id={campo}
                                value={data[campo] as string}
                                placeholder={placeholder}
                                onChange={(e) => setData(campo, e.target.value)}
                            />
                            <InputError message={errors[campo]} />
                        </div>
                    ))}
                    <div className="grid gap-2">
                        <Label>Normas</Label>
                        <SistemasPicker value={data.sistemas} onChange={(v) => setData('sistemas', v)} />
                        <InputError message={errors.sistemas} />
                    </div>
                    <DialogFooter className="gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function LogDialog({ registro, matriz, onClose }: { registro: Registro | null; matriz: Item[]; onClose: () => void }) {
    const { data, setData, post, put, processing, errors } = useForm({
        fecha: registro?.fecha ?? hoy(),
        tipo: registro?.tipo ?? ('externa' as Tipo),
        direccion: registro?.direccion ?? ('entrante' as Registro['direccion']),
        parte_interesada: registro?.parte_interesada ?? '',
        asunto: registro?.asunto ?? '',
        medio: registro?.medio ?? '',
        responsable: registro?.responsable ?? '',
        detalle: registro?.detalle ?? '',
        requiere_respuesta: registro?.requiere_respuesta ?? false,
        fecha_limite_respuesta: registro?.fecha_limite_respuesta ?? '',
        fecha_respuesta: registro?.fecha_respuesta ?? '',
        respuesta: registro?.respuesta ?? '',
        communication_plan_id: registro?.communication_plan_id ?? ('' as number | ''),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (registro) put(`/comunicaciones/registro/${registro.id}`, opts);
        else post('/comunicaciones/registro', opts);
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{registro ? 'Editar registro' : 'Registrar comunicación'}</DialogTitle>
                    <DialogDescription>Lo que se envió o se recibió, y la respuesta cuando la pide.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="fecha">Fecha</Label>
                            <Input id="fecha" type="date" value={data.fecha} onChange={(e) => setData('fecha', e.target.value)} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="tipo-log">Tipo</Label>
                            <select id="tipo-log" value={data.tipo} onChange={(e) => setData('tipo', e.target.value as Tipo)} className={selectCls}>
                                <option value="externa">Externa</option>
                                <option value="interna">Interna</option>
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="direccion">Sentido</Label>
                            <select
                                id="direccion"
                                value={data.direccion}
                                onChange={(e) => setData('direccion', e.target.value as Registro['direccion'])}
                                className={selectCls}
                            >
                                <option value="entrante">Recibida</option>
                                <option value="saliente">Enviada</option>
                            </select>
                        </div>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="parte_interesada">Parte interesada</Label>
                            <Input
                                id="parte_interesada"
                                value={data.parte_interesada}
                                placeholder="ARL, Ministerio del Trabajo, cliente…"
                                onChange={(e) => setData('parte_interesada', e.target.value)}
                            />
                            <InputError message={errors.parte_interesada} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="medio">Medio</Label>
                            <Input
                                id="medio"
                                value={data.medio}
                                placeholder="Correo, oficio, llamada…"
                                onChange={(e) => setData('medio', e.target.value)}
                            />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="asunto">Asunto</Label>
                        <Input id="asunto" value={data.asunto} onChange={(e) => setData('asunto', e.target.value)} />
                        <InputError message={errors.asunto} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="responsable">Responsable</Label>
                            <Input id="responsable" value={data.responsable} onChange={(e) => setData('responsable', e.target.value)} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="plan">De la matriz (opcional)</Label>
                            <select
                                id="plan"
                                value={data.communication_plan_id}
                                onChange={(e) => setData('communication_plan_id', e.target.value ? Number(e.target.value) : '')}
                                className={selectCls}
                            >
                                <option value="">—</option>
                                {matriz.map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.que}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <textarea
                        rows={2}
                        value={data.detalle}
                        onChange={(e) => setData('detalle', e.target.value)}
                        placeholder="Detalle"
                        className={textareaCls}
                        aria-label="Detalle"
                    />
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={data.requiere_respuesta} onCheckedChange={(v) => setData('requiere_respuesta', v === true)} />
                        Requiere respuesta
                    </label>
                    {data.requiere_respuesta && (
                        <div className="bg-muted/40 grid gap-3 rounded-md p-3 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="fecha_limite_respuesta">Plazo para responder</Label>
                                <Input
                                    id="fecha_limite_respuesta"
                                    type="date"
                                    value={data.fecha_limite_respuesta}
                                    onChange={(e) => setData('fecha_limite_respuesta', e.target.value)}
                                />
                                <InputError message={errors.fecha_limite_respuesta} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fecha_respuesta">Respondida el</Label>
                                <Input
                                    id="fecha_respuesta"
                                    type="date"
                                    value={data.fecha_respuesta}
                                    onChange={(e) => setData('fecha_respuesta', e.target.value)}
                                />
                                <InputError message={errors.fecha_respuesta} />
                            </div>
                            <textarea
                                rows={2}
                                value={data.respuesta}
                                onChange={(e) => setData('respuesta', e.target.value)}
                                placeholder="Qué se respondió"
                                className={cn(textareaCls, 'sm:col-span-2')}
                                aria-label="Respuesta"
                            />
                        </div>
                    )}
                    <DialogFooter className="gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
