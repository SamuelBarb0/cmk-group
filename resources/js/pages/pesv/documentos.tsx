import { CodigoSig } from '@/components/codigo-sig';
import { ChipDocumento } from '@/components/pesv/requisitos';
import { SinCliente, StatCard } from '@/components/pesv/shared';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { CircleAlert, CircleCheck, CircleDashed, Gauge } from 'lucide-react';
import { useState } from 'react';

interface Fila {
    tipo: 'conductor' | 'vehiculo';
    id: number;
    nombre: string;
    detalle: string | null;
    documentos: { documento: string; vence: string | null; dias: number | null; estado: string }[];
}
type Props =
    | { needsClient: true }
    | {
          needsClient: false;
          filas: Fila[];
          resumen: { V: number; P: number; E: number; sin_dato: number; cumplimiento: number | null };
          reglas: { no_cumple: number; por_vencer: number };
      };

const breadcrumbs = [
    { title: 'PESV', href: '/pesv' },
    { title: 'Semáforo de documentos', href: '/pesv/documentos' },
];

export default function PesvDocumentos(props: Props) {
    const [soloAlertas, setSoloAlertas] = useState(false);
    if (props.needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Semáforo de documentos" />
                <SinCliente titulo="Semáforo de documentos" descripcion="Vencimientos de conductores y vehículos del PESV." />
            </AppLayout>
        );
    }
    const { filas, resumen, reglas } = props;
    const visibles = soloAlertas ? filas.filter((f) => f.documentos.some((d) => d.estado === 'E' || d.estado === 'P')) : filas;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Semáforo de documentos" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="font-brand text-2xl font-bold tracking-tight">
                        <CodigoSig className="mr-2" />
                        Semáforo de documentos
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Seguimiento a documentos de conductores y vehículos (RE-SST-54). E: vence en menos de {reglas.no_cumple} días o ya venció · P:
                        vence en menos de {reglas.por_vencer} días · V: vigente.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Cumplimiento (V + P)" value={resumen.cumplimiento === null ? '—' : `${resumen.cumplimiento} %`} icon={Gauge} />
                    <StatCard label="Vigentes (V)" value={resumen.V} icon={CircleCheck} />
                    <StatCard label="Por vencer (P)" value={resumen.P} icon={CircleDashed} />
                    <StatCard label="No cumplen (E)" value={resumen.E} icon={CircleAlert} danger={resumen.E > 0} />
                </div>
                {resumen.sin_dato > 0 && (
                    <p className="text-muted-foreground text-sm">
                        {resumen.sin_dato} documento(s) sin fecha registrada: no cuentan en el cumplimiento. Complétalos en la ficha del conductor o
                        del vehículo.
                    </p>
                )}

                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={soloAlertas} onChange={(e) => setSoloAlertas(e.target.checked)} />
                    Ver solo los que tienen documentos por vencer o que no cumplen
                </label>

                <Card className="overflow-hidden">
                    {visibles.length === 0 ? (
                        <p className="text-muted-foreground p-6 text-center text-sm">
                            {filas.length === 0 ? 'No hay conductores ni vehículos activos.' : 'Todo al día.'}
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {visibles.map((f) => (
                                <li key={`${f.tipo}-${f.id}`} className="flex flex-col gap-2 p-3 md:flex-row md:items-center">
                                    <div className="min-w-48">
                                        <Link
                                            href={f.tipo === 'conductor' ? `/pesv/conductores/${f.id}` : `/pesv/vehiculos/${f.id}`}
                                            className="font-medium hover:underline"
                                        >
                                            {f.nombre}
                                        </Link>
                                        <div className="text-muted-foreground text-xs">
                                            {f.tipo === 'conductor' ? 'Conductor' : 'Vehículo'}
                                            {f.detalle ? ` · ${f.detalle}` : ''}
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap gap-1.5">
                                        {f.documentos.map((d) => (
                                            <ChipDocumento key={d.documento} documento={d.documento} vence={d.vence} estado={d.estado} />
                                        ))}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
