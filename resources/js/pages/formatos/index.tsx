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
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Building2, CheckCircle2, ClipboardCheck, Download, FileText, Plus, Save, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Tipo = 'text' | 'textarea' | 'date' | 'number' | 'select' | 'checklist' | 'firma';
type Estado = 'borrador' | 'completado';

interface Campo {
    key: string;
    label: string;
    tipo: Tipo;
    requerido?: boolean;
    opciones?: string[];
    items?: string[];
}
interface Seccion {
    titulo: string;
    campos: Campo[];
}
interface Schema {
    secciones: Seccion[];
}

interface Format {
    id: number;
    codigo: string;
    nombre: string;
    categoria: string;
    grupo: string;
    descripcion: string | null;
    schema: Schema;
}
interface RecordRow {
    id: number;
    form_format_id: number | null;
    codigo: string;
    titulo: string;
    categoria: string;
    grupo: string;
    estado: Estado;
    fecha: string | null;
    responsable: string | null;
    generado_por: string | null;
    updated_at: string;
}
interface OpenRecord {
    id: number;
    codigo: string;
    titulo: string;
    grupo: string;
    schema: Schema;
    data: Record<string, unknown> | null;
    estado: Estado;
    fecha: string | null;
    responsable: string | null;
}

interface Props {
    formats: Format[];
    records: RecordRow[];
    needsClient: boolean;
    open?: OpenRecord;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Formatos', href: '/formatos' },
];

const GRUPO: Record<string, { label: string; cls: string }> = {
    inspeccion: { label: 'Inspección', cls: 'bg-blue-500/10 text-blue-700 dark:text-blue-400' },
    acta: { label: 'Acta', cls: 'bg-purple-500/10 text-purple-700 dark:text-purple-400' },
    lista: { label: 'Lista de chequeo', cls: 'bg-teal-500/10 text-teal-700 dark:text-teal-400' },
    general: { label: 'Formato', cls: 'bg-slate-500/10 text-slate-700 dark:text-slate-400' },
};

const ESTADO: Record<Estado, { label: string; cls: string }> = {
    borrador: { label: 'Borrador', cls: 'bg-slate-500 text-white' },
    completado: { label: 'Completado', cls: 'bg-green-600 text-white' },
};

const CHECK = [
    { v: 'cumple', label: 'Cumple', cls: 'bg-green-600 text-white' },
    { v: 'no_cumple', label: 'No cumple', cls: 'bg-red-600 text-white' },
    { v: 'no_aplica', label: 'N/A', cls: 'bg-slate-400 text-white' },
] as const;

export default function FormatosIndex({ formats, records, needsClient, open }: Props) {
    const { can } = usePermissions();
    const canPerform = can('inspections.perform');
    const page = usePage<SharedData>();
    const flash = page.props.flash;
    const tenant = page.props.tenant as { id: number; name: string } | null;

    const [notice, setNotice] = useState<string | null>(null);
    const [creating, setCreating] = useState<number | null>(null);

    useEffect(() => {
        if (flash?.success) {
            setNotice(flash.success);
            const t = setTimeout(() => setNotice(null), 4000);
            return () => clearTimeout(t);
        }
    }, [flash?.success]);

    const porGrupo = useMemo(() => {
        const g: Record<string, Format[]> = {};
        formats.forEach((f) => (g[f.grupo] ??= []).push(f));
        return g;
    }, [formats]);

    function crear(f: Format) {
        router.post(route('formatos.store'), { form_format_id: f.id }, { onStart: () => setCreating(f.id), onFinish: () => setCreating(null) });
    }
    function abrir(r: RecordRow) {
        router.get(route('formatos.show', r.id), {}, { preserveScroll: true });
    }
    function eliminar(r: RecordRow) {
        if (confirm(`¿Eliminar el registro «${r.titulo}»?`)) {
            router.delete(route('formatos.destroy', r.id), { preserveScroll: true });
        }
    }

    if (needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Formatos" />
                <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Formatos</h1>
                        <p className="text-muted-foreground text-sm">Inspecciones, actas y listas de chequeo del SGI.</p>
                    </div>
                    <Card>
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <Building2 className="size-7" />
                            </div>
                            <p className="font-medium">Selecciona un cliente para diligenciar sus formatos</p>
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
            <Head title="Formatos" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="font-brand text-2xl font-bold tracking-tight">Formatos</h1>
                    <p className="text-muted-foreground text-sm">
                        Inspecciones, actas y listas de chequeo de <span className="font-medium">{tenant?.name ?? 'la empresa'}</span>.
                    </p>
                </div>

                {notice && (
                    <div className="flex items-center gap-2 rounded-lg border border-green-600/30 bg-green-600/10 px-4 py-2.5 text-sm text-green-700 dark:text-green-400">
                        <CheckCircle2 className="size-4" /> {notice}
                    </div>
                )}

                {/* Catálogo de formatos disponibles */}
                <div>
                    <h2 className="font-brand text-primary mb-3 text-lg font-bold tracking-tight">Formatos disponibles</h2>
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {formats.map((f) => {
                            const g = GRUPO[f.grupo] ?? GRUPO.general;
                            return (
                                <Card key={f.id} className="flex flex-col">
                                    <CardContent className="flex flex-1 flex-col gap-3 p-5">
                                        <div className="flex items-start justify-between gap-2">
                                            <div>
                                                <div className="text-primary font-mono text-xs font-semibold">{f.codigo}</div>
                                                <div className="font-semibold leading-tight">{f.nombre}</div>
                                            </div>
                                            <span className={cn('rounded px-1.5 py-0.5 text-[10px] font-medium', g.cls)}>{g.label}</span>
                                        </div>
                                        {f.descripcion && <p className="text-muted-foreground text-sm">{f.descripcion}</p>}
                                        {canPerform && (
                                            <Button onClick={() => crear(f)} disabled={creating !== null} className="mt-auto gap-2">
                                                <Plus className="size-4" /> {creating === f.id ? 'Creando…' : 'Diligenciar'}
                                            </Button>
                                        )}
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                </div>

                {/* Registros diligenciados */}
                <div>
                    <h2 className="font-brand text-primary mb-3 text-lg font-bold tracking-tight">Registros diligenciados</h2>
                    <Card className="overflow-hidden">
                        {records.length === 0 ? (
                            <CardContent className="flex min-h-40 flex-col items-center justify-center gap-2 text-center">
                                <ClipboardCheck className="text-muted-foreground size-7" />
                                <p className="text-muted-foreground text-sm">Aún no hay registros. Diligencia un formato arriba.</p>
                            </CardContent>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground border-b text-left">
                                        <tr>
                                            <th className="px-5 py-3 font-medium">Formato</th>
                                            <th className="px-5 py-3 font-medium">Fecha</th>
                                            <th className="px-5 py-3 font-medium">Responsable</th>
                                            <th className="px-5 py-3 text-center font-medium">Estado</th>
                                            <th className="px-5 py-3 text-right font-medium">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {records.map((r) => {
                                            const g = GRUPO[r.grupo] ?? GRUPO.general;
                                            return (
                                                <tr key={r.id} className="hover:bg-muted/40 transition-colors">
                                                    <td className="px-5 py-3">
                                                        <div className="font-medium">{r.titulo}</div>
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-muted-foreground font-mono text-xs">{r.codigo}</span>
                                                            <span className={cn('rounded px-1.5 py-0.5 text-[10px] font-medium', g.cls)}>{g.label}</span>
                                                        </div>
                                                    </td>
                                                    <td className="text-muted-foreground px-5 py-3">{r.fecha ?? '—'}</td>
                                                    <td className="text-muted-foreground px-5 py-3">{r.responsable ?? '—'}</td>
                                                    <td className="px-5 py-3 text-center">
                                                        <Badge className={ESTADO[r.estado].cls}>{ESTADO[r.estado].label}</Badge>
                                                    </td>
                                                    <td className="px-5 py-3">
                                                        <div className="flex items-center justify-end gap-1">
                                                            <Button variant="ghost" size="icon" asChild aria-label="Descargar Word">
                                                                <a href={route('formatos.export', r.id)}>
                                                                    <Download className="size-4" />
                                                                </a>
                                                            </Button>
                                                            <Button variant="ghost" size="icon" onClick={() => abrir(r)} aria-label="Ver / diligenciar">
                                                                <FileText className="size-4" />
                                                            </Button>
                                                            {canPerform && (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    onClick={() => eliminar(r)}
                                                                    aria-label="Eliminar"
                                                                    className="text-destructive hover:text-destructive"
                                                                >
                                                                    <Trash2 className="size-4" />
                                                                </Button>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>
                </div>
            </div>

            {open && <RecordEditor key={open.id} record={open} canPerform={canPerform} />}
        </AppLayout>
    );
}

/** Editor dinámico: construye el formulario desde el esquema del registro. */
function RecordEditor({ record, canPerform }: { record: OpenRecord; canPerform: boolean }) {
    const [openState, setOpenState] = useState(true);
    const [titulo, setTitulo] = useState(record.titulo);
    const [fecha, setFecha] = useState(record.fecha ?? '');
    const [responsable, setResponsable] = useState(record.responsable ?? '');
    const [estado, setEstado] = useState<Estado>(record.estado);
    const [data, setData] = useState<Record<string, unknown>>(() => initData(record));
    const [saving, setSaving] = useState(false);

    function setField(key: string, value: unknown) {
        setData((d) => ({ ...d, [key]: value }));
    }

    function guardar(nuevoEstado?: Estado) {
        router.put(
            route('formatos.update', record.id),
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            { titulo, fecha: fecha || null, responsable: responsable || null, data: data as any, estado: nuevoEstado ?? estado },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => close(),
            },
        );
    }

    function close() {
        setOpenState(false);
        // Vuelve al índice sin el registro abierto.
        setTimeout(() => router.get(route('formatos.index'), {}, { preserveScroll: true, preserveState: false }), 150);
    }

    return (
        <Dialog open={openState} onOpenChange={(o) => !o && close()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="font-brand">{record.codigo}</DialogTitle>
                    <DialogDescription>Diligencia el formato y márcalo como completado cuando esté listo.</DialogDescription>
                </DialogHeader>

                <div className="space-y-5">
                    {/* Cabecera común */}
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="sm:col-span-3">
                            <Label>Título</Label>
                            <Input value={titulo} onChange={(e) => setTitulo(e.target.value)} disabled={!canPerform} />
                        </div>
                        <div>
                            <Label>Fecha</Label>
                            <Input type="date" value={fecha} onChange={(e) => setFecha(e.target.value)} disabled={!canPerform} />
                        </div>
                        <div className="sm:col-span-2">
                            <Label>Responsable</Label>
                            <Input value={responsable} onChange={(e) => setResponsable(e.target.value)} disabled={!canPerform} />
                        </div>
                    </div>

                    {/* Secciones dinámicas del esquema */}
                    {record.schema.secciones.map((sec, si) => (
                        <div key={si} className="space-y-3">
                            <h3 className="font-brand text-primary border-b pb-1 text-sm font-bold tracking-tight uppercase">{sec.titulo}</h3>
                            {sec.campos.map((campo) => (
                                <CampoRenderer
                                    key={campo.key}
                                    campo={campo}
                                    value={data[campo.key]}
                                    disabled={!canPerform}
                                    onChange={(v) => setField(campo.key, v)}
                                />
                            ))}
                        </div>
                    ))}
                </div>

                {canPerform && (
                    <DialogFooter className="flex-wrap gap-2">
                        <Button variant="outline" onClick={close}>
                            Cerrar
                        </Button>
                        <Button variant="secondary" disabled={saving} onClick={() => guardar('borrador')} className="gap-2">
                            <Save className="size-4" /> Guardar borrador
                        </Button>
                        <Button disabled={saving} onClick={() => guardar('completado')} className="gap-2">
                            <CheckCircle2 className="size-4" /> {saving ? 'Guardando…' : 'Marcar completado'}
                        </Button>
                    </DialogFooter>
                )}
            </DialogContent>
        </Dialog>
    );
}

/** Estado inicial de `data` a partir del esquema (inicializa checklists). */
function initData(record: OpenRecord): Record<string, unknown> {
    const d: Record<string, unknown> = { ...(record.data ?? {}) };
    record.schema.secciones.forEach((sec) =>
        sec.campos.forEach((campo) => {
            if (campo.tipo === 'checklist') {
                const guardado = (d[campo.key] as { item: string; estado: string; obs: string }[] | undefined) ?? [];
                const porItem = new Map(guardado.map((g) => [g.item, g]));
                d[campo.key] = (campo.items ?? []).map((item) => porItem.get(item) ?? { item, estado: '', obs: '' });
            } else if (campo.tipo === 'firma' && !d[campo.key]) {
                d[campo.key] = { nombre: '', cc: '' };
            }
        }),
    );
    return d;
}

function CampoRenderer({
    campo,
    value,
    disabled,
    onChange,
}: {
    campo: Campo;
    value: unknown;
    disabled: boolean;
    onChange: (v: unknown) => void;
}) {
    if (campo.tipo === 'checklist') {
        const filas = (value as { item: string; estado: string; obs: string }[]) ?? [];
        return (
            <div>
                <Label className="mb-1 block">{campo.label}</Label>
                <div className="divide-border overflow-hidden rounded-md border">
                    {filas.map((fila, i) => (
                        <div key={i} className="border-b last:border-b-0">
                            <div className="flex flex-col gap-2 p-2 sm:flex-row sm:items-center">
                                <span className="flex-1 text-sm">{fila.item}</span>
                                <div className="flex gap-1">
                                    {CHECK.map((opt) => (
                                        <button
                                            key={opt.v}
                                            type="button"
                                            disabled={disabled}
                                            onClick={() => {
                                                const next = [...filas];
                                                next[i] = { ...fila, estado: fila.estado === opt.v ? '' : opt.v };
                                                onChange(next);
                                            }}
                                            className={cn(
                                                'rounded px-2 py-1 text-xs font-medium transition',
                                                fila.estado === opt.v ? opt.cls : 'bg-muted text-muted-foreground hover:bg-muted/70',
                                            )}
                                        >
                                            {opt.label}
                                        </button>
                                    ))}
                                </div>
                                <Input
                                    value={fila.obs}
                                    onChange={(e) => {
                                        const next = [...filas];
                                        next[i] = { ...fila, obs: e.target.value };
                                        onChange(next);
                                    }}
                                    disabled={disabled}
                                    placeholder="Observación"
                                    className="h-8 sm:w-56"
                                />
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        );
    }

    if (campo.tipo === 'firma') {
        const firma = (value as { nombre: string; cc: string }) ?? { nombre: '', cc: '' };
        return (
            <div>
                <Label className="mb-1 block">{campo.label}</Label>
                <div className="grid gap-2 sm:grid-cols-2">
                    <Input
                        value={firma.nombre}
                        onChange={(e) => onChange({ ...firma, nombre: e.target.value })}
                        disabled={disabled}
                        placeholder="Nombre"
                    />
                    <Input
                        value={firma.cc}
                        onChange={(e) => onChange({ ...firma, cc: e.target.value })}
                        disabled={disabled}
                        placeholder="C.C."
                    />
                </div>
            </div>
        );
    }

    if (campo.tipo === 'textarea') {
        return (
            <div>
                <Label className="mb-1 block">{campo.label}</Label>
                <textarea
                    value={(value as string) ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                    disabled={disabled}
                    rows={4}
                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm disabled:opacity-60"
                />
            </div>
        );
    }

    if (campo.tipo === 'select') {
        return (
            <div>
                <Label className="mb-1 block">{campo.label}</Label>
                <select
                    value={(value as string) ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                    disabled={disabled}
                    className="border-input bg-background h-9 w-full rounded-md border px-3 text-sm disabled:opacity-60"
                >
                    <option value="">—</option>
                    {(campo.opciones ?? []).map((o) => (
                        <option key={o} value={o}>
                            {o}
                        </option>
                    ))}
                </select>
            </div>
        );
    }

    // text | date | number
    return (
        <div>
            <Label className="mb-1 block">{campo.label}</Label>
            <Input
                type={campo.tipo === 'number' ? 'number' : campo.tipo === 'date' ? 'date' : 'text'}
                value={(value as string) ?? ''}
                onChange={(e) => onChange(e.target.value)}
                disabled={disabled}
            />
        </div>
    );
}
