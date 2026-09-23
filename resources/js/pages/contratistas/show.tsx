import InputError from '@/components/input-error';
import { ModuloPage } from '@/components/modulo-page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ClipboardCheck, PenLine, Plus, Save, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { calificar, type Catalogos, COLOR_RESULTADO, type Formato, puntajeTexto, type Respuesta, type Situacion, TIPO_LABEL } from './tipos';

interface Documento {
    tipo: string;
    nombre: string | null;
    estado: 'recibido' | 'no_entregado' | 'no_aplica';
    fecha_expedicion: string | null;
    fecha_vencimiento: string | null;
    observacion: string | null;
    alerta?: string | null;
}

interface Evaluacion {
    id: number;
    formato: string;
    uso: string;
    fecha: string;
    evaluador: string | null;
    evaluador_cargo: string | null;
    estructura: Formato;
    respuestas: Record<string, { opcion: number | 'na'; observacion: string | null }>;
    puntaje: number;
    porcentaje: number;
    resultado: string;
    incumplimientos: number;
    observaciones: string | null;
}

interface Contratista {
    id: number;
    nombre: string;
    nit: string | null;
    tipo: string;
    persona: 'juridica' | 'natural' | null;
    actividad: string | null;
    direccion: string | null;
    ciudad: string | null;
    representante_legal: string | null;
    supervisor: string | null;
    fecha_ingreso: string | null;
    contacto_nombre: string | null;
    contacto_telefono: string | null;
    contacto_email: string | null;
    observaciones: string | null;
    is_active: boolean;
}

interface Props {
    needsClient: boolean;
    anio: number;
    contratista: Contratista;
    documentos: Documento[];
    evaluaciones: Evaluacion[];
    situacion: Situacion;
    catalogos: Catalogos;
}

const selectCls = 'border-input bg-background h-9 w-full rounded-md border px-2 text-sm';
const hoy = () => new Date().toISOString().slice(0, 10);
const ALERTA: Record<string, [string, string]> = {
    vencido: ['Vencido', 'text-red-700 dark:text-red-400'],
    por_vencer: ['Por vencer', 'text-amber-700 dark:text-amber-400'],
    no_entregado: ['Sin entregar', 'text-muted-foreground'],
};

function ResultadoBadge({ resultado, catalogos }: { resultado: string; catalogos: Catalogos }) {
    return (
        <span className={cn('rounded-full border px-2 py-0.5 text-[11px] whitespace-nowrap', COLOR_RESULTADO[resultado])}>
            {catalogos.resultados[resultado] ?? resultado}
        </span>
    );
}

/** Editor keyed por contratista: Inertia reutiliza el componente al navegar entre fichas. */
export default function ContratistaShow(props: Props) {
    return <Ficha key={props.contratista.id} {...props} />;
}

function Ficha({ needsClient, contratista, documentos, evaluaciones, situacion, catalogos }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const errors = usePage<SharedData & { errors: Record<string, string> }>().props.errors;

    const [datos, setDatos] = useState({
        nombre: contratista.nombre,
        nit: contratista.nit ?? '',
        tipo: contratista.tipo,
        persona: contratista.persona ?? 'juridica',
        actividad: contratista.actividad ?? '',
        direccion: contratista.direccion ?? '',
        ciudad: contratista.ciudad ?? '',
        representante_legal: contratista.representante_legal ?? '',
        supervisor: contratista.supervisor ?? '',
        fecha_ingreso: contratista.fecha_ingreso ?? '',
        contacto_nombre: contratista.contacto_nombre ?? '',
        contacto_telefono: contratista.contacto_telefono ?? '',
        contacto_email: contratista.contacto_email ?? '',
        observaciones: contratista.observaciones ?? '',
        is_active: contratista.is_active,
    });
    const [docs, setDocs] = useState<Documento[]>(documentos);
    const [sucio, setSucio] = useState(false);
    const [guardando, setGuardando] = useState(false);

    const campo = (k: keyof typeof datos, v: string | boolean) => {
        setDatos((d) => ({ ...d, [k]: v }));
        setSucio(true);
    };
    const doc = (i: number, cambio: Partial<Documento>) => {
        setDocs((d) => d.map((x, j) => (j === i ? { ...x, ...cambio } : x)));
        setSucio(true);
    };

    const faltantes = catalogos.requeridos[datos.persona as 'juridica' | 'natural'].filter((t) => !docs.some((d) => d.tipo === t));

    const guardar = () => {
        setGuardando(true);
        router.put(
            `/contratistas/${contratista.id}`,
            {
                ...datos,
                fecha_ingreso: datos.fecha_ingreso || null,
                documentos: docs.map((d) => ({
                    tipo: d.tipo,
                    nombre: d.nombre,
                    estado: d.estado,
                    fecha_expedicion: d.fecha_expedicion || null,
                    fecha_vencimiento: d.fecha_vencimiento || null,
                    observacion: d.observacion,
                })),
            } as never,
            {
                preserveScroll: true,
                // La tabla vive en estado local: sin esto no se enteraría de lo que el
                // servidor calculó al guardar (un documento que quedó vencido).
                onSuccess: (page) => {
                    setDocs((page.props as unknown as Props).documentos);
                    setSucio(false);
                },
                onFinish: () => setGuardando(false),
            },
        );
    };

    // ------------------------------------------------------------ evaluación
    const [evalAbierta, setEvalAbierta] = useState(false);
    const [evalEditando, setEvalEditando] = useState<Evaluacion | null>(null);
    const [formatoClave, setFormatoClave] = useState<string>('');
    const [evalDatos, setEvalDatos] = useState({ fecha: hoy(), evaluador: '', evaluador_cargo: '', observaciones: '' });
    const [respuestas, setRespuestas] = useState<Record<string, Respuesta>>({});
    const [evalGuardando, setEvalGuardando] = useState(false);

    const formatoActivo: Formato | null = evalEditando ? evalEditando.estructura : formatoClave ? catalogos.formatos[formatoClave] : null;
    const calificacion = formatoActivo ? calificar(formatoActivo, respuestas, catalogos.umbrales) : null;

    const abrirEvaluacion = (clave: string, e?: Evaluacion) => {
        setEvalEditando(e ?? null);
        setFormatoClave(e?.formato ?? clave);
        setEvalDatos(
            e
                ? { fecha: e.fecha, evaluador: e.evaluador ?? '', evaluador_cargo: e.evaluador_cargo ?? '', observaciones: e.observaciones ?? '' }
                : { fecha: hoy(), evaluador: datos.supervisor, evaluador_cargo: '', observaciones: '' },
        );
        setRespuestas(
            e ? Object.fromEntries(Object.entries(e.respuestas).map(([k, r]) => [k, { opcion: r.opcion, observacion: r.observacion ?? '' }])) : {},
        );
        setEvalAbierta(true);
    };

    const responder = (key: string, cambio: Partial<Respuesta>) =>
        setRespuestas((r) => ({ ...r, [key]: { ...(r[key] ?? { opcion: null, observacion: '' }), ...cambio } }));

    const guardarEvaluacion = () => {
        setEvalGuardando(true);
        const cuerpo = { ...evalDatos, formato: formatoClave, respuestas } as never;
        const opts = { preserveScroll: true, onSuccess: () => setEvalAbierta(false), onFinish: () => setEvalGuardando(false) };
        if (evalEditando) router.put(`/contratistas/evaluaciones/${evalEditando.id}`, cuerpo, opts);
        else router.post(`/contratistas/${contratista.id}/evaluaciones`, cuerpo, opts);
    };

    // Formularios sugeridos primero: el de requisitos SST que corresponde a la persona.
    const formatosOrdenados = Object.entries(catalogos.formatos).sort(([a], [b]) => {
        const sugerido = datos.persona === 'natural' ? 'SST_NATURAL' : 'SST_JURIDICA';
        const otro = datos.persona === 'natural' ? 'SST_JURIDICA' : 'SST_NATURAL';
        const peso = (k: string) => (k === otro ? 2 : k === sugerido ? 0 : 1);
        return peso(a) - peso(b);
    });

    const s = situacion;

    return (
        <ModuloPage
            titulo={contratista.nombre}
            descripcion={`${TIPO_LABEL[contratista.tipo] ?? contratista.tipo}${contratista.nit ? ` · ${contratista.nit}` : ''}`}
            needsClient={needsClient}
            accion={
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" asChild className="gap-2">
                        <Link href="/contratistas">
                            <ArrowLeft className="size-4" /> Contratistas
                        </Link>
                    </Button>
                    {canManage && (
                        <Button onClick={guardar} disabled={guardando} className="gap-2">
                            <Save className="size-4" /> {guardando ? 'Guardando…' : 'Guardar ficha'}
                        </Button>
                    )}
                </div>
            }
        >
            {Object.values(errors ?? {})[0] && !evalAbierta && <InputError message={Object.values(errors)[0]} />}

            {/* Situación */}
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {(
                    [
                        ['Selección', s.seleccion],
                        ['Requisitos SST', s.requisitos_sst],
                        ['Última evaluación', s.evaluacion],
                    ] as const
                ).map(([titulo, r]) => (
                    <Card key={titulo}>
                        <CardContent className="space-y-1.5 p-4">
                            <div className="text-muted-foreground text-xs">{titulo}</div>
                            {r ? (
                                <>
                                    <ResultadoBadge resultado={r.resultado} catalogos={catalogos} />
                                    <div className="text-muted-foreground text-xs tabular-nums">
                                        {catalogos.formatos[r.formato]?.escala === 'chequeo'
                                            ? `${r.incumplimientos} incumplimiento(s) · ${r.fecha}`
                                            : `${puntajeTexto(catalogos.formatos[r.formato]?.escala, r.puntaje, r.porcentaje)} · ${r.fecha}`}
                                    </div>
                                </>
                            ) : (
                                <div className="text-muted-foreground text-sm">Sin registrar</div>
                            )}
                            {titulo === 'Última evaluación' && s.proxima_evaluacion && (
                                <div className={cn('text-xs', s.evaluacion_vencida ? 'text-red-700 dark:text-red-400' : 'text-muted-foreground')}>
                                    {s.evaluacion_vencida ? 'Vencida desde' : 'Próxima'} {s.proxima_evaluacion}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ))}
                <Card>
                    <CardContent className="space-y-1.5 p-4">
                        <div className="text-muted-foreground text-xs">Reevaluación anual</div>
                        {s.reevaluacion ? (
                            <>
                                <ResultadoBadge resultado={s.reevaluacion.resultado} catalogos={catalogos} />
                                <div className="text-muted-foreground text-xs tabular-nums">
                                    {s.reevaluacion.porcentaje} % · promedio de {s.reevaluacion.evaluaciones} evaluación(es) de {s.reevaluacion.anio}
                                </div>
                            </>
                        ) : (
                            <div className="text-muted-foreground text-sm">Sin evaluaciones este año</div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Hoja de vida */}
            <Card>
                <CardContent className="grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div className="space-y-1.5 sm:col-span-2">
                        <Label htmlFor="nombre">Nombre o razón social</Label>
                        <Input id="nombre" value={datos.nombre} disabled={!canManage} onChange={(e) => campo('nombre', e.target.value)} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="nit">NIT o cédula</Label>
                        <Input id="nit" value={datos.nit} disabled={!canManage} onChange={(e) => campo('nit', e.target.value)} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="persona">Tipo de persona</Label>
                        <select
                            id="persona"
                            value={datos.persona}
                            disabled={!canManage}
                            onChange={(e) => campo('persona', e.target.value)}
                            className={selectCls}
                        >
                            <option value="juridica">Jurídica</option>
                            <option value="natural">Natural</option>
                        </select>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="tipo">Relación</Label>
                        <select
                            id="tipo"
                            value={datos.tipo}
                            disabled={!canManage}
                            onChange={(e) => campo('tipo', e.target.value)}
                            className={selectCls}
                        >
                            {catalogos.tipos.map((t) => (
                                <option key={t} value={t}>
                                    {TIPO_LABEL[t] ?? t}
                                </option>
                            ))}
                        </select>
                    </div>
                    {(
                        [
                            ['actividad', 'Bien o servicio que presta'],
                            ['representante_legal', 'Representante legal'],
                            ['supervisor', 'Supervisor del contrato'],
                            ['direccion', 'Dirección'],
                            ['ciudad', 'Ciudad'],
                            ['contacto_nombre', 'Contacto'],
                            ['contacto_telefono', 'Teléfono'],
                            ['contacto_email', 'Correo'],
                        ] as const
                    ).map(([k, l]) => (
                        <div key={k} className="space-y-1.5">
                            <Label htmlFor={k}>{l}</Label>
                            <Input id={k} value={datos[k]} disabled={!canManage} onChange={(e) => campo(k, e.target.value)} />
                        </div>
                    ))}
                    <div className="space-y-1.5">
                        <Label htmlFor="fecha_ingreso">Fecha de ingreso</Label>
                        <Input
                            id="fecha_ingreso"
                            type="date"
                            value={datos.fecha_ingreso}
                            disabled={!canManage}
                            onChange={(e) => campo('fecha_ingreso', e.target.value)}
                        />
                    </div>
                    <label className="flex items-center gap-2 self-end pb-2 text-sm">
                        <input
                            type="checkbox"
                            checked={datos.is_active}
                            disabled={!canManage}
                            onChange={(e) => campo('is_active', e.target.checked)}
                        />
                        Activo
                    </label>
                </CardContent>
            </Card>

            {/* Documentos */}
            <Card className="overflow-hidden">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                    <h2 className="font-semibold">Documentos</h2>
                    {canManage && (
                        <div className="flex flex-wrap gap-2">
                            {faltantes.length > 0 && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                        setDocs((d) => [
                                            ...d,
                                            ...faltantes.map((t) => ({
                                                tipo: t,
                                                nombre: null,
                                                estado: 'no_entregado' as const,
                                                fecha_expedicion: null,
                                                fecha_vencimiento: null,
                                                observacion: null,
                                            })),
                                        ]);
                                        setSucio(true);
                                    }}
                                >
                                    Agregar los {faltantes.length} requeridos que faltan
                                </Button>
                            )}
                            <Button
                                size="sm"
                                variant="outline"
                                className="gap-1"
                                onClick={() => {
                                    setDocs((d) => [
                                        ...d,
                                        {
                                            tipo: 'otro',
                                            nombre: '',
                                            estado: 'recibido',
                                            fecha_expedicion: null,
                                            fecha_vencimiento: null,
                                            observacion: null,
                                        },
                                    ]);
                                    setSucio(true);
                                }}
                            >
                                <Plus className="size-3.5" /> Documento
                            </Button>
                        </div>
                    )}
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[52rem] text-sm">
                        <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                            <tr>
                                <th className="px-3 py-2 font-medium">Documento</th>
                                <th className="px-3 py-2 font-medium">Estado</th>
                                <th className="px-3 py-2 font-medium">Expedición</th>
                                <th className="px-3 py-2 font-medium">Vence</th>
                                <th className="px-3 py-2 font-medium">Observación</th>
                                <th className="w-8" />
                            </tr>
                        </thead>
                        <tbody>
                            {docs.map((d, i) => (
                                <tr key={i} className="border-t">
                                    <td className="px-3 py-2">
                                        {d.tipo === 'otro' ? (
                                            <Input
                                                value={d.nombre ?? ''}
                                                placeholder="Nombre del documento"
                                                aria-label="Nombre del documento"
                                                disabled={!canManage}
                                                onChange={(e) => doc(i, { nombre: e.target.value })}
                                                className="h-8"
                                            />
                                        ) : (
                                            catalogos.documentos[d.tipo]
                                        )}
                                        {d.alerta && ALERTA[d.alerta] && (
                                            <div className={cn('text-[11px]', ALERTA[d.alerta][1])}>{ALERTA[d.alerta][0]}</div>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <select
                                            aria-label={`Estado de ${catalogos.documentos[d.tipo]}`}
                                            value={d.estado}
                                            disabled={!canManage}
                                            onChange={(e) => doc(i, { estado: e.target.value as Documento['estado'] })}
                                            className="border-input bg-background h-8 rounded-md border px-1 text-xs"
                                        >
                                            <option value="recibido">Recibido y revisado</option>
                                            <option value="no_entregado">No entregado</option>
                                            <option value="no_aplica">No aplica</option>
                                        </select>
                                    </td>
                                    {(['fecha_expedicion', 'fecha_vencimiento'] as const).map((k) => (
                                        <td key={k} className="px-3 py-2">
                                            <input
                                                type="date"
                                                aria-label={k === 'fecha_expedicion' ? 'Expedición' : 'Vencimiento'}
                                                value={d[k] ?? ''}
                                                disabled={!canManage}
                                                onChange={(e) => doc(i, { [k]: e.target.value || null })}
                                                className="border-input bg-background h-8 rounded-md border px-1 text-xs"
                                            />
                                        </td>
                                    ))}
                                    <td className="px-3 py-2">
                                        <input
                                            value={d.observacion ?? ''}
                                            disabled={!canManage}
                                            aria-label="Observación"
                                            onChange={(e) => doc(i, { observacion: e.target.value })}
                                            className="border-input bg-background h-8 w-full min-w-40 rounded-md border px-2 text-xs"
                                        />
                                    </td>
                                    <td className="px-1 text-center">
                                        {canManage && (
                                            <button
                                                type="button"
                                                aria-label="Quitar documento"
                                                className="text-muted-foreground hover:text-destructive"
                                                onClick={() => {
                                                    setDocs((x) => x.filter((_, j) => j !== i));
                                                    setSucio(true);
                                                }}
                                            >
                                                <X className="size-4" />
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>

            {/* Evaluaciones */}
            <Card className="overflow-hidden">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                    <h2 className="font-semibold">Selección, requisitos SST y evaluaciones</h2>
                    {canManage && (
                        <select
                            aria-label="Nuevo formulario"
                            value=""
                            disabled={sucio}
                            title={sucio ? 'Guarda la ficha antes de evaluar' : undefined}
                            onChange={(e) => e.target.value && abrirEvaluacion(e.target.value)}
                            className="border-input bg-background h-9 max-w-80 rounded-md border px-2 text-sm"
                        >
                            <option value="">+ Diligenciar formulario…</option>
                            {formatosOrdenados.map(([k, f]) => (
                                <option key={k} value={k}>
                                    {f.nombre}
                                </option>
                            ))}
                        </select>
                    )}
                </div>
                {evaluaciones.length === 0 ? (
                    <p className="text-muted-foreground p-6 text-center text-sm">Todavía no se ha diligenciado ningún formulario.</p>
                ) : (
                    <div className="divide-y">
                        {evaluaciones.map((e) => (
                            <div key={e.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                <ClipboardCheck className="text-muted-foreground size-4 shrink-0" />
                                <div className="min-w-0 flex-1">
                                    <div className="text-sm font-medium">{e.estructura.nombre}</div>
                                    <div className="text-muted-foreground text-xs">
                                        {e.fecha}
                                        {e.evaluador && ` · ${e.evaluador}`}
                                    </div>
                                </div>
                                <div className="text-right">
                                    <ResultadoBadge resultado={e.resultado} catalogos={catalogos} />
                                    <div className="text-muted-foreground mt-0.5 text-xs tabular-nums">
                                        {e.estructura.escala === 'chequeo'
                                            ? `${e.incumplimientos} incumplimiento(s)`
                                            : puntajeTexto(e.estructura.escala, e.puntaje, e.porcentaje)}
                                    </div>
                                </div>
                                {canManage && (
                                    <div className="flex">
                                        <Button size="sm" variant="ghost" title="Editar" onClick={() => abrirEvaluacion(e.formato, e)}>
                                            <PenLine className="size-4" />
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            title="Eliminar"
                                            onClick={() =>
                                                confirm('¿Eliminar este formulario?') &&
                                                router.delete(`/contratistas/evaluaciones/${e.id}`, { preserveScroll: true })
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </Card>

            {canManage && (
                <div className="flex justify-between">
                    <Button
                        variant="ghost"
                        className="text-destructive gap-2"
                        onClick={() =>
                            confirm(`¿Eliminar a ${contratista.nombre}? También desaparece del PESV, con sus documentos y evaluaciones.`) &&
                            router.delete(`/contratistas/${contratista.id}`)
                        }
                    >
                        <Trash2 className="size-4" /> Eliminar contratista
                    </Button>
                    {sucio && (
                        <Button onClick={guardar} disabled={guardando} className="gap-2">
                            <Save className="size-4" /> Guardar cambios
                        </Button>
                    )}
                </div>
            )}

            {/* Formulario dinámico */}
            <Dialog open={evalAbierta} onOpenChange={setEvalAbierta}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    {formatoActivo && (
                        <div className="space-y-4">
                            <DialogHeader>
                                <DialogTitle>{formatoActivo.nombre}</DialogTitle>
                                <DialogDescription>
                                    {formatoActivo.fuente}.{' '}
                                    {formatoActivo.escala === 'ponderada' &&
                                        `Criterios ponderados: entra al listado maestro con ${catalogos.umbrales.seleccion} o más sobre 5.`}
                                    {formatoActivo.escala === 'puntos' &&
                                        `Confiable desde ${catalogos.umbrales.confiable} %, regular desde ${catalogos.umbrales.regular} %.`}{' '}
                                    «No aplica» cuenta con la máxima calificación.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-3 sm:grid-cols-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="e-fecha">Fecha</Label>
                                    <Input
                                        id="e-fecha"
                                        type="date"
                                        max={hoy()}
                                        value={evalDatos.fecha}
                                        onChange={(e) => setEvalDatos({ ...evalDatos, fecha: e.target.value })}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="e-evaluador">Evaluado por</Label>
                                    <Input
                                        id="e-evaluador"
                                        value={evalDatos.evaluador}
                                        onChange={(e) => setEvalDatos({ ...evalDatos, evaluador: e.target.value })}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="e-cargo">Cargo</Label>
                                    <Input
                                        id="e-cargo"
                                        value={evalDatos.evaluador_cargo}
                                        onChange={(e) => setEvalDatos({ ...evalDatos, evaluador_cargo: e.target.value })}
                                    />
                                </div>
                            </div>

                            {formatoActivo.secciones.map((sec) => (
                                <div key={sec.titulo} className="rounded-lg border">
                                    <div className="bg-muted/40 border-b px-3 py-2 text-xs font-semibold tracking-wide uppercase">{sec.titulo}</div>
                                    <div className="divide-y">
                                        {sec.items.map((it) => {
                                            const r = respuestas[it.key];
                                            return (
                                                <div key={it.key} className="space-y-1.5 px-3 py-2.5" data-item={it.key}>
                                                    <div className="text-sm">
                                                        {it.texto}
                                                        {it.peso !== undefined && (
                                                            <span className="text-muted-foreground ml-1 text-xs">
                                                                ({Math.round(it.peso * 100)} %)
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        {it.opciones.map((o, idx) => (
                                                            <button
                                                                key={idx}
                                                                type="button"
                                                                onClick={() => responder(it.key, { opcion: idx })}
                                                                className={cn(
                                                                    'rounded-md border px-2 py-1 text-xs',
                                                                    r?.opcion === idx
                                                                        ? 'bg-primary text-primary-foreground border-primary'
                                                                        : 'hover:bg-muted',
                                                                )}
                                                            >
                                                                {o.label}
                                                                {formatoActivo.escala !== 'chequeo' && (
                                                                    <span className="opacity-70"> · {o.puntos}</span>
                                                                )}
                                                            </button>
                                                        ))}
                                                        <button
                                                            type="button"
                                                            onClick={() => responder(it.key, { opcion: 'na' })}
                                                            className={cn(
                                                                'rounded-md border px-2 py-1 text-xs',
                                                                r?.opcion === 'na'
                                                                    ? 'bg-primary text-primary-foreground border-primary'
                                                                    : 'hover:bg-muted',
                                                            )}
                                                        >
                                                            No aplica
                                                        </button>
                                                        <input
                                                            value={r?.observacion ?? ''}
                                                            onChange={(e) => responder(it.key, { observacion: e.target.value })}
                                                            placeholder="Observación"
                                                            aria-label={`Observación: ${it.texto}`}
                                                            className="border-input bg-background h-7 min-w-40 flex-1 rounded-md border px-2 text-xs"
                                                        />
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            ))}

                            <div className="space-y-1.5">
                                <Label htmlFor="e-obs">Observaciones generales</Label>
                                <textarea
                                    id="e-obs"
                                    rows={2}
                                    value={evalDatos.observaciones}
                                    onChange={(e) => setEvalDatos({ ...evalDatos, observaciones: e.target.value })}
                                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                                />
                            </div>
                            <InputError message={errors?.respuestas ?? errors?.fecha} />

                            <DialogFooter className="items-center gap-3 sm:justify-between">
                                <div className="text-sm" data-testid="calificacion">
                                    {calificacion?.resultado ? (
                                        <span className="flex items-center gap-2">
                                            <ResultadoBadge resultado={calificacion.resultado} catalogos={catalogos} />
                                            <span className="tabular-nums">
                                                {formatoActivo.escala === 'chequeo'
                                                    ? `${calificacion.incumplimientos} incumplimiento(s)`
                                                    : puntajeTexto(formatoActivo.escala, calificacion.puntaje ?? 0, calificacion.porcentaje ?? 0)}
                                            </span>
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">Faltan {calificacion?.faltan} por responder</span>
                                    )}
                                </div>
                                <div className="flex gap-2">
                                    <Button type="button" variant="outline" onClick={() => setEvalAbierta(false)}>
                                        Cancelar
                                    </Button>
                                    <Button type="button" onClick={guardarEvaluacion} disabled={evalGuardando}>
                                        Guardar
                                    </Button>
                                </div>
                            </DialogFooter>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </ModuloPage>
    );
}
