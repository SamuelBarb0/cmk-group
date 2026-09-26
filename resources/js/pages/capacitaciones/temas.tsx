import { CodigoSig } from '@/components/codigo-sig';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, Download, Eye, EyeOff, FileWarning, Pencil, Plus, Upload } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Tema {
    id: number;
    codigo: string;
    titulo: string;
    categoria: string;
    descripcion: string | null;
    duracion_sugerida: number | null;
    orden: number;
    activo: boolean;
    editado_at: string | null;
    editado_por: string | null;
    material: { extension: string; bytes: number } | null;
    usos: number;
}
interface Props {
    topics: Tema[];
    categorias: string[];
    extensiones: string[];
    maxBytes: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Capacitaciones', href: '/capacitaciones' },
    { title: 'Biblioteca de temas', href: '/capacitaciones/temas' },
];

const mb = (b: number) =>
    b < 1024 * 1024 ? `${Math.max(1, Math.round(b / 1024))} KB` : `${(b / 1024 / 1024).toFixed(b < 10 * 1024 * 1024 ? 1 : 0)} MB`;

export default function TemasCapacitacion({ topics, categorias, extensiones, maxBytes }: Props) {
    const flash = usePage<SharedData>().props.flash;
    const [notice, setNotice] = useState<string | null>(null);
    // null = cerrado · 'nuevo' = crear · Tema = editar
    const [editando, setEditando] = useState<Tema | 'nuevo' | null>(null);

    useEffect(() => {
        if (flash?.success) {
            setNotice(flash.success);
            const t = setTimeout(() => setNotice(null), 4000);
            return () => clearTimeout(t);
        }
    }, [flash?.success]);

    function alternar(t: Tema) {
        const msg = t.activo
            ? `¿Retirar «${t.titulo}» de la biblioteca? Las capacitaciones ya registradas no cambian.`
            : `¿Volver a activar «${t.titulo}»?`;
        if (confirm(msg)) router.patch(route('capacitaciones.temas.toggle', t.id), {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Biblioteca de temas" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            <CodigoSig className="mr-2" />
                            Biblioteca de temas
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Temas de capacitación y su material, compartidos con todas las empresas.{' '}
                            {`Hasta ${mb(maxBytes)} por archivo (${extensiones.join(', ')}).`}
                        </p>
                    </div>
                    <Button className="gap-2" onClick={() => setEditando('nuevo')}>
                        <Plus className="size-4" /> Nuevo tema
                    </Button>
                </div>

                {notice && (
                    <div className="flex items-center gap-2 rounded-lg border border-green-600/30 bg-green-600/10 px-4 py-2.5 text-sm text-green-700 dark:text-green-400">
                        <CheckCircle2 className="size-4" /> {notice}
                    </div>
                )}

                <Card className="overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground border-b text-left">
                                <tr>
                                    <th className="px-5 py-3 font-medium">Tema</th>
                                    <th className="px-5 py-3 font-medium">Material</th>
                                    <th className="px-5 py-3 text-center font-medium">Usado</th>
                                    <th className="px-5 py-3 text-center font-medium">Estado</th>
                                    <th className="px-5 py-3 text-right font-medium">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {topics.map((t) => (
                                    <tr key={t.id} className={cn('hover:bg-muted/40 transition-colors', !t.activo && 'opacity-60')}>
                                        <td className="px-5 py-3">
                                            <div className="font-medium">{t.titulo}</div>
                                            <div className="text-muted-foreground font-mono text-xs">
                                                {t.codigo} · {t.categoria}
                                                {t.duracion_sugerida ? ` · ${t.duracion_sugerida} min` : ''}
                                            </div>
                                        </td>
                                        <td className="px-5 py-3">
                                            {t.material ? (
                                                <a
                                                    href={route('capacitaciones.material', t.id)}
                                                    className="text-primary inline-flex items-center gap-1.5 hover:underline"
                                                >
                                                    <Download className="size-3.5" /> .{t.material.extension} · {mb(t.material.bytes)}
                                                </a>
                                            ) : (
                                                <span className="inline-flex items-center gap-1.5 text-amber-700 dark:text-amber-400">
                                                    <FileWarning className="size-3.5" /> Sin material
                                                </span>
                                            )}
                                            {t.editado_at && (
                                                <div className="text-muted-foreground text-xs">
                                                    Editado {t.editado_at.slice(0, 10)}
                                                    {t.editado_por && ` por ${t.editado_por}`}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-5 py-3 text-center">{t.usos}</td>
                                        <td className="px-5 py-3 text-center">
                                            <Badge className={t.activo ? 'bg-green-600 text-white' : 'bg-slate-500 text-white'}>
                                                {t.activo ? 'Activo' : 'Retirado'}
                                            </Badge>
                                        </td>
                                        <td className="px-5 py-3">
                                            <div className="flex items-center justify-end gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() => setEditando(t)}
                                                    aria-label="Editar"
                                                    title="Editar / cambiar material"
                                                >
                                                    <Pencil className="size-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() => alternar(t)}
                                                    aria-label={t.activo ? 'Retirar' : 'Activar'}
                                                    title={t.activo ? 'Retirar' : 'Activar'}
                                                >
                                                    {t.activo ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            </div>

            {editando && (
                <FormTema
                    // key: abrir otro tema debe arrancar un formulario limpio.
                    key={editando === 'nuevo' ? 'nuevo' : editando.id}
                    tema={editando === 'nuevo' ? null : editando}
                    categorias={categorias}
                    extensiones={extensiones}
                    maxBytes={maxBytes}
                    onClose={() => setEditando(null)}
                />
            )}
        </AppLayout>
    );
}

function FormTema({
    tema,
    categorias,
    extensiones,
    maxBytes,
    onClose,
}: {
    tema: Tema | null;
    categorias: string[];
    extensiones: string[];
    maxBytes: number;
    onClose: () => void;
}) {
    const form = useForm<{
        codigo: string;
        titulo: string;
        categoria: string;
        descripcion: string;
        duracion_sugerida: string;
        orden: string;
        archivo: File | null;
    }>({
        codigo: tema?.codigo ?? 'CAP-',
        titulo: tema?.titulo ?? '',
        categoria: tema?.categoria ?? categorias[0],
        descripcion: tema?.descripcion ?? '',
        duracion_sugerida: tema?.duracion_sugerida?.toString() ?? '60',
        orden: tema?.orden?.toString() ?? '',
        archivo: null,
    });
    const [avisoArchivo, setAvisoArchivo] = useState<string | null>(null);

    function elegir(f: File | null) {
        setAvisoArchivo(null);
        // El servidor descartaría el POST entero sin decir por qué: mejor avisar aquí.
        if (f && f.size > maxBytes) {
            setAvisoArchivo(`El archivo pesa ${mb(f.size)} y el servidor admite hasta ${mb(maxBytes)}.`);
            form.setData('archivo', null);
            return;
        }
        form.setData('archivo', f);
    }

    function guardar(e: React.FormEvent) {
        e.preventDefault();
        const opts = { preserveScroll: true, forceFormData: true, onSuccess: onClose };
        if (tema) form.post(route('capacitaciones.temas.update', tema.id), opts);
        else form.post(route('capacitaciones.temas.store'), opts);
    }

    const e = form.errors;
    return (
        <Dialog open onOpenChange={(o) => !o && !form.processing && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle className="font-brand">{tema ? tema.titulo : 'Nuevo tema'}</DialogTitle>
                    <DialogDescription>
                        {tema?.usos
                            ? `Usado en ${tema.usos} capacitación(es). Cambiar el material solo afecta las descargas de ahora en adelante.`
                            : 'Aparece en la biblioteca de capacitaciones de todas las empresas.'}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={guardar} className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-[1fr_8rem]">
                        <div className="grid gap-1.5">
                            <Label htmlFor="titulo">Título</Label>
                            <Input id="titulo" value={form.data.titulo} onChange={(ev) => form.setData('titulo', ev.target.value)} />
                            {e.titulo && <p className="text-destructive text-xs">{e.titulo}</p>}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="categoria">Sistema</Label>
                            <select
                                id="categoria"
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                value={form.data.categoria}
                                onChange={(ev) => form.setData('categoria', ev.target.value)}
                            >
                                {categorias.map((c) => (
                                    <option key={c}>{c}</option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="grid grid-cols-3 gap-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="codigo">Código</Label>
                            <Input
                                id="codigo"
                                className="font-mono uppercase"
                                value={form.data.codigo}
                                onChange={(ev) => form.setData('codigo', ev.target.value.toUpperCase())}
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="duracion">Duración (min)</Label>
                            <Input
                                id="duracion"
                                type="number"
                                min={5}
                                value={form.data.duracion_sugerida}
                                onChange={(ev) => form.setData('duracion_sugerida', ev.target.value)}
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="orden">Orden</Label>
                            <Input
                                id="orden"
                                type="number"
                                min={0}
                                placeholder="Al final"
                                value={form.data.orden}
                                onChange={(ev) => form.setData('orden', ev.target.value)}
                            />
                        </div>
                    </div>
                    {(e.codigo || e.duracion_sugerida || e.orden) && (
                        <p className="text-destructive -mt-2 text-xs">{e.codigo ?? e.duracion_sugerida ?? e.orden}</p>
                    )}
                    <div className="grid gap-1.5">
                        <Label htmlFor="descripcion">Descripción (opcional)</Label>
                        <textarea
                            id="descripcion"
                            rows={3}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                            value={form.data.descripcion}
                            onChange={(ev) => form.setData('descripcion', ev.target.value)}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="archivo">{tema?.material ? 'Reemplazar material (opcional)' : 'Material'}</Label>
                        {tema?.material && (
                            <p className="text-muted-foreground text-xs">
                                Actual: .{tema.material.extension} · {mb(tema.material.bytes)}. Si no eliges archivo, se conserva.
                            </p>
                        )}
                        <Input
                            id="archivo"
                            type="file"
                            accept={extensiones.map((x) => '.' + x).join(',')}
                            onChange={(ev) => elegir(ev.target.files?.[0] ?? null)}
                        />
                        {(avisoArchivo || e.archivo) && <p className="text-destructive text-xs">{avisoArchivo ?? e.archivo}</p>}
                        {form.progress && (
                            <div className="bg-muted h-1.5 overflow-hidden rounded">
                                <div className="bg-primary h-full transition-all" style={{ width: `${form.progress.percentage ?? 0}%` }} />
                            </div>
                        )}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose} disabled={form.processing}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing} className="gap-2">
                            <Upload className="size-4" /> {form.processing ? 'Guardando…' : 'Guardar'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
