import { SeccionCard, type Informe } from '@/components/reportes/seccion-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Building2, CheckCircle2, FileDown, FileSpreadsheet, FileText, Loader2, RefreshCw } from 'lucide-react';
import { useState } from 'react';

interface Exportacion {
    clave: string;
    titulo: string;
    descripcion: string;
    grupo: string;
    porPeriodo: boolean;
}
type Props =
    | { needsClient: true }
    | {
          needsClient: false;
          periodo: { desde: string; hasta: string };
          secciones: { clave: string; titulo: string }[];
          seleccion: string[];
          informe: Informe;
          exportaciones: Exportacion[];
          canGenerate: boolean;
      };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Reportes', href: '/reportes' },
];

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** Periodos habituales de un informe de SST. Los que terminan en el futuro se cortan en hoy. */
function presets(): { label: string; desde: string; hasta: string }[] {
    const hoy = new Date();
    const y = hoy.getFullYear();
    const corta = (d: Date) => iso(d > hoy ? hoy : d);
    const trim = Math.floor(hoy.getMonth() / 3);
    const tAnt = trim === 0 ? { y: y - 1, t: 3 } : { y, t: trim - 1 };
    return [
        { label: 'Año a la fecha', desde: `${y}-01-01`, hasta: iso(hoy) },
        { label: 'Mes anterior', desde: iso(new Date(y, hoy.getMonth() - 1, 1)), hasta: iso(new Date(y, hoy.getMonth(), 0)) },
        { label: 'Trimestre anterior', desde: iso(new Date(tAnt.y, tAnt.t * 3, 1)), hasta: iso(new Date(tAnt.y, tAnt.t * 3 + 3, 0)) },
        { label: 'Primer semestre', desde: `${y}-01-01`, hasta: corta(new Date(y, 5, 30)) },
        { label: 'Año anterior', desde: `${y - 1}-01-01`, hasta: `${y - 1}-12-31` },
    ];
}

function csrfToken(): string {
    const cookie = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

export default function Reportes(props: Props) {
    if (props.needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Reportes" />
                <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Reportes</h1>
                        <p className="text-muted-foreground text-sm">Informe de gestión del SG-SST y exportaciones a Excel.</p>
                    </div>
                    <Card>
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <Building2 className="size-7" />
                            </div>
                            <p className="font-medium">Selecciona un cliente para generar sus reportes</p>
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
    return <ReportesCliente {...props} />;
}

function ReportesCliente({ periodo, secciones, seleccion, informe, exportaciones, canGenerate }: Extract<Props, { needsClient: false }>) {
    const [pestana, setPestana] = useState<'informe' | 'excel'>('informe');
    const [desde, setDesde] = useState(periodo.desde);
    const [hasta, setHasta] = useState(periodo.hasta);
    const [elegidas, setElegidas] = useState<string[]>(seleccion);
    const [observaciones, setObservaciones] = useState('');
    const [cargando, setCargando] = useState(false);
    const [descargando, setDescargando] = useState<'word' | 'pdf' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [todo, setTodo] = useState(false);

    // La vista previa corresponde a lo que está aplicado, no a lo que se está
    // tecleando: se avisa si difieren para no descargar algo distinto a lo visto.
    const pendiente = desde !== periodo.desde || hasta !== periodo.hasta || [...elegidas].sort().join() !== [...seleccion].sort().join();

    function aplicar(d = desde, h = hasta, s = elegidas) {
        setError(null);
        router.get(
            route('reportes.index'),
            { desde: d, hasta: h, ...(s.length === secciones.length ? {} : { secciones: s }) },
            {
                preserveState: true,
                preserveScroll: true,
                onStart: () => setCargando(true),
                onFinish: () => setCargando(false),
                onError: (e) => setError(Object.values(e)[0] ?? 'Revisa el periodo.'),
            },
        );
    }

    async function descargar(formato: 'word' | 'pdf') {
        setError(null);
        setDescargando(formato);
        try {
            const res = await fetch(route('reportes.informe'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
                body: JSON.stringify({ desde: periodo.desde, hasta: periodo.hasta, secciones: seleccion, formato, observaciones }),
            });
            if (!res.ok) {
                const cuerpo = await res.json().catch(() => null);
                throw new Error(cuerpo?.message ?? `No se pudo generar el informe (error ${res.status}).`);
            }
            const nombre =
                /filename="?([^";]+)"?/.exec(res.headers.get('Content-Disposition') ?? '')?.[1] ?? `informe.${formato === 'pdf' ? 'pdf' : 'docx'}`;
            const url = URL.createObjectURL(await res.blob());
            const a = document.createElement('a');
            a.href = url;
            a.download = nombre;
            a.click();
            URL.revokeObjectURL(url);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'No se pudo generar el informe.');
        } finally {
            setDescargando(null);
        }
    }

    const grupos = exportaciones.reduce<Record<string, Exportacion[]>>((acc, e) => ((acc[e.grupo] ??= []).push(e), acc), {});

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reportes" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="font-brand text-2xl font-bold tracking-tight">Reportes</h1>
                    <p className="text-muted-foreground text-sm">
                        Informe de gestión del SG-SST y exportaciones de <span className="font-medium">{informe.empresa.nombre}</span>
                        {informe.empresa.nombre.endsWith('.') ? '' : '.'}
                    </p>
                </div>

                <div className="flex gap-1 border-b">
                    {(
                        [
                            ['informe', 'Informe de gestión', FileText],
                            ['excel', 'Exportar a Excel', FileSpreadsheet],
                        ] as const
                    ).map(([clave, texto, Icono]) => (
                        <button
                            key={clave}
                            onClick={() => setPestana(clave)}
                            className={cn(
                                '-mb-px flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium transition-colors',
                                pestana === clave ? 'border-primary text-primary' : 'text-muted-foreground hover:text-foreground border-transparent',
                            )}
                        >
                            <Icono className="size-4" /> {texto}
                        </button>
                    ))}
                </div>

                {/* Periodo: común a las dos pestañas */}
                <Card>
                    <CardContent className="flex flex-col gap-4 p-5">
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="desde">Desde</Label>
                                <Input id="desde" type="date" value={desde} max={hasta} onChange={(e) => setDesde(e.target.value)} className="w-40" />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="hasta">Hasta</Label>
                                <Input id="hasta" type="date" value={hasta} min={desde} onChange={(e) => setHasta(e.target.value)} className="w-40" />
                            </div>
                            <Button onClick={() => aplicar()} disabled={cargando || !pendiente} className="gap-2">
                                {cargando ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />} Actualizar
                            </Button>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {presets().map((p) => (
                                <Button
                                    key={p.label}
                                    variant="outline"
                                    size="sm"
                                    className={cn(p.desde === periodo.desde && p.hasta === periodo.hasta && 'border-primary text-primary')}
                                    onClick={() => {
                                        setDesde(p.desde);
                                        setHasta(p.hasta);
                                        aplicar(p.desde, p.hasta);
                                    }}
                                >
                                    {p.label}
                                </Button>
                            ))}
                        </div>
                        {error && (
                            <p className="text-destructive flex items-center gap-1.5 text-sm">
                                <AlertTriangle className="size-4" /> {error}
                            </p>
                        )}
                    </CardContent>
                </Card>

                {pestana === 'informe' ? (
                    <div className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[20rem_minmax(0,1fr)]">
                        {/* Configuración y descarga */}
                        <div className="flex flex-col gap-4 lg:sticky lg:top-4 lg:self-start">
                            <Card>
                                <CardContent className="flex flex-col gap-3 p-5">
                                    <div className="flex items-center justify-between">
                                        <h2 className="text-sm font-semibold">
                                            Secciones ({elegidas.length} de {secciones.length})
                                        </h2>
                                        <button
                                            className="text-primary text-xs hover:underline"
                                            onClick={() =>
                                                setElegidas(elegidas.length === secciones.length ? ['empresa'] : secciones.map((s) => s.clave))
                                            }
                                        >
                                            {elegidas.length === secciones.length ? 'Quitar todas' : 'Todas'}
                                        </button>
                                    </div>
                                    <div className="flex max-h-72 flex-col gap-1.5 overflow-y-auto pr-1">
                                        {secciones.map((s) => (
                                            <label key={s.clave} className="flex items-center gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    checked={elegidas.includes(s.clave)}
                                                    onChange={(e) =>
                                                        setElegidas(e.target.checked ? [...elegidas, s.clave] : elegidas.filter((c) => c !== s.clave))
                                                    }
                                                />
                                                {s.titulo}
                                            </label>
                                        ))}
                                    </div>
                                    {pendiente && (
                                        <Button variant="secondary" size="sm" onClick={() => aplicar()} disabled={cargando || elegidas.length === 0}>
                                            Aplicar cambios a la vista previa
                                        </Button>
                                    )}
                                </CardContent>
                            </Card>
                            {canGenerate && (
                                <Card>
                                    <CardContent className="flex flex-col gap-3 p-5">
                                        <Label htmlFor="observaciones">Análisis y recomendaciones (opcional)</Label>
                                        <textarea
                                            id="observaciones"
                                            rows={6}
                                            maxLength={20000}
                                            placeholder="Conclusiones del periodo, prioridades y recomendaciones para la gerencia. Va después de los puntos de atención."
                                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                            value={observaciones}
                                            onChange={(e) => setObservaciones(e.target.value)}
                                        />
                                        {pendiente && (
                                            <p className="text-xs text-amber-700 dark:text-amber-400">
                                                Aplica los cambios antes de descargar: se descarga lo que ves.
                                            </p>
                                        )}
                                        <div className="grid grid-cols-2 gap-2">
                                            <Button
                                                variant="outline"
                                                className="gap-2"
                                                disabled={descargando !== null || pendiente}
                                                onClick={() => descargar('word')}
                                            >
                                                {descargando === 'word' ? (
                                                    <Loader2 className="size-4 animate-spin" />
                                                ) : (
                                                    <FileDown className="size-4" />
                                                )}{' '}
                                                Word
                                            </Button>
                                            <Button className="gap-2" disabled={descargando !== null || pendiente} onClick={() => descargar('pdf')}>
                                                {descargando === 'pdf' ? (
                                                    <Loader2 className="size-4 animate-spin" />
                                                ) : (
                                                    <FileDown className="size-4" />
                                                )}{' '}
                                                PDF
                                            </Button>
                                        </div>
                                        <p className="text-muted-foreground text-xs">
                                            Word para revisarlo y completarlo; PDF como copia final para archivar.
                                        </p>
                                    </CardContent>
                                </Card>
                            )}
                        </div>

                        {/* Vista previa */}
                        <div className={cn('flex flex-col gap-4 transition-opacity', cargando && 'opacity-50')}>
                            <div className="text-muted-foreground text-sm">
                                Vista previa · <span className="text-foreground font-medium">{informe.periodo.etiqueta}</span>
                            </div>
                            <Card className={informe.atencion.length ? 'border-red-600/30' : 'border-green-600/30'}>
                                <CardContent className="p-5">
                                    <h2 className="font-brand mb-2 text-lg font-bold">Puntos de atención</h2>
                                    {informe.atencion.length === 0 ? (
                                        <p className="flex items-center gap-2 text-sm text-green-700 dark:text-green-400">
                                            <CheckCircle2 className="size-4" /> Sin situaciones que requieran acción inmediata.
                                        </p>
                                    ) : (
                                        <ul className="flex flex-col gap-1.5 text-sm">
                                            {informe.atencion.map((a, i) => (
                                                <li key={i} className="flex gap-2">
                                                    <AlertTriangle className="mt-0.5 size-4 shrink-0 text-red-600" />
                                                    <span>
                                                        <span className="font-semibold text-red-700 dark:text-red-400">{a.seccion}:</span> {a.texto}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </CardContent>
                            </Card>
                            {informe.secciones.map((s) => (
                                <SeccionCard key={s.clave} s={s} />
                            ))}
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-col gap-4">
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={todo} onChange={(e) => setTodo(e.target.checked)} />
                            Exportar todos los registros (ignorar el periodo)
                        </label>
                        {Object.entries(grupos).map(([grupo, lista]) => (
                            <div key={grupo}>
                                <h2 className="font-brand text-primary mb-2 text-base font-bold">{grupo}</h2>
                                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                    {lista.map((e) => (
                                        <Card key={e.clave}>
                                            <CardContent className="flex h-full flex-col gap-2 p-4">
                                                <div className="font-semibold">{e.titulo}</div>
                                                <p className="text-muted-foreground flex-1 text-sm">{e.descripcion}</p>
                                                <div className="text-muted-foreground text-xs">
                                                    {e.porPeriodo
                                                        ? todo
                                                            ? 'Todos los registros'
                                                            : informe.periodo.etiqueta
                                                        : 'Estado actual (completo)'}
                                                </div>
                                                {canGenerate && (
                                                    <Button variant="outline" size="sm" asChild className="gap-2 self-start">
                                                        <a
                                                            href={route('reportes.exportar', {
                                                                clave: e.clave,
                                                                desde: periodo.desde,
                                                                hasta: periodo.hasta,
                                                                ...(todo ? { todo: 1 } : {}),
                                                            })}
                                                        >
                                                            <FileSpreadsheet className="size-4" /> Descargar Excel
                                                        </a>
                                                    </Button>
                                                )}
                                            </CardContent>
                                        </Card>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
