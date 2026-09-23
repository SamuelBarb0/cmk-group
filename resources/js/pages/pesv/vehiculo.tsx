import { ChipDocumento, ListaRequisitos, RESULTADO } from '@/components/pesv/requisitos';
import { Notice, useNotice } from '@/components/pesv/shared';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

interface Props {
    vehiculo: {
        id: number;
        placa: string;
        tipo: string;
        marca: string | null;
        linea: string | null;
        modelo: number | null;
        propiedad: string;
        propietario: string | null;
        kilometraje: number | null;
        is_active: boolean;
        ultimo_mantenimiento: string | null;
    };
    documentos: { documento: string; vence: string | null; estado: string }[];
    requisitos: {
        catalogo: Record<string, [string, boolean?]>;
        respuestas: Record<string, { estado: string; obs: string | null }>;
        resultado: string;
        fecha: string | null;
        verificado_por: string | null;
        observaciones: string | null;
    };
    historial: {
        mantenimientos: { fecha: string; tipo: string; descripcion: string | null }[];
        mantenimiento_url: string | null;
        siniestros: { fecha: string; tipo: string; gravedad: string }[];
        infracciones: number;
    };
}

export default function PesvVehiculo({ vehiculo, documentos, requisitos, historial }: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const notice = useNotice(usePage<SharedData>().props.flash?.success);
    const r = RESULTADO[requisitos.resultado] ?? RESULTADO.pendiente;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'PESV', href: '/pesv' },
                { title: 'Vehículos', href: '/pesv/vehiculos' },
                { title: vehiculo.placa, href: `/pesv/vehiculos/${vehiculo.id}` },
            ]}
        >
            <Head title={`Vehículo · ${vehiculo.placa}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-muted-foreground text-sm">PESV · Hoja de vida del vehículo</p>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">{vehiculo.placa}</h1>
                        <p className="text-muted-foreground text-sm capitalize">
                            {[vehiculo.tipo, vehiculo.marca, vehiculo.linea, vehiculo.modelo].filter(Boolean).join(' · ')} · {vehiculo.propiedad}
                            {vehiculo.propietario ? ` (${vehiculo.propietario})` : ''}
                            {vehiculo.kilometraje ? ` · ${vehiculo.kilometraje.toLocaleString('es-CO')} km` : ''}
                        </p>
                    </div>
                    <Button asChild variant="outline" size="sm" className="gap-1">
                        <Link href="/pesv/vehiculos">
                            <ArrowLeft className="size-4" /> Vehículos
                        </Link>
                    </Button>
                </div>

                <Notice mensaje={notice} />

                <div className="flex flex-wrap gap-2">
                    {documentos.map((d) => (
                        <ChipDocumento key={d.documento} {...d} />
                    ))}
                </div>

                <Card>
                    <CardContent className="space-y-4 p-5">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <h2 className="font-semibold">Requisitos del vehículo</h2>
                                <p className="text-muted-foreground text-sm">
                                    Lista de verificación RE-SST-50.
                                    {requisitos.verificado_por && ` Última verificación: ${requisitos.fecha} por ${requisitos.verificado_por}.`}
                                </p>
                            </div>
                            <span className={cn('rounded-md px-2 py-1 text-xs font-semibold', r.clase)}>
                                {requisitos.resultado === 'cumple' ? 'Aprobado' : requisitos.resultado === 'no_cumple' ? 'No aprobado' : r.texto}
                            </span>
                        </div>
                        <ListaRequisitos
                            catalogo={{ Requisitos: requisitos.catalogo }}
                            respuestas={requisitos.respuestas}
                            fecha={requisitos.fecha}
                            observaciones={requisitos.observaciones}
                            url={`/pesv/vehiculos/${vehiculo.id}/requisitos`}
                            canManage={canManage}
                            marca="cuando aplique"
                        />
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardContent className="space-y-2 p-5">
                            <div className="flex items-center justify-between">
                                <h2 className="font-semibold">Mantenimientos</h2>
                                {historial.mantenimiento_url && (
                                    <Link href={historial.mantenimiento_url} className="text-primary text-sm hover:underline">
                                        Ver en Mantenimiento
                                    </Link>
                                )}
                            </div>
                            {!historial.mantenimiento_url ? (
                                <p className="text-muted-foreground text-sm">
                                    El vehículo no está enlazado a un activo de Mantenimiento. Desde Mantenimiento se traen los vehículos del PESV.
                                </p>
                            ) : historial.mantenimientos.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Sin mantenimientos registrados.</p>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {historial.mantenimientos.map((m, i) => (
                                        <li key={i} className="py-1.5">
                                            {m.fecha} · <span className="capitalize">{m.tipo}</span>
                                            {m.descripcion ? ` · ${m.descripcion}` : ''}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="space-y-2 p-5">
                            <h2 className="font-semibold">Siniestros y comparendos</h2>
                            {historial.siniestros.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Sin siniestros viales registrados.</p>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {historial.siniestros.map((s, i) => (
                                        <li key={i} className="py-1.5 capitalize">
                                            {s.fecha} · {s.tipo.replace('_', ' ')} · {s.gravedad.replace('_', ' ')}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <p className="text-muted-foreground text-sm">{historial.infracciones} comparendo(s) asociado(s) a este vehículo.</p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
