import { selectCls, textareaCls } from '@/components/control-documental/etiquetas';
import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ClockAlert, FileOutput, MessageSquareWarning, PackageX, Pencil, Plus, Smile, Trash2 } from 'lucide-react';
import { FormEventHandler, ReactNode, useState } from 'react';

type Proceso = { id: number; sigla: string; nombre: string };
type Acpm = { id: number; codigo: string; estado: string } | null;

interface Pqrs {
    id: number;
    radicado: string;
    fecha: string;
    tipo: string;
    cliente: string;
    contacto: string | null;
    canal: string | null;
    descripcion: string;
    process_id: number | null;
    responsable: string | null;
    fecha_limite: string;
    respuesta: string | null;
    fecha_respuesta: string | null;
    procede: boolean | null;
    estado: string;
    vencida: boolean;
    a_tiempo: boolean | null;
    dias_respuesta: number | null;
    process: { sigla: string } | null;
    acpm_action: Acpm;
}

interface Salida {
    id: number;
    codigo: string;
    fecha: string;
    producto: string;
    process_id: number | null;
    detectado_en: string;
    descripcion: string;
    cantidad: string | null;
    tratamiento: string;
    detalle_tratamiento: string | null;
    autorizado_por: string | null;
    verificado_por: string | null;
    fecha_verificacion: string | null;
    estado: 'abierta' | 'cerrada';
    process: { sigla: string } | null;
    acpm_action: Acpm;
}

interface Encuesta {
    id: number;
    fecha: string;
    cliente: string;
    producto: string | null;
    comentario: string | null;
    indice: number;
    [criterio: string]: string | number | null;
}

interface Props {
    needsClient: boolean;
    pqrs: Pqrs[];
    salidas: Salida[];
    encuestas: Encuesta[];
    resumen: {
        pqrs_abiertas: number;
        pqrs_vencidas: number;
        a_tiempo: number | null;
        salidas_abiertas: number;
        satisfaccion: { n: number; indice: number | null; criterios: Record<string, number | null> };
    } | null;
    procesos: Proceso[];
    documentos: { clave: string; titulo: string; id: number | null; codigo: string | null; estado: string | null }[];
    tiposPqrs: Record<string, string>;
    canales: Record<string, string>;
    estadosPqrs: Record<string, string>;
    deteccion: Record<string, string>;
    tratamientos: Record<string, string>;
    verificables: string[];
    criterios: Record<string, string>;
    meta: number;
    plazoDias: number;
}

type Pestana = 'pqrs' | 'salidas' | 'satisfaccion';
type Accion = { url: string; titulo: string } | null;

const hoy = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const th = 'px-3 py-2.5 font-semibold';

export default function Calidad(props: Props) {
    const { needsClient, pqrs, salidas, encuestas, resumen, documentos } = props;
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const errores = usePage<SharedData>().props.errors as Record<string, string | undefined>;
    const [pestana, setPestana] = useState<Pestana>('pqrs');
    const [pqrsDlg, setPqrsDlg] = useState<Pqrs | 'nuevo' | null>(null);
    const [salidaDlg, setSalidaDlg] = useState<Salida | 'nuevo' | null>(null);
    const [encuestaDlg, setEncuestaDlg] = useState<Encuesta | 'nuevo' | null>(null);
    const [accion, setAccion] = useState<Accion>(null);

    const borrar = (url: string, que: string) => confirm(`¿Eliminar ${que}?`) && router.delete(url, { preserveScroll: true });
    const nuevo = { pqrs: 'Nueva PQRS', salidas: 'Nueva salida no conforme', satisfaccion: 'Registrar encuesta' }[pestana];

    return (
        <ModuloPage
            titulo="Calidad"
            descripcion="PQRS, salidas no conformes y satisfacción del cliente (ISO 9001 8.2.1, 8.7 y 9.1.2)"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button
                        className="gap-2"
                        onClick={() =>
                            pestana === 'pqrs' ? setPqrsDlg('nuevo') : pestana === 'salidas' ? setSalidaDlg('nuevo') : setEncuestaDlg('nuevo')
                        }
                    >
                        <Plus className="size-4" /> {nuevo}
                    </Button>
                ) : undefined
            }
        >
            {resumen && (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard label="PQRS abiertas" value={resumen.pqrs_abiertas} icon={MessageSquareWarning} />
                    <StatCard
                        label={`PQRS vencidas · ${resumen.a_tiempo === null ? '—' : `${resumen.a_tiempo} %`} a tiempo`}
                        value={resumen.pqrs_vencidas}
                        icon={ClockAlert}
                        alerta={resumen.pqrs_vencidas > 0}
                    />
                    <StatCard label="Salidas no conformes abiertas" value={resumen.salidas_abiertas} icon={PackageX} />
                    <StatCard
                        label={`Satisfacción (12 meses, ${resumen.satisfaccion.n} encuestas)`}
                        value={resumen.satisfaccion.indice === null ? '—' : `${resumen.satisfaccion.indice} %`}
                        icon={Smile}
                        alerta={resumen.satisfaccion.indice !== null && resumen.satisfaccion.indice < props.meta}
                    />
                </div>
            )}

            <div className="flex gap-1 border-b">
                {(
                    [
                        ['pqrs', `PQRS (${pqrs.length})`],
                        ['salidas', `Salidas no conformes (${salidas.length})`],
                        ['satisfaccion', `Satisfacción (${encuestas.length})`],
                    ] as const
                ).map(([k, label]) => (
                    <button
                        key={k}
                        type="button"
                        onClick={() => setPestana(k)}
                        className={cn(
                            '-mb-px border-b-2 px-4 py-2 text-sm',
                            pestana === k ? 'border-primary font-medium' : 'text-muted-foreground border-transparent',
                        )}
                    >
                        {label}
                    </button>
                ))}
            </div>
            {errores.acpm && <p className="text-destructive text-sm">{errores.acpm}</p>}

            <Card>
                <CardContent className="p-0">
                    {pestana === 'pqrs' && (
                        <Tabla vacio="Sin PQRS registradas." filas={pqrs.length}>
                            <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                <tr>
                                    <th className={th}>Radicado</th>
                                    <th className={th}>Cliente y solicitud</th>
                                    <th className={th}>Plazo</th>
                                    <th className={th}>Respuesta</th>
                                    {canManage && <th className={th} />}
                                </tr>
                            </thead>
                            <tbody>
                                {pqrs.map((p) => (
                                    <tr key={p.id} className="border-t align-top">
                                        <td className="px-3 py-2.5 whitespace-nowrap">
                                            <div className="font-mono text-xs">{p.radicado}</div>
                                            <div className="text-muted-foreground text-xs">{p.fecha}</div>
                                            <div className="text-xs">{props.tiposPqrs[p.tipo]}</div>
                                        </td>
                                        <td className="max-w-md px-3 py-2.5">
                                            <div className="font-medium">{p.cliente}</div>
                                            <div className="text-muted-foreground line-clamp-2 text-xs">{p.descripcion}</div>
                                            <div className="text-muted-foreground text-xs">
                                                {[p.canal && props.canales[p.canal], p.process?.sigla, p.responsable].filter(Boolean).join(' · ')}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2.5 text-xs whitespace-nowrap tabular-nums">
                                            {p.fecha_limite}
                                            {p.vencida && <div className="text-destructive font-medium">Vencida</div>}
                                        </td>
                                        <td className="max-w-xs px-3 py-2.5 text-xs">
                                            <div>{props.estadosPqrs[p.estado]}</div>
                                            {p.fecha_respuesta && (
                                                <div className={p.a_tiempo ? 'text-emerald-700 dark:text-emerald-400' : 'text-destructive'}>
                                                    Respondida el {p.fecha_respuesta} ({p.dias_respuesta} días){p.a_tiempo ? '' : ', fuera de plazo'}
                                                </div>
                                            )}
                                            {p.procede !== null && (
                                                <div className="text-muted-foreground">{p.procede ? 'Procede' : 'No procede'}</div>
                                            )}
                                            <AcpmLink
                                                acpm={p.acpm_action}
                                                puede={canManage && p.procede === true}
                                                onCrear={() =>
                                                    setAccion({ url: `/calidad/pqrs/${p.id}/accion`, titulo: `${p.radicado} · ${p.cliente}` })
                                                }
                                            />
                                        </td>
                                        {canManage && (
                                            <Acciones
                                                onEditar={() => setPqrsDlg(p)}
                                                onBorrar={() => borrar(`/calidad/pqrs/${p.id}`, `la PQRS ${p.radicado}`)}
                                            />
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </Tabla>
                    )}

                    {pestana === 'salidas' && (
                        <Tabla vacio="Sin salidas no conformes registradas." filas={salidas.length}>
                            <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                <tr>
                                    <th className={th}>Código</th>
                                    <th className={th}>Producto o servicio</th>
                                    <th className={th}>Tratamiento</th>
                                    <th className={th}>Estado</th>
                                    {canManage && <th className={th} />}
                                </tr>
                            </thead>
                            <tbody>
                                {salidas.map((s) => (
                                    <tr key={s.id} className="border-t align-top">
                                        <td className="px-3 py-2.5 whitespace-nowrap">
                                            <div className="font-mono text-xs">{s.codigo}</div>
                                            <div className="text-muted-foreground text-xs">{s.fecha}</div>
                                        </td>
                                        <td className="max-w-md px-3 py-2.5">
                                            <div className="font-medium">
                                                {s.producto}
                                                {s.cantidad && <span className="text-muted-foreground font-normal"> · {s.cantidad}</span>}
                                            </div>
                                            <div className="text-muted-foreground line-clamp-2 text-xs">{s.descripcion}</div>
                                            <div
                                                className={cn('text-xs', s.detectado_en === 'cliente' ? 'text-destructive' : 'text-muted-foreground')}
                                            >
                                                {props.deteccion[s.detectado_en]}
                                                {s.process && ` · ${s.process.sigla}`}
                                            </div>
                                        </td>
                                        <td className="max-w-xs px-3 py-2.5 text-xs">
                                            <div>{props.tratamientos[s.tratamiento]}</div>
                                            {s.detalle_tratamiento && (
                                                <div className="text-muted-foreground line-clamp-2">{s.detalle_tratamiento}</div>
                                            )}
                                            {s.autorizado_por && <div className="text-muted-foreground">Autorizó: {s.autorizado_por}</div>}
                                            {s.verificado_por && (
                                                <div className="text-muted-foreground">
                                                    Verificó: {s.verificado_por} ({s.fecha_verificacion})
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5 text-xs">
                                            <div className={s.estado === 'abierta' ? 'font-medium' : 'text-muted-foreground'}>
                                                {s.estado === 'abierta' ? 'Abierta' : 'Cerrada'}
                                            </div>
                                            <AcpmLink
                                                acpm={s.acpm_action}
                                                puede={canManage}
                                                onCrear={() =>
                                                    setAccion({ url: `/calidad/salidas/${s.id}/accion`, titulo: `${s.codigo} · ${s.producto}` })
                                                }
                                            />
                                        </td>
                                        {canManage && (
                                            <Acciones
                                                onEditar={() => setSalidaDlg(s)}
                                                onBorrar={() => borrar(`/calidad/salidas/${s.id}`, `la salida ${s.codigo}`)}
                                            />
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </Tabla>
                    )}

                    {pestana === 'satisfaccion' && (
                        <>
                            {resumen && resumen.satisfaccion.n > 0 && (
                                <div className="grid gap-3 border-b p-4 sm:grid-cols-5">
                                    {Object.entries(props.criterios).map(([c, etiqueta]) => (
                                        <div key={c}>
                                            <div className="text-muted-foreground text-xs">{etiqueta}</div>
                                            <div className="text-lg font-semibold tabular-nums">{resumen.satisfaccion.criterios[c]} / 5</div>
                                        </div>
                                    ))}
                                </div>
                            )}
                            <Tabla vacio="Sin encuestas registradas." filas={encuestas.length}>
                                <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                    <tr>
                                        <th className={th}>Fecha</th>
                                        <th className={th}>Cliente</th>
                                        {Object.values(props.criterios).map((l) => (
                                            <th key={l} className={cn(th, 'text-center')} title={l}>
                                                {l.split(' ')[0]}
                                            </th>
                                        ))}
                                        <th className={cn(th, 'text-right')}>Índice</th>
                                        {canManage && <th className={th} />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {encuestas.map((e) => (
                                        <tr key={e.id} className="border-t align-top">
                                            <td className="px-3 py-2.5 text-xs whitespace-nowrap tabular-nums">{e.fecha}</td>
                                            <td className="max-w-xs px-3 py-2.5">
                                                <div>{e.cliente}</div>
                                                {e.comentario && <div className="text-muted-foreground line-clamp-2 text-xs">{e.comentario}</div>}
                                            </td>
                                            {Object.keys(props.criterios).map((c) => (
                                                <td key={c} className="px-3 py-2.5 text-center tabular-nums">
                                                    {e[c]}
                                                </td>
                                            ))}
                                            <td
                                                className={cn(
                                                    'px-3 py-2.5 text-right font-medium tabular-nums',
                                                    e.indice < props.meta && 'text-destructive',
                                                )}
                                            >
                                                {e.indice} %
                                            </td>
                                            {canManage && (
                                                <Acciones
                                                    onEditar={() => setEncuestaDlg(e)}
                                                    onBorrar={() => borrar(`/calidad/encuestas/${e.id}`, 'esta encuesta')}
                                                />
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </Tabla>
                        </>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="pb-2">
                    <CardTitle className="text-base">Documentos</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-3 text-sm sm:grid-cols-2">
                    {documentos.length === 0 && (
                        <p className="text-muted-foreground text-xs">El catálogo del SIG no está cargado en esta instalación.</p>
                    )}
                    {documentos.map((d) => (
                        <div key={d.clave} className="flex items-center justify-between gap-3">
                            <div className="min-w-0">
                                <div className="truncate" title={d.titulo}>
                                    {d.titulo}
                                </div>
                                <div className="text-muted-foreground text-xs">
                                    {d.codigo ? (
                                        <Link href={`/control-documental/${d.id}`} className="underline underline-offset-2">
                                            {d.codigo} · {d.estado?.replace('_', ' ')}
                                        </Link>
                                    ) : (
                                        'Aún no está en el control documental'
                                    )}
                                </div>
                            </div>
                            {canManage && (
                                <Button
                                    size="icon"
                                    variant="outline"
                                    className="shrink-0"
                                    title="Enviar como borrador al control documental"
                                    aria-label={`Enviar ${d.titulo}`}
                                    onClick={() => router.post('/calidad/enviar', { documento: d.clave }, { preserveScroll: true })}
                                >
                                    <FileOutput className="size-4" />
                                </Button>
                            )}
                        </div>
                    ))}
                    {errores.documento && <p className="text-destructive text-xs">{errores.documento}</p>}
                </CardContent>
            </Card>

            {pqrsDlg && <PqrsDialog pqrs={pqrsDlg === 'nuevo' ? null : pqrsDlg} props={props} onClose={() => setPqrsDlg(null)} />}
            {salidaDlg && <SalidaDialog salida={salidaDlg === 'nuevo' ? null : salidaDlg} props={props} onClose={() => setSalidaDlg(null)} />}
            {encuestaDlg && (
                <EncuestaDialog
                    encuesta={encuestaDlg === 'nuevo' ? null : encuestaDlg}
                    criterios={props.criterios}
                    onClose={() => setEncuestaDlg(null)}
                />
            )}
            {accion && <AccionDialog accion={accion} onClose={() => setAccion(null)} />}
        </ModuloPage>
    );
}

function Tabla({ vacio, filas, children }: { vacio: string; filas: number; children: ReactNode }) {
    if (filas === 0) return <p className="text-muted-foreground p-8 text-center text-sm">{vacio}</p>;
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">{children}</table>
        </div>
    );
}

function Acciones({ onEditar, onBorrar }: { onEditar: () => void; onBorrar: () => void }) {
    return (
        <td className="px-3 py-2.5">
            <div className="flex justify-end gap-1">
                <Button size="icon" variant="ghost" onClick={onEditar} aria-label="Editar">
                    <Pencil className="size-4" />
                </Button>
                <Button size="icon" variant="ghost" onClick={onBorrar} aria-label="Eliminar">
                    <Trash2 className="size-4" />
                </Button>
            </div>
        </td>
    );
}

function AcpmLink({ acpm, puede, onCrear }: { acpm: Acpm; puede: boolean; onCrear: () => void }) {
    if (acpm) {
        return (
            <Link href="/acpm" className="text-muted-foreground underline underline-offset-2">
                {acpm.codigo} · {acpm.estado.replace('_', ' ')}
            </Link>
        );
    }
    if (!puede) return null;
    return (
        <button type="button" className="text-primary block underline underline-offset-2" onClick={onCrear}>
            Crear acción correctiva
        </button>
    );
}

function Campo({ id, label, error, children, className }: { id: string; label: string; error?: string; children: ReactNode; className?: string }) {
    return (
        <div className={cn('grid gap-2', className)}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function Pie({ onClose, processing, texto = 'Guardar' }: { onClose: () => void; processing: boolean; texto?: string }) {
    return (
        <DialogFooter className="gap-2 pt-2">
            <Button type="button" variant="outline" onClick={onClose}>
                Cancelar
            </Button>
            <Button type="submit" disabled={processing}>
                {texto}
            </Button>
        </DialogFooter>
    );
}

function ProcesoSelect({
    id,
    value,
    procesos,
    onChange,
}: {
    id: string;
    value: number | '';
    procesos: Proceso[];
    onChange: (v: number | '') => void;
}) {
    return (
        <select id={id} value={value} onChange={(e) => onChange(e.target.value ? Number(e.target.value) : '')} className={selectCls}>
            <option value="">Sin proceso</option>
            {procesos.map((p) => (
                <option key={p.id} value={p.id}>
                    {p.sigla} · {p.nombre}
                </option>
            ))}
        </select>
    );
}

function Opciones({ opciones }: { opciones: Record<string, string> }) {
    return (
        <>
            {Object.entries(opciones).map(([k, v]) => (
                <option key={k} value={k}>
                    {v}
                </option>
            ))}
        </>
    );
}

function PqrsDialog({ pqrs, props, onClose }: { pqrs: Pqrs | null; props: Props; onClose: () => void }) {
    const { data, setData, post, put, processing, errors, transform } = useForm({
        fecha: pqrs?.fecha ?? hoy(),
        tipo: pqrs?.tipo ?? 'peticion',
        cliente: pqrs?.cliente ?? '',
        contacto: pqrs?.contacto ?? '',
        canal: pqrs?.canal ?? 'correo',
        descripcion: pqrs?.descripcion ?? '',
        process_id: (pqrs?.process_id ?? '') as number | '',
        responsable: pqrs?.responsable ?? '',
        fecha_limite: pqrs?.fecha_limite ?? '',
        respuesta: pqrs?.respuesta ?? '',
        fecha_respuesta: pqrs?.fecha_respuesta ?? '',
        procede: pqrs?.procede === null || pqrs?.procede === undefined ? '' : pqrs.procede ? '1' : '0',
        estado: pqrs?.estado ?? 'abierta',
    });
    const evaluable = data.tipo === 'queja' || data.tipo === 'reclamo';

    transform((d) => ({ ...d, procede: d.procede === '' ? null : d.procede === '1', fecha_limite: d.fecha_limite || null }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (pqrs) put(`/calidad/pqrs/${pqrs.id}`, opts);
        else post('/calidad/pqrs', opts);
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{pqrs ? `PQRS ${pqrs.radicado}` : 'Nueva PQRS'}</DialogTitle>
                    <DialogDescription>
                        Plazo de respuesta: {props.plazoDias} días hábiles desde la radicación, si no se indica otro.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Campo id="p-fecha" label="Fecha" error={errors.fecha}>
                            <Input id="p-fecha" type="date" value={data.fecha} onChange={(e) => setData('fecha', e.target.value)} />
                        </Campo>
                        <Campo id="p-tipo" label="Tipo">
                            <select id="p-tipo" value={data.tipo} onChange={(e) => setData('tipo', e.target.value)} className={selectCls}>
                                <Opciones opciones={props.tiposPqrs} />
                            </select>
                        </Campo>
                        <Campo id="p-canal" label="Canal">
                            <select id="p-canal" value={data.canal} onChange={(e) => setData('canal', e.target.value)} className={selectCls}>
                                <Opciones opciones={props.canales} />
                            </select>
                        </Campo>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Campo id="p-cliente" label="Cliente" error={errors.cliente}>
                            <Input id="p-cliente" value={data.cliente} onChange={(e) => setData('cliente', e.target.value)} />
                        </Campo>
                        <Campo id="p-contacto" label="Contacto">
                            <Input id="p-contacto" value={data.contacto} onChange={(e) => setData('contacto', e.target.value)} />
                        </Campo>
                    </div>
                    <Campo id="p-desc" label="Descripción" error={errors.descripcion}>
                        <textarea
                            id="p-desc"
                            rows={3}
                            value={data.descripcion}
                            onChange={(e) => setData('descripcion', e.target.value)}
                            className={textareaCls}
                        />
                    </Campo>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Campo id="p-proceso" label="Proceso" error={errors.process_id}>
                            <ProcesoSelect
                                id="p-proceso"
                                value={data.process_id}
                                procesos={props.procesos}
                                onChange={(v) => setData('process_id', v)}
                            />
                        </Campo>
                        <Campo id="p-resp" label="Responsable">
                            <Input id="p-resp" value={data.responsable} onChange={(e) => setData('responsable', e.target.value)} />
                        </Campo>
                        <Campo id="p-limite" label="Fecha límite" error={errors.fecha_limite}>
                            <Input id="p-limite" type="date" value={data.fecha_limite} onChange={(e) => setData('fecha_limite', e.target.value)} />
                        </Campo>
                    </div>
                    <Campo id="p-respuesta" label="Respuesta" error={errors.respuesta}>
                        <textarea
                            id="p-respuesta"
                            rows={3}
                            value={data.respuesta}
                            onChange={(e) => setData('respuesta', e.target.value)}
                            className={textareaCls}
                        />
                    </Campo>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Campo id="p-fresp" label="Fecha de respuesta" error={errors.fecha_respuesta}>
                            <Input
                                id="p-fresp"
                                type="date"
                                value={data.fecha_respuesta}
                                onChange={(e) => setData('fecha_respuesta', e.target.value)}
                            />
                        </Campo>
                        {evaluable && (
                            <Campo id="p-procede" label="¿Procede?">
                                <select
                                    id="p-procede"
                                    value={data.procede}
                                    onChange={(e) => setData('procede', e.target.value)}
                                    className={selectCls}
                                >
                                    <option value="">Sin analizar</option>
                                    <option value="1">Sí, el cliente tiene razón</option>
                                    <option value="0">No procede</option>
                                </select>
                            </Campo>
                        )}
                        <Campo id="p-estado" label="Estado">
                            <select id="p-estado" value={data.estado} onChange={(e) => setData('estado', e.target.value)} className={selectCls}>
                                <Opciones opciones={props.estadosPqrs} />
                            </select>
                        </Campo>
                    </div>
                    <Pie onClose={onClose} processing={processing} />
                </form>
            </DialogContent>
        </Dialog>
    );
}

function SalidaDialog({ salida, props, onClose }: { salida: Salida | null; props: Props; onClose: () => void }) {
    const { data, setData, post, put, processing, errors } = useForm({
        fecha: salida?.fecha ?? hoy(),
        producto: salida?.producto ?? '',
        process_id: (salida?.process_id ?? '') as number | '',
        detectado_en: salida?.detectado_en ?? 'proceso',
        descripcion: salida?.descripcion ?? '',
        cantidad: salida?.cantidad ?? '',
        tratamiento: salida?.tratamiento ?? 'correccion',
        detalle_tratamiento: salida?.detalle_tratamiento ?? '',
        autorizado_por: salida?.autorizado_por ?? '',
        verificado_por: salida?.verificado_por ?? '',
        fecha_verificacion: salida?.fecha_verificacion ?? '',
        estado: salida?.estado ?? 'abierta',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (salida) put(`/calidad/salidas/${salida.id}`, opts);
        else post('/calidad/salidas', opts);
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{salida ? `Salida no conforme ${salida.codigo}` : 'Nueva salida no conforme'}</DialogTitle>
                    <DialogDescription>Qué no cumplió, qué se hizo con ello y quién lo verificó o lo autorizó.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Campo id="s-fecha" label="Fecha" error={errors.fecha}>
                            <Input id="s-fecha" type="date" value={data.fecha} onChange={(e) => setData('fecha', e.target.value)} />
                        </Campo>
                        <Campo id="s-producto" label="Producto o servicio" error={errors.producto} className="sm:col-span-2">
                            <Input id="s-producto" value={data.producto} onChange={(e) => setData('producto', e.target.value)} />
                        </Campo>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Campo id="s-detectado" label="Detectado en">
                            <select
                                id="s-detectado"
                                value={data.detectado_en}
                                onChange={(e) => setData('detectado_en', e.target.value)}
                                className={selectCls}
                            >
                                <Opciones opciones={props.deteccion} />
                            </select>
                        </Campo>
                        <Campo id="s-proceso" label="Proceso" error={errors.process_id}>
                            <ProcesoSelect
                                id="s-proceso"
                                value={data.process_id}
                                procesos={props.procesos}
                                onChange={(v) => setData('process_id', v)}
                            />
                        </Campo>
                        <Campo id="s-cantidad" label="Cantidad afectada">
                            <Input id="s-cantidad" value={data.cantidad} onChange={(e) => setData('cantidad', e.target.value)} />
                        </Campo>
                    </div>
                    <Campo id="s-desc" label="Descripción de la no conformidad" error={errors.descripcion}>
                        <textarea
                            id="s-desc"
                            rows={3}
                            value={data.descripcion}
                            onChange={(e) => setData('descripcion', e.target.value)}
                            className={textareaCls}
                        />
                    </Campo>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Campo id="s-trat" label="Tratamiento">
                            <select
                                id="s-trat"
                                value={data.tratamiento}
                                onChange={(e) => setData('tratamiento', e.target.value)}
                                className={selectCls}
                            >
                                <Opciones opciones={props.tratamientos} />
                            </select>
                        </Campo>
                        <Campo id="s-detalle" label="Qué se hizo" className="sm:col-span-2">
                            <Input id="s-detalle" value={data.detalle_tratamiento} onChange={(e) => setData('detalle_tratamiento', e.target.value)} />
                        </Campo>
                    </div>
                    {data.tratamiento === 'concesion' && (
                        <Campo id="s-autorizo" label="Autorizado por (y aval del cliente, si aplica)" error={errors.autorizado_por}>
                            <Input id="s-autorizo" value={data.autorizado_por} onChange={(e) => setData('autorizado_por', e.target.value)} />
                        </Campo>
                    )}
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Campo id="s-verifico" label="Verificado por" error={errors.verificado_por}>
                            <Input id="s-verifico" value={data.verificado_por} onChange={(e) => setData('verificado_por', e.target.value)} />
                        </Campo>
                        <Campo id="s-fver" label="Fecha de verificación" error={errors.fecha_verificacion}>
                            <Input
                                id="s-fver"
                                type="date"
                                value={data.fecha_verificacion}
                                onChange={(e) => setData('fecha_verificacion', e.target.value)}
                            />
                        </Campo>
                        <Campo id="s-estado" label="Estado">
                            <select
                                id="s-estado"
                                value={data.estado}
                                onChange={(e) => setData('estado', e.target.value as 'abierta' | 'cerrada')}
                                className={selectCls}
                            >
                                <option value="abierta">Abierta</option>
                                <option value="cerrada">Cerrada</option>
                            </select>
                        </Campo>
                    </div>
                    {props.verificables.includes(data.tratamiento) && (
                        <p className="text-muted-foreground text-xs">
                            Tras corregir o reprocesar hay que verificar de nuevo la conformidad antes de cerrar (ISO 9001 8.7.1).
                        </p>
                    )}
                    <Pie onClose={onClose} processing={processing} />
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EncuestaDialog({ encuesta, criterios, onClose }: { encuesta: Encuesta | null; criterios: Record<string, string>; onClose: () => void }) {
    const { data, setData, post, put, processing, errors } = useForm<Record<string, string | number>>({
        fecha: encuesta?.fecha ?? hoy(),
        cliente: encuesta?.cliente ?? '',
        producto: encuesta?.producto ?? '',
        comentario: encuesta?.comentario ?? '',
        ...Object.fromEntries(Object.keys(criterios).map((c) => [c, (encuesta?.[c] as number | undefined) ?? 5])),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (encuesta) put(`/calidad/encuestas/${encuesta.id}`, opts);
        else post('/calidad/encuestas', opts);
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{encuesta ? 'Editar encuesta' : 'Registrar encuesta de satisfacción'}</DialogTitle>
                    <DialogDescription>Calificaciones de 1 (muy insatisfecho) a 5 (muy satisfecho).</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-[9rem_1fr]">
                        <Campo id="e-fecha" label="Fecha" error={errors.fecha}>
                            <Input id="e-fecha" type="date" value={data.fecha} onChange={(e) => setData('fecha', e.target.value)} />
                        </Campo>
                        <Campo id="e-cliente" label="Cliente" error={errors.cliente}>
                            <Input id="e-cliente" value={data.cliente} onChange={(e) => setData('cliente', e.target.value)} />
                        </Campo>
                    </div>
                    <Campo id="e-producto" label="Producto o servicio">
                        <Input id="e-producto" value={data.producto} onChange={(e) => setData('producto', e.target.value)} />
                    </Campo>
                    <div className="space-y-2">
                        {Object.entries(criterios).map(([c, etiqueta]) => (
                            <div key={c} className="flex items-center justify-between gap-3">
                                <span className="text-sm">{etiqueta}</span>
                                <div className="flex gap-1" role="radiogroup" aria-label={etiqueta}>
                                    {[1, 2, 3, 4, 5].map((n) => (
                                        <button
                                            key={n}
                                            type="button"
                                            role="radio"
                                            aria-checked={Number(data[c]) === n}
                                            aria-label={`${etiqueta}: ${n}`}
                                            onClick={() => setData(c, n)}
                                            className={cn(
                                                'size-8 rounded-md border text-sm tabular-nums',
                                                Number(data[c]) === n ? 'bg-primary text-primary-foreground border-primary' : 'hover:bg-muted',
                                            )}
                                        >
                                            {n}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                    <Campo id="e-comentario" label="Comentarios">
                        <textarea
                            id="e-comentario"
                            rows={2}
                            value={data.comentario}
                            onChange={(e) => setData('comentario', e.target.value)}
                            className={textareaCls}
                        />
                    </Campo>
                    <Pie onClose={onClose} processing={processing} />
                </form>
            </DialogContent>
        </Dialog>
    );
}

function AccionDialog({ accion, onClose }: { accion: NonNullable<Accion>; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ causa: '', accion: '', responsable: '', fecha_limite: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(accion.url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Acción correctiva en ACPM</DialogTitle>
                    <DialogDescription>{accion.titulo}</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <Campo id="a-causa" label="Causa">
                        <textarea
                            id="a-causa"
                            rows={2}
                            value={data.causa}
                            onChange={(e) => setData('causa', e.target.value)}
                            className={textareaCls}
                        />
                    </Campo>
                    <Campo id="a-accion" label="Acción" error={errors.accion}>
                        <textarea
                            id="a-accion"
                            rows={3}
                            value={data.accion}
                            onChange={(e) => setData('accion', e.target.value)}
                            className={textareaCls}
                        />
                    </Campo>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Campo id="a-resp" label="Responsable" error={errors.responsable}>
                            <Input id="a-resp" value={data.responsable} onChange={(e) => setData('responsable', e.target.value)} />
                        </Campo>
                        <Campo id="a-fecha" label="Fecha límite" error={errors.fecha_limite}>
                            <Input id="a-fecha" type="date" value={data.fecha_limite} onChange={(e) => setData('fecha_limite', e.target.value)} />
                        </Campo>
                    </div>
                    <Pie onClose={onClose} processing={processing} texto="Crear en ACPM" />
                </form>
            </DialogContent>
        </Dialog>
    );
}
