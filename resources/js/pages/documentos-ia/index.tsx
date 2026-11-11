import { CodigoSig } from '@/components/codigo-sig';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Building2, CheckCircle2, Download, FileText, Library, Loader2, Pencil, Sparkles, Trash2, Upload } from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

interface Template {
    id: number;
    codigo: string;
    nombre: string;
    tipo: string;
    categoria: string;
    normas: string[];
    descripcion: string | null;
    tiene_base: boolean;
    /** De CMK: la empresa puede cambiarla por la suya. */
    es_global: boolean;
    /** Plantilla propia de la empresa que reemplaza a esta de CMK. */
    reemplaza_id: number | null;
    /** Un PDF propio se está pasando a texto («procesando») o falló («error»). */
    estado_proceso: 'procesando' | 'error' | null;
    error_proceso: string | null;
}

interface GenDoc {
    id: number;
    document_template_id: number | null;
    titulo: string;
    estado: 'generando' | 'borrador' | 'en_revision' | 'aprobado' | 'error';
    version: number;
    generado_por: string | null;
    updated_at: string;
    contenido: string;
    /** Documento del listado maestro al que ya se envió este texto. */
    controlado: { id: number; codigo: string } | null;
}

interface Props {
    templates: Template[];
    documents: GenDoc[];
    needsClient: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Documentos IA', href: '/documentos-ia' },
];

const ESTADO: Record<GenDoc['estado'], { label: string; cls: string }> = {
    generando: { label: 'Generando…', cls: 'bg-blue-600 text-white' },
    borrador: { label: 'Borrador', cls: 'bg-slate-500 text-white' },
    en_revision: { label: 'En revisión', cls: 'bg-amber-500 text-white' },
    aprobado: { label: 'Aprobado', cls: 'bg-green-600 text-white' },
    error: { label: 'Error', cls: 'bg-red-600 text-white' },
};

export default function DocumentosIaIndex({ templates, documents, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('documents.manage');
    const page = usePage<SharedData>();
    const flash = page.props.flash;
    const tenant = page.props.tenant as { id: number; name: string } | null;
    // Enviar al control documental solo si la empresa contrató ese módulo (null = todos).
    const modulos = (page.props as { modulos_contratados?: string[] | null }).modulos_contratados ?? null;
    const conControlDocumental = modulos === null || modulos.includes('control-documental');

    const [notice, setNotice] = useState<string | null>(null);
    const [generatingId, setGeneratingId] = useState<number | null>(null);
    const [editing, setEditing] = useState<GenDoc | null>(null);

    const { data, setData, put, processing, errors } = useForm({ titulo: '', contenido: '', estado: 'borrador' as GenDoc['estado'] });

    useEffect(() => {
        if (flash?.success) {
            setNotice(flash.success);
            const t = setTimeout(() => setNotice(null), 4000);
            return () => clearTimeout(t);
        }
    }, [flash?.success]);

    // Mientras la cola redacta algún documento, refresca la lista cada 5 s
    // para que el estado pase solo de «Generando…» a «Borrador».
    const generandoEnCola = documents.some((d) => d.estado === 'generando');
    useEffect(() => {
        if (!generandoEnCola) return;
        const t = setInterval(() => router.reload({ only: ['documents'] }), 5000);
        return () => clearInterval(t);
    }, [generandoEnCola]);

    // Mientras un PDF propio se pasa a texto, la tarjeta se refresca sola.
    const procesandoPlantilla = templates.some((t) => t.estado_proceso === 'procesando');
    useEffect(() => {
        if (!procesandoPlantilla) return;
        const t = setInterval(() => router.reload({ only: ['templates'] }), 8000);
        return () => clearInterval(t);
    }, [procesandoPlantilla]);

    function generar(t: Template) {
        router.post(
            route('documentos-ia.generate'),
            { document_template_id: t.id },
            { preserveScroll: true, onStart: () => setGeneratingId(t.id), onFinish: () => setGeneratingId(null) },
        );
    }

    function openEdit(doc: GenDoc) {
        setEditing(doc);
        // «generando»/«error» no son estados del flujo de revisión: al editar se retoma como borrador.
        const estado = doc.estado === 'generando' || doc.estado === 'error' ? 'borrador' : doc.estado;
        setData({ titulo: doc.titulo, contenido: doc.contenido, estado });
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!editing) return;
        put(route('documentos-ia.update', editing.id), { preserveScroll: true, onSuccess: () => setEditing(null) });
    };

    function enviarAControl(doc: GenDoc) {
        const destino = doc.controlado ? `al borrador de ${doc.controlado.codigo}` : 'al listado maestro';
        if (confirm(`¿Enviar «${doc.titulo}» ${destino}? Allí pasa por revisión y aprobación.`)) {
            router.post(`/control-documental/desde-ia/${doc.id}`);
        }
    }

    function destroy(doc: GenDoc) {
        if (confirm(`¿Eliminar «${doc.titulo}»?`)) {
            router.delete(route('documentos-ia.destroy', doc.id), { preserveScroll: true });
        }
    }

    if (needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Documentos IA" />
                <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            <CodigoSig className="mr-2" />
                            Generación de Documentos con IA
                        </h1>
                        <p className="text-muted-foreground text-sm">Genera documentos del SGI con Claude a partir del contexto del cliente.</p>
                    </div>
                    <Card>
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <Building2 className="size-7" />
                            </div>
                            <p className="font-medium">Selecciona un cliente para generar sus documentos</p>
                            <Button asChild variant="outline" className="gap-2">
                                <Link href="/clientes">
                                    <Building2 className="size-4" /> Ir a Clientes
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Documentos IA" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            <CodigoSig className="mr-2" />
                            Generación de Documentos con IA
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Claude redacta documentos del SGI usando el contexto de{' '}
                            <span className="font-medium">{tenant?.name ?? 'la empresa'}</span>. Revísalos y apruébalos.
                        </p>
                    </div>
                    <Button asChild variant="outline" className="gap-2">
                        <Link href="/documentos-ia/plantillas">
                            <Upload className="size-4" /> Plantillas
                        </Link>
                    </Button>
                </div>

                {notice && (
                    <div className="flex items-center gap-2 rounded-lg border border-green-600/30 bg-green-600/10 px-4 py-2.5 text-sm text-green-700 dark:text-green-400">
                        <CheckCircle2 className="size-4" /> {notice}
                    </div>
                )}

                {/* Catálogo de plantillas */}
                <div>
                    <h2 className="font-brand text-primary mb-3 text-lg font-bold tracking-tight">Plantillas disponibles</h2>
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {templates.map((t) => (
                            <Card key={t.id} className="flex flex-col">
                                <CardContent className="flex flex-1 flex-col gap-3 p-5">
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <div className="text-primary font-mono text-xs font-semibold">{t.codigo}</div>
                                            <div className="leading-tight font-semibold">{t.nombre}</div>
                                        </div>
                                        <Badge variant="outline">{t.tipo}</Badge>
                                    </div>
                                    {t.descripcion && <p className="text-muted-foreground text-sm">{t.descripcion}</p>}
                                    <div>
                                        {t.reemplaza_id ? (
                                            <span className="inline-flex items-center gap-1 rounded bg-sky-600/10 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:text-sky-400">
                                                <Building2 className="size-3" /> Plantilla de la empresa
                                            </span>
                                        ) : t.tiene_base ? (
                                            <span className="inline-flex items-center gap-1 rounded bg-green-600/10 px-1.5 py-0.5 text-[10px] font-medium text-green-700 dark:text-green-400">
                                                <FileText className="size-3" /> Modelo base CMK
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 rounded bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:text-amber-400">
                                                <Sparkles className="size-3" /> Redacción IA
                                            </span>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap gap-1">
                                        {t.normas.map((n) => (
                                            <span key={n} className="bg-primary/10 text-primary rounded px-1.5 py-0.5 text-[10px] font-medium">
                                                {n}
                                            </span>
                                        ))}
                                    </div>
                                    {t.estado_proceso === 'procesando' && (
                                        <p className="text-muted-foreground flex items-center gap-1.5 text-xs" role="status">
                                            <Loader2 className="size-3.5 motion-safe:animate-spin" /> La IA está leyendo el PDF de la empresa…
                                        </p>
                                    )}
                                    {t.estado_proceso === 'error' && (
                                        <p className="text-destructive text-xs">No se pudo leer el PDF: {t.error_proceso} Vuelve a subirlo.</p>
                                    )}
                                    {canManage && (
                                        <Button
                                            onClick={() => generar(t)}
                                            disabled={generatingId !== null || t.estado_proceso !== null}
                                            className="mt-auto gap-2"
                                        >
                                            {generatingId === t.id ? (
                                                <>
                                                    <Loader2 className="size-4 animate-spin" /> Generando…
                                                </>
                                            ) : t.tiene_base ? (
                                                <>
                                                    <FileText className="size-4" /> Generar documento
                                                </>
                                            ) : (
                                                <>
                                                    <Sparkles className="size-4" /> Generar con IA
                                                </>
                                            )}
                                        </Button>
                                    )}
                                    {canManage && <PlantillaDeLaEmpresa t={t} />}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </div>

                {/* Documentos generados */}
                <div>
                    <h2 className="font-brand text-primary mb-3 text-lg font-bold tracking-tight">Documentos generados</h2>
                    <Card className="overflow-hidden">
                        {documents.length === 0 ? (
                            <CardContent className="flex min-h-40 flex-col items-center justify-center gap-2 text-center">
                                <FileText className="text-muted-foreground size-7" />
                                <p className="text-muted-foreground text-sm">Aún no has generado documentos. Usa una plantilla arriba.</p>
                            </CardContent>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground border-b text-left">
                                        <tr>
                                            <th className="px-5 py-3 font-medium">Documento</th>
                                            <th className="px-5 py-3 text-center font-medium">Versión</th>
                                            <th className="px-5 py-3 font-medium">Generado por</th>
                                            <th className="px-5 py-3 text-center font-medium">Estado</th>
                                            <th className="px-5 py-3 text-right font-medium">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {documents.map((d) => (
                                            <tr key={d.id} className="hover:bg-muted/40 transition-colors">
                                                <td className="px-5 py-3 font-medium">
                                                    {d.titulo}
                                                    {d.controlado && (
                                                        <Link
                                                            href={`/control-documental/${d.controlado.id}`}
                                                            className="text-primary block text-xs font-normal hover:underline"
                                                        >
                                                            En el listado maestro como {d.controlado.codigo}
                                                        </Link>
                                                    )}
                                                </td>
                                                <td className="px-5 py-3 text-center tabular-nums">v{d.version}</td>
                                                <td className="text-muted-foreground px-5 py-3">{d.generado_por ?? '—'}</td>
                                                <td className="px-5 py-3 text-center">
                                                    <Badge className={cn(ESTADO[d.estado].cls, 'gap-1')}>
                                                        {d.estado === 'generando' && <Loader2 className="size-3 animate-spin" />}
                                                        {ESTADO[d.estado].label}
                                                    </Badge>
                                                </td>
                                                <td className="px-5 py-3">
                                                    <div className="flex items-center justify-end gap-1">
                                                        {d.estado !== 'generando' && d.estado !== 'error' && (
                                                            <Button variant="ghost" size="icon" asChild aria-label="Descargar Word">
                                                                <a href={route('documentos-ia.export', d.id)}>
                                                                    <Download className="size-4" />
                                                                </a>
                                                            </Button>
                                                        )}
                                                        {d.estado !== 'generando' && (
                                                            <Button variant="ghost" size="icon" onClick={() => openEdit(d)} aria-label="Ver / editar">
                                                                <Pencil className="size-4" />
                                                            </Button>
                                                        )}
                                                        {canManage && conControlDocumental && d.estado !== 'generando' && d.estado !== 'error' && (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                onClick={() => enviarAControl(d)}
                                                                aria-label="Enviar a control documental"
                                                                title="Enviar a control documental"
                                                            >
                                                                <Library className="size-4" />
                                                            </Button>
                                                        )}
                                                        {canManage && (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                onClick={() => destroy(d)}
                                                                aria-label="Eliminar"
                                                                className="text-destructive hover:text-destructive"
                                                            >
                                                                <Trash2 className="size-4" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>
                </div>
            </div>

            {/* Editor del documento */}
            <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle className="font-brand">Revisar documento</DialogTitle>
                        <DialogDescription>Edita el borrador generado por la IA y cámbialo de estado (revisión / aprobado).</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="titulo">Título</Label>
                            <Input
                                id="titulo"
                                value={data.titulo}
                                onChange={(e) => setData('titulo', e.target.value)}
                                disabled={!canManage}
                                required
                            />
                            <InputError message={errors.titulo} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="contenido">Contenido (Markdown)</Label>
                            <textarea
                                id="contenido"
                                value={data.contenido}
                                onChange={(e) => setData('contenido', e.target.value)}
                                disabled={!canManage}
                                rows={18}
                                className="border-input bg-background w-full rounded-md border px-3 py-2 font-mono text-xs leading-relaxed disabled:opacity-60"
                            />
                            <InputError message={errors.contenido} />
                        </div>
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="estado">Estado</Label>
                                <select
                                    id="estado"
                                    value={data.estado}
                                    onChange={(e) => setData('estado', e.target.value as GenDoc['estado'])}
                                    disabled={!canManage}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm disabled:opacity-60"
                                >
                                    <option value="borrador">Borrador</option>
                                    <option value="en_revision">En revisión</option>
                                    <option value="aprobado">Aprobado</option>
                                </select>
                            </div>
                            {canManage && (
                                <DialogFooter className="gap-2">
                                    <Button type="button" variant="outline" onClick={() => setEditing(null)}>
                                        Cerrar
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        Guardar cambios
                                    </Button>
                                </DialogFooter>
                            )}
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

/**
 * Cambiar una plantilla de CMK por la de la empresa (Word o PDF), o volver a
 * la de CMK. La de la empresa queda solo para esta empresa y conserva el
 * código y los vínculos de la de CMK.
 */
function PlantillaDeLaEmpresa({ t }: { t: Template }) {
    const input = useRef<HTMLInputElement>(null);
    const [subiendo, setSubiendo] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // Se sube siempre contra la de CMK: si ya hay una propia, se reemplaza.
    const idCmk = t.reemplaza_id ?? (t.es_global ? t.id : null);
    if (idCmk === null) return null; // plantilla creada por la empresa, sin equivalente de CMK
    const destino: number = idCmk;

    function subir(archivo: File) {
        setError(null);
        router.post(
            route('plantillas.sustituir', destino),
            { archivo },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setSubiendo(true),
                onFinish: () => setSubiendo(false),
                onError: (e) => setError(e.archivo ?? null),
            },
        );
    }

    function volverACmk() {
        if (confirm(`¿Volver a la plantilla de CMK para «${t.nombre}»? Se elimina la de la empresa; los documentos ya generados no cambian.`)) {
            router.delete(route('plantillas.destroy', t.id), { preserveScroll: true });
        }
    }

    return (
        <div className="space-y-1">
            <input
                ref={input}
                type="file"
                accept=".docx,.pdf,.txt,.md"
                className="hidden"
                onChange={(e) => {
                    const archivo = e.target.files?.[0];
                    if (archivo) subir(archivo);
                    e.target.value = '';
                }}
            />
            <div className="flex flex-wrap gap-x-3 gap-y-1 text-xs">
                <button
                    type="button"
                    disabled={subiendo}
                    className="text-primary hover:underline disabled:opacity-60"
                    onClick={() => input.current?.click()}
                >
                    {subiendo ? 'Subiendo…' : t.reemplaza_id ? 'Cambiar archivo' : 'Usar la plantilla de la empresa (Word o PDF)'}
                </button>
                {t.reemplaza_id && (
                    <button type="button" className="text-muted-foreground hover:text-foreground hover:underline" onClick={volverACmk}>
                        Volver a la de CMK
                    </button>
                )}
            </div>
            <InputError message={error ?? undefined} />
        </div>
    );
}
