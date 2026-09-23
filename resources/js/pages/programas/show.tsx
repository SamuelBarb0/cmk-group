import InputError from '@/components/input-error';
import { ModuloPage } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, CalendarPlus, ClipboardCheck, Plus, Save, Trash2, X } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';

type Fase = 'planear' | 'hacer' | 'verificar' | 'actuar';
type Frecuencia = 'trimestral' | 'semestral' | 'anual';

interface Actividad {
    fase: Fase;
    nombre: string;
    responsable: string | null;
    presupuesto: string | null;
    programados: number[];
    ejecutados: number[];
    observaciones: string | null;
}

interface Lectura {
    numerador: number | null;
    denominador: number | null;
}

interface Indicador {
    clave: string;
    nombre: string;
    numerador_label: string;
    denominador_label: string;
    meta: number | null;
    meta_texto: string | null;
    sentido: 'asc' | 'desc';
    frecuencia: Frecuencia;
    automatico: boolean;
    lecturas: Record<string, Lectura>;
}

interface Props {
    needsClient: boolean;
    programa: {
        id: number;
        anio: number;
        codigo: string;
        nombre: string;
        categoria: string;
        objetivo: string | null;
        alcance: string | null;
        recursos: string | null;
        responsable: string | null;
        observaciones: string | null;
        del_catalogo: boolean;
    };
    actividades: Actividad[];
    indicadores: Indicador[];
    formato: { codigo: string; nombre: string } | null;
    siguienteExiste: boolean;
    categorias: Record<string, string>;
}

const FASES: { key: Fase; label: string }[] = [
    { key: 'planear', label: 'Planear' },
    { key: 'hacer', label: 'Hacer' },
    { key: 'verificar', label: 'Verificar' },
    { key: 'actuar', label: 'Actuar' },
];
const MESES = ['E', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];
const MESES_LARGOS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const PERIODOS: Record<Frecuencia, number> = { trimestral: 4, semestral: 2, anual: 1 };
const NOMBRE_PERIODO: Record<Frecuencia, (n: number) => string> = {
    trimestral: (n) => `Trimestre ${n}`,
    semestral: (n) => `Semestre ${n}`,
    anual: () => 'Año',
};

/** Meses que abarca el periodo `n`. Igual que ProgramPlan::mesesDelPeriodo(). */
function mesesDelPeriodo(f: Frecuencia, n: number): number[] {
    const largo = 12 / PERIODOS[f];
    return Array.from({ length: largo }, (_, i) => (n - 1) * largo + i + 1);
}

/**
 * Programado / ejecutado del cronograma en unos meses. Un mes ejecutado solo
 * cuenta si estaba programado: la misma regla que el servidor.
 */
function conteo(acts: Actividad[], meses?: number[]) {
    let programadas = 0;
    let ejecutadas = 0;
    for (const a of acts) {
        const prog = meses ? a.programados.filter((m) => meses.includes(m)) : a.programados;
        programadas += prog.length;
        ejecutadas += prog.filter((m) => a.ejecutados.includes(m)).length;
    }
    return { programadas, ejecutadas };
}

function valor(n: number | null, d: number | null): number | null {
    return n !== null && d !== null && d > 0 ? Math.round((n / d) * 1000) / 10 : null;
}

function cumple(ind: Indicador, v: number | null): boolean | null {
    if (v === null || ind.meta === null) return null;
    return ind.sentido === 'desc' ? v <= ind.meta : v >= ind.meta;
}

/** Valores por periodo y acumulado del año (suma numeradores y denominadores). */
function valores(ind: Indicador, acts: Actividad[]) {
    let sumN = 0;
    let sumD = 0;
    const periodos = Array.from({ length: PERIODOS[ind.frecuencia] }, (_, i) => {
        const p = i + 1;
        let n: number | null;
        let d: number | null;
        if (ind.automatico) {
            const c = conteo(acts, mesesDelPeriodo(ind.frecuencia, p));
            n = c.ejecutadas;
            d = c.programadas;
        } else {
            n = ind.lecturas[String(p)]?.numerador ?? null;
            d = ind.lecturas[String(p)]?.denominador ?? null;
        }
        const v = valor(n, d);
        if (v !== null) {
            sumN += n ?? 0;
            sumD += d ?? 0;
        }
        return { p, n, d, v };
    });
    return { periodos, anual: sumD > 0 ? Math.round((sumN / sumD) * 1000) / 10 : null };
}

const numero = (s: string): number | null => (s.trim() === '' || isNaN(Number(s)) ? null : Number(s));

/**
 * Inertia reutiliza el mismo componente al pasar de un programa a otro (por
 * ejemplo, al «Programar» el año siguiente), y el estado local sobreviviría:
 * el cronograma nuevo aparecería con lo del año anterior. La `key` fuerza un
 * editor limpio por programa.
 */
export default function ProgramaShow(props: Props) {
    return <Editor key={props.programa.id} {...props} />;
}

function Editor({ needsClient, programa, actividades, indicadores, formato, siguienteExiste, categorias }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const errors = usePage<SharedData & { errors: Record<string, string> }>().props.errors;

    const [ficha, setFicha] = useState({
        nombre: programa.nombre,
        objetivo: programa.objetivo ?? '',
        alcance: programa.alcance ?? '',
        recursos: programa.recursos ?? '',
        responsable: programa.responsable ?? '',
        observaciones: programa.observaciones ?? '',
    });
    const [acts, setActs] = useState<Actividad[]>(actividades);
    const [inds, setInds] = useState<Indicador[]>(indicadores);
    const [saving, setSaving] = useState(false);
    const [sucio, setSucio] = useState(false);

    const total = useMemo(() => conteo(acts), [acts]);
    const cumplimiento = total.programadas > 0 ? Math.round((total.ejecutadas / total.programadas) * 1000) / 10 : null;

    const cambiarActs = (fn: (prev: Actividad[]) => Actividad[]) => {
        setActs(fn);
        setSucio(true);
    };
    const cambiarInd = (i: number, cambio: Partial<Indicador>) => {
        setInds((prev) => prev.map((x, j) => (j === i ? { ...x, ...cambio } : x)));
        setSucio(true);
    };

    /** Clic en un mes: sin programar → programado → ejecutado → sin programar. */
    const ciclar = (idx: number, mes: number) =>
        cambiarActs((prev) =>
            prev.map((a, j) => {
                if (j !== idx) return a;
                const prog = a.programados.includes(mes);
                const ejec = a.ejecutados.includes(mes);
                if (!prog) return { ...a, programados: [...a.programados, mes] };
                if (!ejec) return { ...a, ejecutados: [...a.ejecutados, mes] };
                return { ...a, programados: a.programados.filter((m) => m !== mes), ejecutados: a.ejecutados.filter((m) => m !== mes) };
            }),
        );

    const agregarActividad = (fase: Fase) =>
        cambiarActs((prev) => {
            // Se inserta al final de su fase para que el orden guardado siga el PHVA.
            const orden = (f: Fase) => FASES.findIndex((x) => x.key === f);
            const ultima = prev.map((a) => a.fase).lastIndexOf(fase);
            const siguiente = prev.findIndex((a) => orden(a.fase) > orden(fase));
            const pos = ultima !== -1 ? ultima + 1 : siguiente !== -1 ? siguiente : prev.length;
            const nueva: Actividad = { fase, nombre: '', responsable: '', presupuesto: null, programados: [], ejecutados: [], observaciones: null };
            return [...prev.slice(0, pos), nueva, ...prev.slice(pos)];
        });

    const setLectura = (i: number, periodo: number, campo: keyof Lectura, v: string) => {
        const ind = inds[i];
        const actual = ind.lecturas[String(periodo)] ?? { numerador: null, denominador: null };
        cambiarInd(i, { lecturas: { ...ind.lecturas, [String(periodo)]: { ...actual, [campo]: numero(v) } } });
    };

    const agregarIndicador = () => {
        setInds((prev) => [
            ...prev,
            {
                clave: `propio-${Date.now()}`,
                nombre: '',
                numerador_label: '',
                denominador_label: '',
                meta: null,
                meta_texto: null,
                sentido: 'asc',
                frecuencia: 'semestral',
                automatico: false,
                lecturas: {},
            },
        ]);
        setSucio(true);
    };

    const guardar = () => {
        setSaving(true);
        // Las actividades sin nombre son filas agregadas y dejadas en blanco.
        const actividadesValidas = acts.filter((a) => a.nombre.trim() !== '');
        router.put(
            `/programas/${programa.id}`,
            {
                ...ficha,
                actividades: actividadesValidas,
                indicadores: inds,
            } as never,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setActs(actividadesValidas);
                    setSucio(false);
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    const eliminar = () => {
        if (confirm(`¿Eliminar el programa ${programa.codigo} de ${programa.anio}? Se pierden su cronograma y sus lecturas.`)) {
            router.delete(`/programas/${programa.id}`);
        }
    };

    const primerError = Object.values(errors ?? {})[0];

    return (
        <ModuloPage
            titulo={programa.nombre}
            descripcion={`${programa.codigo} · ${categorias[programa.categoria] ?? programa.categoria} · ${programa.anio}`}
            needsClient={needsClient}
            accion={
                <div className="flex flex-wrap items-center gap-2">
                    <Button variant="outline" asChild className="gap-2">
                        <Link href={`/programas?anio=${programa.anio}`}>
                            <ArrowLeft className="size-4" /> Programas
                        </Link>
                    </Button>
                    {canManage && !siguienteExiste && (
                        <Button
                            variant="outline"
                            className="gap-2"
                            disabled={sucio}
                            title={sucio ? 'Guarda los cambios antes de programar el año siguiente' : undefined}
                            onClick={() => router.post(`/programas/${programa.id}/renovar`)}
                        >
                            <CalendarPlus className="size-4" /> Programar {programa.anio + 1}
                        </Button>
                    )}
                    {canManage && (
                        <Button onClick={guardar} disabled={saving} className="gap-2">
                            <Save className="size-4" /> {saving ? 'Guardando…' : 'Guardar'}
                        </Button>
                    )}
                </div>
            }
        >
            {primerError && <InputError message={primerError} />}

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardContent className="grid gap-4 p-5 sm:grid-cols-2">
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label htmlFor="nombre">Nombre</Label>
                            <Input
                                id="nombre"
                                value={ficha.nombre}
                                disabled={!canManage}
                                onChange={(e) => {
                                    setFicha({ ...ficha, nombre: e.target.value });
                                    setSucio(true);
                                }}
                            />
                        </div>
                        {(['objetivo', 'alcance'] as const).map((campo) => (
                            <div key={campo} className="space-y-1.5">
                                <Label htmlFor={campo}>{campo === 'objetivo' ? 'Objetivo' : 'Alcance'}</Label>
                                <textarea
                                    id={campo}
                                    rows={4}
                                    value={ficha[campo]}
                                    disabled={!canManage}
                                    placeholder={
                                        campo === 'objetivo' && !ficha.objetivo ? 'El modelo de CMK no trae objetivo para este programa' : ''
                                    }
                                    onChange={(e) => {
                                        setFicha({ ...ficha, [campo]: e.target.value });
                                        setSucio(true);
                                    }}
                                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm disabled:opacity-70"
                                />
                            </div>
                        ))}
                        {(['responsable', 'recursos'] as const).map((campo) => (
                            <div key={campo} className="space-y-1.5">
                                <Label htmlFor={campo}>{campo === 'responsable' ? 'Responsable del programa' : 'Recursos'}</Label>
                                <Input
                                    id={campo}
                                    value={ficha[campo]}
                                    disabled={!canManage}
                                    onChange={(e) => {
                                        setFicha({ ...ficha, [campo]: e.target.value });
                                        setSucio(true);
                                    }}
                                />
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="flex h-full flex-col gap-3 p-5">
                        <div className="text-muted-foreground text-sm">Cumplimiento del cronograma</div>
                        <div className="text-4xl font-bold tabular-nums">{cumplimiento === null ? '—' : `${cumplimiento} %`}</div>
                        <div className="text-muted-foreground text-xs tabular-nums">
                            {total.ejecutadas} de {total.programadas} programadas ejecutadas
                        </div>
                        <div className="bg-muted h-2 overflow-hidden rounded-full">
                            <div className="bg-primary h-full" style={{ width: `${Math.min(100, cumplimiento ?? 0)}%` }} />
                        </div>
                        {formato && (
                            <div className="mt-auto rounded-md border p-3 text-sm">
                                <div className="flex items-center gap-2 font-medium">
                                    <ClipboardCheck className="size-4" /> Formato del programa
                                </div>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    {formato.codigo} · {formato.nombre}. Se diligencia en{' '}
                                    <Link href="/formatos" className="text-primary hover:underline">
                                        Formatos
                                    </Link>
                                    .
                                </p>
                            </div>
                        )}
                        {canManage && (
                            <Button variant="ghost" size="sm" onClick={eliminar} className="text-destructive mt-auto gap-2 self-start">
                                <Trash2 className="size-4" /> Eliminar programa
                            </Button>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Card className="overflow-hidden">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                    <h2 className="font-semibold">Cronograma PHVA {programa.anio}</h2>
                    <div className="text-muted-foreground flex items-center gap-3 text-xs">
                        <span className="flex items-center gap-1">
                            <span className="inline-block size-3 rounded-sm bg-blue-500" /> Programado
                        </span>
                        <span className="flex items-center gap-1">
                            <span className="inline-block size-3 rounded-sm bg-green-600" /> Ejecutado
                        </span>
                        <span>Clic en el mes para cambiar</span>
                    </div>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground text-xs">
                            <tr>
                                <th className="px-3 py-2 text-left font-medium">Actividad</th>
                                <th className="px-2 py-2 text-left font-medium">Responsable</th>
                                {MESES.map((m, i) => (
                                    <th key={i} className="w-7 px-0 py-2 text-center font-semibold" title={MESES_LARGOS[i]}>
                                        {m}
                                    </th>
                                ))}
                                <th className="w-8" />
                            </tr>
                        </thead>
                        <tbody>
                            {FASES.map((f) => {
                                const filas = acts.map((a, idx) => ({ a, idx })).filter(({ a }) => a.fase === f.key);
                                return (
                                    <Fragment key={f.key}>
                                        <tr className="bg-muted/30 border-t">
                                            <td colSpan={15} className="px-3 py-1.5">
                                                <div className="flex items-center justify-between">
                                                    <span className="text-xs font-semibold tracking-wide uppercase">{f.label}</span>
                                                    {canManage && (
                                                        <button
                                                            type="button"
                                                            onClick={() => agregarActividad(f.key)}
                                                            className="text-primary flex items-center gap-1 text-xs hover:underline"
                                                        >
                                                            <Plus className="size-3" /> Actividad
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                        {filas.map(({ a, idx }) => (
                                            <tr key={idx} className="border-t">
                                                <td className="px-3 py-1.5">
                                                    <input
                                                        value={a.nombre}
                                                        disabled={!canManage}
                                                        placeholder="Nombre de la actividad"
                                                        aria-label="Nombre de la actividad"
                                                        onChange={(e) =>
                                                            cambiarActs((prev) =>
                                                                prev.map((x, j) => (j === idx ? { ...x, nombre: e.target.value } : x)),
                                                            )
                                                        }
                                                        className="hover:border-input focus:border-input w-full min-w-64 rounded border border-transparent bg-transparent px-1.5 py-1 text-sm disabled:opacity-80"
                                                    />
                                                    {a.observaciones && (
                                                        <div className="text-muted-foreground px-1.5 text-[11px]">{a.observaciones}</div>
                                                    )}
                                                </td>
                                                <td className="px-2 py-1.5">
                                                    <input
                                                        value={a.responsable ?? ''}
                                                        disabled={!canManage}
                                                        placeholder="—"
                                                        aria-label="Responsable"
                                                        onChange={(e) =>
                                                            cambiarActs((prev) =>
                                                                prev.map((x, j) => (j === idx ? { ...x, responsable: e.target.value } : x)),
                                                            )
                                                        }
                                                        className="border-input bg-background w-28 rounded border px-2 py-1 text-xs disabled:opacity-60"
                                                    />
                                                </td>
                                                {MESES.map((_, i) => {
                                                    const mes = i + 1;
                                                    const s =
                                                        a.ejecutados.includes(mes) && a.programados.includes(mes)
                                                            ? 2
                                                            : a.programados.includes(mes)
                                                              ? 1
                                                              : 0;
                                                    return (
                                                        <td key={i} className="px-0.5 py-1.5 text-center">
                                                            <button
                                                                type="button"
                                                                disabled={!canManage}
                                                                onClick={() => ciclar(idx, mes)}
                                                                title={`${MESES_LARGOS[i]}: ${s === 2 ? 'Ejecutado' : s === 1 ? 'Programado' : 'Sin programar'}`}
                                                                className={cn(
                                                                    'size-5 rounded-sm border transition-colors disabled:cursor-not-allowed',
                                                                    s === 2
                                                                        ? 'border-green-600 bg-green-600'
                                                                        : s === 1
                                                                          ? 'border-blue-500 bg-blue-500'
                                                                          : 'border-input bg-background hover:bg-muted',
                                                                )}
                                                            />
                                                        </td>
                                                    );
                                                })}
                                                <td className="px-1 text-center">
                                                    {canManage && (
                                                        <button
                                                            type="button"
                                                            aria-label="Quitar actividad"
                                                            onClick={() => cambiarActs((prev) => prev.filter((_, j) => j !== idx))}
                                                            className="text-muted-foreground hover:text-destructive"
                                                        >
                                                            <X className="size-4" />
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </Card>

            <div>
                <div className="mb-3 flex items-center justify-between">
                    <h2 className="font-semibold">Indicadores</h2>
                    {canManage && (
                        <Button variant="outline" size="sm" onClick={agregarIndicador} className="gap-1.5">
                            <Plus className="size-3.5" /> Indicador
                        </Button>
                    )}
                </div>
                <div className="grid gap-4 xl:grid-cols-2">
                    {inds.map((ind, i) => {
                        const v = valores(ind, acts);
                        const ok = cumple(ind, v.anual);
                        return (
                            <Card key={ind.clave}>
                                <CardContent className="space-y-3 p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0 flex-1">
                                            <input
                                                value={ind.nombre}
                                                disabled={!canManage || ind.automatico}
                                                placeholder="Nombre del indicador"
                                                aria-label="Nombre del indicador"
                                                onChange={(e) => cambiarInd(i, { nombre: e.target.value })}
                                                className="hover:border-input focus:border-input w-full rounded border border-transparent bg-transparent px-1 font-medium disabled:opacity-100"
                                            />
                                            <div className="text-muted-foreground px-1 text-xs">
                                                {ind.automatico ? (
                                                    'Se calcula solo, con el cronograma'
                                                ) : (
                                                    <>
                                                        <input
                                                            value={ind.numerador_label}
                                                            disabled={!canManage}
                                                            placeholder="Numerador"
                                                            aria-label="Numerador"
                                                            onChange={(e) => cambiarInd(i, { numerador_label: e.target.value })}
                                                            className="hover:border-input focus:border-input w-full rounded border border-transparent bg-transparent"
                                                        />
                                                        <div className="border-t" />
                                                        <input
                                                            value={ind.denominador_label}
                                                            disabled={!canManage}
                                                            placeholder="Denominador"
                                                            aria-label="Denominador"
                                                            onChange={(e) => cambiarInd(i, { denominador_label: e.target.value })}
                                                            className="hover:border-input focus:border-input w-full rounded border border-transparent bg-transparent"
                                                        />
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                        <div className="text-right">
                                            <div
                                                className={cn(
                                                    'text-2xl font-bold tabular-nums',
                                                    ok === true && 'text-green-700 dark:text-green-400',
                                                    ok === false && 'text-red-700 dark:text-red-400',
                                                )}
                                            >
                                                {v.anual === null ? '—' : `${v.anual} %`}
                                            </div>
                                            <div className="text-muted-foreground text-[11px]">acumulado del año</div>
                                        </div>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2 text-xs">
                                        <span className="text-muted-foreground">Meta</span>
                                        <select
                                            aria-label="Sentido de la meta"
                                            value={ind.sentido}
                                            disabled={!canManage}
                                            onChange={(e) => cambiarInd(i, { sentido: e.target.value as 'asc' | 'desc' })}
                                            className="border-input bg-background h-7 rounded border px-1"
                                        >
                                            <option value="asc">≥</option>
                                            <option value="desc">≤</option>
                                        </select>
                                        <input
                                            type="number"
                                            min={0}
                                            step="any"
                                            aria-label="Meta"
                                            value={ind.meta ?? ''}
                                            disabled={!canManage}
                                            placeholder="—"
                                            onChange={(e) => cambiarInd(i, { meta: numero(e.target.value) })}
                                            className="border-input bg-background h-7 w-16 rounded border px-1.5"
                                        />
                                        <span className="text-muted-foreground">%</span>
                                        <select
                                            aria-label="Frecuencia"
                                            value={ind.frecuencia}
                                            disabled={!canManage}
                                            onChange={(e) => cambiarInd(i, { frecuencia: e.target.value as Frecuencia })}
                                            className="border-input bg-background ml-auto h-7 rounded border px-1"
                                        >
                                            <option value="trimestral">Trimestral</option>
                                            <option value="semestral">Semestral</option>
                                            <option value="anual">Anual</option>
                                        </select>
                                        {canManage && !ind.automatico && (
                                            <button
                                                type="button"
                                                aria-label="Quitar indicador"
                                                onClick={() => {
                                                    setInds((prev) => prev.filter((_, j) => j !== i));
                                                    setSucio(true);
                                                }}
                                                className="text-muted-foreground hover:text-destructive"
                                            >
                                                <Trash2 className="size-3.5" />
                                            </button>
                                        )}
                                    </div>
                                    {ind.meta_texto && (
                                        <p className="text-muted-foreground text-[11px] italic">Meta en el modelo: {ind.meta_texto}</p>
                                    )}

                                    <table className="w-full text-xs">
                                        <thead className="text-muted-foreground">
                                            <tr>
                                                <th className="py-1 text-left font-medium">Periodo</th>
                                                <th className="py-1 text-right font-medium">Numerador</th>
                                                <th className="py-1 text-right font-medium">Denominador</th>
                                                <th className="py-1 text-right font-medium">Valor</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {v.periodos.map(({ p, n, d, v: val }) => {
                                                const okP = cumple(ind, val);
                                                return (
                                                    <tr key={p} className="border-t">
                                                        <td className="py-1">{NOMBRE_PERIODO[ind.frecuencia](p)}</td>
                                                        {ind.automatico ? (
                                                            <>
                                                                <td className="py-1 text-right tabular-nums">{n}</td>
                                                                <td className="py-1 text-right tabular-nums">{d}</td>
                                                            </>
                                                        ) : (
                                                            (['numerador', 'denominador'] as const).map((campo) => (
                                                                <td key={campo} className="py-1 text-right">
                                                                    <input
                                                                        type="number"
                                                                        min={0}
                                                                        step="any"
                                                                        aria-label={`${campo} ${NOMBRE_PERIODO[ind.frecuencia](p)}`}
                                                                        value={(campo === 'numerador' ? n : d) ?? ''}
                                                                        disabled={!canManage}
                                                                        onChange={(e) => setLectura(i, p, campo, e.target.value)}
                                                                        className="border-input bg-background h-7 w-20 rounded border px-1.5 text-right tabular-nums"
                                                                    />
                                                                </td>
                                                            ))
                                                        )}
                                                        <td
                                                            className={cn(
                                                                'py-1 text-right font-semibold tabular-nums',
                                                                okP === true && 'text-green-700 dark:text-green-400',
                                                                okP === false && 'text-red-700 dark:text-red-400',
                                                            )}
                                                        >
                                                            {val === null ? '—' : `${val} %`}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                    {ind.automatico && <Badge variant="outline">Automático</Badge>}
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            </div>

            {canManage && sucio && (
                <div className="bg-background/95 sticky bottom-4 flex items-center justify-end gap-3 rounded-lg border p-3 shadow-lg">
                    <span className="text-muted-foreground text-sm">Hay cambios sin guardar</span>
                    <Button onClick={guardar} disabled={saving} className="gap-2">
                        <Save className="size-4" /> {saving ? 'Guardando…' : 'Guardar'}
                    </Button>
                </div>
            )}
        </ModuloPage>
    );
}
