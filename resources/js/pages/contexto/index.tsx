import { SistemaChips, SistemasPicker, selectCls, textareaCls, type Sistema } from '@/components/control-documental/etiquetas';
import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePartes } from '@/hooks/use-partes';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, Circle, CloudSun, FileOutput, Layers, Library, Pencil, Plus, ShieldAlert, Trash2, Users, Workflow } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type Origen = 'interno' | 'externo';
type Dofa = 'fortaleza' | 'debilidad' | 'oportunidad' | 'amenaza';
type Nivel = 'alta' | 'media' | 'baja';
type NivelInteres = 'alto' | 'medio' | 'bajo';

interface Cuestion {
    id: number;
    origen: Origen;
    dofa: Dofa;
    pestel: string | null;
    descripcion: string;
    impacto: NivelInteres;
    cambio_climatico: boolean;
    tratamiento: string | null;
    sistemas: Sistema[];
}

interface Parte {
    id: number;
    nombre: string;
    tipo: 'interna' | 'externa';
    categoria: string;
    necesidades: string;
    expectativas: string | null;
    es_requisito: boolean;
    influencia: Nivel;
    interes: NivelInteres;
    como_se_atiende: string | null;
    cambio_climatico: boolean;
    sistemas: Sistema[];
    estrategia: string;
}

interface Proceso {
    id: number;
    sigla: string;
    nombre: string;
    tipo: 'estrategico' | 'misional' | 'apoyo' | 'evaluacion';
    objetivo: string | null;
    lider: string | null;
    caracterizacion: Record<string, string> | null;
    caracterizado: boolean;
}

interface Perfil {
    alcance: string | null;
    sedes: string | null;
    productos_servicios: string | null;
    exclusiones: { requisito: string; justificacion: string }[] | null;
    cambio_climatico: boolean | null;
    cambio_climatico_justificacion: string | null;
    revisado_at: string | null;
    revisado_por: string | null;
    revision_vencida: boolean;
}

interface Documento {
    disponible: boolean;
    titulo: string | null;
    id: number | null;
    codigo: string | null;
    estado: string | null;
}

interface Props {
    needsClient: boolean;
    perfil: Perfil | null;
    cuestiones: Cuestion[];
    partes: Parte[];
    procesos: Proceso[];
    completitud: { dofa: boolean; clima: boolean; partes: boolean; alcance: boolean; procesos: boolean };
    documentos: Record<'dofa' | 'partes' | 'alcance', Documento>;
    categorias: Record<string, string>;
    camposCaracterizacion: Record<string, string>;
}

type Pestana = 'alcance' | 'dofa' | 'partes' | 'procesos';

const PESTANAS: [Pestana, string][] = [
    ['alcance', 'Alcance y cambio climático'],
    ['dofa', 'DOFA / PESTEL'],
    ['partes', 'Partes interesadas'],
    ['procesos', 'Procesos'],
];

const CUADRANTES: { clave: Dofa; titulo: string; origen: Origen; cls: string }[] = [
    { clave: 'fortaleza', titulo: 'Fortalezas', origen: 'interno', cls: 'border-t-emerald-600' },
    { clave: 'debilidad', titulo: 'Debilidades', origen: 'interno', cls: 'border-t-amber-500' },
    { clave: 'oportunidad', titulo: 'Oportunidades', origen: 'externo', cls: 'border-t-sky-600' },
    { clave: 'amenaza', titulo: 'Amenazas', origen: 'externo', cls: 'border-t-red-600' },
];

const PESTEL: Record<string, string> = {
    politico: 'Político',
    economico: 'Económico',
    social: 'Social',
    tecnologico: 'Tecnológico',
    ambiental: 'Ambiental',
    legal: 'Legal',
};

const TIPOS_PROCESO: Record<Proceso['tipo'], string> = {
    estrategico: 'Estratégicos',
    misional: 'Misionales',
    apoyo: 'De apoyo',
    evaluacion: 'De evaluación',
};

const DOCUMENTOS: { clave: 'dofa' | 'partes' | 'alcance'; ref: string }[] = [
    { clave: 'dofa', ref: '4.1' },
    { clave: 'partes', ref: '4.2' },
    { clave: 'alcance', ref: '4.3 · 4.4' },
];

const impactoCls: Record<NivelInteres, string> = {
    alto: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    medio: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    bajo: 'bg-muted text-muted-foreground',
};

export default function ContextoIndex({
    needsClient,
    perfil,
    cuestiones,
    partes,
    procesos,
    completitud,
    documentos,
    categorias,
    camposCaracterizacion,
}: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const errores = usePage<SharedData>().props.errors as Record<string, string | undefined>;
    const { tiene, primera } = usePartes('contexto');
    const [pestana, setPestana] = useState<Pestana>(() => primera(PESTANAS.map(([p]) => p)));
    const [cuestion, setCuestion] = useState<Cuestion | { nuevo: Dofa } | null>(null);
    const [parte, setParte] = useState<Parte | 'nuevo' | null>(null);
    const [proceso, setProceso] = useState<Proceso | null>(null);

    const pendientes: [boolean, string][] = [
        [completitud.alcance, 'Alcance del sistema definido'],
        [completitud.clima, 'Decisión justificada sobre el cambio climático'],
        [completitud.dofa, 'DOFA con las cuatro caras'],
        [completitud.partes, 'Partes interesadas identificadas'],
        [completitud.procesos, 'Todos los procesos caracterizados'],
    ];
    const hechos = pendientes.filter(([ok]) => ok).length;
    const caracterizados = procesos.filter((p) => p.caracterizado).length;

    return (
        <ModuloPage
            titulo="Contexto de la organización"
            descripcion="Alcance, cuestiones internas y externas, partes interesadas y procesos (capítulo 4 de ISO 45001, 9001 y 14001)"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button variant="outline" className="gap-2" onClick={() => router.post('/contexto/revisado', {}, { preserveScroll: true })}>
                        <CheckCircle2 className="size-4" /> Marcar contexto revisado hoy
                    </Button>
                ) : undefined
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Cuestiones DOFA" value={cuestiones.length} icon={Layers} />
                <StatCard label="Partes interesadas" value={partes.length} icon={Users} />
                <StatCard label="Procesos caracterizados" value={`${caracterizados} / ${procesos.length}`} icon={Workflow} />
                <StatCard
                    label="Última revisión"
                    value={perfil?.revisado_at ?? 'Nunca'}
                    icon={ShieldAlert}
                    alerta={perfil ? perfil.revision_vencida : true}
                />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">
                            Qué falta · {hechos} de {pendientes.length}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-1.5 text-sm">
                        {pendientes.map(([ok, texto]) => (
                            <div key={texto} className={cn('flex items-center gap-2', ok ? 'text-muted-foreground' : '')}>
                                {ok ? <CheckCircle2 className="size-4 text-emerald-600" /> : <Circle className="size-4 text-amber-500" />}
                                {texto}
                            </div>
                        ))}
                        {perfil?.revision_vencida && perfil.revisado_at && (
                            <p className="text-destructive pt-1 text-xs">El contexto no se ha revisado en el último año.</p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Documentos que evidencia</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        {DOCUMENTOS.map(({ clave, ref }) => {
                            const d = documentos[clave];
                            if (!d) return null;
                            return (
                                <div key={clave} className="flex items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="truncate">{d.titulo ?? 'Sin catálogo del SIG'}</div>
                                        <div className="text-muted-foreground text-xs">
                                            ISO {ref} ·{' '}
                                            {d.codigo ? (
                                                <Link href={`/control-documental/${d.id}`} className="underline underline-offset-2">
                                                    {d.codigo} · {d.estado?.replace('_', ' ')}
                                                </Link>
                                            ) : (
                                                'aún no está en el control documental'
                                            )}
                                        </div>
                                    </div>
                                    {canManage && d.disponible && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="shrink-0 gap-1.5"
                                            onClick={() => router.post('/contexto/enviar', { documento: clave }, { preserveScroll: true })}
                                        >
                                            <FileOutput className="size-3.5" /> Enviar como borrador
                                        </Button>
                                    )}
                                </div>
                            );
                        })}
                        {(errores.documentos || errores.estado) && <p className="text-destructive text-xs">{errores.documentos ?? errores.estado}</p>}
                        <p className="text-muted-foreground pt-1 text-xs">
                            Aquí se trabaja; en el control documental se revisa, se aprueba y queda la versión que ve el auditor.
                        </p>
                    </CardContent>
                </Card>
            </div>

            <div className="flex gap-1 overflow-x-auto border-b">
                {PESTANAS.filter(([p]) => tiene(p)).map(([p, label]) => (
                    <button
                        key={p}
                        type="button"
                        onClick={() => setPestana(p)}
                        className={cn(
                            '-mb-px border-b-2 px-4 py-2 text-sm whitespace-nowrap',
                            pestana === p ? 'border-primary font-medium' : 'text-muted-foreground border-transparent',
                        )}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {pestana === 'alcance' && <AlcanceForm perfil={perfil} canManage={canManage} />}

            {pestana === 'dofa' && (
                <div className="grid gap-4 md:grid-cols-2">
                    {CUADRANTES.map((q) => {
                        const lista = cuestiones.filter((c) => c.dofa === q.clave);
                        return (
                            <Card key={q.clave} className={cn('border-t-4', q.cls)}>
                                <CardHeader className="flex flex-row items-center justify-between pb-2">
                                    <CardTitle className="text-base">
                                        {q.titulo}
                                        <span className="text-muted-foreground ml-2 text-xs font-normal">
                                            {q.origen === 'interno' ? 'internas' : 'externas'}
                                        </span>
                                    </CardTitle>
                                    {canManage && (
                                        <Button size="sm" variant="ghost" className="gap-1" onClick={() => setCuestion({ nuevo: q.clave })}>
                                            <Plus className="size-4" /> Agregar
                                        </Button>
                                    )}
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {lista.length === 0 && <p className="text-muted-foreground text-sm">Sin cuestiones.</p>}
                                    {lista.map((c) => (
                                        <div key={c.id} className="rounded-md border p-2.5 text-sm">
                                            <div className="flex items-start justify-between gap-2">
                                                <p>{c.descripcion}</p>
                                                {canManage && (
                                                    <div className="flex shrink-0 gap-0.5">
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            className="size-7"
                                                            onClick={() => setCuestion(c)}
                                                            aria-label="Editar"
                                                        >
                                                            <Pencil className="size-3.5" />
                                                        </Button>
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            className="size-7"
                                                            aria-label="Eliminar"
                                                            onClick={() =>
                                                                confirm('¿Eliminar esta cuestión?') &&
                                                                router.delete(`/contexto/cuestiones/${c.id}`, { preserveScroll: true })
                                                            }
                                                        >
                                                            <Trash2 className="size-3.5" />
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>
                                            <div className="mt-1.5 flex flex-wrap items-center gap-1">
                                                <span className={cn('rounded px-1.5 py-0.5 text-xs capitalize', impactoCls[c.impacto])}>
                                                    Impacto {c.impacto}
                                                </span>
                                                {c.pestel && (
                                                    <Badge variant="outline" className="font-normal">
                                                        {PESTEL[c.pestel]}
                                                    </Badge>
                                                )}
                                                {c.cambio_climatico && (
                                                    <Badge variant="outline" className="gap-1 font-normal">
                                                        <CloudSun className="size-3" /> Cambio climático
                                                    </Badge>
                                                )}
                                                <SistemaChips sistemas={c.sistemas} />
                                            </div>
                                            {c.tratamiento && <p className="text-muted-foreground mt-1.5 text-xs">Tratamiento: {c.tratamiento}</p>}
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            )}

            {pestana === 'partes' && (
                <Card>
                    <CardContent className="p-0">
                        <div className="flex flex-wrap items-center justify-end gap-2 border-b p-3">
                            {errores.partes && <p className="text-destructive mr-auto text-sm">{errores.partes}</p>}
                            {canManage && partes.length === 0 && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="gap-2"
                                    onClick={() => router.post('/contexto/partes/base', {}, { preserveScroll: true })}
                                >
                                    <Library className="size-4" /> Cargar partes típicas
                                </Button>
                            )}
                            {canManage && (
                                <Button size="sm" className="gap-2" onClick={() => setParte('nuevo')}>
                                    <Plus className="size-4" /> Agregar parte interesada
                                </Button>
                            )}
                        </div>
                        {partes.length === 0 ? (
                            <p className="text-muted-foreground p-8 text-center text-sm">
                                Sin partes interesadas. Carga las típicas y ajústalas, o agrégalas una a una.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                        <tr>
                                            <th className="px-4 py-2.5 font-semibold">Parte interesada</th>
                                            <th className="px-4 py-2.5 font-semibold">Necesidades y expectativas</th>
                                            <th className="px-4 py-2.5 font-semibold">Relación</th>
                                            <th className="px-4 py-2.5 font-semibold">Cómo se atiende</th>
                                            {canManage && <th className="px-4 py-2.5" />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {partes.map((p) => (
                                            <tr key={p.id} className="border-t align-top">
                                                <td className="px-4 py-2.5">
                                                    <div className="font-medium">{p.nombre}</div>
                                                    <div className="text-muted-foreground text-xs">
                                                        {p.tipo === 'interna' ? 'Interna' : 'Externa'} · {categorias[p.categoria] ?? p.categoria}
                                                    </div>
                                                    <div className="mt-1 flex flex-wrap gap-1">
                                                        {p.es_requisito && (
                                                            <Badge variant="outline" className="border-primary text-primary font-normal">
                                                                Es requisito
                                                            </Badge>
                                                        )}
                                                        {p.cambio_climatico && (
                                                            <Badge variant="outline" className="gap-1 font-normal">
                                                                <CloudSun className="size-3" /> Clima
                                                            </Badge>
                                                        )}
                                                        <SistemaChips sistemas={p.sistemas} />
                                                    </div>
                                                </td>
                                                <td className="max-w-sm px-4 py-2.5">
                                                    <div>{p.necesidades}</div>
                                                    {p.expectativas && (
                                                        <div className="text-muted-foreground mt-1 text-xs">Espera: {p.expectativas}</div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5 whitespace-nowrap">
                                                    <div className="font-medium">{p.estrategia}</div>
                                                    <div className="text-muted-foreground text-xs">
                                                        Influencia {p.influencia} · interés {p.interes}
                                                    </div>
                                                </td>
                                                <td className="max-w-xs px-4 py-2.5 text-xs">{p.como_se_atiende ?? '—'}</td>
                                                {canManage && (
                                                    <td className="px-4 py-2.5">
                                                        <div className="flex justify-end gap-1">
                                                            <Button size="icon" variant="ghost" onClick={() => setParte(p)} aria-label="Editar">
                                                                <Pencil className="size-4" />
                                                            </Button>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                aria-label="Eliminar"
                                                                onClick={() =>
                                                                    confirm('¿Eliminar esta parte interesada?') &&
                                                                    router.delete(`/contexto/partes/${p.id}`, { preserveScroll: true })
                                                                }
                                                            >
                                                                <Trash2 className="size-4" />
                                                            </Button>
                                                        </div>
                                                    </td>
                                                )}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            )}

            {pestana === 'procesos' && (
                <div className="space-y-4">
                    <p className="text-muted-foreground text-sm">
                        Las siglas, nombres y tipos del mapa se administran en el{' '}
                        <Link href="/control-documental" className="underline underline-offset-2">
                            control documental
                        </Link>
                        , porque de ellos salen los códigos de los documentos. Aquí se caracteriza cada proceso.
                    </p>
                    {procesos.length === 0 ? (
                        <Card>
                            <CardContent className="text-muted-foreground p-8 text-center text-sm">
                                La empresa todavía no tiene mapa de procesos. Créalo en el control documental.
                            </CardContent>
                        </Card>
                    ) : (
                        (Object.keys(TIPOS_PROCESO) as Proceso['tipo'][])
                            .filter((t) => procesos.some((p) => p.tipo === t))
                            .map((t) => (
                                <div key={t} className="space-y-2">
                                    <h3 className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">
                                        Procesos {TIPOS_PROCESO[t].toLowerCase()}
                                    </h3>
                                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                        {procesos
                                            .filter((p) => p.tipo === t)
                                            .map((p) => (
                                                <Card key={p.id}>
                                                    <CardContent className="space-y-1.5 p-4 text-sm">
                                                        <div className="flex items-start justify-between gap-2">
                                                            <div>
                                                                <span className="text-muted-foreground font-mono text-xs">{p.sigla}</span>
                                                                <div className="font-medium">{p.nombre}</div>
                                                            </div>
                                                            {canManage && (
                                                                <Button
                                                                    size="icon"
                                                                    variant="ghost"
                                                                    className="size-7"
                                                                    onClick={() => setProceso(p)}
                                                                    aria-label="Caracterizar"
                                                                >
                                                                    <Pencil className="size-3.5" />
                                                                </Button>
                                                            )}
                                                        </div>
                                                        <div className="text-muted-foreground text-xs">Líder: {p.lider ?? 'sin definir'}</div>
                                                        {p.objetivo && <p className="line-clamp-2 text-xs">{p.objetivo}</p>}
                                                        <div
                                                            className={cn(
                                                                'flex items-center gap-1.5 text-xs',
                                                                p.caracterizado ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-600',
                                                            )}
                                                        >
                                                            {p.caracterizado ? (
                                                                <CheckCircle2 className="size-3.5" />
                                                            ) : (
                                                                <Circle className="size-3.5" />
                                                            )}
                                                            {p.caracterizado
                                                                ? 'Caracterizado'
                                                                : 'Falta objetivo, líder, entradas, actividades o salidas'}
                                                        </div>
                                                    </CardContent>
                                                </Card>
                                            ))}
                                    </div>
                                </div>
                            ))
                    )}
                </div>
            )}

            {cuestion && (
                <CuestionDialog
                    cuestion={'nuevo' in cuestion ? null : cuestion}
                    dofaInicial={'nuevo' in cuestion ? cuestion.nuevo : cuestion.dofa}
                    onClose={() => setCuestion(null)}
                />
            )}
            {parte && <ParteDialog parte={parte === 'nuevo' ? null : parte} categorias={categorias} onClose={() => setParte(null)} />}
            {proceso && <ProcesoDialog proceso={proceso} campos={camposCaracterizacion} onClose={() => setProceso(null)} />}
        </ModuloPage>
    );
}

type PerfilForm = {
    alcance: string;
    productos_servicios: string;
    sedes: string;
    exclusiones: { requisito: string; justificacion: string }[];
    cambio_climatico: boolean | null;
    cambio_climatico_justificacion: string;
};

function AlcanceForm({ perfil, canManage }: { perfil: Perfil | null; canManage: boolean }) {
    const { data, setData, put, processing, errors, isDirty } = useForm<PerfilForm>({
        alcance: perfil?.alcance ?? '',
        productos_servicios: perfil?.productos_servicios ?? '',
        sedes: perfil?.sedes ?? '',
        exclusiones: perfil?.exclusiones ?? [],
        cambio_climatico: perfil?.cambio_climatico ?? null,
        cambio_climatico_justificacion: perfil?.cambio_climatico_justificacion ?? '',
    });
    const err = errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/contexto/perfil', { preserveScroll: true });
    };

    const setExclusion = (i: number, campo: 'requisito' | 'justificacion', valor: string) =>
        setData(
            'exclusiones',
            data.exclusiones.map((x, j) => (j === i ? { ...x, [campo]: valor } : x)),
        );

    return (
        <form onSubmit={submit} className="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader className="pb-2">
                    <CardTitle className="text-base">Alcance del sistema integrado</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    <div className="grid gap-2">
                        <Label htmlFor="alcance">Alcance</Label>
                        <textarea
                            id="alcance"
                            rows={4}
                            disabled={!canManage}
                            value={data.alcance}
                            onChange={(e) => setData('alcance', e.target.value)}
                            placeholder="Ej.: Diseño, instalación y mantenimiento de redes eléctricas de media y baja tensión en Colombia."
                            className={textareaCls}
                        />
                        <p className="text-muted-foreground text-xs">
                            ISO 4.3. El SG-SST cubre siempre a todos los trabajadores y contratistas (Dec. 1072, art. 2.2.4.6.1).
                        </p>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="productos">Productos y servicios</Label>
                        <textarea
                            id="productos"
                            rows={2}
                            disabled={!canManage}
                            value={data.productos_servicios}
                            onChange={(e) => setData('productos_servicios', e.target.value)}
                            className={textareaCls}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="sedes">Sedes y centros de trabajo incluidos</Label>
                        <textarea
                            id="sedes"
                            rows={2}
                            disabled={!canManage}
                            value={data.sedes}
                            onChange={(e) => setData('sedes', e.target.value)}
                            className={textareaCls}
                        />
                    </div>
                    <div className="space-y-2">
                        <div className="flex items-center justify-between">
                            <Label>Requisitos que no aplican</Label>
                            {canManage && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    className="gap-1"
                                    onClick={() => setData('exclusiones', [...data.exclusiones, { requisito: '', justificacion: '' }])}
                                >
                                    <Plus className="size-4" /> Agregar
                                </Button>
                            )}
                        </div>
                        {data.exclusiones.length === 0 && <p className="text-muted-foreground text-xs">Todos los requisitos aplican.</p>}
                        {data.exclusiones.map((x, i) => (
                            <div key={i} className="bg-muted/40 space-y-2 rounded-md p-2.5">
                                <div className="flex gap-2">
                                    <Input
                                        value={x.requisito}
                                        disabled={!canManage}
                                        placeholder="ISO 9001 8.3 Diseño y desarrollo"
                                        onChange={(e) => setExclusion(i, 'requisito', e.target.value)}
                                    />
                                    {canManage && (
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            aria-label="Quitar"
                                            onClick={() =>
                                                setData(
                                                    'exclusiones',
                                                    data.exclusiones.filter((_, j) => j !== i),
                                                )
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    )}
                                </div>
                                <textarea
                                    rows={2}
                                    disabled={!canManage}
                                    value={x.justificacion}
                                    placeholder="Por qué no aplica"
                                    onChange={(e) => setExclusion(i, 'justificacion', e.target.value)}
                                    className={textareaCls}
                                    aria-label="Justificación"
                                />
                                <InputError message={err[`exclusiones.${i}.justificacion`] ?? err[`exclusiones.${i}.requisito`]} />
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="pb-2">
                    <CardTitle className="flex items-center gap-2 text-base">
                        <CloudSun className="size-4" /> Cambio climático
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    <p className="text-muted-foreground text-sm">
                        La enmienda de 2024 a ISO 45001 y las ediciones 2026 de ISO 9001 e ISO 14001 piden determinar si el cambio climático es una
                        cuestión pertinente (4.1) y si las partes interesadas tienen requisitos sobre él (4.2). Decir que no también vale, pero
                        justificado.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {(
                            [
                                [true, 'Sí es pertinente'],
                                [false, 'No es pertinente'],
                                [null, 'Sin decidir'],
                            ] as [boolean | null, string][]
                        ).map(([valor, label]) => (
                            <Button
                                key={label}
                                type="button"
                                size="sm"
                                disabled={!canManage}
                                variant={data.cambio_climatico === valor ? 'default' : 'outline'}
                                onClick={() => setData('cambio_climatico', valor)}
                            >
                                {label}
                            </Button>
                        ))}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="clima-just">Justificación</Label>
                        <textarea
                            id="clima-just"
                            rows={4}
                            disabled={!canManage}
                            value={data.cambio_climatico_justificacion}
                            onChange={(e) => setData('cambio_climatico_justificacion', e.target.value)}
                            placeholder="Ej.: Las olas de calor aumentan el riesgo de estrés térmico del personal en campo; las lluvias intensas afectan las vías de la flota."
                            className={textareaCls}
                        />
                        <InputError message={err.cambio_climatico_justificacion} />
                    </div>
                    {perfil?.revisado_at && (
                        <p className="text-muted-foreground text-xs">
                            Contexto revisado el {perfil.revisado_at}
                            {perfil.revisado_por ? ` por ${perfil.revisado_por}` : ''}.
                        </p>
                    )}
                </CardContent>
            </Card>

            {canManage && (
                <div className="flex justify-end lg:col-span-2">
                    <Button type="submit" disabled={processing || !isDirty}>
                        Guardar alcance y cambio climático
                    </Button>
                </div>
            )}
        </form>
    );
}

type CuestionForm = {
    origen: Origen;
    dofa: Dofa;
    pestel: string;
    descripcion: string;
    impacto: NivelInteres;
    cambio_climatico: boolean;
    tratamiento: string;
    sistemas: Sistema[];
};

function CuestionDialog({ cuestion, dofaInicial, onClose }: { cuestion: Cuestion | null; dofaInicial: Dofa; onClose: () => void }) {
    const origenDe = (d: Dofa): Origen => (d === 'fortaleza' || d === 'debilidad' ? 'interno' : 'externo');
    const { data, setData, post, put, processing, errors } = useForm<CuestionForm>({
        origen: cuestion?.origen ?? origenDe(dofaInicial),
        dofa: cuestion?.dofa ?? dofaInicial,
        pestel: cuestion?.pestel ?? '',
        descripcion: cuestion?.descripcion ?? '',
        impacto: cuestion?.impacto ?? 'medio',
        cambio_climatico: cuestion?.cambio_climatico ?? false,
        tratamiento: cuestion?.tratamiento ?? '',
        sistemas: cuestion?.sistemas ?? ['iso45001', 'iso9001', 'iso14001'],
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (cuestion) put(`/contexto/cuestiones/${cuestion.id}`, opts);
        else post('/contexto/cuestiones', opts);
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{cuestion ? 'Editar cuestión' : 'Nueva cuestión'}</DialogTitle>
                    <DialogDescription>
                        Algo interno o externo que afecta la capacidad de la empresa de lograr los resultados del sistema.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="dofa">Tipo</Label>
                            <select
                                id="dofa"
                                value={data.dofa}
                                onChange={(e) => {
                                    const d = e.target.value as Dofa;
                                    setData((prev) => ({
                                        ...prev,
                                        dofa: d,
                                        origen: origenDe(d),
                                        pestel: origenDe(d) === 'interno' ? '' : prev.pestel,
                                    }));
                                }}
                                className={selectCls}
                            >
                                <option value="fortaleza">Fortaleza (interna)</option>
                                <option value="debilidad">Debilidad (interna)</option>
                                <option value="oportunidad">Oportunidad (externa)</option>
                                <option value="amenaza">Amenaza (externa)</option>
                            </select>
                            <InputError message={errors.dofa} />
                        </div>
                        {data.origen === 'externo' && (
                            <div className="grid gap-2">
                                <Label htmlFor="pestel">Factor PESTEL</Label>
                                <select id="pestel" value={data.pestel} onChange={(e) => setData('pestel', e.target.value)} className={selectCls}>
                                    <option value="">—</option>
                                    {Object.entries(PESTEL).map(([k, v]) => (
                                        <option key={k} value={k}>
                                            {v}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="descripcion">Cuestión</Label>
                        <textarea
                            id="descripcion"
                            rows={3}
                            value={data.descripcion}
                            onChange={(e) => setData('descripcion', e.target.value)}
                            className={textareaCls}
                        />
                        <InputError message={errors.descripcion} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="impacto">Impacto</Label>
                            <select
                                id="impacto"
                                value={data.impacto}
                                onChange={(e) => setData('impacto', e.target.value as NivelInteres)}
                                className={selectCls}
                            >
                                <option value="alto">Alto</option>
                                <option value="medio">Medio</option>
                                <option value="bajo">Bajo</option>
                            </select>
                        </div>
                        <label className="flex items-center gap-2 self-end pb-2 text-sm">
                            <Checkbox checked={data.cambio_climatico} onCheckedChange={(v) => setData('cambio_climatico', v === true)} />
                            Relacionada con el cambio climático
                        </label>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="tratamiento">Tratamiento (cómo se aprovecha o se enfrenta)</Label>
                        <textarea
                            id="tratamiento"
                            rows={2}
                            value={data.tratamiento}
                            onChange={(e) => setData('tratamiento', e.target.value)}
                            className={textareaCls}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label>Normas</Label>
                        <SistemasPicker value={data.sistemas} onChange={(v) => setData('sistemas', v)} />
                        <InputError message={errors.sistemas} />
                    </div>
                    <DialogFooter className="gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

type ParteForm = {
    nombre: string;
    tipo: 'interna' | 'externa';
    categoria: string;
    necesidades: string;
    expectativas: string;
    es_requisito: boolean;
    influencia: Nivel;
    interes: NivelInteres;
    como_se_atiende: string;
    cambio_climatico: boolean;
    sistemas: Sistema[];
};

function ParteDialog({ parte, categorias, onClose }: { parte: Parte | null; categorias: Record<string, string>; onClose: () => void }) {
    const { data, setData, post, put, processing, errors } = useForm<ParteForm>({
        nombre: parte?.nombre ?? '',
        tipo: parte?.tipo ?? 'externa',
        categoria: parte?.categoria ?? 'clientes',
        necesidades: parte?.necesidades ?? '',
        expectativas: parte?.expectativas ?? '',
        es_requisito: parte?.es_requisito ?? false,
        influencia: parte?.influencia ?? 'media',
        interes: parte?.interes ?? 'medio',
        como_se_atiende: parte?.como_se_atiende ?? '',
        cambio_climatico: parte?.cambio_climatico ?? false,
        sistemas: parte?.sistemas ?? ['iso45001', 'iso9001', 'iso14001'],
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (parte) put(`/contexto/partes/${parte.id}`, opts);
        else post('/contexto/partes', opts);
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{parte ? 'Editar parte interesada' : 'Nueva parte interesada'}</DialogTitle>
                    <DialogDescription>Quién es, qué necesita y espera de la empresa, y cómo se le atiende.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="nombre">Nombre</Label>
                            <Input id="nombre" value={data.nombre} onChange={(e) => setData('nombre', e.target.value)} />
                            <InputError message={errors.nombre} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="tipo">Tipo</Label>
                            <select
                                id="tipo"
                                value={data.tipo}
                                onChange={(e) => setData('tipo', e.target.value as ParteForm['tipo'])}
                                className={selectCls}
                            >
                                <option value="interna">Interna</option>
                                <option value="externa">Externa</option>
                            </select>
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="categoria">Categoría</Label>
                        <select id="categoria" value={data.categoria} onChange={(e) => setData('categoria', e.target.value)} className={selectCls}>
                            {Object.entries(categorias).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="necesidades">Necesidades</Label>
                            <textarea
                                id="necesidades"
                                rows={3}
                                value={data.necesidades}
                                onChange={(e) => setData('necesidades', e.target.value)}
                                className={textareaCls}
                            />
                            <InputError message={errors.necesidades} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="expectativas">Expectativas</Label>
                            <textarea
                                id="expectativas"
                                rows={3}
                                value={data.expectativas}
                                onChange={(e) => setData('expectativas', e.target.value)}
                                className={textareaCls}
                            />
                        </div>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="influencia">Influencia sobre la empresa</Label>
                            <select
                                id="influencia"
                                value={data.influencia}
                                onChange={(e) => setData('influencia', e.target.value as Nivel)}
                                className={selectCls}
                            >
                                <option value="alta">Alta</option>
                                <option value="media">Media</option>
                                <option value="baja">Baja</option>
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="interes">Interés en el sistema</Label>
                            <select
                                id="interes"
                                value={data.interes}
                                onChange={(e) => setData('interes', e.target.value as NivelInteres)}
                                className={selectCls}
                            >
                                <option value="alto">Alto</option>
                                <option value="medio">Medio</option>
                                <option value="bajo">Bajo</option>
                            </select>
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="como">Cómo se atiende</Label>
                        <Input
                            id="como"
                            value={data.como_se_atiende}
                            placeholder="Reuniones, informes, canal de PQRS…"
                            onChange={(e) => setData('como_se_atiende', e.target.value)}
                        />
                    </div>
                    <div className="flex flex-wrap gap-x-6 gap-y-2">
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.es_requisito} onCheckedChange={(v) => setData('es_requisito', v === true)} />
                            Sus necesidades se vuelven requisito u obligación de cumplimiento
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.cambio_climatico} onCheckedChange={(v) => setData('cambio_climatico', v === true)} />
                            Tiene requisitos sobre cambio climático
                        </label>
                    </div>
                    <div className="grid gap-2">
                        <Label>Normas</Label>
                        <SistemasPicker value={data.sistemas} onChange={(v) => setData('sistemas', v)} />
                        <InputError message={errors.sistemas} />
                    </div>
                    <DialogFooter className="gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

type ProcesoForm = {
    objetivo: string;
    lider: string;
    caracterizacion: Record<string, string>;
};

function ProcesoDialog({ proceso, campos, onClose }: { proceso: Proceso; campos: Record<string, string>; onClose: () => void }) {
    const { data, setData, put, processing } = useForm<ProcesoForm>({
        objetivo: proceso.objetivo ?? '',
        lider: proceso.lider ?? '',
        caracterizacion: Object.fromEntries(Object.keys(campos).map((k) => [k, proceso.caracterizacion?.[k] ?? ''])),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/contexto/procesos/${proceso.id}`, { preserveScroll: true, onSuccess: onClose });
    };

    const campo = (k: string) => (
        <div key={k} className="grid gap-1.5">
            <Label htmlFor={`c-${k}`} className="text-xs">
                {campos[k]}
            </Label>
            <textarea
                id={`c-${k}`}
                rows={2}
                value={data.caracterizacion[k] ?? ''}
                onChange={(e) => setData('caracterizacion', { ...data.caracterizacion, [k]: e.target.value })}
                className={textareaCls}
            />
        </div>
    );

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>
                        Caracterización · {proceso.sigla} {proceso.nombre}
                    </DialogTitle>
                    <DialogDescription>Qué entra, qué se hace en cada fase del PHVA, qué sale y cómo se mide (ISO 4.4).</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor="objetivo" className="text-xs">
                                Objetivo del proceso
                            </Label>
                            <textarea
                                id="objetivo"
                                rows={2}
                                value={data.objetivo}
                                onChange={(e) => setData('objetivo', e.target.value)}
                                className={textareaCls}
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="lider" className="text-xs">
                                Líder (cargo)
                            </Label>
                            <Input id="lider" value={data.lider} onChange={(e) => setData('lider', e.target.value)} />
                        </div>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">{['proveedores', 'entradas'].map(campo)}</div>
                    <div className="grid gap-3 sm:grid-cols-4">{['planear', 'hacer', 'verificar', 'actuar'].map(campo)}</div>
                    <div className="grid gap-3 sm:grid-cols-2">{['salidas', 'clientes'].map(campo)}</div>
                    <div className="grid gap-3 sm:grid-cols-3">{['recursos', 'indicadores', 'riesgos'].map(campo)}</div>
                    <DialogFooter className="gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
