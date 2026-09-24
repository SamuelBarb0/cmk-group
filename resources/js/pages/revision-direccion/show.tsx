import { fechaHora, selectCls, SistemaChips, SistemasPicker, textareaCls, type Sistema } from '@/components/control-documental/etiquetas';
import InputError from '@/components/input-error';
import { ModuloPage } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, CircleAlert, Download, Lock, Plus, RefreshCw, Save, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Cifra {
    etiqueta: string;
    valor: string;
    alerta: string | null;
}

interface Tabla {
    titulo: string;
    columnas: string[];
    filas: string[][];
    omitidas: number;
    vacio: string;
}

interface Seccion {
    clave: string;
    titulo: string;
    cifras: Cifra[];
    tablas: Tabla[];
    notas: string[];
}

interface Entrada {
    clave: string;
    titulo: string;
    referencias: string;
    secciones: Seccion[];
    analisis: string;
}

interface Previa {
    revision: string | null;
    tipo: string;
    descripcion: string;
    responsable: string | null;
    fecha_limite: string | null;
    estado: string;
    seguimiento: string | null;
}

interface Decision {
    id: number;
    tipo: string;
    descripcion: string;
    responsable: string | null;
    fecha_limite: string | null;
    estado: string;
    seguimiento: string | null;
    accion: { id: number; codigo: string; estado: string } | null;
}

interface Props {
    revision: {
        id: number;
        codigo: string;
        periodo_desde: string;
        periodo_hasta: string;
        fecha_reunion: string | null;
        sistemas: Sistema[];
        participantes: string | null;
        conclusiones_sistema: Record<string, string> | null;
        conclusiones: string | null;
        estado: 'borrador' | 'cerrada';
        cerrada_por: string | null;
        cerrada_at: string | null;
        datos_at: string | null;
        atencion: { seccion: string; texto: string }[];
    };
    entradas: Entrada[];
    previas: Previa[];
    decisiones: Decision[];
    catalogos: { criterios: Record<string, string>; valoraciones: string[]; tipos: string[]; estados: string[] };
}

const VALORACION: Record<string, string> = { si: 'Sí', parcial: 'Parcialmente', no: 'No' };
const TIPO: Record<string, string> = { mejora: 'Mejora', cambio: 'Cambio al sistema', recursos: 'Recursos', otro: 'Otro' };
/** ISO 9.3 pide concluir sobre la conveniencia, la adecuación y la eficacia del sistema. */
const PREGUNTA: Record<string, string> = {
    conveniente: '¿El sistema sigue siendo conveniente?',
    adecuado: '¿Es adecuado?',
    eficaz: '¿Es eficaz?',
};
const ESTADO: Record<string, string> = { pendiente: 'Pendiente', en_proceso: 'En proceso', cumplida: 'Cumplida', cancelada: 'Cancelada' };

export default function RevisionDireccionShow({ revision, entradas, previas, decisiones, catalogos }: Props) {
    const { can } = usePermissions();
    const canManage = can('reports.generate');
    const editable = canManage && revision.estado === 'borrador';
    const errores = usePage<SharedData>().props.errors as Record<string, string | undefined>;
    const base = `/revision-direccion/${revision.id}`;

    const { data, setData, put, processing, isDirty, setDefaults } = useForm({
        periodo_desde: revision.periodo_desde,
        periodo_hasta: revision.periodo_hasta,
        fecha_reunion: revision.fecha_reunion ?? '',
        sistemas: revision.sistemas,
        participantes: revision.participantes ?? '',
        analisis: Object.fromEntries(entradas.map((e) => [e.clave, e.analisis])) as Record<string, string>,
        conclusiones_sistema: { ...(revision.conclusiones_sistema ?? {}) } as Record<string, string>,
        conclusiones: revision.conclusiones ?? '',
    });

    const guardar: FormEventHandler = (e) => {
        e.preventDefault();
        // Lo guardado pasa a ser la base: si no, el formulario seguiría
        // contando como «sin guardar» y bloquearía el cierre.
        put(base, { preserveScroll: true, onSuccess: () => setDefaults() });
    };

    const sinDatos = !revision.datos_at;

    return (
        <ModuloPage
            titulo={`Revisión ${revision.codigo}`}
            descripcion={`Periodo ${revision.periodo_desde} a ${revision.periodo_hasta}`}
            needsClient={false}
            accion={
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="ghost" className="gap-2">
                        <Link href="/revision-direccion">
                            <ArrowLeft className="size-4" /> Revisiones
                        </Link>
                    </Button>
                    <Button asChild variant="outline" className="gap-2">
                        <a href={`${base}/word`}>
                            <Download className="size-4" /> Word
                        </a>
                    </Button>
                    {editable && (
                        <>
                            <Button
                                variant="outline"
                                className="gap-2"
                                onClick={() => router.post(`${base}/recopilar`, {}, { preserveScroll: true })}
                            >
                                <RefreshCw className="size-4" /> {sinDatos ? 'Recopilar datos' : 'Volver a recopilar'}
                            </Button>
                            <Button
                                className="gap-2"
                                disabled={isDirty}
                                title={isDirty ? 'Guarda primero los cambios' : undefined}
                                onClick={() =>
                                    confirm('Cerrada, la revisión ya no se edita (solo el seguimiento de las decisiones). ¿Cerrarla?') &&
                                    router.post(`${base}/cerrar`, {}, { preserveScroll: true })
                                }
                            >
                                <Lock className="size-4" /> Cerrar revisión
                            </Button>
                        </>
                    )}
                </div>
            }
        >
            <div className="flex flex-wrap items-center gap-3 text-sm">
                <Badge
                    className={cn(
                        'font-normal',
                        revision.estado === 'cerrada' ? 'bg-emerald-500/15 text-emerald-700' : 'bg-muted text-muted-foreground',
                    )}
                >
                    {revision.estado === 'cerrada' ? 'Cerrada' : 'Borrador'}
                </Badge>
                <SistemaChips sistemas={revision.sistemas} />
                {revision.datos_at && <span className="text-muted-foreground text-xs">Datos recopilados el {fechaHora(revision.datos_at)}</span>}
                {revision.cerrada_at && (
                    <span className="text-muted-foreground text-xs">
                        Cerrada por {revision.cerrada_por} el {fechaHora(revision.cerrada_at)}
                    </span>
                )}
            </div>

            {(errores.cierre || errores.estado || errores.acpm) && (
                <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">
                    {errores.cierre ?? errores.estado ?? errores.acpm}
                </div>
            )}

            {sinDatos && editable && (
                <Card>
                    <CardContent className="text-muted-foreground p-6 text-sm">
                        Empieza por <b>Recopilar datos</b>: trae del periodo los resultados de indicadores, plan de trabajo, accidentalidad, ACPM,
                        auditorías, requisitos legales y demás módulos contratados, y los congela para el acta.
                    </CardContent>
                </Card>
            )}

            <form onSubmit={guardar} className="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Datos de la reunión</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="periodo_desde">Periodo desde</Label>
                                <Input
                                    id="periodo_desde"
                                    type="date"
                                    disabled={!editable}
                                    value={data.periodo_desde}
                                    onChange={(e) => setData('periodo_desde', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="periodo_hasta">Periodo hasta</Label>
                                <Input
                                    id="periodo_hasta"
                                    type="date"
                                    disabled={!editable}
                                    value={data.periodo_hasta}
                                    onChange={(e) => setData('periodo_hasta', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fecha_reunion">Fecha de la reunión</Label>
                                <Input
                                    id="fecha_reunion"
                                    type="date"
                                    disabled={!editable}
                                    value={data.fecha_reunion}
                                    onChange={(e) => setData('fecha_reunion', e.target.value)}
                                />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label>Normas</Label>
                            {editable ? (
                                <SistemasPicker value={data.sistemas} onChange={(v) => setData('sistemas', v)} />
                            ) : (
                                <SistemaChips sistemas={data.sistemas} />
                            )}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="participantes">Participantes</Label>
                            <textarea
                                id="participantes"
                                rows={2}
                                disabled={!editable}
                                value={data.participantes}
                                onChange={(e) => setData('participantes', e.target.value)}
                                placeholder="Nombre y cargo: gerente, responsable del SG-SST, líder del PESV…"
                                className={textareaCls}
                            />
                        </div>
                    </CardContent>
                </Card>

                {revision.atencion.length > 0 && (
                    <Card className="border-amber-500/40">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <CircleAlert className="size-4 text-amber-600" /> Puntos de atención del periodo
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="list-disc space-y-1 pl-5 text-sm">
                                {revision.atencion.map((a, i) => (
                                    <li key={i}>
                                        <span className="text-muted-foreground">{a.seccion}:</span> {a.texto}
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {entradas.map((e, i) => (
                    <Card key={e.clave}>
                        <CardHeader className="space-y-1">
                            <CardTitle className="text-base">
                                {i + 1}. {e.titulo}
                            </CardTitle>
                            <p className="text-muted-foreground text-xs">{e.referencias}</p>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {e.clave === 'acciones_previas' &&
                                (previas.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">No hay decisiones de revisiones anteriores.</p>
                                ) : (
                                    <table className="w-full text-sm">
                                        <tbody>
                                            {previas.map((p, j) => (
                                                <tr key={j} className="border-t first:border-t-0">
                                                    <td className="text-muted-foreground py-1.5 pr-3 text-xs whitespace-nowrap">{p.revision}</td>
                                                    <td className="py-1.5 pr-3">{p.descripcion}</td>
                                                    <td className="py-1.5 pr-3 text-xs">{p.responsable ?? '—'}</td>
                                                    <td className="py-1.5 text-xs whitespace-nowrap">{ESTADO[p.estado] ?? p.estado}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                ))}

                            {e.secciones.map((s) => (
                                <SeccionDatos key={s.clave} seccion={s} />
                            ))}
                            {e.secciones.length === 0 && e.clave !== 'acciones_previas' && !sinDatos && (
                                <p className="text-muted-foreground text-xs">
                                    Esta entrada no sale de un módulo: la sustenta el análisis de la dirección.
                                </p>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor={`analisis-${e.clave}`}>Análisis de la dirección</Label>
                                <textarea
                                    id={`analisis-${e.clave}`}
                                    rows={3}
                                    disabled={!editable}
                                    value={data.analisis[e.clave] ?? ''}
                                    onChange={(ev) => setData('analisis', { ...data.analisis, [e.clave]: ev.target.value })}
                                    className={textareaCls}
                                />
                            </div>
                        </CardContent>
                    </Card>
                ))}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Conclusiones sobre el sistema</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-3">
                            {Object.entries(catalogos.criterios).map(([clave, nombre]) => (
                                <div key={clave} className="grid gap-2">
                                    <Label htmlFor={`criterio-${clave}`}>{PREGUNTA[clave] ?? nombre}</Label>
                                    <select
                                        id={`criterio-${clave}`}
                                        disabled={!editable}
                                        value={data.conclusiones_sistema[clave] ?? ''}
                                        onChange={(ev) => setData('conclusiones_sistema', { ...data.conclusiones_sistema, [clave]: ev.target.value })}
                                        className={selectCls}
                                    >
                                        <option value="">Sin concluir</option>
                                        {catalogos.valoraciones.map((v) => (
                                            <option key={v} value={v}>
                                                {VALORACION[v]}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            ))}
                        </div>
                        <textarea
                            rows={3}
                            disabled={!editable}
                            value={data.conclusiones}
                            onChange={(ev) => setData('conclusiones', ev.target.value)}
                            placeholder="Conclusiones generales de la revisión."
                            className={textareaCls}
                            aria-label="Conclusiones generales"
                        />
                    </CardContent>
                </Card>

                {editable && (
                    <div className="bg-background/90 sticky bottom-0 flex justify-end border-t py-3 backdrop-blur">
                        <Button type="submit" className="gap-2" disabled={processing || !isDirty}>
                            <Save className="size-4" /> Guardar revisión
                        </Button>
                    </div>
                )}
            </form>

            <Decisiones base={base} decisiones={decisiones} catalogos={catalogos} editable={editable} canManage={canManage} />

            {editable && (
                <div className="flex justify-end">
                    <Button
                        variant="ghost"
                        className="text-destructive gap-2"
                        onClick={() => confirm(`¿Eliminar la revisión ${revision.codigo}?`) && router.delete(base)}
                    >
                        <Trash2 className="size-4" /> Eliminar borrador
                    </Button>
                </div>
            )}
        </ModuloPage>
    );
}

/** Cifras de una sección del periodo; las tablas quedan plegadas. */
function SeccionDatos({ seccion }: { seccion: Seccion }) {
    return (
        <div className="rounded-md border">
            <div className="bg-muted/40 px-3 py-1.5 text-xs font-semibold">{seccion.titulo}</div>
            <div className="grid gap-x-6 gap-y-1 p-3 text-sm sm:grid-cols-2">
                {seccion.cifras.map((c) => (
                    <div key={c.etiqueta} className="flex justify-between gap-3">
                        <span className="text-muted-foreground">{c.etiqueta}</span>
                        <span className={cn('font-medium tabular-nums', c.alerta && 'text-destructive')} title={c.alerta ?? undefined}>
                            {c.valor}
                        </span>
                    </div>
                ))}
            </div>
            {seccion.tablas.map((t) => (
                <details key={t.titulo} className="border-t px-3 py-1.5 text-xs">
                    <summary className="cursor-pointer font-medium">{t.titulo}</summary>
                    {t.filas.length === 0 ? (
                        <p className="text-muted-foreground py-1">{t.vacio}</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="my-1 w-full">
                                <thead className="text-muted-foreground text-left">
                                    <tr>
                                        {t.columnas.map((c) => (
                                            <th key={c} className="py-1 pr-3 font-semibold">
                                                {c}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {t.filas.map((f, i) => (
                                        <tr key={i} className="border-t">
                                            {f.map((v, j) => (
                                                <td key={j} className="py-1 pr-3">
                                                    {v}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {t.omitidas > 0 && <p className="text-muted-foreground">y {t.omitidas} filas más.</p>}
                        </div>
                    )}
                </details>
            ))}
        </div>
    );
}

function Decisiones({
    base,
    decisiones,
    catalogos,
    editable,
    canManage,
}: {
    base: string;
    decisiones: Decision[];
    catalogos: Props['catalogos'];
    editable: boolean;
    canManage: boolean;
}) {
    const nueva = useForm({ tipo: 'mejora', descripcion: '', responsable: '', fecha_limite: '' });

    const agregar: FormEventHandler = (e) => {
        e.preventDefault();
        nueva.post(`${base}/decisiones`, { preserveScroll: true, onSuccess: () => nueva.reset() });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Decisiones y acciones</CardTitle>
                <p className="text-muted-foreground text-xs">
                    Salidas de la revisión (mejora, cambios al sistema, recursos). Su seguimiento sigue abierto después de cerrar y es la primera
                    entrada de la próxima revisión.
                </p>
            </CardHeader>
            <CardContent className="space-y-3">
                {decisiones.length === 0 && <p className="text-muted-foreground text-sm">Todavía no hay decisiones.</p>}
                {decisiones.map((d) => (
                    <DecisionFila
                        key={`${d.id}-${d.estado}-${d.seguimiento}`}
                        base={base}
                        decision={d}
                        catalogos={catalogos}
                        editable={editable}
                        canManage={canManage}
                    />
                ))}

                {editable && (
                    <form
                        onSubmit={agregar}
                        className="bg-muted/40 grid gap-2 rounded-md p-3 sm:grid-cols-[10rem_1fr_12rem_10rem_auto] sm:items-start"
                    >
                        <select
                            value={nueva.data.tipo}
                            onChange={(e) => nueva.setData('tipo', e.target.value)}
                            className={selectCls}
                            aria-label="Tipo de decisión"
                        >
                            {catalogos.tipos.map((t) => (
                                <option key={t} value={t}>
                                    {TIPO[t]}
                                </option>
                            ))}
                        </select>
                        <div>
                            <Input
                                value={nueva.data.descripcion}
                                onChange={(e) => nueva.setData('descripcion', e.target.value)}
                                placeholder="Decisión"
                                aria-label="Decisión"
                            />
                            <InputError message={nueva.errors.descripcion} />
                        </div>
                        <Input
                            value={nueva.data.responsable}
                            onChange={(e) => nueva.setData('responsable', e.target.value)}
                            placeholder="Responsable"
                            aria-label="Responsable"
                        />
                        <Input
                            type="date"
                            value={nueva.data.fecha_limite}
                            onChange={(e) => nueva.setData('fecha_limite', e.target.value)}
                            aria-label="Fecha límite"
                        />
                        <Button type="submit" size="icon" disabled={nueva.processing} aria-label="Agregar decisión">
                            <Plus className="size-4" />
                        </Button>
                    </form>
                )}
            </CardContent>
        </Card>
    );
}

/** Una decisión: el estado y el seguimiento se editan siempre; el resto, solo en borrador. */
function DecisionFila({
    base,
    decision,
    catalogos,
    editable,
    canManage,
}: {
    base: string;
    decision: Decision;
    catalogos: Props['catalogos'];
    editable: boolean;
    canManage: boolean;
}) {
    const [estado, setEstado] = useState(decision.estado);
    const [seguimiento, setSeguimiento] = useState(decision.seguimiento ?? '');
    const cambiado = estado !== decision.estado || seguimiento !== (decision.seguimiento ?? '');
    const url = `${base}/decisiones/${decision.id}`;

    function guardar() {
        router.put(url, { ...decision, estado, seguimiento }, { preserveScroll: true });
    }

    return (
        <div className="space-y-2 rounded-md border p-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 flex-1">
                    <div className="text-sm">{decision.descripcion}</div>
                    <div className="text-muted-foreground text-xs">
                        {TIPO[decision.tipo] ?? decision.tipo} · {decision.responsable ?? 'sin responsable'} · límite {decision.fecha_limite ?? '—'}
                        {decision.accion && (
                            <>
                                {' · '}
                                <Link href="/acpm" className="text-primary hover:underline">
                                    {decision.accion.codigo}
                                </Link>
                            </>
                        )}
                    </div>
                </div>
                <div className="flex items-center gap-1">
                    {canManage && !decision.accion && (
                        <Button size="sm" variant="outline" onClick={() => router.post(`${url}/acpm`, {}, { preserveScroll: true })}>
                            Crear ACPM
                        </Button>
                    )}
                    {editable && (
                        <Button
                            size="icon"
                            variant="ghost"
                            aria-label="Eliminar decisión"
                            onClick={() => confirm('¿Eliminar la decisión?') && router.delete(url, { preserveScroll: true })}
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    )}
                </div>
            </div>
            {canManage && (
                <div className="flex flex-wrap items-center gap-2">
                    <select
                        value={estado}
                        onChange={(e) => setEstado(e.target.value)}
                        className={cn(selectCls, 'h-8 text-xs')}
                        aria-label="Estado de la decisión"
                    >
                        {catalogos.estados.map((e) => (
                            <option key={e} value={e}>
                                {ESTADO[e]}
                            </option>
                        ))}
                    </select>
                    <Input
                        value={seguimiento}
                        onChange={(e) => setSeguimiento(e.target.value)}
                        placeholder="Seguimiento"
                        className="h-8 flex-1 text-xs"
                        aria-label="Seguimiento"
                    />
                    <Button size="sm" variant="outline" disabled={!cambiado} onClick={guardar}>
                        Guardar
                    </Button>
                </div>
            )}
        </div>
    );
}
