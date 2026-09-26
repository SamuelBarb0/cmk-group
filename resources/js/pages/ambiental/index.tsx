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
import { Droplets, FileDown, FileOutput, FlaskConical, Pencil, Plus, Recycle, Trash2, TriangleAlert } from 'lucide-react';
import { FormEventHandler, ReactNode, useState } from 'react';

interface Residuo {
    id: number;
    fecha: string;
    tipo: string;
    corriente: string | null;
    cantidad_kg: number;
    gestor: string | null;
    licencia_gestor: string | null;
    disposicion: string | null;
    certificado: string | null;
    observaciones: string | null;
    tiene_archivo: boolean;
    archivo_nombre: string | null;
    sin_certificado: boolean;
}

interface Lectura {
    id: number;
    mes: string;
    recurso: string;
    cantidad: number;
    costo: number | null;
    trabajadores: number | null;
    por_persona: number | null;
    observaciones: string | null;
}

interface Quimico {
    id: number;
    nombre: string;
    proveedor: string | null;
    uso: string | null;
    cantidad: string | null;
    ubicacion: string | null;
    peligros: string[];
    hds_fecha: string | null;
    epp: string | null;
    activo: boolean;
    observaciones: string | null;
    estado_hds: 'vigente' | 'desactualizada' | 'sin_hds';
}

interface Props {
    needsClient: boolean;
    residuos: Residuo[];
    lecturas: Lectura[];
    quimicos: Quimico[];
    matriz: Record<string, 'compatible' | 'separar' | 'incompatible'>;
    respel: { media_kg: number; categoria: string; registro_obligatorio: boolean; desde: string; hasta: string } | null;
    documentos: { clave: string; titulo: string; id: number | null; codigo: string | null; estado: string | null }[];
    tiposResiduo: Record<string, string>;
    disposiciones: Record<string, string>;
    conCertificado: string[];
    categorias: Record<string, string>;
    recursos: Record<string, [string, string]>;
    pictogramas: Record<string, string>;
    hdsAnios: number;
}

type Pestana = 'residuos' | 'consumos' | 'quimicos';

const hoy = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const num = (v: number, dec = 1) => v.toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: dec });

const th = 'px-3 py-2.5 font-semibold';

const COMPAT = {
    compatible: { txt: 'C', cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300', label: 'Compatible' },
    separar: { txt: 'S', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300', label: 'Separar' },
    incompatible: { txt: 'X', cls: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300', label: 'Incompatible' },
};

const HDS = {
    vigente: { label: 'Vigente', cls: 'text-emerald-700 dark:text-emerald-400' },
    desactualizada: { label: 'Pedir versión nueva', cls: 'text-amber-700 dark:text-amber-400' },
    sin_hds: { label: 'Sin HDS', cls: 'text-destructive' },
};

export default function Ambiental(props: Props) {
    const { needsClient, residuos, lecturas, quimicos, respel, documentos } = props;
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const errores = usePage<SharedData>().props.errors as Record<string, string | undefined>;
    const [pestana, setPestana] = useState<Pestana>('residuos');
    const [residuoDlg, setResiduoDlg] = useState<Residuo | 'nuevo' | null>(null);
    const [lecturaDlg, setLecturaDlg] = useState<boolean>(false);
    const [quimicoDlg, setQuimicoDlg] = useState<Quimico | 'nuevo' | null>(null);

    const hace12 = (() => {
        const d = new Date();
        d.setMonth(d.getMonth() - 11, 1);
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    })();
    const del12 = residuos.filter((r) => r.fecha.slice(0, 7) >= hace12);
    const total12 = del12.reduce((s, r) => s + r.cantidad_kg, 0);
    const aprov12 = del12.filter((r) => r.disposicion === 'aprovechamiento' || r.disposicion === 'posconsumo').reduce((s, r) => s + r.cantidad_kg, 0);
    const pendientes = residuos.filter((r) => r.sin_certificado).length;
    const activos = quimicos.filter((q) => q.activo);
    const sinHds = activos.filter((q) => q.estado_hds !== 'vigente').length;

    const borrar = (url: string, que: string) => confirm(`¿Eliminar ${que}?`) && router.delete(url, { preserveScroll: true });
    const nuevo = { residuos: 'Registrar entrega de residuos', consumos: 'Registrar consumo', quimicos: 'Nuevo producto químico' }[pestana];

    return (
        <ModuloPage
            titulo="Gestión ambiental"
            descripcion="Residuos y RESPEL, consumos de agua y energía, y productos químicos (ISO 14001 6.1.4, 8.1 y 9.1.1)"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button
                        className="gap-2"
                        onClick={() =>
                            pestana === 'residuos' ? setResiduoDlg('nuevo') : pestana === 'consumos' ? setLecturaDlg(true) : setQuimicoDlg('nuevo')
                        }
                    >
                        <Plus className="size-4" /> {nuevo}
                    </Button>
                ) : undefined
            }
        >
            {!needsClient && (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label={`Residuos 12 meses (kg) · ${total12 > 0 ? Math.round((100 * aprov12) / total12) : 0} % aprovechado`}
                        value={num(total12)}
                        icon={Recycle}
                    />
                    <StatCard label="Entregas sin certificado de disposición" value={pendientes} icon={TriangleAlert} alerta={pendientes > 0} />
                    <StatCard label="Productos químicos activos" value={activos.length} icon={FlaskConical} />
                    <StatCard label="Sin HDS vigente" value={sinHds} icon={Droplets} alerta={sinHds > 0} />
                </div>
            )}

            {respel && (
                <Card>
                    <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                        <div>
                            <div className="text-muted-foreground text-xs">
                                Categoría de generador RESPEL · media móvil de 6 meses ({respel.desde.slice(0, 7)} a {respel.hasta.slice(0, 7)})
                            </div>
                            <div className="font-medium">
                                {num(respel.media_kg, 2)} kg/mes · {props.categorias[respel.categoria]}
                            </div>
                        </div>
                        <p
                            className={cn(
                                'max-w-md text-xs',
                                respel.registro_obligatorio ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground',
                            )}
                        >
                            {respel.registro_obligatorio
                                ? 'Debe estar inscrita en el Registro de Generadores de RESPEL del IDEAM ante su autoridad ambiental y actualizarlo cada año (Res. 1362 de 2007).'
                                : 'Por debajo de 10 kg/mes no está obligada a inscribirse; los RESPEL igual se entregan a gestores autorizados.'}
                        </p>
                    </CardContent>
                </Card>
            )}

            <div className="flex gap-1 border-b">
                {(
                    [
                        ['residuos', `Residuos (${residuos.length})`],
                        ['consumos', 'Consumos'],
                        ['quimicos', `Productos químicos (${quimicos.length})`],
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

            {pestana === 'residuos' && (
                <Card>
                    <CardContent className="p-0">
                        <Tabla vacio="Sin entregas de residuos registradas." filas={residuos.length}>
                            <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                <tr>
                                    <th className={th}>Fecha</th>
                                    <th className={th}>Residuo</th>
                                    <th className={cn(th, 'text-right')}>kg</th>
                                    <th className={th}>Gestor y disposición</th>
                                    <th className={th}>Certificado</th>
                                    {canManage && <th className={th} />}
                                </tr>
                            </thead>
                            <tbody>
                                {residuos.map((r) => (
                                    <tr key={r.id} className="border-t align-top">
                                        <td className="px-3 py-2.5 text-xs whitespace-nowrap tabular-nums">{r.fecha}</td>
                                        <td className="px-3 py-2.5">
                                            <div className={cn(r.tipo === 'peligroso' && 'font-medium')}>{props.tiposResiduo[r.tipo]}</div>
                                            {r.corriente && <div className="text-muted-foreground text-xs">{r.corriente}</div>}
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums">{num(r.cantidad_kg, 2)}</td>
                                        <td className="px-3 py-2.5 text-xs">
                                            <div>{r.gestor ?? '—'}</div>
                                            {r.licencia_gestor && <div className="text-muted-foreground">Licencia {r.licencia_gestor}</div>}
                                            {r.disposicion && <div className="text-muted-foreground">{props.disposiciones[r.disposicion]}</div>}
                                        </td>
                                        <td className="px-3 py-2.5 text-xs">
                                            {r.tiene_archivo ? (
                                                <a
                                                    href={`/ambiental/residuos/${r.id}/certificado`}
                                                    className="inline-flex items-center gap-1 underline underline-offset-2"
                                                >
                                                    <FileDown className="size-3.5" /> {r.certificado || r.archivo_nombre}
                                                </a>
                                            ) : r.sin_certificado ? (
                                                <span className="text-destructive">Pendiente</span>
                                            ) : (
                                                (r.certificado ?? '—')
                                            )}
                                        </td>
                                        {canManage && (
                                            <Acciones
                                                onEditar={() => setResiduoDlg(r)}
                                                onBorrar={() => borrar(`/ambiental/residuos/${r.id}`, 'este registro y su certificado')}
                                            />
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </Tabla>
                    </CardContent>
                </Card>
            )}

            {pestana === 'consumos' && <Consumos lecturas={lecturas} recursos={props.recursos} canManage={canManage} borrar={borrar} />}

            {pestana === 'quimicos' && (
                <>
                    <Card>
                        <CardContent className="p-0">
                            <Tabla vacio="Sin productos químicos en el inventario." filas={quimicos.length}>
                                <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                    <tr>
                                        <th className={th}>Producto</th>
                                        <th className={th}>Peligros SGA</th>
                                        <th className={th}>Almacenamiento</th>
                                        <th className={th}>HDS</th>
                                        {canManage && <th className={th} />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {quimicos.map((q) => (
                                        <tr key={q.id} className={cn('border-t align-top', !q.activo && 'opacity-60')}>
                                            <td className="px-3 py-2.5">
                                                <div className="font-medium">
                                                    {q.nombre}
                                                    {!q.activo && <span className="text-muted-foreground font-normal"> · inactivo</span>}
                                                </div>
                                                <div className="text-muted-foreground text-xs">
                                                    {[q.proveedor, q.uso].filter(Boolean).join(' · ')}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <div className="flex flex-wrap gap-1">
                                                    {q.peligros.length === 0 && (
                                                        <span className="text-muted-foreground text-xs">Sin pictogramas</span>
                                                    )}
                                                    {q.peligros.map((g) => (
                                                        <span
                                                            key={g}
                                                            title={props.pictogramas[g]}
                                                            className="rounded border border-red-600/60 px-1.5 py-0.5 text-[11px] leading-none"
                                                        >
                                                            {props.pictogramas[g]}
                                                        </span>
                                                    ))}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2.5 text-xs">
                                                <div>{q.ubicacion ?? '—'}</div>
                                                {q.cantidad && <div className="text-muted-foreground">{q.cantidad}</div>}
                                                {q.epp && <div className="text-muted-foreground">EPP: {q.epp}</div>}
                                            </td>
                                            <td className="px-3 py-2.5 text-xs whitespace-nowrap">
                                                <div className={HDS[q.estado_hds].cls}>{HDS[q.estado_hds].label}</div>
                                                {q.hds_fecha && <div className="text-muted-foreground tabular-nums">{q.hds_fecha}</div>}
                                            </td>
                                            {canManage && (
                                                <Acciones
                                                    onEditar={() => setQuimicoDlg(q)}
                                                    onBorrar={() => borrar(`/ambiental/quimicos/${q.id}`, `«${q.nombre}» del inventario`)}
                                                />
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </Tabla>
                        </CardContent>
                    </Card>
                    <Matriz quimicos={activos} matriz={props.matriz} />
                </>
            )}

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
                                    onClick={() => router.post('/ambiental/enviar', { documento: d.clave }, { preserveScroll: true })}
                                >
                                    <FileOutput className="size-4" />
                                </Button>
                            )}
                        </div>
                    ))}
                    {errores.documento && <p className="text-destructive text-xs">{errores.documento}</p>}
                </CardContent>
            </Card>

            {residuoDlg && <ResiduoDialog residuo={residuoDlg === 'nuevo' ? null : residuoDlg} props={props} onClose={() => setResiduoDlg(null)} />}
            {lecturaDlg && <LecturaDialog recursos={props.recursos} onClose={() => setLecturaDlg(false)} />}
            {quimicoDlg && <QuimicoDialog quimico={quimicoDlg === 'nuevo' ? null : quimicoDlg} props={props} onClose={() => setQuimicoDlg(null)} />}
        </ModuloPage>
    );
}

function Consumos({
    lecturas,
    recursos,
    canManage,
    borrar,
}: {
    lecturas: Lectura[];
    recursos: Props['recursos'];
    canManage: boolean;
    borrar: (url: string, que: string) => void;
}) {
    const porClave = new Map(lecturas.map((l) => [`${l.recurso}|${l.mes}`, l]));
    const usados = Object.keys(recursos).filter((r) => lecturas.some((l) => l.recurso === r));
    const meses = [...new Set(lecturas.map((l) => l.mes))].sort().reverse().slice(0, 12);
    const anterior = (mes: string) => `${Number(mes.slice(0, 4)) - 1}${mes.slice(4)}`;

    if (usados.length === 0) {
        return (
            <Card>
                <CardContent className="text-muted-foreground p-8 text-center text-sm">
                    Sin lecturas. Registra cada mes el consumo de las facturas de agua y energía (y gas o combustible si aplica).
                </CardContent>
            </Card>
        );
    }

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            {usados.map((r) => (
                <Card key={r}>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">
                            {recursos[r][0]} <span className="text-muted-foreground text-sm font-normal">({recursos[r][1]})</span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                <tr>
                                    <th className={th}>Mes</th>
                                    <th className={cn(th, 'text-right')}>Consumo</th>
                                    <th className={cn(th, 'text-right')}>Por persona</th>
                                    <th className={cn(th, 'text-right')}>vs. año anterior</th>
                                    {canManage && <th className={th} />}
                                </tr>
                            </thead>
                            <tbody>
                                {meses.map((mes) => {
                                    const l = porClave.get(`${r}|${mes}`);
                                    if (!l) return null;
                                    const a = porClave.get(`${r}|${anterior(mes)}`);
                                    const v = a && a.cantidad > 0 ? Math.round((1000 * (l.cantidad - a.cantidad)) / a.cantidad) / 10 : null;
                                    return (
                                        <tr key={mes} className="border-t">
                                            <td className="px-3 py-2 tabular-nums">{mes}</td>
                                            <td className="px-3 py-2 text-right tabular-nums">{num(l.cantidad, 2)}</td>
                                            <td className="text-muted-foreground px-3 py-2 text-right tabular-nums">
                                                {l.por_persona === null ? '—' : num(l.por_persona, 2)}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-3 py-2 text-right tabular-nums',
                                                    v !== null && (v > 0 ? 'text-destructive' : 'text-emerald-700 dark:text-emerald-400'),
                                                )}
                                            >
                                                {v === null ? '—' : `${v > 0 ? '+' : ''}${num(v)} %`}
                                            </td>
                                            {canManage && (
                                                <td className="px-2 py-1 text-right">
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        className="size-7"
                                                        aria-label="Eliminar lectura"
                                                        onClick={() => borrar(`/ambiental/lecturas/${l.id}`, `la lectura de ${mes}`)}
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                    </Button>
                                                </td>
                                            )}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

function Matriz({ quimicos, matriz }: { quimicos: Quimico[]; matriz: Props['matriz'] }) {
    if (quimicos.length < 2) return null;

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-base">Matriz de compatibilidad (orientativa)</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                <div className="overflow-x-auto">
                    <table className="text-xs">
                        <thead>
                            <tr>
                                <th />
                                {quimicos.map((q, i) => (
                                    <th key={q.id} className="size-8 text-center font-semibold" title={q.nombre}>
                                        {i + 1}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {quimicos.map((a, i) => (
                                <tr key={a.id}>
                                    <td className="max-w-56 truncate pr-3 whitespace-nowrap" title={a.nombre}>
                                        {i + 1}. {a.nombre}
                                    </td>
                                    {quimicos.map((b) => {
                                        if (a.id === b.id) return <td key={b.id} className="bg-muted/40 size-8" />;
                                        const r = matriz[`${Math.min(a.id, b.id)}-${Math.max(a.id, b.id)}`] ?? 'compatible';
                                        return (
                                            <td key={b.id} className="p-0.5">
                                                <div
                                                    title={`${a.nombre} + ${b.nombre}: ${COMPAT[r].label}`}
                                                    className={cn('flex size-7 items-center justify-center rounded font-semibold', COMPAT[r].cls)}
                                                >
                                                    {COMPAT[r].txt}
                                                </div>
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <p className="text-muted-foreground text-xs">
                    C: compatible · S: separar (distancia, barrera o contención propia) · X: no almacenar juntos. Sale de los pictogramas; la decisión
                    final se toma con las secciones 7 y 10 de cada HDS (un ácido y una base comparten el pictograma de corrosivo y no van juntos).
                </p>
            </CardContent>
        </Card>
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

function Campo({ id, label, error, children, className }: { id: string; label: string; error?: string; children: ReactNode; className?: string }) {
    return (
        <div className={cn('grid gap-2', className)}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function Pie({ onClose, processing }: { onClose: () => void; processing: boolean }) {
    return (
        <DialogFooter className="gap-2 pt-2">
            <Button type="button" variant="outline" onClick={onClose}>
                Cancelar
            </Button>
            <Button type="submit" disabled={processing}>
                Guardar
            </Button>
        </DialogFooter>
    );
}

function Opciones({ opciones, vacio }: { opciones: Record<string, string>; vacio?: string }) {
    return (
        <>
            {vacio !== undefined && <option value="">{vacio}</option>}
            {Object.entries(opciones).map(([k, v]) => (
                <option key={k} value={k}>
                    {v}
                </option>
            ))}
        </>
    );
}

function ResiduoDialog({ residuo, props, onClose }: { residuo: Residuo | null; props: Props; onClose: () => void }) {
    const { data, setData, post, processing, errors, progress } = useForm<{
        fecha: string;
        tipo: string;
        corriente: string;
        cantidad_kg: string;
        gestor: string;
        licencia_gestor: string;
        disposicion: string;
        certificado: string;
        observaciones: string;
        archivo: File | null;
    }>({
        fecha: residuo?.fecha ?? hoy(),
        tipo: residuo?.tipo ?? 'aprovechable',
        corriente: residuo?.corriente ?? '',
        cantidad_kg: residuo ? String(residuo.cantidad_kg) : '',
        gestor: residuo?.gestor ?? '',
        licencia_gestor: residuo?.licencia_gestor ?? '',
        disposicion: residuo?.disposicion ?? '',
        certificado: residuo?.certificado ?? '',
        observaciones: residuo?.observaciones ?? '',
        archivo: null,
    });
    const requiereCert = props.conCertificado.includes(data.tipo);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(residuo ? `/ambiental/residuos/${residuo.id}` : '/ambiental/residuos', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{residuo ? 'Editar entrega de residuos' : 'Entrega de residuos a un gestor'}</DialogTitle>
                    <DialogDescription>Cuánto se entregó, a quién y qué se hizo con ello.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Campo id="r-fecha" label="Fecha" error={errors.fecha}>
                            <Input id="r-fecha" type="date" value={data.fecha} onChange={(e) => setData('fecha', e.target.value)} />
                        </Campo>
                        <Campo id="r-tipo" label="Tipo" className="sm:col-span-2">
                            <select id="r-tipo" value={data.tipo} onChange={(e) => setData('tipo', e.target.value)} className={selectCls}>
                                <Opciones opciones={props.tiposResiduo} />
                            </select>
                        </Campo>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Campo id="r-corriente" label="Residuo" error={errors.corriente} className="sm:col-span-2">
                            <Input
                                id="r-corriente"
                                value={data.corriente}
                                placeholder={
                                    data.tipo === 'peligroso'
                                        ? 'Aceite usado, luminarias, envases contaminados…'
                                        : 'Cartón, plástico, restos de comida…'
                                }
                                onChange={(e) => setData('corriente', e.target.value)}
                            />
                        </Campo>
                        <Campo id="r-kg" label="Cantidad (kg)" error={errors.cantidad_kg}>
                            <Input
                                id="r-kg"
                                type="number"
                                step="0.01"
                                min="0"
                                value={data.cantidad_kg}
                                onChange={(e) => setData('cantidad_kg', e.target.value)}
                            />
                        </Campo>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Campo id="r-gestor" label="Gestor" error={errors.gestor}>
                            <Input id="r-gestor" value={data.gestor} onChange={(e) => setData('gestor', e.target.value)} />
                        </Campo>
                        <Campo id="r-licencia" label="Licencia o permiso ambiental del gestor" error={errors.licencia_gestor}>
                            <Input id="r-licencia" value={data.licencia_gestor} onChange={(e) => setData('licencia_gestor', e.target.value)} />
                        </Campo>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Campo id="r-disp" label="Disposición" error={errors.disposicion}>
                            <select
                                id="r-disp"
                                value={data.disposicion}
                                onChange={(e) => setData('disposicion', e.target.value)}
                                className={selectCls}
                            >
                                <Opciones opciones={props.disposiciones} vacio="Sin definir" />
                            </select>
                        </Campo>
                        <Campo id="r-cert" label="N.º de certificado de disposición" error={errors.certificado}>
                            <Input id="r-cert" value={data.certificado} onChange={(e) => setData('certificado', e.target.value)} />
                        </Campo>
                    </div>
                    <Campo
                        id="r-archivo"
                        label={residuo?.tiene_archivo ? 'Reemplazar certificado (PDF o imagen)' : 'Certificado (PDF o imagen)'}
                        error={errors.archivo}
                    >
                        <Input
                            id="r-archivo"
                            type="file"
                            accept=".pdf,.png,.jpg,.jpeg"
                            onChange={(e) => setData('archivo', e.target.files?.[0] ?? null)}
                        />
                        {progress && <progress value={progress.percentage} max="100" className="w-full" />}
                    </Campo>
                    {requiereCert && (
                        <p className="text-muted-foreground text-xs">
                            Los residuos peligrosos y los RAEE se entregan solo a gestores con licencia, y el certificado de disposición se conserva
                            cinco años.
                        </p>
                    )}
                    <Pie onClose={onClose} processing={processing} />
                </form>
            </DialogContent>
        </Dialog>
    );
}

function LecturaDialog({ recursos, onClose }: { recursos: Props['recursos']; onClose: () => void }) {
    const mesPasado = (() => {
        const d = new Date();
        d.setMonth(d.getMonth() - 1, 1);
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    })();
    const { data, setData, post, processing, errors } = useForm({
        mes: mesPasado,
        recurso: 'agua',
        cantidad: '',
        costo: '',
        trabajadores: '',
        observaciones: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/ambiental/lecturas', { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Consumo del mes</DialogTitle>
                    <DialogDescription>Una lectura por mes y recurso: si el mes ya tiene lectura, se corrige.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Campo id="l-mes" label="Mes" error={errors.mes}>
                            <Input id="l-mes" type="month" value={data.mes} onChange={(e) => setData('mes', e.target.value)} />
                        </Campo>
                        <Campo id="l-recurso" label="Recurso">
                            <select id="l-recurso" value={data.recurso} onChange={(e) => setData('recurso', e.target.value)} className={selectCls}>
                                {Object.entries(recursos).map(([k, [etiqueta, unidad]]) => (
                                    <option key={k} value={k}>
                                        {etiqueta} ({unidad})
                                    </option>
                                ))}
                            </select>
                        </Campo>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Campo id="l-cantidad" label={`Consumo (${recursos[data.recurso][1]})`} error={errors.cantidad}>
                            <Input
                                id="l-cantidad"
                                type="number"
                                step="0.01"
                                min="0"
                                value={data.cantidad}
                                onChange={(e) => setData('cantidad', e.target.value)}
                            />
                        </Campo>
                        <Campo id="l-costo" label="Costo ($)" error={errors.costo}>
                            <Input id="l-costo" type="number" min="0" value={data.costo} onChange={(e) => setData('costo', e.target.value)} />
                        </Campo>
                        <Campo id="l-trab" label="Trabajadores" error={errors.trabajadores}>
                            <Input
                                id="l-trab"
                                type="number"
                                min="1"
                                value={data.trabajadores}
                                onChange={(e) => setData('trabajadores', e.target.value)}
                            />
                        </Campo>
                    </div>
                    <Pie onClose={onClose} processing={processing} />
                </form>
            </DialogContent>
        </Dialog>
    );
}

function QuimicoDialog({ quimico, props, onClose }: { quimico: Quimico | null; props: Props; onClose: () => void }) {
    const { data, setData, post, put, processing, errors } = useForm({
        nombre: quimico?.nombre ?? '',
        proveedor: quimico?.proveedor ?? '',
        uso: quimico?.uso ?? '',
        cantidad: quimico?.cantidad ?? '',
        ubicacion: quimico?.ubicacion ?? '',
        peligros: quimico?.peligros ?? ([] as string[]),
        hds_fecha: quimico?.hds_fecha ?? '',
        epp: quimico?.epp ?? '',
        activo: quimico?.activo ?? true,
        observaciones: quimico?.observaciones ?? '',
    });

    const alternar = (g: string) => setData('peligros', data.peligros.includes(g) ? data.peligros.filter((x) => x !== g) : [...data.peligros, g]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (quimico) put(`/ambiental/quimicos/${quimico.id}`, opts);
        else post('/ambiental/quimicos', opts);
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{quimico ? `Editar ${quimico.nombre}` : 'Nuevo producto químico'}</DialogTitle>
                    <DialogDescription>Los peligros salen de la etiqueta o de la sección 2 de la hoja de datos de seguridad.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Campo id="q-nombre" label="Nombre comercial" error={errors.nombre}>
                            <Input id="q-nombre" value={data.nombre} onChange={(e) => setData('nombre', e.target.value)} />
                        </Campo>
                        <Campo id="q-proveedor" label="Proveedor o fabricante">
                            <Input id="q-proveedor" value={data.proveedor} onChange={(e) => setData('proveedor', e.target.value)} />
                        </Campo>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Campo id="q-uso" label="Uso">
                            <Input id="q-uso" value={data.uso} onChange={(e) => setData('uso', e.target.value)} />
                        </Campo>
                        <Campo id="q-cantidad" label="Cantidad almacenada">
                            <Input
                                id="q-cantidad"
                                value={data.cantidad}
                                placeholder="2 galones"
                                onChange={(e) => setData('cantidad', e.target.value)}
                            />
                        </Campo>
                        <Campo id="q-ubicacion" label="Ubicación">
                            <Input id="q-ubicacion" value={data.ubicacion} onChange={(e) => setData('ubicacion', e.target.value)} />
                        </Campo>
                    </div>
                    <div className="grid gap-2">
                        <Label>Pictogramas SGA</Label>
                        <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-3">
                            {Object.entries(props.pictogramas).map(([g, etiqueta]) => (
                                <label key={g} className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={data.peligros.includes(g)} onChange={() => alternar(g)} className="size-4" />
                                    {etiqueta}
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.peligros} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Campo id="q-hds" label="Fecha de la HDS" error={errors.hds_fecha}>
                            <Input id="q-hds" type="date" value={data.hds_fecha} onChange={(e) => setData('hds_fecha', e.target.value)} />
                        </Campo>
                        <Campo id="q-epp" label="EPP para manipularlo" className="sm:col-span-2">
                            <Input
                                id="q-epp"
                                value={data.epp}
                                placeholder="Guantes de nitrilo, gafas"
                                onChange={(e) => setData('epp', e.target.value)}
                            />
                        </Campo>
                    </div>
                    <p className="text-muted-foreground text-xs">
                        Sin fecha = no se tiene la HDS. Una de más de {props.hdsAnios} años se pide de nuevo al proveedor.
                    </p>
                    <Campo id="q-obs" label="Observaciones">
                        <textarea
                            id="q-obs"
                            rows={2}
                            value={data.observaciones}
                            onChange={(e) => setData('observaciones', e.target.value)}
                            className={textareaCls}
                        />
                    </Campo>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={data.activo} onChange={(e) => setData('activo', e.target.checked)} className="size-4" />
                        En uso (los inactivos salen de la matriz de compatibilidad)
                    </label>
                    <Pie onClose={onClose} processing={processing} />
                </form>
            </DialogContent>
        </Dialog>
    );
}
