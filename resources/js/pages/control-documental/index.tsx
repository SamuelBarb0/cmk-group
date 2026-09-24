import {
    EstadoBadge,
    etiquetaEstado,
    fechaCorta,
    NOMBRE_SISTEMA,
    selectCls,
    SistemaChips,
    SISTEMAS,
    SistemasPicker,
    type EstadoDocumento,
    type EstadoVersion,
    type Sistema,
} from '@/components/control-documental/etiquetas';
import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { Link, router, useForm } from '@inertiajs/react';
import { BookCheck, CalendarClock, FilePlus2, Files, GitPullRequestDraft, Library, Network, Pencil, Plus, Scale, Trash2 } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

interface Proceso {
    id: number;
    sigla: string;
    nombre: string;
    tipo: 'estrategico' | 'misional' | 'apoyo' | 'evaluacion';
    documents_count: number;
}

interface Fila {
    id: number;
    codigo: string;
    tipo: string;
    nivel: number;
    titulo: string;
    sistemas: Sistema[];
    condicional: boolean;
    estado: EstadoDocumento;
    version_vigente: number | null;
    proxima_revision: string | null;
    revision_vencida: boolean;
    dias_para_revision: number | null;
    codigo_historico: string | null;
    proceso: { id: number; sigla: string; nombre: string } | null;
    /** Versión en elaboración de un documento que ya está vigente. */
    en_curso: EstadoVersion | null;
}

interface Tipo {
    clave: string;
    nombre: string;
    nivel: number;
    frecuencia: number;
}

interface ModuloCatalogo {
    modulo: string;
    nombre: string;
    total: number;
    condicionales: number;
    ya: number;
}

interface Props {
    needsClient: boolean;
    documentos: Fila[];
    procesos: Proceso[];
    stats: { total: number; vigentes: number; en_flujo: number; revision_vencida: number; obsoletos: number };
    catalogo: ModuloCatalogo[];
    catalogos: { tipos: Tipo[]; niveles: Record<number, string> };
}

const TIPO_PROCESO: Record<Proceso['tipo'], string> = {
    estrategico: 'Estratégico',
    misional: 'Misional',
    apoyo: 'Apoyo',
    evaluacion: 'Evaluación',
};

export default function ControlDocumentalIndex({ needsClient, documentos, procesos, stats, catalogo, catalogos }: Props) {
    const { can } = usePermissions();
    const canManage = can('documents.manage');

    const [buscar, setBuscar] = useState('');
    const [sistema, setSistema] = useState('');
    const [tipo, setTipo] = useState('');
    const [proceso, setProceso] = useState('');
    const [estado, setEstado] = useState('');
    const [nuevo, setNuevo] = useState(false);
    const [verCatalogo, setVerCatalogo] = useState(false);
    const [verProcesos, setVerProcesos] = useState(false);

    const nombreTipo = useMemo(() => Object.fromEntries(catalogos.tipos.map((t) => [t.clave, t.nombre])), [catalogos.tipos]);

    const filtrados = useMemo(() => {
        const q = buscar.trim().toLowerCase();
        return documentos.filter(
            (d) =>
                (!q || d.titulo.toLowerCase().includes(q) || d.codigo.toLowerCase().includes(q) || d.codigo_historico?.toLowerCase().includes(q)) &&
                (!sistema || d.sistemas.includes(sistema as Sistema)) &&
                (!tipo || d.tipo === tipo) &&
                (!proceso || String(d.proceso?.id) === proceso) &&
                (!estado || (estado === 'revision_vencida' ? d.revision_vencida : d.estado === estado)),
        );
    }, [documentos, buscar, sistema, tipo, proceso, estado]);

    const filtros = needsClient ? undefined : (
        <div className="flex flex-wrap items-center gap-2">
            <Input value={buscar} onChange={(e) => setBuscar(e.target.value)} placeholder="Buscar por título o código…" className="w-64" />
            <select value={sistema} onChange={(e) => setSistema(e.target.value)} className={selectCls} aria-label="Norma">
                <option value="">Todas las normas</option>
                {SISTEMAS.map((s) => (
                    <option key={s} value={s}>
                        {NOMBRE_SISTEMA[s]}
                    </option>
                ))}
            </select>
            <select value={tipo} onChange={(e) => setTipo(e.target.value)} className={selectCls} aria-label="Tipo">
                <option value="">Todos los tipos</option>
                {catalogos.tipos.map((t) => (
                    <option key={t.clave} value={t.clave}>
                        {t.clave} · {t.nombre}
                    </option>
                ))}
            </select>
            <select value={proceso} onChange={(e) => setProceso(e.target.value)} className={selectCls} aria-label="Proceso">
                <option value="">Todos los procesos</option>
                {procesos.map((p) => (
                    <option key={p.id} value={p.id}>
                        {p.sigla} · {p.nombre}
                    </option>
                ))}
            </select>
            <select value={estado} onChange={(e) => setEstado(e.target.value)} className={selectCls} aria-label="Estado">
                <option value="">Todos los estados</option>
                {(['borrador', 'en_revision', 'en_aprobacion', 'vigente', 'obsoleto'] as EstadoDocumento[]).map((e) => (
                    <option key={e} value={e}>
                        {etiquetaEstado(e)}
                    </option>
                ))}
                <option value="revision_vencida">Revisión vencida</option>
            </select>
            <span className="text-muted-foreground ml-auto text-xs tabular-nums">
                {filtrados.length} de {documentos.length}
            </span>
        </div>
    );

    return (
        <ModuloPage
            titulo="Control documental"
            descripcion="Listado maestro de documentos y registros del SIG"
            needsClient={needsClient}
            filtros={filtros}
            accion={
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline" className="gap-2">
                        <Link href="/control-documental/requisitos">
                            <Scale className="size-4" /> Requisitos por norma
                        </Link>
                    </Button>
                    {canManage && (
                        <>
                            <Button variant="outline" className="gap-2" onClick={() => setVerProcesos(true)}>
                                <Network className="size-4" /> Procesos
                            </Button>
                            <Button variant="outline" className="gap-2" onClick={() => setVerCatalogo(true)}>
                                <Library className="size-4" /> Desde el catálogo
                            </Button>
                            <Button className="gap-2" onClick={() => setNuevo(true)}>
                                <Plus className="size-4" /> Nuevo documento
                            </Button>
                        </>
                    )}
                </div>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Documentos en el listado" value={stats.total} icon={Files} />
                <StatCard label="Vigentes" value={stats.vigentes} icon={BookCheck} />
                <StatCard label="En elaboración o aprobación" value={stats.en_flujo} icon={GitPullRequestDraft} />
                <StatCard label="Revisión vencida" value={stats.revision_vencida} icon={CalendarClock} alerta={stats.revision_vencida > 0} />
            </div>

            <Card>
                <CardContent className="p-0">
                    {documentos.length === 0 ? (
                        <div className="flex flex-col items-center gap-3 p-10 text-center">
                            <FilePlus2 className="text-muted-foreground size-8" />
                            <p className="font-medium">El listado maestro está vacío</p>
                            <p className="text-muted-foreground max-w-md text-sm">
                                Arranca desde el catálogo de referencia del SIG (227 documentos en 20 módulos) o crea los documentos uno por uno.
                            </p>
                            {canManage && (
                                <Button variant="outline" className="gap-2" onClick={() => setVerCatalogo(true)}>
                                    <Library className="size-4" /> Cargar desde el catálogo
                                </Button>
                            )}
                        </div>
                    ) : filtrados.length === 0 ? (
                        <p className="text-muted-foreground p-8 text-center text-sm">Ningún documento coincide con los filtros.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2.5 font-semibold">Código</th>
                                        <th className="px-4 py-2.5 font-semibold">Documento</th>
                                        <th className="px-4 py-2.5 font-semibold">Proceso</th>
                                        <th className="px-4 py-2.5 font-semibold">Normas</th>
                                        <th className="px-4 py-2.5 font-semibold">Versión</th>
                                        <th className="px-4 py-2.5 font-semibold">Estado</th>
                                        <th className="px-4 py-2.5 font-semibold">Próxima revisión</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filtrados.map((d) => (
                                        <tr key={d.id} className="hover:bg-muted/30 border-t">
                                            <td className="px-4 py-2.5 font-medium whitespace-nowrap tabular-nums">
                                                <Link href={`/control-documental/${d.id}`} className="hover:underline">
                                                    {d.codigo}
                                                </Link>
                                            </td>
                                            <td className="max-w-md px-4 py-2.5">
                                                <Link href={`/control-documental/${d.id}`} className="hover:underline">
                                                    {d.titulo}
                                                </Link>
                                                <div className="text-muted-foreground flex flex-wrap gap-x-2 text-xs">
                                                    <span>{nombreTipo[d.tipo] ?? d.tipo}</span>
                                                    {d.codigo_historico && <span>antes {d.codigo_historico}</span>}
                                                    {d.condicional && <span className="text-amber-600 dark:text-amber-500">si aplica</span>}
                                                </div>
                                            </td>
                                            <td className="px-4 py-2.5" title={d.proceso?.nombre}>
                                                {d.proceso?.sigla ?? '—'}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <SistemaChips sistemas={d.sistemas} />
                                            </td>
                                            <td className="px-4 py-2.5 tabular-nums">{d.version_vigente ? `v${d.version_vigente}` : '—'}</td>
                                            <td className="px-4 py-2.5">
                                                <EstadoBadge estado={d.estado} />
                                                {d.en_curso && (
                                                    <span className="text-muted-foreground block text-xs">
                                                        actualizando · {etiquetaEstado(d.en_curso).toLowerCase()}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5 whitespace-nowrap tabular-nums">
                                                {fechaCorta(d.proxima_revision)}
                                                {d.revision_vencida ? (
                                                    <span className="text-destructive block text-xs">
                                                        vencida hace {Math.abs(d.dias_para_revision ?? 0)} d
                                                    </span>
                                                ) : d.dias_para_revision !== null && d.dias_para_revision <= 30 ? (
                                                    <span className="block text-xs text-amber-600 dark:text-amber-500">
                                                        quedan {d.dias_para_revision} d
                                                    </span>
                                                ) : null}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </CardContent>
            </Card>

            {!needsClient && (
                <>
                    <NuevoDocumento open={nuevo} onClose={() => setNuevo(false)} procesos={procesos} tipos={catalogos.tipos} />
                    <DesdeCatalogo open={verCatalogo} onClose={() => setVerCatalogo(false)} catalogo={catalogo} />
                    <Procesos open={verProcesos} onClose={() => setVerProcesos(false)} procesos={procesos} />
                </>
            )}
        </ModuloPage>
    );
}

function NuevoDocumento({ open, onClose, procesos, tipos }: { open: boolean; onClose: () => void; procesos: Proceso[]; tipos: Tipo[] }) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        tipo: 'PRC',
        process_id: procesos.find((p) => p.sigla === 'SST')?.id ?? procesos[0]?.id ?? '',
        titulo: '',
        sistemas: ['sst'] as Sistema[],
        condicional: false as boolean,
        codigo_historico: '',
        confirmar_duplicado: false,
    });

    const errs = errors as Record<string, string | undefined>;
    const sigla = procesos.find((p) => p.id === Number(data.process_id))?.sigla ?? '???';

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/control-documental', {
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(v) => {
                if (!v) {
                    clearErrors();
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Nuevo documento</DialogTitle>
                    <DialogDescription>
                        El código lo asigna el sistema: <span className="font-mono">{`${data.tipo}-${sigla}-###`}</span>. Nace como borrador en la
                        versión 1.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="tipo">Tipo</Label>
                            <select id="tipo" value={data.tipo} onChange={(e) => setData('tipo', e.target.value)} className={selectCls}>
                                {tipos.map((t) => (
                                    <option key={t.clave} value={t.clave}>
                                        {t.clave} · {t.nombre}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="process_id">Proceso dueño</Label>
                            <select
                                id="process_id"
                                value={data.process_id}
                                onChange={(e) => setData('process_id', Number(e.target.value))}
                                className={selectCls}
                            >
                                {procesos.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.sigla} · {p.nombre}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="titulo">Título</Label>
                        <Input
                            id="titulo"
                            value={data.titulo}
                            onChange={(e) => setData('titulo', e.target.value)}
                            placeholder="Sin código: el código va aparte."
                        />
                        <InputError message={errors.titulo} />
                    </div>
                    <div className="grid gap-2">
                        <Label>Normas que evidencia</Label>
                        <SistemasPicker value={data.sistemas} onChange={(v) => setData('sistemas', v)} />
                        <InputError message={errors.sistemas} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="codigo_historico">Código anterior (opcional)</Label>
                            <Input
                                id="codigo_historico"
                                value={data.codigo_historico}
                                onChange={(e) => setData('codigo_historico', e.target.value)}
                                placeholder="FT-SST-034"
                            />
                        </div>
                        <label className="flex items-center gap-2 self-end pb-2 text-sm">
                            <Checkbox checked={data.condicional} onCheckedChange={(v) => setData('condicional', v === true)} />
                            Solo aplica según la actividad
                        </label>
                    </div>

                    {errs.duplicado && (
                        <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                            <p>{errs.duplicado}</p>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="mt-2"
                                disabled={processing}
                                onClick={() =>
                                    router.post(
                                        '/control-documental',
                                        { ...data, confirmar_duplicado: true },
                                        {
                                            onSuccess: () => {
                                                reset();
                                                onClose();
                                            },
                                        },
                                    )
                                }
                            >
                                Es otro documento, crearlo igual
                            </Button>
                        </div>
                    )}

                    <DialogFooter className="gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Crear borrador
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DesdeCatalogo({ open, onClose, catalogo }: { open: boolean; onClose: () => void; catalogo: ModuloCatalogo[] }) {
    const { data, setData, post, processing, errors } = useForm({
        modulos: catalogo.filter((m) => m.ya < m.total).map((m) => m.modulo),
        incluir_condicionales: false as boolean,
    });

    const nuevos = catalogo
        .filter((m) => data.modulos.includes(m.modulo))
        .reduce((n, m) => n + Math.max(0, (data.incluir_condicionales ? m.total : m.total - m.condicionales) - m.ya), 0);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/control-documental/catalogo', { preserveScroll: true, onSuccess: onClose });
    };

    const toggle = (m: string) => setData('modulos', data.modulos.includes(m) ? data.modulos.filter((x) => x !== m) : [...data.modulos, m]);

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Cargar desde el catálogo del SIG</DialogTitle>
                    <DialogDescription>
                        Agrega al listado maestro los documentos de referencia de cada módulo, con su código nuevo y el anterior como código
                        histórico. Los que la empresa ya tiene no se repiten; todos nacen en borrador.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="flex gap-2 text-xs">
                        <button
                            type="button"
                            className="text-primary hover:underline"
                            onClick={() =>
                                setData(
                                    'modulos',
                                    catalogo.map((m) => m.modulo),
                                )
                            }
                        >
                            Todos
                        </button>
                        <span className="text-muted-foreground">·</span>
                        <button type="button" className="text-primary hover:underline" onClick={() => setData('modulos', [])}>
                            Ninguno
                        </button>
                    </div>
                    <div className="grid gap-1 sm:grid-cols-2">
                        {catalogo.map((m) => (
                            <label key={m.modulo} className="hover:bg-muted/40 flex items-start gap-2 rounded-md p-2 text-sm">
                                <Checkbox checked={data.modulos.includes(m.modulo)} onCheckedChange={() => toggle(m.modulo)} className="mt-0.5" />
                                <span className="flex-1">
                                    <span className="text-muted-foreground font-mono text-xs">{m.modulo}</span> {m.nombre}
                                    <span className="text-muted-foreground block text-xs">
                                        {m.total} documentos{m.condicionales ? ` (${m.condicionales} si aplica)` : ''}
                                        {m.ya > 0 && ` · ${m.ya} ya en el listado`}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>
                    <InputError message={errors.modulos} />
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={data.incluir_condicionales} onCheckedChange={(v) => setData('incluir_condicionales', v === true)} />
                        Incluir los que solo aplican según la actividad (alturas, químicos, RESPEL, vigía…)
                    </label>
                    <DialogFooter className="gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing || nuevos === 0}>
                            Agregar {nuevos} documentos
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Procesos({ open, onClose, procesos }: { open: boolean; onClose: () => void; procesos: Proceso[] }) {
    const [editando, setEditando] = useState<Proceso | null>(null);
    const vacio = { sigla: '', nombre: '', tipo: 'apoyo' as Proceso['tipo'] };
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm(vacio);
    const errs = errors as Record<string, string | undefined>;

    function editar(p: Proceso) {
        clearErrors();
        setEditando(p);
        setData({ sigla: p.sigla, nombre: p.nombre, tipo: p.tipo });
    }

    function cancelar() {
        setEditando(null);
        clearErrors();
        reset();
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: cancelar };
        if (editando) put(`/control-documental/procesos/${editando.id}`, opts);
        else post('/control-documental/procesos', opts);
    };

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Mapa de procesos</DialogTitle>
                    <DialogDescription>
                        La sigla va en el código de cada documento, así que no cambia una vez que el proceso tiene documentos.
                    </DialogDescription>
                </DialogHeader>
                <table className="w-full text-sm">
                    <tbody>
                        {procesos.map((p) => (
                            <tr key={p.id} className="border-t">
                                <td className="py-2 pr-3 font-mono font-medium">{p.sigla}</td>
                                <td className="py-2 pr-3">{p.nombre}</td>
                                <td className="text-muted-foreground py-2 pr-3 text-xs">{TIPO_PROCESO[p.tipo]}</td>
                                <td className="text-muted-foreground py-2 pr-3 text-xs tabular-nums">{p.documents_count} docs</td>
                                <td className="py-2 text-right whitespace-nowrap">
                                    <Button size="icon" variant="ghost" onClick={() => editar(p)} aria-label="Editar">
                                        <Pencil className="size-4" />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        disabled={p.documents_count > 0}
                                        aria-label="Eliminar"
                                        onClick={() =>
                                            confirm(`¿Eliminar el proceso ${p.sigla}?`) &&
                                            router.delete(`/control-documental/procesos/${p.id}`, { preserveScroll: true })
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <InputError message={errs.proceso} />

                <form onSubmit={submit} className="bg-muted/40 grid gap-3 rounded-md p-3 sm:grid-cols-[5rem_1fr_9rem_auto] sm:items-end">
                    <div className="grid gap-1">
                        <Label htmlFor="sigla" className="text-xs">
                            Sigla
                        </Label>
                        <Input
                            id="sigla"
                            value={data.sigla}
                            maxLength={3}
                            onChange={(e) => setData('sigla', e.target.value.toUpperCase())}
                            className="font-mono"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="nombre" className="text-xs">
                            Proceso
                        </Label>
                        <Input id="nombre" value={data.nombre} onChange={(e) => setData('nombre', e.target.value)} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="tipo-proceso" className="text-xs">
                            Tipo
                        </Label>
                        <select
                            id="tipo-proceso"
                            value={data.tipo}
                            onChange={(e) => setData('tipo', e.target.value as Proceso['tipo'])}
                            className={selectCls}
                        >
                            {(Object.keys(TIPO_PROCESO) as Proceso['tipo'][]).map((t) => (
                                <option key={t} value={t}>
                                    {TIPO_PROCESO[t]}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex gap-1">
                        <Button type="submit" disabled={processing}>
                            {editando ? 'Guardar' : 'Agregar'}
                        </Button>
                        {editando && (
                            <Button type="button" variant="ghost" onClick={cancelar}>
                                Cancelar
                            </Button>
                        )}
                    </div>
                    <div className="sm:col-span-4">
                        <InputError message={errors.sigla ?? errors.nombre} />
                    </div>
                </form>
                <div className="flex justify-end">
                    <Badge variant="outline" className="font-normal">
                        {procesos.length} procesos
                    </Badge>
                </div>
            </DialogContent>
        </Dialog>
    );
}
