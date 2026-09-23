import InputError from '@/components/input-error';
import { Notice, SinCliente, useNotice } from '@/components/pesv/shared';
import { SeccionCard, type Informe } from '@/components/reportes/seccion-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, FileDown, FileText, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';

type Auditor = { nombre: string; cargo: string | null; email: string | null };
type Datos = {
    lider_email: string | null;
    auditores: Auditor[];
    objetivos_siguiente: string | null;
    programas_siguiente: string | null;
    analisis: string | null;
    entidad_verificadora: string | null;
    reportado_at: string | null;
    radicado: string | null;
};
type Props =
    | { needsClient: true }
    | {
          needsClient: false;
          anio: number;
          informe: Informe;
          datos: Datos;
          entidades: Record<string, string>;
          entidadSugerida: string;
          historial: { anio: number; reportado_at: string | null; entidad_verificadora: string | null; radicado: string | null }[];
      };

const breadcrumbs = [
    { title: 'PESV', href: '/pesv' },
    { title: 'Reporte de autogestión', href: '/pesv/autogestion' },
];
const textarea = 'border-input bg-background min-h-20 rounded-md border px-3 py-2 text-sm';

export default function PesvAutogestion(props: Props) {
    if (props.needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Reporte de autogestión" />
                <SinCliente titulo="Reporte de autogestión del PESV" descripcion="Paso 20 del PESV." />
            </AppLayout>
        );
    }

    // Con key: al cambiar de año el formulario arranca con los datos de ese año.
    return <Reporte key={props.anio} {...props} />;
}

function Reporte({ anio, informe, datos, entidades, entidadSugerida, historial }: Extract<Props, { needsClient: false }>) {
    const { can } = usePermissions();
    const canManage = can('pesv.manage');
    const notice = useNotice(usePage<SharedData>().props.flash?.success);
    const form = useForm<Datos & { anio: number }>({
        anio,
        ...datos,
        entidad_verificadora: datos.entidad_verificadora ?? entidadSugerida,
    });
    const errores = form.errors as Record<string, string | undefined>;
    const hoy = new Date();
    const anios = Array.from({ length: hoy.getFullYear() - 2022 + 1 }, (_, i) => hoy.getFullYear() - i);
    const descarga = (formato: 'word' | 'pdf') => `/pesv/autogestion/descargar?anio=${anio}&formato=${formato}`;

    const setAuditor = (i: number, campo: keyof Auditor, v: string) =>
        form.setData(
            'auditores',
            form.data.auditores.map((a, j) => (j === i ? { ...a, [campo]: v } : a)),
        );
    const guardar: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, auditores: d.auditores.filter((a) => a.nombre.trim() !== '') }));
        form.put('/pesv/autogestion', { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Reporte de autogestión ${anio}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">Reporte de autogestión del PESV</h1>
                        <p className="text-muted-foreground text-sm">
                            Paso 20 · Literales a) a l) y los indicadores de la Tabla 10, con corte al 31 de diciembre. Se radica ante la entidad
                            verificadora a más tardar el 31 de enero.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            aria-label="Año reportado"
                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            value={anio}
                            onChange={(e) => router.get('/pesv/autogestion', { anio: e.target.value })}
                        >
                            {anios.map((a) => (
                                <option key={a}>{a}</option>
                            ))}
                        </select>
                        <Button asChild variant="outline" size="sm" className="gap-1.5">
                            <a href={descarga('word')}>
                                <FileText className="size-4" /> Word
                            </a>
                        </Button>
                        <Button asChild size="sm" className="gap-1.5">
                            <a href={descarga('pdf')}>
                                <FileDown className="size-4" /> PDF
                            </a>
                        </Button>
                    </div>
                </div>

                <Notice mensaje={notice} />

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card className={cn(datos.reportado_at ? 'border-emerald-600/40' : 'border-amber-500/40')}>
                        <CardContent className="flex items-start gap-3 p-5">
                            {datos.reportado_at ? (
                                <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-emerald-600" />
                            ) : (
                                <AlertTriangle className="mt-0.5 size-5 shrink-0 text-amber-600" />
                            )}
                            <div className="text-sm">
                                <p className="font-semibold">
                                    {datos.reportado_at ? `Reporte ${anio} radicado el ${datos.reportado_at}` : `Reporte ${anio} sin radicar`}
                                </p>
                                <p className="text-muted-foreground">
                                    {datos.reportado_at
                                        ? [entidades[datos.entidad_verificadora ?? ''], datos.radicado && `radicado ${datos.radicado}`]
                                              .filter(Boolean)
                                              .join(' · ')
                                        : `Plazo: 31 de enero de ${anio + 1}. Cuando se radique, registra la fecha y el número abajo.`}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className={cn(informe.atencion.length > 0 && 'border-red-600/40')}>
                        <CardContent className="space-y-2 p-5">
                            <h2 className="font-semibold">
                                {informe.atencion.length > 0
                                    ? `Información pendiente (${informe.atencion.length})`
                                    : 'El reporte tiene toda la información que exige el paso 20'}
                            </h2>
                            <ul className="max-h-48 space-y-1 overflow-y-auto text-sm">
                                {informe.atencion.map((a, i) => (
                                    <li key={i}>
                                        <span className="font-medium text-red-700 dark:text-red-400">{a.seccion.split(')')[0]})</span> {a.texto}
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                </div>

                {canManage && (
                    <Card>
                        <CardContent className="p-5">
                            <form onSubmit={guardar} className="grid gap-4">
                                <div>
                                    <h2 className="font-semibold">Datos que no salen de la plataforma</h2>
                                    <p className="text-muted-foreground text-sm">
                                        Lo demás se toma de Organización, la caracterización, los siniestros, los indicadores y la lista de
                                        verificación.
                                    </p>
                                </div>
                                <div className="grid gap-1.5 sm:max-w-md">
                                    <Label htmlFor="lider_email">Correo institucional del líder del PESV</Label>
                                    <Input
                                        id="lider_email"
                                        type="email"
                                        value={form.data.lider_email ?? ''}
                                        onChange={(e) => form.setData('lider_email', e.target.value)}
                                    />
                                    <InputError message={form.errors.lider_email} />
                                </div>

                                <div className="grid gap-2">
                                    <div className="flex items-center justify-between">
                                        <Label>Auditores del PESV en {anio}</Label>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="gap-1"
                                            onClick={() => form.setData('auditores', [...form.data.auditores, { nombre: '', cargo: '', email: '' }])}
                                        >
                                            <Plus className="size-4" /> Auditor
                                        </Button>
                                    </div>
                                    {form.data.auditores.length === 0 && <p className="text-muted-foreground text-sm">Sin auditores.</p>}
                                    {form.data.auditores.map((a, i) => (
                                        <div key={i} className="grid gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
                                            <Input
                                                aria-label="Nombre del auditor"
                                                placeholder="Nombre"
                                                value={a.nombre}
                                                onChange={(e) => setAuditor(i, 'nombre', e.target.value)}
                                            />
                                            <Input
                                                aria-label="Cargo del auditor"
                                                placeholder="Cargo"
                                                value={a.cargo ?? ''}
                                                onChange={(e) => setAuditor(i, 'cargo', e.target.value)}
                                            />
                                            <Input
                                                aria-label="Correo del auditor"
                                                placeholder="Correo"
                                                type="email"
                                                value={a.email ?? ''}
                                                onChange={(e) => setAuditor(i, 'email', e.target.value)}
                                            />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label="Quitar auditor"
                                                onClick={() =>
                                                    form.setData(
                                                        'auditores',
                                                        form.data.auditores.filter((_, j) => j !== i),
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4 text-red-600" />
                                            </Button>
                                            <InputError
                                                className="sm:col-span-4"
                                                message={errores[`auditores.${i}.email`] ?? errores[`auditores.${i}.nombre`]}
                                            />
                                        </div>
                                    ))}
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="objetivos_siguiente">Objetivos y metas propuestos para {anio + 1} (literal i)</Label>
                                        <textarea
                                            id="objetivos_siguiente"
                                            className={textarea}
                                            value={form.data.objetivos_siguiente ?? ''}
                                            onChange={(e) => form.setData('objetivos_siguiente', e.target.value)}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="programas_siguiente">Programas propuestos para {anio + 1} (literal j)</Label>
                                        <textarea
                                            id="programas_siguiente"
                                            className={textarea}
                                            value={form.data.programas_siguiente ?? ''}
                                            onChange={(e) => form.setData('programas_siguiente', e.target.value)}
                                        />
                                    </div>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="analisis">Análisis de los indicadores por el comité de seguridad vial</Label>
                                    <textarea
                                        id="analisis"
                                        className={cn(textarea, 'min-h-28')}
                                        value={form.data.analisis ?? ''}
                                        onChange={(e) => form.setData('analisis', e.target.value)}
                                    />
                                </div>

                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="entidad">Entidad verificadora</Label>
                                        <select
                                            id="entidad"
                                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                            value={form.data.entidad_verificadora ?? ''}
                                            onChange={(e) => form.setData('entidad_verificadora', e.target.value)}
                                        >
                                            {Object.entries(entidades).map(([k, v]) => (
                                                <option key={k} value={k}>
                                                    {v}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="reportado_at">Fecha de radicación</Label>
                                        <Input
                                            id="reportado_at"
                                            type="date"
                                            value={form.data.reportado_at ?? ''}
                                            onChange={(e) => form.setData('reportado_at', e.target.value)}
                                        />
                                        <InputError message={form.errors.reportado_at} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="radicado">Número de radicado</Label>
                                        <Input
                                            id="radicado"
                                            value={form.data.radicado ?? ''}
                                            onChange={(e) => form.setData('radicado', e.target.value)}
                                        />
                                    </div>
                                </div>
                                <div className="flex justify-end">
                                    <Button type="submit" disabled={form.processing}>
                                        Guardar
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <div className="grid grid-cols-[minmax(0,1fr)] gap-4">
                    {informe.secciones.map((s) => (
                        <SeccionCard key={s.clave} s={s} />
                    ))}
                </div>

                {historial.length > 0 && (
                    <Card>
                        <CardContent className="space-y-2 p-5">
                            <h2 className="font-semibold">Reportes anteriores</h2>
                            <ul className="divide-y text-sm">
                                {historial.map((h) => (
                                    <li key={h.anio} className="flex flex-wrap justify-between gap-2 py-1.5">
                                        <span className="font-medium">{h.anio}</span>
                                        <span className="text-muted-foreground">
                                            {h.reportado_at ? `Radicado el ${h.reportado_at}${h.radicado ? ` · ${h.radicado}` : ''}` : 'Sin radicar'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
