import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Building2, CheckCircle2, FileText, Globe2, Pencil, Sparkles, Trash2, Upload } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Plantilla {
    id: number;
    codigo: string;
    nombre: string;
    tipo: string;
    categoria: string;
    normas: string[];
    descripcion: string | null;
    tiene_base: boolean;
    conserva_formato: boolean;
    es_global: boolean;
    subido_por: string | null;
    subida_at: string | null;
    caracteres: number;
    editable: boolean;
}

interface Props {
    plantillas: Plantilla[];
    puedeGestionar: boolean;
    puedeSubirGlobal: boolean;
    tenant: { id: number; name: string } | null;
    extensiones: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Documentos IA', href: '/documentos-ia' },
    { title: 'Plantillas', href: '/documentos-ia/plantillas' },
];

const TIPOS = ['Política', 'Procedimiento', 'Manual', 'Plan', 'Programa', 'Formato', 'Instructivo', 'Matriz'];
const CATEGORIAS = ['SGI', 'SST', 'PESV', 'HSEQ'];

type Formulario = {
    archivo: File | null;
    codigo: string;
    nombre: string;
    tipo: string;
    categoria: string;
    normas: string;
    descripcion: string;
    prompt: string;
    alcance: 'global' | 'cliente';
};

export default function Plantillas({ plantillas, puedeGestionar, puedeSubirGlobal, tenant, extensiones }: Props) {
    const flash = usePage<SharedData>().props.flash;
    const [notice, setNotice] = useState<string | null>(null);
    const [abierto, setAbierto] = useState(false);
    const [editando, setEditando] = useState<Plantilla | null>(null);

    const vacio: Formulario = {
        archivo: null,
        codigo: '',
        nombre: '',
        tipo: TIPOS[0],
        categoria: 'SGI',
        normas: '',
        descripcion: '',
        prompt: '',
        alcance: puedeSubirGlobal && !tenant ? 'global' : 'cliente',
    };

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm<Formulario>(vacio);

    useEffect(() => {
        if (!flash?.success) return;
        setNotice(flash.success);
        const t = setTimeout(() => setNotice(null), 4000);
        return () => clearTimeout(t);
    }, [flash?.success]);

    function abrirAlta() {
        reset();
        clearErrors();
        setEditando(null);
        setAbierto(true);
    }

    function abrirEdicion(p: Plantilla) {
        clearErrors();
        setEditando(p);
        setData({
            archivo: null,
            codigo: p.codigo,
            nombre: p.nombre,
            tipo: p.tipo,
            categoria: p.categoria,
            normas: p.normas.join(', '),
            descripcion: p.descripcion ?? '',
            prompt: '',
            alcance: p.es_global ? 'global' : 'cliente',
        });
        setAbierto(true);
    }

    const enviar: FormEventHandler = (e) => {
        e.preventDefault();

        // La edición también va por POST: un PUT multipart no lleva el archivo
        // en PHP, así que la ruta de update acepta POST.
        const url = editando ? route('plantillas.update', editando.id) : route('plantillas.store');

        post(url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setAbierto(false);
                setEditando(null);
                reset();
            },
        });
    };

    function eliminar(p: Plantilla) {
        if (confirm(`¿Eliminar la plantilla «${p.nombre}»? Los documentos ya generados con ella no se tocan.`)) {
            router.delete(route('plantillas.destroy', p.id), { preserveScroll: true });
        }
    }

    const propias = plantillas.filter((p) => !p.es_global);
    const globales = plantillas.filter((p) => p.es_global);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Plantillas de documentos" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Plantillas de documentos</h1>
                        <p className="text-muted-foreground max-w-2xl text-sm">
                            Sube un documento modelo y la IA generará los documentos a partir de él, con los datos de la empresa. Acepta{' '}
                            {extensiones.map((e) => `.${e}`).join(', ')}.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button asChild variant="outline" className="gap-2">
                            <Link href="/documentos-ia">
                                <ArrowLeft className="size-4" /> Documentos
                            </Link>
                        </Button>
                        {puedeGestionar && (
                            <Button onClick={abrirAlta} className="gap-2">
                                <Upload className="size-4" /> Subir plantilla
                            </Button>
                        )}
                    </div>
                </div>

                {notice && (
                    <div className="flex items-center gap-2 rounded-lg border border-green-600/30 bg-green-600/10 px-4 py-2.5 text-sm text-green-700 dark:text-green-400">
                        <CheckCircle2 className="size-4" /> {notice}
                    </div>
                )}

                <Seccion
                    titulo={tenant ? `Formatos propios de ${tenant.name}` : 'Formatos propios de la empresa'}
                    vacio={
                        tenant
                            ? 'Esta empresa todavía no tiene formatos propios. Sube el suyo y solo ella lo verá.'
                            : 'Selecciona una empresa cliente para ver y subir sus formatos propios.'
                    }
                    plantillas={propias}
                    onEditar={abrirEdicion}
                    onEliminar={eliminar}
                />

                <Seccion
                    titulo="Catálogo de CMK"
                    vacio="El catálogo compartido está vacío."
                    plantillas={globales}
                    onEditar={abrirEdicion}
                    onEliminar={eliminar}
                />
            </div>

            <Dialog open={abierto} onOpenChange={setAbierto}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{editando ? `Editar «${editando.nombre}»` : 'Subir plantilla'}</DialogTitle>
                        <DialogDescription>
                            {editando
                                ? 'Cambia los datos de la plantilla. Solo se reemplaza el documento modelo si subes un archivo nuevo.'
                                : 'El texto del archivo queda como documento modelo: la IA lo usa de base en vez de inventar la estructura.'}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={enviar} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="archivo">
                                Documento modelo {editando && <span className="text-muted-foreground font-normal">(opcional)</span>}
                            </Label>
                            <Input
                                id="archivo"
                                type="file"
                                accept={extensiones.map((e) => `.${e}`).join(',')}
                                onChange={(e) => setData('archivo', e.target.files?.[0] ?? null)}
                                required={!editando}
                            />
                            <p className="text-muted-foreground text-xs">
                                Si el .docx trae marcadores <code className="font-mono">{'${EMPRESA}'}</code>, la descarga conserva tu formato y
                                membrete exactos y solo cambia los datos.
                            </p>
                            <InputError message={errors.archivo} />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="codigo">Código</Label>
                                <Input
                                    id="codigo"
                                    value={data.codigo}
                                    onChange={(e) => setData('codigo', e.target.value.toUpperCase())}
                                    placeholder="POL-SGI"
                                    required
                                />
                                <InputError message={errors.codigo} />
                            </div>
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="nombre">Nombre</Label>
                                <Input
                                    id="nombre"
                                    value={data.nombre}
                                    onChange={(e) => setData('nombre', e.target.value)}
                                    placeholder="Política del Sistema de Gestión Integral"
                                    required
                                />
                                <InputError message={errors.nombre} />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="tipo">Tipo</Label>
                                <select
                                    id="tipo"
                                    value={data.tipo}
                                    onChange={(e) => setData('tipo', e.target.value)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    {TIPOS.map((t) => (
                                        <option key={t} value={t}>
                                            {t}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.tipo} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="categoria">Categoría</Label>
                                <select
                                    id="categoria"
                                    value={data.categoria}
                                    onChange={(e) => setData('categoria', e.target.value)}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    {CATEGORIAS.map((c) => (
                                        <option key={c} value={c}>
                                            {c}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.categoria} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="normas">Normas que cubre</Label>
                            <Input
                                id="normas"
                                value={data.normas}
                                onChange={(e) => setData('normas', e.target.value)}
                                placeholder="ISO 45001, Decreto 1072, Res 0312"
                            />
                            <p className="text-muted-foreground text-xs">Separadas por comas.</p>
                            <InputError message={errors.normas} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="descripcion">Descripción</Label>
                            <Input
                                id="descripcion"
                                value={data.descripcion}
                                onChange={(e) => setData('descripcion', e.target.value)}
                                placeholder="Para qué sirve y cuándo se usa."
                            />
                            <InputError message={errors.descripcion} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="prompt">Instrucción para la IA (opcional)</Label>
                            <textarea
                                id="prompt"
                                value={data.prompt}
                                onChange={(e) => setData('prompt', e.target.value)}
                                rows={3}
                                placeholder="Se usa solo cuando la IA redacta desde cero. Si lo dejas vacío se escribe una por ti."
                                className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                            />
                            <InputError message={errors.prompt} />
                        </div>

                        {!editando && (
                            <div className="grid gap-2">
                                <Label htmlFor="alcance">¿Quién puede usarla?</Label>
                                <select
                                    id="alcance"
                                    value={data.alcance}
                                    onChange={(e) => setData('alcance', e.target.value as Formulario['alcance'])}
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                >
                                    <option value="cliente" disabled={!tenant}>
                                        {tenant ? `Solo ${tenant.name}` : 'Solo la empresa activa (selecciona una primero)'}
                                    </option>
                                    {puedeSubirGlobal && <option value="global">Catálogo de CMK — todas las empresas</option>}
                                </select>
                                <InputError message={errors.alcance} />
                            </div>
                        )}

                        <DialogFooter className="gap-2">
                            <Button type="button" variant="outline" onClick={() => setAbierto(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Guardando…' : editando ? 'Guardar cambios' : 'Subir plantilla'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function Seccion({
    titulo,
    vacio,
    plantillas,
    onEditar,
    onEliminar,
}: {
    titulo: string;
    vacio: string;
    plantillas: Plantilla[];
    onEditar: (p: Plantilla) => void;
    onEliminar: (p: Plantilla) => void;
}) {
    return (
        <div>
            <h2 className="font-brand text-primary mb-3 text-lg font-bold tracking-tight">{titulo}</h2>
            {plantillas.length === 0 ? (
                <Card>
                    <CardContent className="text-muted-foreground flex min-h-24 items-center justify-center p-5 text-sm">{vacio}</CardContent>
                </Card>
            ) : (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {plantillas.map((p) => (
                        <Card key={p.id} className="flex flex-col">
                            <CardContent className="flex flex-1 flex-col gap-3 p-5">
                                <div className="flex items-start justify-between gap-2">
                                    <div>
                                        <div className="text-primary font-mono text-xs font-semibold">{p.codigo}</div>
                                        <div className="leading-tight font-semibold">{p.nombre}</div>
                                    </div>
                                    <Badge variant="outline">{p.tipo}</Badge>
                                </div>

                                {p.descripcion && <p className="text-muted-foreground text-sm">{p.descripcion}</p>}

                                <div className="flex flex-wrap gap-1.5">
                                    <Etiqueta
                                        icono={p.es_global ? <Globe2 className="size-3" /> : <Building2 className="size-3" />}
                                        clase={p.es_global ? 'bg-primary/10 text-primary' : 'bg-sky-500/10 text-sky-700 dark:text-sky-400'}
                                    >
                                        {p.es_global ? 'Catálogo CMK' : 'Propia'}
                                    </Etiqueta>

                                    {p.tiene_base ? (
                                        <Etiqueta icono={<FileText className="size-3" />} clase="bg-green-600/10 text-green-700 dark:text-green-400">
                                            Modelo base
                                        </Etiqueta>
                                    ) : (
                                        <Etiqueta icono={<Sparkles className="size-3" />} clase="bg-amber-500/10 text-amber-700 dark:text-amber-400">
                                            Redacción IA
                                        </Etiqueta>
                                    )}

                                    {p.conserva_formato && (
                                        <Etiqueta icono={<FileText className="size-3" />} clase="bg-violet-500/10 text-violet-700 dark:text-violet-400">
                                            Conserva formato
                                        </Etiqueta>
                                    )}
                                </div>

                                <div className="flex flex-wrap gap-1">
                                    {p.normas.map((n) => (
                                        <span key={n} className="bg-primary/10 text-primary rounded px-1.5 py-0.5 text-[10px] font-medium">
                                            {n}
                                        </span>
                                    ))}
                                </div>

                                <p className="text-muted-foreground mt-auto text-xs">
                                    {p.caracteres > 0 ? `${p.caracteres.toLocaleString('es-CO')} caracteres de modelo` : 'Sin documento modelo'}
                                    {p.subido_por && ` · ${p.subido_por}`}
                                    {p.subida_at && ` · ${p.subida_at}`}
                                </p>

                                {p.editable && (
                                    <div className="flex gap-2">
                                        <Button variant="outline" size="sm" className="flex-1 gap-1.5" onClick={() => onEditar(p)}>
                                            <Pencil className="size-3.5" /> Editar
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="text-destructive gap-1.5"
                                            onClick={() => onEliminar(p)}
                                            aria-label={`Eliminar ${p.nombre}`}
                                        >
                                            <Trash2 className="size-3.5" />
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
        </div>
    );
}

function Etiqueta({ icono, clase, children }: { icono: React.ReactNode; clase: string; children: React.ReactNode }) {
    return (
        <span className={`inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium ${clase}`}>
            {icono} {children}
        </span>
    );
}
