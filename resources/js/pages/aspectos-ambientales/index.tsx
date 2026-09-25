import { selectCls, textareaCls } from '@/components/control-documental/etiquetas';
import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { CircleAlert, FileOutput, Leaf, Library, Pencil, Plus, Siren, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Condicion = 'normal' | 'anormal' | 'emergencia';

interface Aspecto {
    id: number;
    process_id: number | null;
    actividad: string;
    aspecto: string;
    impacto: string;
    tipo_impacto: 'negativo' | 'positivo';
    condicion: Condicion;
    etapa: string;
    frecuencia: number;
    severidad: number;
    valor: number;
    significativo: boolean;
    requisito_legal: boolean;
    preocupa_partes: boolean;
    controles: string | null;
    process: { id: number; sigla: string; nombre: string } | null;
    acpm_action: { id: number; codigo: string; estado: string } | null;
}

interface Props {
    needsClient: boolean;
    aspectos: Aspecto[];
    procesos: { id: number; sigla: string; nombre: string }[];
    stats: { total: number; significativos: number; emergencia: number; sin_control: number };
    documento: { titulo: string; id: number | null; codigo: string | null; estado: string | null } | null;
    etapas: Record<string, string>;
    umbral: number;
}

const CONDICION: Record<Condicion, string> = { normal: 'Normal', anormal: 'Anormal', emergencia: 'Emergencia' };
const ESCALA_F = ['Muy rara', 'Rara', 'Ocasional', 'Frecuente', 'Permanente'];
const ESCALA_S = ['Insignificante', 'Leve', 'Moderada', 'Grave', 'Muy grave'];

/** Por qué un aspecto es significativo, en palabras. */
function razones(a: Aspecto, umbral: number): string[] {
    const r: string[] = [];
    if (a.valor >= umbral) r.push(`puntaje ${a.valor}`);
    if (a.requisito_legal) r.push('requisito legal');
    if (a.preocupa_partes) r.push('preocupa a partes interesadas');
    if (a.condicion === 'emergencia' && a.severidad >= 4) r.push('emergencia grave');
    return r;
}

export default function AspectosAmbientales({ needsClient, aspectos, procesos, stats, documento, etapas, umbral }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const errores = usePage<SharedData>().props.errors as Record<string, string | undefined>;
    const [editar, setEditar] = useState<Aspecto | 'nuevo' | null>(null);
    const [accion, setAccion] = useState<Aspecto | null>(null);
    const [soloSignificativos, setSoloSignificativos] = useState(false);

    const lista = soloSignificativos ? aspectos.filter((a) => a.significativo) : aspectos;

    return (
        <ModuloPage
            titulo="Aspectos ambientales"
            descripcion="Aspectos e impactos de las actividades con perspectiva de ciclo de vida y su significancia (ISO 14001 6.1.2)"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <div className="flex flex-wrap gap-2">
                        {aspectos.length === 0 && (
                            <Button
                                variant="outline"
                                className="gap-2"
                                onClick={() => router.post('/aspectos-ambientales/base', {}, { preserveScroll: true })}
                            >
                                <Library className="size-4" /> Cargar aspectos típicos
                            </Button>
                        )}
                        <Button className="gap-2" onClick={() => setEditar('nuevo')}>
                            <Plus className="size-4" /> Nuevo aspecto
                        </Button>
                    </div>
                ) : undefined
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Aspectos" value={stats.total} icon={Leaf} />
                <StatCard label="Significativos" value={stats.significativos} icon={CircleAlert} />
                <StatCard label="En emergencia" value={stats.emergencia} icon={Siren} />
                <StatCard label="Significativos sin controles" value={stats.sin_control} icon={CircleAlert} alerta={stats.sin_control > 0} />
            </div>

            <Card>
                <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                    <p className="text-muted-foreground max-w-2xl text-xs">
                        Valor = frecuencia × severidad. Un aspecto negativo es significativo con {umbral} o más, si tiene un requisito legal asociado,
                        si preocupa a las partes interesadas, o si es una emergencia de severidad 4 o 5 (es rara por definición: pesa su severidad).
                        Los significativos van a los objetivos, a los controles operacionales y al plan de emergencias.
                    </p>
                    {documento && (
                        <div className="flex items-center gap-3">
                            <div className="text-right text-xs">
                                {documento.codigo ? (
                                    <Link href={`/control-documental/${documento.id}`} className="underline underline-offset-2">
                                        {documento.codigo} · {documento.estado?.replace('_', ' ')}
                                    </Link>
                                ) : (
                                    <span className="text-muted-foreground">Aún no está en el control documental</span>
                                )}
                            </div>
                            {canManage && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="gap-1.5"
                                    onClick={() => router.post('/aspectos-ambientales/enviar', {}, { preserveScroll: true })}
                                >
                                    <FileOutput className="size-3.5" /> Enviar como borrador
                                </Button>
                            )}
                        </div>
                    )}
                </CardContent>
            </Card>
            {(errores.matriz || errores.documento || errores.estado || errores.acpm) && (
                <p className="text-destructive text-sm">{errores.matriz ?? errores.documento ?? errores.estado ?? errores.acpm}</p>
            )}

            <label className="flex items-center gap-2 text-sm">
                <Checkbox checked={soloSignificativos} onCheckedChange={(v) => setSoloSignificativos(v === true)} />
                Ver solo los significativos
            </label>

            <Card>
                <CardContent className="p-0">
                    {lista.length === 0 ? (
                        <p className="text-muted-foreground p-8 text-center text-sm">
                            {aspectos.length === 0
                                ? 'Sin aspectos. Carga los típicos y ajústalos, o agrégalos uno a uno.'
                                : 'Ningún aspecto significativo.'}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2.5 font-semibold">Actividad</th>
                                        <th className="px-4 py-2.5 font-semibold">Aspecto → impacto</th>
                                        <th className="px-4 py-2.5 font-semibold">Condición y ciclo de vida</th>
                                        <th className="px-4 py-2.5 font-semibold">Significancia</th>
                                        <th className="px-4 py-2.5 font-semibold">Controles</th>
                                        {canManage && <th className="px-4 py-2.5" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {lista.map((a) => (
                                        <tr key={a.id} className="border-t align-top">
                                            <td className="max-w-[14rem] px-4 py-2.5">
                                                <div>{a.actividad}</div>
                                                {a.process && <div className="text-muted-foreground text-xs">{a.process.sigla}</div>}
                                            </td>
                                            <td className="max-w-sm px-4 py-2.5">
                                                <div className="font-medium">{a.aspecto}</div>
                                                <div className="text-muted-foreground text-xs">
                                                    → {a.impacto}
                                                    {a.tipo_impacto === 'positivo' && ' (positivo)'}
                                                </div>
                                            </td>
                                            <td className="px-4 py-2.5 text-xs">
                                                <div className={cn(a.condicion === 'emergencia' && 'text-destructive font-medium')}>
                                                    {CONDICION[a.condicion]}
                                                </div>
                                                <div className="text-muted-foreground">{etapas[a.etapa] ?? a.etapa}</div>
                                            </td>
                                            <td className="px-4 py-2.5 text-xs whitespace-nowrap">
                                                <span
                                                    className={cn(
                                                        'rounded px-1.5 py-0.5 font-medium',
                                                        a.significativo
                                                            ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300'
                                                            : 'bg-muted text-muted-foreground',
                                                    )}
                                                >
                                                    {a.significativo ? 'Significativo' : 'No significativo'}
                                                </span>
                                                <div className="text-muted-foreground mt-1 tabular-nums">
                                                    F{a.frecuencia} × S{a.severidad} = {a.valor}
                                                </div>
                                                {a.significativo && <div className="text-muted-foreground">por {razones(a, umbral).join(', ')}</div>}
                                            </td>
                                            <td className="max-w-xs px-4 py-2.5 text-xs">
                                                {a.controles ?? (a.significativo ? <span className="text-destructive">Sin controles</span> : '—')}
                                                <div className="mt-1">
                                                    {a.acpm_action ? (
                                                        <Link href="/acpm" className="text-muted-foreground underline underline-offset-2">
                                                            {a.acpm_action.codigo} · {a.acpm_action.estado.replace('_', ' ')}
                                                        </Link>
                                                    ) : (
                                                        canManage &&
                                                        a.significativo && (
                                                            <button
                                                                type="button"
                                                                className="text-primary underline underline-offset-2"
                                                                onClick={() => setAccion(a)}
                                                            >
                                                                Crear acción en ACPM
                                                            </button>
                                                        )
                                                    )}
                                                </div>
                                            </td>
                                            {canManage && (
                                                <td className="px-4 py-2.5">
                                                    <div className="flex justify-end gap-1">
                                                        <Button size="icon" variant="ghost" onClick={() => setEditar(a)} aria-label="Editar">
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            aria-label="Eliminar"
                                                            onClick={() =>
                                                                confirm('¿Eliminar este aspecto?') &&
                                                                router.delete(`/aspectos-ambientales/${a.id}`, { preserveScroll: true })
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

            {editar && (
                <AspectoDialog aspecto={editar === 'nuevo' ? null : editar} procesos={procesos} etapas={etapas} onClose={() => setEditar(null)} />
            )}
            {accion && <AccionDialog aspecto={accion} onClose={() => setAccion(null)} />}
        </ModuloPage>
    );
}

type AspectoForm = {
    process_id: number | '';
    actividad: string;
    aspecto: string;
    impacto: string;
    tipo_impacto: 'negativo' | 'positivo';
    condicion: Condicion;
    etapa: string;
    frecuencia: number;
    severidad: number;
    requisito_legal: boolean;
    preocupa_partes: boolean;
    controles: string;
};

function AspectoDialog({
    aspecto,
    procesos,
    etapas,
    onClose,
}: {
    aspecto: Aspecto | null;
    procesos: Props['procesos'];
    etapas: Record<string, string>;
    onClose: () => void;
}) {
    const { data, setData, post, put, processing, errors } = useForm<AspectoForm>({
        process_id: aspecto?.process_id ?? '',
        actividad: aspecto?.actividad ?? '',
        aspecto: aspecto?.aspecto ?? '',
        impacto: aspecto?.impacto ?? '',
        tipo_impacto: aspecto?.tipo_impacto ?? 'negativo',
        condicion: aspecto?.condicion ?? 'normal',
        etapa: aspecto?.etapa ?? 'operacion',
        frecuencia: aspecto?.frecuencia ?? 3,
        severidad: aspecto?.severidad ?? 3,
        requisito_legal: aspecto?.requisito_legal ?? false,
        preocupa_partes: aspecto?.preocupa_partes ?? false,
        controles: aspecto?.controles ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (aspecto) put(`/aspectos-ambientales/${aspecto.id}`, opts);
        else post('/aspectos-ambientales', opts);
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{aspecto ? 'Editar aspecto' : 'Nuevo aspecto ambiental'}</DialogTitle>
                    <DialogDescription>Qué actividad, qué elemento interactúa con el ambiente y qué cambio le produce.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="actividad">Actividad</Label>
                            <Input id="actividad" value={data.actividad} onChange={(e) => setData('actividad', e.target.value)} />
                            <InputError message={errors.actividad} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="proceso">Proceso</Label>
                            <select
                                id="proceso"
                                value={data.process_id}
                                onChange={(e) => setData('process_id', e.target.value ? Number(e.target.value) : '')}
                                className={selectCls}
                            >
                                <option value="">—</option>
                                {procesos.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.sigla} · {p.nombre}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="aspecto">Aspecto</Label>
                            <Input
                                id="aspecto"
                                value={data.aspecto}
                                placeholder="Consumo de energía, generación de RESPEL…"
                                onChange={(e) => setData('aspecto', e.target.value)}
                            />
                            <InputError message={errors.aspecto} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="impacto">Impacto</Label>
                            <Input
                                id="impacto"
                                value={data.impacto}
                                placeholder="Agotamiento de recursos, contaminación del suelo…"
                                onChange={(e) => setData('impacto', e.target.value)}
                            />
                            <InputError message={errors.impacto} />
                        </div>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="tipo_impacto">Tipo de impacto</Label>
                            <select
                                id="tipo_impacto"
                                value={data.tipo_impacto}
                                onChange={(e) => setData('tipo_impacto', e.target.value as AspectoForm['tipo_impacto'])}
                                className={selectCls}
                            >
                                <option value="negativo">Negativo</option>
                                <option value="positivo">Positivo</option>
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="condicion">Condición</Label>
                            <select
                                id="condicion"
                                value={data.condicion}
                                onChange={(e) => setData('condicion', e.target.value as Condicion)}
                                className={selectCls}
                            >
                                {Object.entries(CONDICION).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="etapa">Etapa del ciclo de vida</Label>
                            <select id="etapa" value={data.etapa} onChange={(e) => setData('etapa', e.target.value)} className={selectCls}>
                                {Object.entries(etapas).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="frecuencia">Frecuencia</Label>
                            <select
                                id="frecuencia"
                                value={data.frecuencia}
                                onChange={(e) => setData('frecuencia', Number(e.target.value))}
                                className={selectCls}
                            >
                                {ESCALA_F.map((l, i) => (
                                    <option key={l} value={i + 1}>
                                        {i + 1} · {l}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="severidad">Severidad</Label>
                            <select
                                id="severidad"
                                value={data.severidad}
                                onChange={(e) => setData('severidad', Number(e.target.value))}
                                className={selectCls}
                            >
                                {ESCALA_S.map((l, i) => (
                                    <option key={l} value={i + 1}>
                                        {i + 1} · {l}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-x-6 gap-y-2">
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.requisito_legal} onCheckedChange={(v) => setData('requisito_legal', v === true)} />
                            Tiene un requisito legal asociado
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.preocupa_partes} onCheckedChange={(v) => setData('preocupa_partes', v === true)} />
                            Preocupa a partes interesadas
                        </label>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="controles">Controles operacionales</Label>
                        <textarea
                            id="controles"
                            rows={2}
                            value={data.controles}
                            onChange={(e) => setData('controles', e.target.value)}
                            className={textareaCls}
                        />
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

function AccionDialog({ aspecto, onClose }: { aspecto: Aspecto; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ accion: '', responsable: '', fecha_limite: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/aspectos-ambientales/${aspecto.id}/accion`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Acción preventiva en ACPM</DialogTitle>
                    <DialogDescription>
                        Para el aspecto: {aspecto.aspecto} → {aspecto.impacto}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-2">
                        <Label htmlFor="accion">Acción</Label>
                        <textarea
                            id="accion"
                            rows={3}
                            value={data.accion}
                            onChange={(e) => setData('accion', e.target.value)}
                            className={textareaCls}
                        />
                        <InputError message={errors.accion} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="acc-resp">Responsable</Label>
                            <Input id="acc-resp" value={data.responsable} onChange={(e) => setData('responsable', e.target.value)} />
                            <InputError message={errors.responsable} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="acc-fecha">Fecha límite</Label>
                            <Input id="acc-fecha" type="date" value={data.fecha_limite} onChange={(e) => setData('fecha_limite', e.target.value)} />
                            <InputError message={errors.fecha_limite} />
                        </div>
                    </div>
                    <DialogFooter className="gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Crear en ACPM
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
