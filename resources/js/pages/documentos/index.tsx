import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Building2, CheckCircle2, Download, FileText, FolderOpen, Sparkles, Trash2, Upload } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Documento {
    id: number;
    nombre: string;
    categoria: string | null;
    origen: 'export' | 'upload';
    size: number;
    mime: string | null;
    subido_por: string | null;
    updated_at: string | null;
}

interface Props {
    documents: Documento[];
    categorias: string[];
    needsClient: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Documentos', href: '/documentos' },
];

/** Tamaño legible: 12.3 KB / 1.4 MB. */
function tamano(bytes: number): string {
    if (bytes >= 1_048_576) return `${(bytes / 1_048_576).toFixed(1)} MB`;
    if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${bytes} B`;
}

export default function DocumentosIndex({ documents, categorias, needsClient }: Props) {
    const { can } = usePermissions();
    const canManage = can('documents.manage');
    const page = usePage<SharedData>();
    const flash = page.props.flash;
    const tenant = page.props.tenant;

    const [open, setOpen] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm<{
        archivo: File | null;
        nombre: string;
        categoria: string;
    }>({ archivo: null, nombre: '', categoria: '' });

    useEffect(() => {
        if (flash?.success) {
            setNotice(flash.success);
            const t = setTimeout(() => setNotice(null), 3500);
            return () => clearTimeout(t);
        }
    }, [flash?.success]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('documentos.store'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                setOpen(false);
                reset();
            },
        });
    };

    function destroy(doc: Documento) {
        if (confirm(`¿Eliminar «${doc.nombre}» del repositorio? Esta acción no se puede deshacer.`)) {
            router.delete(route('documentos.destroy', doc.id), { preserveScroll: true });
        }
    }

    if (needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Documentos" />
                <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Documentos de la empresa</h1>
                        <p className="text-muted-foreground text-sm">Repositorio documental por cliente.</p>
                    </div>
                    <Card>
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <FolderOpen className="size-7" />
                            </div>
                            <p className="font-medium">Selecciona un cliente para ver sus documentos</p>
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

    const exportados = documents.filter((d) => d.origen === 'export').length;
    const subidos = documents.length - exportados;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Documentos" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Encabezado */}
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Documentos de la empresa</h1>
                        <p className="text-muted-foreground text-sm">
                            Repositorio documental de <span className="font-medium">{tenant?.name}</span>: exports de Documentos IA y archivos subidos.
                        </p>
                    </div>
                    {canManage && (
                        <Button onClick={() => { clearErrors(); reset(); setOpen(true); }} className="gap-2">
                            <Upload className="size-4" /> Subir documento
                        </Button>
                    )}
                </div>

                {/* Confirmación flash */}
                {notice && (
                    <div className="flex items-center gap-2 rounded-lg border border-green-600/30 bg-green-600/10 px-4 py-2.5 text-sm text-green-700 dark:text-green-400">
                        <CheckCircle2 className="size-4" /> {notice}
                    </div>
                )}

                {/* Métricas */}
                <div className="grid gap-4 sm:grid-cols-3">
                    {[
                        { label: 'Documentos guardados', value: documents.length, icon: FolderOpen },
                        { label: 'Exports de Documentos IA', value: exportados, icon: Sparkles },
                        { label: 'Archivos subidos', value: subidos, icon: Upload },
                    ].map(({ label, value, icon: Icon }) => (
                        <Card key={label}>
                            <CardContent className="flex items-center gap-4 p-5">
                                <div className="bg-primary/10 text-primary flex size-11 items-center justify-center rounded-lg">
                                    <Icon className="size-5" />
                                </div>
                                <div>
                                    <div className="text-2xl font-bold tabular-nums">{value}</div>
                                    <div className="text-muted-foreground text-sm">{label}</div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Tabla */}
                <Card className="overflow-hidden">
                    {documents.length === 0 ? (
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <FileText className="size-7" />
                            </div>
                            <p className="font-medium">Aún no hay documentos guardados para esta empresa</p>
                            <p className="text-muted-foreground max-w-md text-sm">
                                Los .docx que exportes desde Documentos IA quedan archivados aquí automáticamente; también puedes subir archivos (versiones firmadas, evidencias).
                            </p>
                        </CardContent>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground border-b text-left">
                                    <tr>
                                        <th className="px-5 py-3 font-medium">Documento</th>
                                        <th className="px-5 py-3 font-medium">Categoría</th>
                                        <th className="px-5 py-3 text-center font-medium">Origen</th>
                                        <th className="px-5 py-3 text-right font-medium">Tamaño</th>
                                        <th className="px-5 py-3 font-medium">Actualizado</th>
                                        <th className="px-5 py-3 text-right font-medium">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-border divide-y">
                                    {documents.map((d) => (
                                        <tr key={d.id} className="hover:bg-muted/40 transition-colors">
                                            <td className="px-5 py-3">
                                                <div className="font-medium">{d.nombre}</div>
                                                {d.subido_por && <div className="text-muted-foreground text-xs">por {d.subido_por}</div>}
                                            </td>
                                            <td className="text-muted-foreground px-5 py-3">{d.categoria ?? '—'}</td>
                                            <td className="px-5 py-3 text-center">
                                                <Badge variant={d.origen === 'export' ? 'default' : 'secondary'} className="gap-1">
                                                    {d.origen === 'export' ? <Sparkles className="size-3" /> : <Upload className="size-3" />}
                                                    {d.origen === 'export' ? 'Documentos IA' : 'Subido'}
                                                </Badge>
                                            </td>
                                            <td className="text-muted-foreground px-5 py-3 text-right tabular-nums">{tamano(d.size)}</td>
                                            <td className="text-muted-foreground px-5 py-3">{d.updated_at ?? '—'}</td>
                                            <td className="px-5 py-3">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Button asChild variant="ghost" size="icon" aria-label="Descargar">
                                                        <a href={route('documentos.download', d.id)}>
                                                            <Download className="size-4" />
                                                        </a>
                                                    </Button>
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

            {/* Diálogo de subida */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="font-brand">Subir documento</DialogTitle>
                        <DialogDescription>
                            Queda guardado en el repositorio de {tenant?.name}. Formatos: PDF, Word, Excel, PowerPoint, imágenes (máx. 10 MB).
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="archivo">Archivo *</Label>
                            <Input
                                id="archivo"
                                type="file"
                                accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.txt,.csv"
                                onChange={(e) => setData('archivo', e.target.files?.[0] ?? null)}
                                required
                            />
                            <InputError message={errors.archivo} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="nombre">Nombre (opcional)</Label>
                            <Input
                                id="nombre"
                                value={data.nombre}
                                onChange={(e) => setData('nombre', e.target.value)}
                                placeholder="Si se deja vacío, usa el nombre del archivo"
                            />
                            <InputError message={errors.nombre} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="categoria">Categoría</Label>
                            <select
                                id="categoria"
                                value={data.categoria}
                                onChange={(e) => setData('categoria', e.target.value)}
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            >
                                <option value="">—</option>
                                {categorias.map((c) => (
                                    <option key={c} value={c}>
                                        {c}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.categoria} />
                        </div>

                        <DialogFooter className="gap-2 pt-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing || !data.archivo}>
                                {processing ? 'Subiendo…' : 'Guardar documento'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
