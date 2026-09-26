import { CodigoSig } from '@/components/codigo-sig';
import InputError from '@/components/input-error';
import { Notice, SinCliente, StatCard, textoVencimiento, useNotice } from '@/components/pesv/shared';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Contact, IdCard, Pencil, TriangleAlert, Users } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Alerta {
    documento: string;
    vence: string;
    dias: number;
}

interface Colaborador {
    id: number;
    nombre_completo: string;
    numero_documento: string;
    cargo: string | null;
    area: string | null;
    sede: string | null;
    es_conductor: boolean;
    licencia_numero: string | null;
    licencia_categoria: string | null;
    licencia_vence: string | null;
    examen_psicosensometrico_vence: string | null;
    curso_manejo_defensivo: string | null;
    observaciones_conductor: string | null;
    alertas: Alerta[];
}

interface Props {
    needsClient: boolean;
    colaboradores: Colaborador[];
    stats: { total: number; conductores: number; sin_licencia: number; con_alertas: number } | null;
    categorias: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'PESV', href: '/pesv' },
    { title: 'Colaboradores', href: '/pesv/colaboradores' },
];

export default function PesvColaboradores({ needsClient, colaboradores, stats, categorias }: Props) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const page = usePage<SharedData>();
    const notice = useNotice(page.props.flash?.success);

    const [open, setOpen] = useState(false);
    const [editando, setEditando] = useState<Colaborador | null>(null);
    const [soloConductores, setSoloConductores] = useState(false);

    const { data, setData, put, processing, errors, clearErrors } = useForm({
        es_conductor: false as boolean,
        licencia_numero: '',
        licencia_categoria: '',
        licencia_vence: '',
        examen_psicosensometrico_vence: '',
        curso_manejo_defensivo: '',
        observaciones_conductor: '',
    });

    if (needsClient || !stats) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Colaboradores PESV" />
                <SinCliente titulo="Colaboradores" descripcion="Colaboradores y conductores (PESV, Paso 5)." />
            </AppLayout>
        );
    }

    function abrir(c: Colaborador) {
        setEditando(c);
        clearErrors();
        setData({
            es_conductor: c.es_conductor,
            licencia_numero: c.licencia_numero ?? '',
            licencia_categoria: c.licencia_categoria ?? '',
            licencia_vence: c.licencia_vence ?? '',
            examen_psicosensometrico_vence: c.examen_psicosensometrico_vence ?? '',
            curso_manejo_defensivo: c.curso_manejo_defensivo ?? '',
            observaciones_conductor: c.observaciones_conductor ?? '',
        });
        setOpen(true);
    }

    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        if (!editando) return;
        put(`/pesv/colaboradores/${editando.id}`, { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    const lista = soloConductores ? colaboradores.filter((c) => c.es_conductor) : colaboradores;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Colaboradores PESV" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="font-brand text-2xl font-bold tracking-tight">
                        <CodigoSig className="mr-2" />
                        Colaboradores y conductores
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        Sale de los empleados que ya cargaste. Aquí solo marcas quién conduce y completas su ficha.
                    </p>
                </div>

                <Notice mensaje={notice} />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Colaboradores activos" value={stats.total} icon={Users} />
                    <StatCard label="Conductores" value={stats.conductores} icon={Contact} />
                    <StatCard label="Sin licencia registrada" value={stats.sin_licencia} icon={IdCard} danger={stats.sin_licencia > 0} />
                    <StatCard label="Con documentos por vencer" value={stats.con_alertas} icon={TriangleAlert} danger={stats.con_alertas > 0} />
                </div>

                {colaboradores.length === 0 ? (
                    <Card>
                        <CardContent className="flex min-h-40 flex-col items-center justify-center gap-3 text-center">
                            <p className="text-muted-foreground text-sm">
                                Esta empresa no tiene empleados cargados. El PESV se alimenta de esa lista.
                            </p>
                            <Button asChild variant="outline">
                                <Link href="/empleados">Ir a Empleados</Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <div className="flex items-center justify-between gap-3 border-b p-3">
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox checked={soloConductores} onCheckedChange={(v) => setSoloConductores(v === true)} />
                                    Ver solo conductores
                                </label>
                                <span className="text-muted-foreground text-sm">{lista.length} personas</span>
                            </div>

                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="p-3 font-medium">Colaborador</th>
                                            <th className="p-3 font-medium">Cargo</th>
                                            <th className="p-3 font-medium">Conduce</th>
                                            <th className="p-3 font-medium">Licencia</th>
                                            <th className="p-3 font-medium">Vencimientos</th>
                                            {canManage && <th className="p-3" />}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-border divide-y">
                                        {lista.map((c) => (
                                            <tr key={c.id}>
                                                <td className="p-3">
                                                    {c.es_conductor ? (
                                                        <Link href={`/pesv/conductores/${c.id}`} className="font-medium hover:underline">
                                                            {c.nombre_completo}
                                                        </Link>
                                                    ) : (
                                                        <div className="font-medium">{c.nombre_completo}</div>
                                                    )}
                                                    <div className="text-muted-foreground text-xs">C.C. {c.numero_documento}</div>
                                                </td>
                                                <td className="p-3">
                                                    {c.cargo ?? '—'}
                                                    {c.sede && <div className="text-muted-foreground text-xs">{c.sede}</div>}
                                                </td>
                                                <td className="p-3">
                                                    {c.es_conductor ? (
                                                        <Badge variant="secondary" className="bg-emerald-600/15 text-emerald-700">
                                                            Sí
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-muted-foreground">No</span>
                                                    )}
                                                </td>
                                                <td className="p-3">
                                                    {c.es_conductor ? (
                                                        c.licencia_numero ? (
                                                            <>
                                                                {c.licencia_numero}
                                                                {c.licencia_categoria && (
                                                                    <span className="text-muted-foreground"> · {c.licencia_categoria}</span>
                                                                )}
                                                            </>
                                                        ) : (
                                                            <span className="text-red-600">Sin registrar</span>
                                                        )
                                                    ) : (
                                                        <span className="text-muted-foreground">—</span>
                                                    )}
                                                </td>
                                                <td className="p-3">
                                                    {c.alertas.length === 0 ? (
                                                        <span className="text-muted-foreground">{c.es_conductor ? 'Al día' : '—'}</span>
                                                    ) : (
                                                        <div className="flex flex-wrap gap-1">
                                                            {c.alertas.map((a) => (
                                                                <Badge
                                                                    key={a.documento}
                                                                    variant="secondary"
                                                                    className={cn(
                                                                        a.dias < 0 ? 'bg-red-600/15 text-red-700' : 'bg-amber-500/15 text-amber-700',
                                                                    )}
                                                                >
                                                                    {a.documento} {textoVencimiento(a.dias)}
                                                                </Badge>
                                                            ))}
                                                        </div>
                                                    )}
                                                </td>
                                                {canManage && (
                                                    <td className="p-3 text-right">
                                                        <Button variant="ghost" size="icon" onClick={() => abrir(c)}>
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                    </td>
                                                )}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <form onSubmit={enviar}>
                        <DialogHeader>
                            <DialogTitle>Ficha de conductor · {editando?.nombre_completo}</DialogTitle>
                        </DialogHeader>

                        <div className="grid gap-4 py-4">
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={data.es_conductor} onCheckedChange={(v) => setData('es_conductor', v === true)} />
                                Este colaborador conduce vehículos de la empresa
                            </label>

                            {data.es_conductor && (
                                <>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="grid gap-2">
                                            <Label htmlFor="licencia_numero">Número de licencia</Label>
                                            <Input
                                                id="licencia_numero"
                                                value={data.licencia_numero}
                                                onChange={(e) => setData('licencia_numero', e.target.value)}
                                            />
                                            <InputError message={errors.licencia_numero} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="licencia_categoria">Categoría</Label>
                                            <select
                                                id="licencia_categoria"
                                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                                value={data.licencia_categoria}
                                                onChange={(e) => setData('licencia_categoria', e.target.value)}
                                            >
                                                <option value="">Sin especificar</option>
                                                {categorias.map((c) => (
                                                    <option key={c} value={c}>
                                                        {c}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="grid gap-2">
                                            <Label htmlFor="licencia_vence">Licencia vence</Label>
                                            <Input
                                                id="licencia_vence"
                                                type="date"
                                                value={data.licencia_vence}
                                                onChange={(e) => setData('licencia_vence', e.target.value)}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="examen_psicosensometrico_vence">Psicosensométrico vence</Label>
                                            <Input
                                                id="examen_psicosensometrico_vence"
                                                type="date"
                                                value={data.examen_psicosensometrico_vence}
                                                onChange={(e) => setData('examen_psicosensometrico_vence', e.target.value)}
                                            />
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="curso_manejo_defensivo">Curso de manejo defensivo</Label>
                                        <Input
                                            id="curso_manejo_defensivo"
                                            type="date"
                                            value={data.curso_manejo_defensivo}
                                            onChange={(e) => setData('curso_manejo_defensivo', e.target.value)}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="observaciones_conductor">Observaciones</Label>
                                        <textarea
                                            id="observaciones_conductor"
                                            rows={2}
                                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                            value={data.observaciones_conductor}
                                            onChange={(e) => setData('observaciones_conductor', e.target.value)}
                                        />
                                    </div>
                                </>
                            )}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                Guardar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
