import { CodigoSig } from '@/components/codigo-sig';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Copy, Eye, EyeOff, Pencil, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Formato {
    id: number;
    codigo: string;
    nombre: string;
    categoria: string;
    grupo: string;
    descripcion: string | null;
    activo: boolean;
    editado_at: string | null;
    editado_por: string | null;
    secciones: number;
    campos: number;
    registros: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Formatos', href: '/formatos' },
    { title: 'Catálogo', href: '/formatos/catalogo' },
];

const GRUPO: Record<string, string> = { inspeccion: 'Inspección', acta: 'Acta', lista: 'Lista de chequeo', general: 'Otro' };

export default function CatalogoFormatos({ formats }: { formats: Formato[] }) {
    const page = usePage<SharedData>();
    const flash = page.props.flash;
    const errors = page.props.errors as Record<string, string>;
    const [notice, setNotice] = useState<string | null>(null);

    useEffect(() => {
        if (flash?.success) {
            setNotice(flash.success);
            const t = setTimeout(() => setNotice(null), 4000);
            return () => clearTimeout(t);
        }
    }, [flash?.success]);

    function alternar(f: Formato) {
        const msg = f.activo
            ? `¿Retirar «${f.nombre}»? Deja de aparecer para diligenciar; los registros existentes no cambian.`
            : `¿Volver a activar «${f.nombre}»?`;
        if (confirm(msg)) router.patch(route('formatos.catalogo.toggle', f.id), {}, { preserveScroll: true });
    }
    function eliminar(f: Formato) {
        if (confirm(`¿Eliminar «${f.nombre}» del catálogo? No se puede deshacer.`)) {
            router.delete(route('formatos.catalogo.destroy', f.id), { preserveScroll: true });
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Catálogo de formatos" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            <CodigoSig className="mr-2" />
                            Catálogo de formatos
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Los formatos que todas las empresas pueden diligenciar. Editar uno no altera los registros ya diligenciados.
                        </p>
                    </div>
                    <Button asChild className="gap-2">
                        <Link href={route('formatos.catalogo.create')}>
                            <Plus className="size-4" /> Nuevo formato
                        </Link>
                    </Button>
                </div>

                {notice && (
                    <div className="flex items-center gap-2 rounded-lg border border-green-600/30 bg-green-600/10 px-4 py-2.5 text-sm text-green-700 dark:text-green-400">
                        <CheckCircle2 className="size-4" /> {notice}
                    </div>
                )}
                {errors.formato && (
                    <div className="border-destructive/30 bg-destructive/10 text-destructive flex items-center gap-2 rounded-lg border px-4 py-2.5 text-sm">
                        <AlertTriangle className="size-4" /> {errors.formato}
                    </div>
                )}

                <Card className="overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground border-b text-left">
                                <tr>
                                    <th className="px-5 py-3 font-medium">Formato</th>
                                    <th className="px-5 py-3 font-medium">Tipo</th>
                                    <th className="px-5 py-3 text-center font-medium">Contenido</th>
                                    <th className="px-5 py-3 text-center font-medium">Registros</th>
                                    <th className="px-5 py-3 text-center font-medium">Estado</th>
                                    <th className="px-5 py-3 text-right font-medium">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {formats.map((f) => (
                                    <tr key={f.id} className={cn('hover:bg-muted/40 transition-colors', !f.activo && 'opacity-60')}>
                                        <td className="px-5 py-3">
                                            <div className="font-medium">{f.nombre}</div>
                                            <div className="text-muted-foreground font-mono text-xs">{f.codigo}</div>
                                            {f.editado_at && (
                                                <div className="text-muted-foreground text-xs">
                                                    Editado {f.editado_at.slice(0, 10)}
                                                    {f.editado_por && ` por ${f.editado_por}`}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-5 py-3">
                                            <div>{GRUPO[f.grupo] ?? f.grupo}</div>
                                            <div className="text-muted-foreground text-xs">{f.categoria}</div>
                                        </td>
                                        <td className="text-muted-foreground px-5 py-3 text-center text-xs">
                                            {f.secciones} secc. · {f.campos} campos
                                        </td>
                                        <td className="px-5 py-3 text-center">{f.registros}</td>
                                        <td className="px-5 py-3 text-center">
                                            <Badge className={f.activo ? 'bg-green-600 text-white' : 'bg-slate-500 text-white'}>
                                                {f.activo ? 'Activo' : 'Retirado'}
                                            </Badge>
                                        </td>
                                        <td className="px-5 py-3">
                                            <div className="flex items-center justify-end gap-1">
                                                <Button variant="ghost" size="icon" asChild aria-label="Editar" title="Editar">
                                                    <Link href={route('formatos.catalogo.edit', f.id)}>
                                                        <Pencil className="size-4" />
                                                    </Link>
                                                </Button>
                                                <Button variant="ghost" size="icon" asChild aria-label="Duplicar" title="Duplicar">
                                                    <Link href={route('formatos.catalogo.create', { desde: f.id })}>
                                                        <Copy className="size-4" />
                                                    </Link>
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() => alternar(f)}
                                                    aria-label={f.activo ? 'Retirar' : 'Activar'}
                                                    title={f.activo ? 'Retirar' : 'Activar'}
                                                >
                                                    {f.activo ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                                </Button>
                                                {f.registros === 0 && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() => eliminar(f)}
                                                        aria-label="Eliminar"
                                                        title="Eliminar"
                                                    >
                                                        <Trash2 className="text-destructive size-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            </div>
        </AppLayout>
    );
}
