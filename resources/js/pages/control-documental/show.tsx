import { Markdown } from '@/components/asistente/markdown';
import {
    EstadoBadge,
    fechaCorta,
    fechaHora,
    NOMBRE_SISTEMA,
    selectCls,
    SistemaChips,
    SistemasPicker,
    textareaCls,
    type EstadoDocumento,
    type EstadoVersion,
    type Sistema,
} from '@/components/control-documental/etiquetas';
import InputError from '@/components/input-error';
import { ModuloPage } from '@/components/modulo-page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { type SharedData } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Archive, ArrowLeft, BookOpenCheck, CheckCheck, Download, FilePlus2, Send, Trash2, Undo2 } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

interface Documento {
    id: number;
    codigo: string;
    tipo: string;
    nivel: number;
    titulo: string;
    sistemas: Sistema[];
    condicional: boolean;
    estado: EstadoDocumento;
    version_vigente: number | null;
    frecuencia_revision_meses: number;
    proxima_revision: string | null;
    retencion_anios: number | null;
    disposicion_final: string | null;
    ubicacion: string | null;
    codigo_historico: string | null;
    revision_vencida: boolean;
    dias_para_revision: number | null;
    process: { sigla: string; nombre: string } | null;
    updated_at: string;
}

interface Version {
    id: number;
    version: number;
    estado: EstadoVersion;
    contenido: string | null;
    archivo: string | null;
    archivo_nombre: string | null;
    descripcion_cambio: string | null;
    observaciones: string | null;
    elaboro_nombre: string | null;
    enviado_revision_at: string | null;
    reviso_nombre: string | null;
    revisado_at: string | null;
    aprobo_nombre: string | null;
    aprobado_at: string | null;
    obsoleto_at: string | null;
    motivo_obsoleto: string | null;
    reads_count: number;
    updated_at: string;
}

interface Requisito {
    clave_comun: string;
    titulo: string;
    etapa: string;
    modulo: string;
    referencias: { norma: Sistema; referencia: string }[];
}

interface Props {
    documento: Documento;
    versiones: Version[];
    lecturas: { user_nombre: string; leido_at: string }[];
    leidoPorMi: boolean;
    puedeEliminarse: boolean;
    requisitos: Requisito[];
    vinculados: string[];
    documentosIa: { id: number; titulo: string; version: number }[];
    catalogos: { tipos: { clave: string; nombre: string }[]; niveles: Record<number, string>; disposiciones: string[] };
}

const EN_CURSO: EstadoVersion[] = ['borrador', 'en_revision', 'en_aprobacion'];

const ETAPAS: Record<string, string> = {
    '0': 'Diagnóstico inicial',
    '4': 'Contexto de la organización',
    '5': 'Liderazgo y participación',
    '6': 'Planificación',
    '7': 'Apoyo',
    '8': 'Operación',
    '9': 'Evaluación del desempeño',
    '10': 'Mejora',
};

export default function ControlDocumentalShow(props: Props) {
    const { documento, versiones, lecturas, leidoPorMi, puedeEliminarse } = props;
    const { can } = usePermissions();
    const canManage = can('documents.manage');
    const errores = usePage<SharedData>().props.errors as Record<string, string | undefined>;

    const enCurso = versiones.find((v) => EN_CURSO.includes(v.estado));
    const vigente = versiones.find((v) => v.estado === 'vigente');
    const nombreTipo = props.catalogos.tipos.find((t) => t.clave === documento.tipo)?.nombre ?? documento.tipo;

    const [nuevaVersion, setNuevaVersion] = useState(false);
    const [retirando, setRetirando] = useState(false);

    function eliminar() {
        if (confirm(`¿Eliminar ${documento.codigo}? El código no se volverá a usar.`)) {
            router.delete(`/control-documental/${documento.id}`);
        }
    }

    return (
        <ModuloPage
            titulo={documento.codigo}
            descripcion={documento.titulo}
            needsClient={false}
            accion={
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="ghost" className="gap-2">
                        <Link href="/control-documental">
                            <ArrowLeft className="size-4" /> Listado maestro
                        </Link>
                    </Button>
                    {vigente && !leidoPorMi && (
                        <Button
                            variant="outline"
                            className="gap-2"
                            onClick={() => router.post(`/control-documental/${documento.id}/leido`, {}, { preserveScroll: true })}
                        >
                            <BookOpenCheck className="size-4" /> Confirmar lectura
                        </Button>
                    )}
                    {canManage && vigente && !enCurso && (
                        <>
                            <Button variant="outline" className="gap-2" onClick={() => setRetirando((v) => !v)}>
                                <Archive className="size-4" /> Retirar
                            </Button>
                            <Button className="gap-2" onClick={() => setNuevaVersion((v) => !v)}>
                                <FilePlus2 className="size-4" /> Nueva versión
                            </Button>
                        </>
                    )}
                    {canManage && puedeEliminarse && (
                        <Button variant="outline" className="text-destructive gap-2" onClick={eliminar}>
                            <Trash2 className="size-4" /> Eliminar
                        </Button>
                    )}
                </div>
            }
        >
            <div className="flex flex-wrap items-center gap-3 text-sm">
                <EstadoBadge estado={documento.estado} />
                {documento.version_vigente && <span className="tabular-nums">Versión vigente v{documento.version_vigente}</span>}
                <span className="text-muted-foreground">
                    {nombreTipo} · nivel {documento.nivel} ({props.catalogos.niveles[documento.nivel]}) · {documento.process?.sigla}{' '}
                    {documento.process?.nombre}
                </span>
                <SistemaChips sistemas={documento.sistemas} />
                {documento.revision_vencida && (
                    <span className="text-destructive text-xs font-medium">Revisión vencida desde el {fechaCorta(documento.proxima_revision)}</span>
                )}
            </div>

            {(errores.estado || errores.version) && (
                <div className="border-destructive/40 bg-destructive/10 text-destructive rounded-md border px-4 py-2.5 text-sm">
                    {errores.estado ?? errores.version}
                </div>
            )}

            {nuevaVersion && <NuevaVersion documentoId={documento.id} onClose={() => setNuevaVersion(false)} />}
            {retirando && <Retirar documentoId={documento.id} onClose={() => setRetirando(false)} />}

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    {/* La key remonta el formulario tras cada guardado: así toma lo que
                        quedó en el servidor (p. ej. el texto traído de Documentos IA) y deja
                        de contar como «sin guardar». */}
                    {enCurso && (
                        <VersionEnCurso
                            key={`${enCurso.id}-${enCurso.updated_at}`}
                            documentoId={documento.id}
                            version={enCurso}
                            canManage={canManage}
                            documentosIa={props.documentosIa}
                        />
                    )}

                    {vigente && (
                        <Card>
                            <CardHeader className="flex-row items-center justify-between space-y-0">
                                <CardTitle className="text-base">Versión vigente · v{vigente.version}</CardTitle>
                                {vigente.archivo && <ArchivoLink documentoId={documento.id} version={vigente} />}
                            </CardHeader>
                            <CardContent>
                                {vigente.contenido ? (
                                    <div className="max-h-[32rem] overflow-y-auto text-sm">
                                        <Markdown>{vigente.contenido}</Markdown>
                                    </div>
                                ) : (
                                    <p className="text-muted-foreground text-sm">El contenido de esta versión está en el archivo adjunto.</p>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    <Historial documentoId={documento.id} versiones={versiones} />
                </div>

                <div className="space-y-6">
                    <Ficha key={documento.updated_at} documento={documento} canManage={canManage} disposiciones={props.catalogos.disposiciones} />
                    <Requisitos documentoId={documento.id} requisitos={props.requisitos} vinculados={props.vinculados} canManage={canManage} />
                    {vigente && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Lecturas confirmadas · v{vigente.version}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {lecturas.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">Nadie ha confirmado la lectura de esta versión.</p>
                                ) : (
                                    <ul className="space-y-1 text-sm">
                                        {lecturas.map((l) => (
                                            <li key={l.user_nombre + l.leido_at} className="flex justify-between gap-2">
                                                <span>{l.user_nombre}</span>
                                                <span className="text-muted-foreground text-xs">{fechaHora(l.leido_at)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </ModuloPage>
    );
}

function ArchivoLink({ documentoId, version }: { documentoId: number; version: Version }) {
    return (
        <a
            href={`/control-documental/${documentoId}/versiones/${version.id}/archivo`}
            className="text-primary inline-flex items-center gap-1 text-sm hover:underline"
        >
            <Download className="size-4" /> {version.archivo_nombre ?? 'Archivo'}
        </a>
    );
}

/** La versión que se está elaborando, revisando o aprobando, con sus acciones. */
function VersionEnCurso({
    documentoId,
    version,
    canManage,
    documentosIa,
}: {
    documentoId: number;
    version: Version;
    canManage: boolean;
    documentosIa: Props['documentosIa'];
}) {
    const base = `/control-documental/${documentoId}/versiones/${version.id}`;
    const editable = version.estado === 'borrador' && canManage;
    const { data, setData, post, processing, errors, isDirty } = useForm<{
        contenido: string;
        descripcion_cambio: string;
        archivo: File | null;
        documento_ia_id: string;
    }>({
        contenido: version.contenido ?? '',
        descripcion_cambio: version.descripcion_cambio ?? '',
        archivo: null,
        documento_ia_id: '',
    });
    const [observaciones, setObservaciones] = useState('');
    const errores = usePage<SharedData>().props.errors as Record<string, string | undefined>;

    const guardar: FormEventHandler = (e) => {
        e.preventDefault();
        post(base, { preserveScroll: true, forceFormData: true });
    };

    function transicion(accion: 'enviar' | 'revisar' | 'devolver' | 'aprobar') {
        router.post(`${base}/transicion`, { accion, observaciones }, { preserveScroll: true, onSuccess: () => setObservaciones('') });
    }

    const pasos: EstadoVersion[] = ['borrador', 'en_revision', 'en_aprobacion', 'vigente'];
    const paso = pasos.indexOf(version.estado);

    return (
        <Card className="border-primary/40">
            <CardHeader className="space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="text-base">Versión {version.version} en curso</CardTitle>
                    <EstadoBadge estado={version.estado} />
                </div>
                <ol className="text-muted-foreground flex flex-wrap items-center gap-1 text-xs">
                    {['Elaboración', 'Revisión técnica', 'Aprobación', 'Publicación'].map((t, i) => (
                        <li key={t} className="flex items-center gap-1">
                            {i > 0 && <span aria-hidden>→</span>}
                            <span className={i === paso ? 'text-foreground font-semibold' : i < paso ? 'text-emerald-600 dark:text-emerald-500' : ''}>
                                {t}
                            </span>
                        </li>
                    ))}
                </ol>
            </CardHeader>
            <CardContent className="space-y-4">
                {version.observaciones && version.estado === 'borrador' && (
                    <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                        <span className="font-medium">Devuelto con observaciones:</span> {version.observaciones}
                    </div>
                )}

                {editable ? (
                    <form onSubmit={guardar} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="descripcion_cambio">Descripción del cambio</Label>
                            <textarea
                                id="descripcion_cambio"
                                rows={2}
                                value={data.descripcion_cambio}
                                onChange={(e) => setData('descripcion_cambio', e.target.value)}
                                className={textareaCls}
                                placeholder="Qué cambia en esta versión y por qué. Queda en el control de cambios."
                            />
                            <InputError message={errors.descripcion_cambio ?? errores.descripcion_cambio} />
                        </div>
                        <div className="grid gap-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <Label htmlFor="contenido">Contenido (Markdown)</Label>
                                {documentosIa.length > 0 && (
                                    <select
                                        value={data.documento_ia_id}
                                        onChange={(e) => setData('documento_ia_id', e.target.value)}
                                        className={selectCls + ' max-w-xs text-xs'}
                                        aria-label="Traer texto de Documentos IA"
                                    >
                                        <option value="">Traer texto de Documentos IA…</option>
                                        {documentosIa.map((d) => (
                                            <option key={d.id} value={d.id}>
                                                {d.titulo} (v{d.version})
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </div>
                            {data.documento_ia_id ? (
                                <p className="text-muted-foreground rounded-md border border-dashed p-3 text-sm">
                                    Al guardar, el contenido se reemplaza por el del documento elegido.
                                </p>
                            ) : (
                                <textarea
                                    id="contenido"
                                    rows={14}
                                    value={data.contenido}
                                    onChange={(e) => setData('contenido', e.target.value)}
                                    className={textareaCls + ' font-mono text-xs'}
                                />
                            )}
                            <InputError message={errores.contenido} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="archivo">Archivo (opcional)</Label>
                            {version.archivo && (
                                <p className="text-muted-foreground text-xs">
                                    Actual: <ArchivoLink documentoId={documentoId} version={version} />
                                </p>
                            )}
                            <Input id="archivo" type="file" onChange={(e) => setData('archivo', e.target.files?.[0] ?? null)} />
                            <InputError message={errors.archivo} />
                        </div>
                        <div className="flex flex-wrap justify-between gap-2">
                            <div className="flex gap-2">
                                <Button type="submit" variant="outline" disabled={processing}>
                                    Guardar borrador
                                </Button>
                                {version.version > 1 && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="text-destructive"
                                        onClick={() => confirm('¿Descartar este borrador?') && router.delete(base, { preserveScroll: true })}
                                    >
                                        Descartar
                                    </Button>
                                )}
                            </div>
                            <Button
                                type="button"
                                className="gap-2"
                                disabled={processing || isDirty}
                                title={isDirty ? 'Guarda primero los cambios' : undefined}
                                onClick={() => transicion('enviar')}
                            >
                                <Send className="size-4" /> Enviar a revisión
                            </Button>
                        </div>
                    </form>
                ) : (
                    <>
                        {version.descripcion_cambio && (
                            <p className="text-sm">
                                <span className="text-muted-foreground">Cambio:</span> {version.descripcion_cambio}
                            </p>
                        )}
                        {version.contenido && (
                            <div className="bg-muted/30 max-h-80 overflow-y-auto rounded-md p-3 text-sm">
                                <Markdown>{version.contenido}</Markdown>
                            </div>
                        )}
                        {version.archivo && <ArchivoLink documentoId={documentoId} version={version} />}
                        <p className="text-muted-foreground text-xs">
                            Elaboró {version.elaboro_nombre ?? '—'}
                            {version.reviso_nombre && ` · revisó ${version.reviso_nombre} el ${fechaHora(version.revisado_at)}`}
                        </p>

                        {canManage && (version.estado === 'en_revision' || version.estado === 'en_aprobacion') && (
                            <div className="bg-muted/40 space-y-3 rounded-md p-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="observaciones">Observaciones (obligatorias para devolver)</Label>
                                    <textarea
                                        id="observaciones"
                                        rows={2}
                                        value={observaciones}
                                        onChange={(e) => setObservaciones(e.target.value)}
                                        className={textareaCls}
                                    />
                                    <InputError message={errores.observaciones} />
                                </div>
                                <div className="flex flex-wrap justify-end gap-2">
                                    <Button variant="outline" className="gap-2" onClick={() => transicion('devolver')}>
                                        <Undo2 className="size-4" /> {version.estado === 'en_revision' ? 'Devolver con ajustes' : 'Rechazar'}
                                    </Button>
                                    {version.estado === 'en_revision' ? (
                                        <Button className="gap-2" onClick={() => transicion('revisar')}>
                                            <CheckCheck className="size-4" /> Revisión OK, pasar a aprobación
                                        </Button>
                                    ) : (
                                        <Button className="gap-2" onClick={() => transicion('aprobar')}>
                                            <CheckCheck className="size-4" /> Aprobar y publicar
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}
                    </>
                )}
            </CardContent>
        </Card>
    );
}

/** Control de cambios: todas las versiones con quién elaboró, revisó y aprobó. */
function Historial({ documentoId, versiones }: { documentoId: number; versiones: Version[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Control de cambios</CardTitle>
            </CardHeader>
            <CardContent className="p-0">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                            <tr>
                                <th className="px-4 py-2 font-semibold">Versión</th>
                                <th className="px-4 py-2 font-semibold">Cambio</th>
                                <th className="px-4 py-2 font-semibold">Elaboró · revisó · aprobó</th>
                                <th className="px-4 py-2 font-semibold">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            {versiones.map((v) => (
                                <tr key={v.id} className="border-t align-top">
                                    <td className="px-4 py-2.5 font-medium tabular-nums">v{v.version}</td>
                                    <td className="max-w-xs px-4 py-2.5">
                                        {v.descripcion_cambio ?? <span className="text-muted-foreground">—</span>}
                                        {v.archivo && (
                                            <div className="mt-1">
                                                <ArchivoLink documentoId={documentoId} version={v} />
                                            </div>
                                        )}
                                        {v.motivo_obsoleto && <div className="text-muted-foreground mt-1 text-xs">{v.motivo_obsoleto}</div>}
                                    </td>
                                    <td className="px-4 py-2.5 text-xs">
                                        <div>{v.elaboro_nombre ?? '—'}</div>
                                        <div className="text-muted-foreground">
                                            {v.reviso_nombre ? `${v.reviso_nombre} · ${fechaCorta(v.revisado_at)}` : 'sin revisar'}
                                        </div>
                                        <div className="text-muted-foreground">
                                            {v.aprobo_nombre ? `${v.aprobo_nombre} · ${fechaCorta(v.aprobado_at)}` : 'sin aprobar'}
                                        </div>
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <EstadoBadge estado={v.estado} />
                                        {v.reads_count > 0 && <div className="text-muted-foreground mt-1 text-xs">{v.reads_count} lecturas</div>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    );
}

function NuevaVersion({ documentoId, onClose }: { documentoId: number; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ descripcion_cambio: '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/control-documental/${documentoId}/versiones`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Card>
            <CardContent className="pt-6">
                <form onSubmit={submit} className="space-y-3">
                    <Label htmlFor="nueva-descripcion">¿Qué va a cambiar en la nueva versión?</Label>
                    <textarea
                        id="nueva-descripcion"
                        rows={2}
                        value={data.descripcion_cambio}
                        onChange={(e) => setData('descripcion_cambio', e.target.value)}
                        className={textareaCls}
                    />
                    <InputError message={errors.descripcion_cambio} />
                    <p className="text-muted-foreground text-xs">La versión vigente sigue en uso hasta que la nueva se apruebe.</p>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Abrir borrador
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function Retirar({ documentoId, onClose }: { documentoId: number; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ motivo: '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/control-documental/${documentoId}/retirar`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Card className="border-destructive/40">
            <CardContent className="pt-6">
                <form onSubmit={submit} className="space-y-3">
                    <Label htmlFor="motivo">Motivo del retiro</Label>
                    <textarea id="motivo" rows={2} value={data.motivo} onChange={(e) => setData('motivo', e.target.value)} className={textareaCls} />
                    <InputError message={errors.motivo} />
                    <p className="text-muted-foreground text-xs">
                        El documento pasa a obsoleto: no se puede usar, pero se conserva para consulta y auditoría.
                    </p>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            Retirar documento
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function Ficha({ documento, canManage, disposiciones }: { documento: Documento; canManage: boolean; disposiciones: string[] }) {
    const { data, setData, put, processing, errors, isDirty } = useForm({
        titulo: documento.titulo,
        sistemas: documento.sistemas,
        condicional: documento.condicional,
        frecuencia_revision_meses: documento.frecuencia_revision_meses,
        retencion_anios: documento.retencion_anios ?? ('' as number | ''),
        disposicion_final: documento.disposicion_final ?? '',
        ubicacion: documento.ubicacion ?? '',
        codigo_historico: documento.codigo_historico ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/control-documental/${documento.id}`, { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Ficha del documento</CardTitle>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="ficha-titulo">Título</Label>
                        <Input id="ficha-titulo" value={data.titulo} disabled={!canManage} onChange={(e) => setData('titulo', e.target.value)} />
                        <InputError message={errors.titulo} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Normas que evidencia</Label>
                        {canManage ? (
                            <SistemasPicker value={data.sistemas} onChange={(v) => setData('sistemas', v)} />
                        ) : (
                            <SistemaChips sistemas={data.sistemas} />
                        )}
                        <InputError message={errors.sistemas} />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="frecuencia">Revisar cada (meses)</Label>
                            <Input
                                id="frecuencia"
                                type="number"
                                min={1}
                                disabled={!canManage}
                                value={data.frecuencia_revision_meses}
                                onChange={(e) => setData('frecuencia_revision_meses', Number(e.target.value))}
                            />
                            <InputError message={errors.frecuencia_revision_meses} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="retencion">Conservar (años)</Label>
                            <Input
                                id="retencion"
                                type="number"
                                min={1}
                                disabled={!canManage}
                                value={data.retencion_anios}
                                onChange={(e) => setData('retencion_anios', e.target.value === '' ? '' : Number(e.target.value))}
                            />
                        </div>
                    </div>
                    <InputError message={errors.retencion_anios} />
                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="disposicion">Disposición final</Label>
                            <select
                                id="disposicion"
                                disabled={!canManage}
                                value={data.disposicion_final}
                                onChange={(e) => setData('disposicion_final', e.target.value)}
                                className={selectCls + ' capitalize'}
                            >
                                <option value="">—</option>
                                {disposiciones.map((d) => (
                                    <option key={d} value={d}>
                                        {d}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="historico">Código anterior</Label>
                            <Input
                                id="historico"
                                disabled={!canManage}
                                value={data.codigo_historico}
                                onChange={(e) => setData('codigo_historico', e.target.value)}
                            />
                        </div>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="ubicacion">Ubicación</Label>
                        <Input
                            id="ubicacion"
                            disabled={!canManage}
                            value={data.ubicacion}
                            onChange={(e) => setData('ubicacion', e.target.value)}
                            placeholder="Plataforma CMK, archivo físico de gestión humana…"
                        />
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox disabled={!canManage} checked={data.condicional} onCheckedChange={(v) => setData('condicional', v === true)} />
                        Solo aplica según la actividad
                    </label>
                    <p className="text-muted-foreground text-xs">
                        Próxima revisión: {fechaCorta(documento.proxima_revision)}. El tipo y el proceso no cambian: forman el código.
                    </p>
                    {canManage && (
                        <div className="flex justify-end">
                            <Button type="submit" size="sm" disabled={processing || !isDirty}>
                                Guardar ficha
                            </Button>
                        </div>
                    )}
                </form>
            </CardContent>
        </Card>
    );
}

/**
 * Requisitos que el documento evidencia. Se marca el requisito común una vez y
 * queda vinculado en cada norma de la ficha, con su referencia exacta.
 */
function Requisitos({
    documentoId,
    requisitos,
    vinculados,
    canManage,
}: {
    documentoId: number;
    requisitos: Requisito[];
    vinculados: string[];
    canManage: boolean;
}) {
    const [marcados, setMarcados] = useState<string[]>(vinculados);
    const [todos, setTodos] = useState(false);
    const cambiado = marcados.length !== vinculados.length || marcados.some((c) => !vinculados.includes(c));

    const visibles = useMemo(() => (todos ? requisitos : requisitos.filter((r) => marcados.includes(r.clave_comun))), [todos, requisitos, marcados]);
    const porEtapa = useMemo(() => {
        const g: Record<string, Requisito[]> = {};
        visibles.forEach((r) => (g[r.etapa] ??= []).push(r));
        return g;
    }, [visibles]);

    function guardar() {
        router.put(`/control-documental/${documentoId}/requisitos`, { claves: marcados }, { preserveScroll: true });
    }

    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between space-y-0">
                <CardTitle className="text-base">Requisitos que evidencia</CardTitle>
                <span className="text-muted-foreground text-xs tabular-nums">{marcados.length} vinculados</span>
            </CardHeader>
            <CardContent className="space-y-3">
                {visibles.length === 0 && !todos && <p className="text-muted-foreground text-sm">Todavía no está vinculado a ningún requisito.</p>}
                <div className="max-h-[28rem] space-y-3 overflow-y-auto">
                    {Object.keys(ETAPAS)
                        .filter((e) => porEtapa[e])
                        .map((e) => (
                            <div key={e}>
                                <div className="text-muted-foreground mb-1 text-xs font-semibold">
                                    {e}. {ETAPAS[e]}
                                </div>
                                {porEtapa[e].map((r) => (
                                    <label key={r.clave_comun} className="hover:bg-muted/40 flex items-start gap-2 rounded p-1.5 text-sm">
                                        <Checkbox
                                            disabled={!canManage}
                                            checked={marcados.includes(r.clave_comun)}
                                            onCheckedChange={(v) =>
                                                setMarcados((m) => (v === true ? [...m, r.clave_comun] : m.filter((x) => x !== r.clave_comun)))
                                            }
                                            className="mt-0.5"
                                        />
                                        <span>
                                            {r.titulo}
                                            <span className="text-muted-foreground block text-[11px]">
                                                {r.referencias.map((ref) => `${NOMBRE_SISTEMA[ref.norma]} ${ref.referencia}`).join(' · ')}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                        ))}
                </div>
                {canManage && (
                    <div className="flex items-center justify-between gap-2">
                        <button type="button" className="text-primary text-xs hover:underline" onClick={() => setTodos((t) => !t)}>
                            {todos ? 'Ver solo los vinculados' : `Ver los ${requisitos.length} requisitos de sus normas`}
                        </button>
                        <Button size="sm" disabled={!cambiado} onClick={guardar}>
                            Guardar vínculos
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
