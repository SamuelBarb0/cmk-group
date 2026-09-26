import { CodigoSig } from '@/components/codigo-sig';
import InputError from '@/components/input-error';
import { ChipDocumento, ListaRequisitos, RESULTADO, type Catalogo } from '@/components/pesv/requisitos';
import { Notice, useNotice } from '@/components/pesv/shared';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    conductor: {
        id: number;
        nombres: string;
        apellidos: string;
        numero_documento: string | null;
        cargo: string | null;
        area: string | null;
        es_conductor: boolean;
        licencia_numero: string | null;
        licencia_categoria: string | null;
        licencia_vence: string | null;
        examen_psicosensometrico_vence: string | null;
        fecha_ingreso: string | null;
    };
    documentos: { documento: string; vence: string | null; estado: string }[];
    requisitos: {
        catalogo: Catalogo;
        respuestas: Record<string, { estado: string; obs: string | null }>;
        resultado: string;
        fecha: string | null;
        placa_asignada: string | null;
        verificado_por: string | null;
        observaciones: string | null;
    };
    pruebas: {
        id: number;
        tipo: string;
        fecha: string;
        puntaje: number | null;
        resultado: string;
        vigente_hasta: string | null;
        evaluador: string | null;
        observaciones: string | null;
    }[];
    infracciones: { id: number; codigo: string; descripcion: string | null; estado: string; fecha: string; valor: number | null }[];
    tiposPrueba: Record<string, string>;
    puntajeMinimo: number;
    estadosInfraccion: Record<string, string>;
    placas: string[];
}

export default function PesvConductor({
    conductor,
    documentos,
    requisitos,
    pruebas,
    infracciones,
    tiposPrueba,
    puntajeMinimo,
    estadosInfraccion,
    placas,
}: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const notice = useNotice(usePage<SharedData>().props.flash?.success);
    const nombre = `${conductor.nombres} ${conductor.apellidos}`.trim();
    const hoy = new Date().toISOString().slice(0, 10);

    const prueba = useForm({ tipo: 'teorica', fecha: hoy, puntaje: '', resultado: '', vigente_hasta: '', evaluador: '', observaciones: '' });
    const conPuntaje = prueba.data.tipo !== 'psicosensometrica';
    const registrarPrueba: FormEventHandler = (e) => {
        e.preventDefault();
        prueba.post(`/pesv/conductores/${conductor.id}/pruebas`, {
            preserveScroll: true,
            onSuccess: () => prueba.reset('puntaje', 'resultado', 'vigente_hasta', 'observaciones'),
        });
    };

    const r = RESULTADO[requisitos.resultado] ?? RESULTADO.pendiente;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'PESV', href: '/pesv' },
                { title: 'Colaboradores', href: '/pesv/colaboradores' },
                { title: nombre, href: `/pesv/conductores/${conductor.id}` },
            ]}
        >
            <Head title={`Conductor · ${nombre}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-muted-foreground text-sm">PESV · Paso 11 · Responsabilidad y comportamiento seguro</p>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            <CodigoSig className="mr-2" />
                            {nombre}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {conductor.numero_documento ? `C.C. ${conductor.numero_documento} · ` : ''}
                            {conductor.cargo ?? 'Sin cargo'}
                            {conductor.licencia_categoria ? ` · Licencia ${conductor.licencia_categoria}` : ''}
                            {conductor.fecha_ingreso ? ` · Ingresó el ${conductor.fecha_ingreso}` : ''}
                        </p>
                        {!conductor.es_conductor && (
                            <p className="mt-1 text-sm text-amber-700 dark:text-amber-400">
                                No está marcado como conductor en su ficha de colaborador.
                            </p>
                        )}
                    </div>
                    <Button asChild variant="outline" size="sm" className="gap-1">
                        <Link href="/pesv/colaboradores">
                            <ArrowLeft className="size-4" /> Colaboradores
                        </Link>
                    </Button>
                </div>

                <Notice mensaje={notice} />

                <div className="flex flex-wrap gap-2">
                    {documentos.map((d) => (
                        <ChipDocumento key={d.documento} {...d} />
                    ))}
                </div>

                {/* Requisitos del operador */}
                <Card>
                    <CardContent className="space-y-4 p-5">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <h2 className="font-semibold">Requisitos del operador</h2>
                                <p className="text-muted-foreground text-sm">
                                    Lista de chequeo RE-SST-51.
                                    {requisitos.verificado_por && ` Última verificación: ${requisitos.fecha} por ${requisitos.verificado_por}.`}
                                </p>
                            </div>
                            <span className={cn('rounded-md px-2 py-1 text-xs font-semibold', r.clase)}>{r.texto}</span>
                        </div>
                        <ListaRequisitos
                            catalogo={requisitos.catalogo}
                            respuestas={requisitos.respuestas}
                            fecha={requisitos.fecha}
                            observaciones={requisitos.observaciones}
                            placa={requisitos.placa_asignada}
                            placas={placas}
                            url={`/pesv/conductores/${conductor.id}/requisitos`}
                            canManage={canManage}
                            marca="hasta un mes después del ingreso"
                        />
                    </CardContent>
                </Card>

                {/* Pruebas de idoneidad */}
                <Card>
                    <CardContent className="space-y-4 p-5">
                        <div>
                            <h2 className="font-semibold">Pruebas de idoneidad</h2>
                            <p className="text-muted-foreground text-sm">
                                Teórica y práctica con puntaje (más de {puntajeMinimo} % es apto); psicosensométrica con el concepto del CRC, que
                                actualiza su vencimiento en la ficha.
                            </p>
                        </div>
                        {pruebas.length > 0 && (
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-muted-foreground text-left">
                                        <tr>
                                            <th className="px-3 py-2 font-medium">Fecha</th>
                                            <th className="px-3 py-2 font-medium">Prueba</th>
                                            <th className="px-3 py-2 font-medium">Puntaje</th>
                                            <th className="px-3 py-2 font-medium">Resultado</th>
                                            <th className="px-3 py-2 font-medium">Vigente hasta</th>
                                            <th className="px-3 py-2 font-medium">Evaluador</th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {pruebas.map((p) => (
                                            <tr key={p.id}>
                                                <td className="px-3 py-1.5 whitespace-nowrap">{p.fecha}</td>
                                                <td className="px-3 py-1.5">{tiposPrueba[p.tipo] ?? p.tipo}</td>
                                                <td className="px-3 py-1.5">{p.puntaje !== null ? `${p.puntaje} %` : '—'}</td>
                                                <td className="px-3 py-1.5">
                                                    <span className={cn('font-medium', p.resultado === 'apto' ? 'text-emerald-700' : 'text-red-700')}>
                                                        {p.resultado === 'apto' ? 'Apto' : 'No apto'}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-1.5">{p.vigente_hasta ?? '—'}</td>
                                                <td className="px-3 py-1.5">{p.evaluador ?? '—'}</td>
                                                <td className="px-3 py-1.5 text-right">
                                                    {canManage && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label="Eliminar prueba"
                                                            onClick={() =>
                                                                confirm('¿Eliminar esta prueba?') &&
                                                                router.delete(`/pesv/pruebas/${p.id}`, { preserveScroll: true })
                                                            }
                                                        >
                                                            <Trash2 className="size-4 text-red-600" />
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        {canManage && (
                            <form onSubmit={registrarPrueba} className="grid gap-3 rounded-lg border p-3 md:grid-cols-6">
                                <div className="grid gap-1.5 md:col-span-2">
                                    <Label htmlFor="p_tipo">Prueba</Label>
                                    <select
                                        id="p_tipo"
                                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                        value={prueba.data.tipo}
                                        onChange={(e) => prueba.setData('tipo', e.target.value)}
                                    >
                                        {Object.entries(tiposPrueba).map(([v, l]) => (
                                            <option key={v} value={v}>
                                                {l}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="p_fecha">Fecha</Label>
                                    <Input
                                        id="p_fecha"
                                        type="date"
                                        max={hoy}
                                        value={prueba.data.fecha}
                                        onChange={(e) => prueba.setData('fecha', e.target.value)}
                                    />
                                </div>
                                {conPuntaje ? (
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="p_puntaje">Puntaje (%)</Label>
                                        <Input
                                            id="p_puntaje"
                                            type="number"
                                            min={0}
                                            max={100}
                                            step="0.1"
                                            value={prueba.data.puntaje}
                                            onChange={(e) => prueba.setData('puntaje', e.target.value)}
                                        />
                                    </div>
                                ) : (
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="p_resultado">Concepto</Label>
                                        <select
                                            id="p_resultado"
                                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                            value={prueba.data.resultado}
                                            onChange={(e) => prueba.setData('resultado', e.target.value)}
                                        >
                                            <option value="">—</option>
                                            <option value="apto">Apto</option>
                                            <option value="no_apto">No apto</option>
                                        </select>
                                    </div>
                                )}
                                <div className="grid gap-1.5">
                                    <Label htmlFor="p_vigencia">Vigente hasta</Label>
                                    <Input
                                        id="p_vigencia"
                                        type="date"
                                        value={prueba.data.vigente_hasta}
                                        onChange={(e) => prueba.setData('vigente_hasta', e.target.value)}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="p_evaluador">Evaluador</Label>
                                    <Input
                                        id="p_evaluador"
                                        value={prueba.data.evaluador}
                                        onChange={(e) => prueba.setData('evaluador', e.target.value)}
                                    />
                                </div>
                                <div className="flex flex-col gap-1 md:col-span-6">
                                    <InputError
                                        message={
                                            prueba.errors.puntaje ?? prueba.errors.resultado ?? prueba.errors.fecha ?? prueba.errors.vigente_hasta
                                        }
                                    />
                                    <Button type="submit" size="sm" className="self-end" disabled={prueba.processing}>
                                        Registrar prueba
                                    </Button>
                                </div>
                            </form>
                        )}
                    </CardContent>
                </Card>

                {/* Comparendos */}
                <Card>
                    <CardContent className="space-y-3 p-5">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="font-semibold">Infracciones de tránsito</h2>
                            <Button asChild variant="outline" size="sm">
                                <Link href="/pesv/infracciones">Ir al seguimiento de infracciones</Link>
                            </Button>
                        </div>
                        {infracciones.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Sin infracciones registradas.</p>
                        ) : (
                            <ul className="divide-y rounded-lg border text-sm">
                                {infracciones.map((i) => (
                                    <li key={i.id} className="flex flex-wrap justify-between gap-2 px-3 py-2">
                                        <span>
                                            <span className="font-mono font-semibold">{i.codigo}</span> {i.descripcion ?? ''} · {i.fecha}
                                        </span>
                                        <span className="text-muted-foreground">{estadosInfraccion[i.estado] ?? i.estado}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
