import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { Link, router, useForm } from '@inertiajs/react';
import { CheckCircle2, CircleAlert, ListTodo, Plus, Target } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface IndicadorResumen {
    nombre: string;
    anual: number | null;
    meta: number | null;
    cumple: boolean | null;
}

interface Programa {
    id: number;
    codigo: string;
    nombre: string;
    categoria: string;
    responsable: string | null;
    cumplimiento: number | null;
    programadas: number;
    ejecutadas: number;
    actividades: number;
    indicadores: IndicadorResumen[];
}

interface ProgramaCatalogo {
    id: number;
    codigo: string;
    nombre: string;
    categoria: string;
    objetivo: string | null;
    actividades: number;
    indicadores: string[];
    adoptado: boolean;
}

interface Props {
    needsClient: boolean;
    anio: number;
    programas: Programa[];
    catalogo: ProgramaCatalogo[];
    categorias: Record<string, string>;
}

const COLOR_CATEGORIA: Record<string, string> = {
    pve: 'border-violet-500/40 text-violet-700 dark:text-violet-300',
    sst: 'border-sky-500/40 text-sky-700 dark:text-sky-300',
    pesv: 'border-amber-500/40 text-amber-700 dark:text-amber-300',
    ambiental: 'border-emerald-500/40 text-emerald-700 dark:text-emerald-300',
};

/** Semáforo de un indicador: verde cumple, rojo no cumple, gris sin dato o sin meta. */
function Semaforo({ ind }: { ind: IndicadorResumen }) {
    return (
        <span
            title={`${ind.nombre}: ${ind.anual === null ? 'sin datos' : `${ind.anual} %`}${ind.meta !== null ? ` · meta ${ind.meta}` : ''}`}
            className={cn(
                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px]',
                ind.cumple === true && 'border-green-600/40 bg-green-600/10 text-green-700 dark:text-green-400',
                ind.cumple === false && 'border-red-600/40 bg-red-600/10 text-red-700 dark:text-red-400',
                ind.cumple === null && 'text-muted-foreground',
            )}
        >
            {ind.nombre}
            {ind.anual !== null && <span className="font-semibold tabular-nums">{ind.anual}</span>}
        </span>
    );
}

export default function ProgramasIndex({ needsClient, anio, programas, catalogo, categorias }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const [propioAbierto, setPropioAbierto] = useState(false);
    const [adoptando, setAdoptando] = useState<number | null>(null);
    const [errorAdopcion, setErrorAdopcion] = useState<string | null>(null);

    const propio = useForm({ anio, codigo: '', nombre: '', categoria: 'sst' });

    const adoptar = (id: number) => {
        setAdoptando(id);
        setErrorAdopcion(null);
        router.post(
            '/programas',
            { anio, management_program_id: id },
            {
                onError: (e) => setErrorAdopcion(Object.values(e)[0] ?? 'No se pudo adoptar el programa.'),
                onFinish: () => setAdoptando(null),
            },
        );
    };

    const crearPropio: FormEventHandler = (e) => {
        e.preventDefault();
        propio.post('/programas', { onSuccess: () => setPropioAbierto(false) });
    };

    const conCumplimiento = programas.filter((p) => p.cumplimiento !== null);
    const promedio = conCumplimiento.length
        ? Math.round((conCumplimiento.reduce((s, p) => s + (p.cumplimiento ?? 0), 0) / conCumplimiento.length) * 10) / 10
        : null;
    const indicadoresEnRojo = programas.reduce((s, p) => s + p.indicadores.filter((i) => i.cumple === false).length, 0);
    const pendientes = catalogo.filter((c) => !c.adoptado);

    return (
        <ModuloPage
            titulo="Programas de gestión"
            descripcion="PVE, alcohol y SPA, fatiga, seguridad vial y ambiental: cronograma PHVA e indicadores por programa"
            needsClient={needsClient}
            accion={
                <div className="flex items-center gap-2">
                    <select
                        aria-label="Año"
                        value={anio}
                        onChange={(e) => router.get('/programas', { anio: e.target.value }, { preserveState: false })}
                        className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                    >
                        {[anio - 1, anio, anio + 1].map((a) => (
                            <option key={a} value={a}>
                                {a}
                            </option>
                        ))}
                    </select>
                    {canManage && (
                        <Button variant="outline" className="gap-2" onClick={() => setPropioAbierto(true)}>
                            <Plus className="size-4" /> Programa propio
                        </Button>
                    )}
                </div>
            }
        >
            <div className="grid gap-4 sm:grid-cols-3">
                <StatCard label={`Programas en ${anio}`} value={programas.length} icon={Target} />
                <StatCard label="Cumplimiento promedio" value={promedio ?? '—'} sufijo={promedio !== null ? ' %' : ''} icon={CheckCircle2} />
                <StatCard label="Indicadores por debajo de la meta" value={indicadoresEnRojo} icon={CircleAlert} alerta={indicadoresEnRojo > 0} />
            </div>

            <Card>
                <CardContent className="p-0">
                    {programas.length === 0 ? (
                        <div className="text-muted-foreground p-8 text-center text-sm">
                            La empresa no tiene programas en {anio}. Adopta uno del catálogo de abajo.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[40rem] text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2.5 font-medium">Programa</th>
                                        <th className="px-4 py-2.5 font-medium">Cumplimiento</th>
                                        <th className="px-4 py-2.5 font-medium">Indicadores (acumulado del año)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {programas.map((p) => (
                                        <tr key={p.id} className="hover:bg-muted/30 border-t">
                                            <td className="px-4 py-3">
                                                <Link href={`/programas/${p.id}`} className="font-medium hover:underline">
                                                    {p.nombre}
                                                </Link>
                                                <div className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-2 text-xs">
                                                    <span className="font-mono">{p.codigo}</span>
                                                    <Badge variant="outline" className={COLOR_CATEGORIA[p.categoria]}>
                                                        {categorias[p.categoria] ?? p.categoria}
                                                    </Badge>
                                                    {p.responsable && <span>· {p.responsable}</span>}
                                                </div>
                                            </td>
                                            <td className="w-48 px-4 py-3">
                                                {p.cumplimiento === null ? (
                                                    <span className="text-muted-foreground text-xs">Sin actividades programadas</span>
                                                ) : (
                                                    <div>
                                                        <div className="flex items-baseline justify-between text-xs">
                                                            <span className="text-base font-semibold tabular-nums">{p.cumplimiento} %</span>
                                                            <span className="text-muted-foreground tabular-nums">
                                                                {p.ejecutadas}/{p.programadas}
                                                            </span>
                                                        </div>
                                                        <div className="bg-muted mt-1 h-1.5 overflow-hidden rounded-full">
                                                            <div
                                                                className="bg-primary h-full"
                                                                style={{ width: `${Math.min(100, p.cumplimiento)}%` }}
                                                            />
                                                        </div>
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex flex-wrap gap-1.5">
                                                    {p.indicadores.map((i) => (
                                                        <Semaforo key={i.nombre} ind={i} />
                                                    ))}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </CardContent>
            </Card>

            {pendientes.length > 0 && (
                <div>
                    <h2 className="mb-1 font-semibold">Catálogo de CMK</h2>
                    {errorAdopcion && <InputError message={errorAdopcion} className="mb-2" />}
                    <p className="text-muted-foreground mb-3 text-sm">
                        Adoptar copia las actividades, los meses programados y los indicadores del modelo; desde ahí se adaptan a la empresa.
                    </p>
                    <div className="grid gap-3 md:grid-cols-2">
                        {pendientes.map((c) => (
                            <Card key={c.id}>
                                <CardContent className="flex h-full flex-col gap-2 p-4">
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <div className="font-medium">{c.nombre}</div>
                                            <div className="text-muted-foreground mt-0.5 flex items-center gap-2 text-xs">
                                                <span className="font-mono">{c.codigo}</span>
                                                <Badge variant="outline" className={COLOR_CATEGORIA[c.categoria]}>
                                                    {categorias[c.categoria] ?? c.categoria}
                                                </Badge>
                                            </div>
                                        </div>
                                        {canManage && (
                                            <Button
                                                size="sm"
                                                onClick={() => adoptar(c.id)}
                                                disabled={adoptando !== null}
                                                className="shrink-0 gap-1.5"
                                            >
                                                <Plus className="size-3.5" /> {adoptando === c.id ? 'Adoptando…' : 'Adoptar'}
                                            </Button>
                                        )}
                                    </div>
                                    {c.objetivo && <p className="text-muted-foreground line-clamp-2 text-xs">{c.objetivo}</p>}
                                    <div className="text-muted-foreground mt-auto flex items-center gap-1.5 text-xs">
                                        <ListTodo className="size-3.5" /> {c.actividades} actividades · {c.indicadores.join(', ')}
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </div>
            )}

            <Dialog open={propioAbierto} onOpenChange={setPropioAbierto}>
                <DialogContent>
                    <form onSubmit={crearPropio} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Programa propio</DialogTitle>
                            <DialogDescription>
                                Para un programa que no está en el catálogo (por ejemplo, riesgo mecánico o riesgo psicosocial). Arranca vacío, con el
                                indicador de cumplimiento.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid grid-cols-3 gap-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="codigo">Código</Label>
                                <Input
                                    id="codigo"
                                    value={propio.data.codigo}
                                    onChange={(e) => propio.setData('codigo', e.target.value)}
                                    placeholder="PR-SST-20"
                                />
                                <InputError message={propio.errors.codigo} />
                            </div>
                            <div className="col-span-2 space-y-1.5">
                                <Label htmlFor="categoria">Categoría</Label>
                                <select
                                    id="categoria"
                                    value={propio.data.categoria}
                                    onChange={(e) => propio.setData('categoria', e.target.value)}
                                    className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                >
                                    {Object.entries(categorias).map(([k, v]) => (
                                        <option key={k} value={k}>
                                            {v}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="nombre">Nombre del programa</Label>
                            <Input id="nombre" value={propio.data.nombre} onChange={(e) => propio.setData('nombre', e.target.value)} />
                            <InputError message={propio.errors.nombre} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setPropioAbierto(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={propio.processing}>
                                Crear programa
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </ModuloPage>
    );
}
