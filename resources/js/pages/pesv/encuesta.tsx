import { CodigoSig } from '@/components/codigo-sig';
import { EncuestaForm, type Respuestas, type Seccion } from '@/components/pesv/encuesta-form';
import { Notice, SinCliente, StatCard, useNotice } from '@/components/pesv/shared';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ClipboardList, Copy, Link2, Plus, Trash2, Users } from 'lucide-react';
import { useState } from 'react';

interface Bloque {
    clave: string;
    texto: string;
    tipo: string;
    respondieron: number;
    promedio?: number;
    opciones?: { opcion: string; cantidad: number; porcentaje: number }[];
}
type Props =
    | { needsClient: true }
    | {
          needsClient: false;
          anio: number;
          enlace: { url: string | null; activa: boolean };
          respuestas: { id: number; fecha: string; nombre: string; documento: string; origen: string; vinculado: boolean; rol: string }[];
          cobertura: { respuestas: number; colaboradores: number };
          tabulacion: Bloque[];
          secciones: Seccion[];
          analisis: string | null;
      };

const breadcrumbs = [
    { title: 'PESV', href: '/pesv' },
    { title: 'Encuesta de movilidad', href: '/pesv/encuesta' },
];

export default function PesvEncuesta(props: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const page = usePage<SharedData>();
    const notice = useNotice(page.props.flash?.success);
    const errores = page.props.errors as Record<string, string>;
    const [copiado, setCopiado] = useState(false);
    const [manual, setManual] = useState(false);
    const [valores, setValores] = useState<Respuestas>({});
    const analisis = useForm({ analisis: props.needsClient ? '' : (props.analisis ?? '') });

    if (props.needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Encuesta de movilidad" />
                <SinCliente titulo="Encuesta de movilidad" descripcion="Diagnóstico del PESV (paso 5)." />
            </AppLayout>
        );
    }
    const { anio, enlace, respuestas, cobertura, tabulacion, secciones } = props;
    const pct = cobertura.colaboradores ? Math.round((cobertura.respuestas * 100) / cobertura.colaboradores) : null;

    function copiar() {
        if (!enlace.url) return;
        navigator.clipboard?.writeText(enlace.url).then(() => {
            setCopiado(true);
            setTimeout(() => setCopiado(false), 2000);
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Encuesta de movilidad" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            <CodigoSig className="mr-2" />
                            Encuesta de movilidad
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Paso 5 · Caracterización de movilidad (RE-SST-36) y su tabulación (RE-SST-37).
                        </p>
                    </div>
                    <select
                        aria-label="Año"
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={anio}
                        onChange={(e) => router.get('/pesv/encuesta', { anio: e.target.value })}
                    >
                        {[0, 1, 2].map((d) => (
                            <option key={d}>{new Date().getFullYear() - d}</option>
                        ))}
                    </select>
                </div>

                <Notice mensaje={notice} />

                {/* Enlace público */}
                <Card>
                    <CardContent className="space-y-3 p-5">
                        <h2 className="flex items-center gap-2 font-semibold">
                            <Link2 className="size-4" /> Enlace para los trabajadores
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            Cada trabajador la responde desde el celular, sin cuenta en la plataforma. Si una persona responde dos veces en el año,
                            queda la última.
                        </p>
                        {enlace.url ? (
                            <div className="flex flex-wrap items-center gap-2">
                                <Input readOnly value={enlace.url} className="min-w-0 flex-1 font-mono text-xs" aria-label="Enlace de la encuesta" />
                                <Button variant="outline" size="sm" className="gap-1.5" onClick={copiar}>
                                    <Copy className="size-4" /> {copiado ? 'Copiado' : 'Copiar'}
                                </Button>
                                {canManage && (
                                    <>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => router.patch('/pesv/encuesta/enlace', {}, { preserveScroll: true })}
                                        >
                                            {enlace.activa ? 'Cerrar encuesta' : 'Abrir encuesta'}
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                confirm('¿Generar un enlace nuevo? El actual dejará de funcionar.') &&
                                                router.post('/pesv/encuesta/enlace', {}, { preserveScroll: true })
                                            }
                                        >
                                            Renovar enlace
                                        </Button>
                                    </>
                                )}
                                <span className={enlace.activa ? 'text-sm text-emerald-700' : 'text-sm text-amber-700'}>
                                    {enlace.activa ? 'Recibiendo respuestas' : 'Cerrada'}
                                </span>
                            </div>
                        ) : canManage ? (
                            <Button className="gap-2" onClick={() => router.post('/pesv/encuesta/enlace', {}, { preserveScroll: true })}>
                                <Link2 className="size-4" /> Generar enlace
                            </Button>
                        ) : (
                            <p className="text-muted-foreground text-sm">Todavía no hay enlace.</p>
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard label={`Respuestas ${anio}`} value={cobertura.respuestas} icon={ClipboardList} />
                    <StatCard label="Colaboradores activos" value={cobertura.colaboradores} icon={Users} />
                    <StatCard label="Cobertura" value={pct === null ? '—' : `${pct} %`} icon={Users} />
                </div>

                {/* Tabulación */}
                <Card>
                    <CardContent className="space-y-5 p-5">
                        <h2 className="font-semibold">Tabulación</h2>
                        {respuestas.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Sin respuestas en {anio}.</p>
                        ) : (
                            <div className="grid gap-5 lg:grid-cols-2">
                                {tabulacion
                                    .filter((b) => b.respondieron > 0)
                                    .map((b) => (
                                        <div key={b.clave} className="space-y-1.5">
                                            <p className="text-sm font-medium">{b.texto}</p>
                                            <p className="text-muted-foreground text-xs">
                                                {b.respondieron} respuesta(s){b.promedio !== undefined ? ` · promedio ${b.promedio}` : ''}
                                            </p>
                                            {b.opciones?.map((o) => (
                                                <div key={o.opcion} className="grid grid-cols-[minmax(0,1fr)_5.5rem] items-center gap-2 text-xs">
                                                    <div className="bg-muted relative h-5 overflow-hidden rounded">
                                                        <div
                                                            className="bg-primary/70 absolute inset-y-0 left-0"
                                                            style={{ width: `${o.porcentaje}%` }}
                                                        />
                                                        <span className="relative px-1.5 leading-5">{o.opcion}</span>
                                                    </div>
                                                    <span className="text-muted-foreground text-right tabular-nums">
                                                        {o.cantidad} · {o.porcentaje} %
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Análisis */}
                <Card>
                    <CardContent className="space-y-3 p-5">
                        <h2 className="font-semibold">Análisis del diagnóstico</h2>
                        <p className="text-muted-foreground text-sm">Conclusiones de la caracterización: qué riesgos priorizar y por qué.</p>
                        <textarea
                            aria-label="Análisis del diagnóstico"
                            rows={5}
                            disabled={!canManage}
                            className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                            value={analisis.data.analisis}
                            onChange={(e) => analisis.setData('analisis', e.target.value)}
                        />
                        {canManage && (
                            <div className="flex justify-end">
                                <Button
                                    size="sm"
                                    disabled={analisis.processing}
                                    onClick={() => analisis.put('/pesv/encuesta/analisis', { preserveScroll: true })}
                                >
                                    Guardar análisis
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Respuestas */}
                <Card>
                    <CardContent className="space-y-3 p-5">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="font-semibold">Respuestas</h2>
                            {canManage && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="gap-1.5"
                                    onClick={() => {
                                        setValores({});
                                        setManual(true);
                                    }}
                                >
                                    <Plus className="size-4" /> Registrar respuesta
                                </Button>
                            )}
                        </div>
                        {respuestas.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Aún no hay respuestas.</p>
                        ) : (
                            <ul className="divide-y rounded-lg border text-sm">
                                {respuestas.map((r) => (
                                    <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                        <span>
                                            <span className="font-medium">{r.nombre}</span>
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {r.documento} · {r.fecha} · {r.origen === 'enlace' ? 'por enlace' : 'registrada por el consultor'}
                                                {r.rol ? ` · ${r.rol}` : ''}
                                                {!r.vinculado && ' · no está en Empleados'}
                                            </span>
                                        </span>
                                        {canManage && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label="Eliminar respuesta"
                                                onClick={() =>
                                                    confirm(`¿Eliminar la respuesta de ${r.nombre}?`) &&
                                                    router.delete(`/pesv/encuesta/respuestas/${r.id}`, { preserveScroll: true })
                                                }
                                            >
                                                <Trash2 className="size-4 text-red-600" />
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Dialog open={manual} onOpenChange={setManual}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Registrar respuesta de un trabajador</DialogTitle>
                    </DialogHeader>
                    <EncuestaForm secciones={secciones} valores={valores} onChange={setValores} errores={errores} />
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setManual(false)}>
                            Cancelar
                        </Button>
                        <Button
                            onClick={() =>
                                router.post('/pesv/encuesta/respuestas', { respuestas: valores } as never, {
                                    preserveScroll: true,
                                    onSuccess: () => setManual(false),
                                })
                            }
                        >
                            Guardar respuesta
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
