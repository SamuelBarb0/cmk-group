import { selectCls, textareaCls } from '@/components/control-documental/etiquetas';
import InputError from '@/components/input-error';
import { ModuloPage, StatCard } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { CalendarClock, ChevronDown, ChevronRight, CircleAlert, FileDown, FileOutput, Pencil, Plus, Ruler, Trash2, Wrench } from 'lucide-react';
import { FormEventHandler, Fragment, useState } from 'react';

type Control = 'calibracion' | 'verificacion';
type EstadoEquipo = 'en_uso' | 'fuera_servicio' | 'baja';
type Situacion = 'vigente' | 'por_vencer' | 'vencido' | 'sin_calibrar' | 'no_conforme' | 'fuera_servicio' | 'baja';

interface Calibracion {
    id: number;
    fecha: string;
    tipo: Control;
    realizado_por: string | null;
    acreditado_onac: boolean;
    certificado: string | null;
    error_encontrado: string | null;
    incertidumbre: string | null;
    resultado: 'conforme' | 'no_conforme';
    impacto_mediciones: string | null;
    observaciones: string | null;
    tiene_archivo: boolean;
    archivo_nombre: string | null;
    acpm_action: { id: number; codigo: string; estado: string } | null;
}

interface Equipo {
    id: number;
    codigo: string;
    nombre: string;
    marca: string | null;
    modelo: string | null;
    serie: string | null;
    magnitud: string | null;
    unidad: string | null;
    rango: string | null;
    resolucion: string | null;
    error_maximo: string | null;
    uso: string | null;
    ubicacion: string | null;
    responsable: string | null;
    control: Control;
    frecuencia_meses: number;
    estado: EstadoEquipo;
    observaciones: string | null;
    calibrations: Calibracion[];
    situacion: { estado: Situacion; ultima: string | null; proxima: string | null; dias: number | null };
}

interface Props {
    needsClient: boolean;
    equipos: Equipo[];
    documentos: { clave: string; titulo: string; id: number | null; codigo: string | null; estado: string | null }[];
    controles: Record<Control, string>;
    estados: Record<EstadoEquipo, string>;
    situaciones: Record<Situacion, string>;
}

const SITUACION: Record<Situacion, string> = {
    vigente: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    por_vencer: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    vencido: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    sin_calibrar: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    no_conforme: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    fuera_servicio: 'bg-muted text-muted-foreground',
    baja: 'bg-muted text-muted-foreground',
};

export default function EquiposMedicion({ needsClient, equipos, documentos, controles, estados, situaciones }: Props) {
    const { can } = usePermissions();
    const canManage = can('sst.manage');
    const errores = usePage<SharedData>().props.errors as Record<string, string | undefined>;
    const [editar, setEditar] = useState<Equipo | 'nuevo' | null>(null);
    const [calibrar, setCalibrar] = useState<Equipo | null>(null);
    const [accion, setAccion] = useState<{ equipo: Equipo; cal: Calibracion } | null>(null);
    const [abiertos, setAbiertos] = useState<number[]>([]);

    const enUso = equipos.filter((e) => e.estado === 'en_uso');
    const cuenta = (s: Situacion[]) => enUso.filter((e) => s.includes(e.situacion.estado)).length;
    const alternar = (id: number) => setAbiertos((a) => (a.includes(id) ? a.filter((x) => x !== id) : [...a, id]));

    return (
        <ModuloPage
            titulo="Equipos de medición"
            descripcion="Hoja de vida, calibraciones y verificaciones, y equipos no conformes (ISO 9001 7.1.5, ISO 45001 y 14001 9.1.1)"
            needsClient={needsClient}
            accion={
                canManage ? (
                    <Button className="gap-2" onClick={() => setEditar('nuevo')}>
                        <Plus className="size-4" /> Nuevo equipo
                    </Button>
                ) : undefined
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Equipos en uso" value={enUso.length} icon={Ruler} />
                <StatCard
                    label="Vencidos o sin calibrar"
                    value={cuenta(['vencido', 'sin_calibrar'])}
                    icon={CircleAlert}
                    alerta={cuenta(['vencido', 'sin_calibrar']) > 0}
                />
                <StatCard label="Por vencer en 30 días" value={cuenta(['por_vencer'])} icon={CalendarClock} />
                <StatCard label="Fuera de servicio" value={equipos.filter((e) => e.estado === 'fuera_servicio').length} icon={Wrench} />
            </div>

            <Card>
                <CardContent className="p-0">
                    {equipos.length === 0 ? (
                        <p className="text-muted-foreground p-8 text-center text-sm">
                            Todavía no hay equipos. Registra los que miden algo que importe al sistema: sonómetros, luxómetros, medidores de gases,
                            alcoholímetros, balanzas, termómetros…
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                    <tr>
                                        <th className="w-8 px-2 py-2.5" />
                                        <th className="px-3 py-2.5 font-semibold">Equipo</th>
                                        <th className="px-3 py-2.5 font-semibold">Medición</th>
                                        <th className="px-3 py-2.5 font-semibold">Control</th>
                                        <th className="px-3 py-2.5 font-semibold">Última / próxima</th>
                                        <th className="px-3 py-2.5 font-semibold">Estado</th>
                                        {canManage && <th className="px-3 py-2.5" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {equipos.map((e) => {
                                        const abierto = abiertos.includes(e.id);
                                        return (
                                            <Fragment key={e.id}>
                                                <tr className="border-t align-top">
                                                    <td className="px-2 py-2.5">
                                                        <button
                                                            type="button"
                                                            onClick={() => alternar(e.id)}
                                                            aria-label={abierto ? 'Ocultar historial' : 'Ver historial'}
                                                            className="text-muted-foreground hover:text-foreground"
                                                        >
                                                            {abierto ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                                                        </button>
                                                    </td>
                                                    <td className="px-3 py-2.5">
                                                        <div className="font-medium">
                                                            <span className="text-muted-foreground font-mono text-xs">{e.codigo}</span> {e.nombre}
                                                        </div>
                                                        <div className="text-muted-foreground text-xs">
                                                            {[e.marca, e.modelo, e.serie && `S/N ${e.serie}`].filter(Boolean).join(' · ') || '—'}
                                                        </div>
                                                        {e.ubicacion && <div className="text-muted-foreground text-xs">{e.ubicacion}</div>}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-xs">
                                                        <div>{[e.magnitud, e.unidad && `(${e.unidad})`].filter(Boolean).join(' ') || '—'}</div>
                                                        {e.rango && <div className="text-muted-foreground">Rango {e.rango}</div>}
                                                        {e.error_maximo && <div className="text-muted-foreground">Error máx. {e.error_maximo}</div>}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-xs whitespace-nowrap">
                                                        {controles[e.control]}
                                                        <div className="text-muted-foreground">cada {e.frecuencia_meses} meses</div>
                                                    </td>
                                                    <td className="px-3 py-2.5 text-xs whitespace-nowrap tabular-nums">
                                                        <div>{e.situacion.ultima ?? '—'}</div>
                                                        <div className="text-muted-foreground">{e.situacion.proxima ?? '—'}</div>
                                                    </td>
                                                    <td className="px-3 py-2.5">
                                                        <span
                                                            className={cn(
                                                                'rounded px-1.5 py-0.5 text-xs font-medium whitespace-nowrap',
                                                                SITUACION[e.situacion.estado],
                                                            )}
                                                        >
                                                            {situaciones[e.situacion.estado]}
                                                        </span>
                                                        {e.situacion.estado === 'por_vencer' && e.situacion.dias !== null && (
                                                            <div className="text-muted-foreground mt-1 text-xs">en {e.situacion.dias} días</div>
                                                        )}
                                                    </td>
                                                    {canManage && (
                                                        <td className="px-3 py-2.5">
                                                            <div className="flex justify-end gap-1">
                                                                {e.estado !== 'baja' && (
                                                                    <Button size="sm" variant="outline" onClick={() => setCalibrar(e)}>
                                                                        Registrar {e.control === 'calibracion' ? 'calibración' : 'verificación'}
                                                                    </Button>
                                                                )}
                                                                <Button
                                                                    size="icon"
                                                                    variant="ghost"
                                                                    onClick={() => setEditar(e)}
                                                                    aria-label="Editar equipo"
                                                                >
                                                                    <Pencil className="size-4" />
                                                                </Button>
                                                                <Button
                                                                    size="icon"
                                                                    variant="ghost"
                                                                    aria-label="Eliminar equipo"
                                                                    onClick={() =>
                                                                        confirm(`¿Eliminar ${e.codigo} con todo su historial y certificados?`) &&
                                                                        router.delete(`/equipos-medicion/${e.id}`, { preserveScroll: true })
                                                                    }
                                                                >
                                                                    <Trash2 className="size-4" />
                                                                </Button>
                                                            </div>
                                                        </td>
                                                    )}
                                                </tr>
                                                {abierto && (
                                                    <tr className="bg-muted/20">
                                                        <td />
                                                        <td colSpan={canManage ? 6 : 5} className="px-3 pt-1 pb-3">
                                                            <Historial
                                                                equipo={e}
                                                                controles={controles}
                                                                canManage={canManage}
                                                                onAccion={(cal) => setAccion({ equipo: e, cal })}
                                                            />
                                                        </td>
                                                    </tr>
                                                )}
                                            </Fragment>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="pb-2">
                    <CardTitle className="text-base">Documentos</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-3 text-sm sm:grid-cols-2">
                    {documentos.length === 0 && (
                        <p className="text-muted-foreground text-xs">El catálogo del SIG no está cargado en esta instalación.</p>
                    )}
                    {documentos.map((d) => (
                        <div key={d.clave} className="flex items-center justify-between gap-3">
                            <div className="min-w-0">
                                <div className="truncate" title={d.titulo}>
                                    {d.titulo}
                                </div>
                                <div className="text-muted-foreground text-xs">
                                    {d.codigo ? (
                                        <Link href={`/control-documental/${d.id}`} className="underline underline-offset-2">
                                            {d.codigo} · {d.estado?.replace('_', ' ')}
                                        </Link>
                                    ) : (
                                        'Aún no está en el control documental'
                                    )}
                                </div>
                            </div>
                            {canManage && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="shrink-0 gap-1.5"
                                    aria-label={`Enviar ${d.titulo}`}
                                    onClick={() => router.post('/equipos-medicion/enviar', { documento: d.clave }, { preserveScroll: true })}
                                >
                                    <FileOutput className="size-3.5" /> Enviar como borrador
                                </Button>
                            )}
                        </div>
                    ))}
                    {(errores.documento || errores.acpm) && <p className="text-destructive text-xs">{errores.documento ?? errores.acpm}</p>}
                </CardContent>
            </Card>

            {editar && (
                <EquipoDialog equipo={editar === 'nuevo' ? null : editar} controles={controles} estados={estados} onClose={() => setEditar(null)} />
            )}
            {calibrar && <CalibracionDialog equipo={calibrar} controles={controles} onClose={() => setCalibrar(null)} />}
            {accion && <AccionDialog equipo={accion.equipo} cal={accion.cal} onClose={() => setAccion(null)} />}
        </ModuloPage>
    );
}

function Historial({
    equipo,
    controles,
    canManage,
    onAccion,
}: {
    equipo: Equipo;
    controles: Props['controles'];
    canManage: boolean;
    onAccion: (c: Calibracion) => void;
}) {
    if (equipo.calibrations.length === 0) {
        return <p className="text-muted-foreground py-2 text-xs">Sin calibraciones ni verificaciones registradas.</p>;
    }

    return (
        <table className="w-full text-xs">
            <thead className="text-muted-foreground text-left">
                <tr>
                    <th className="py-1.5 pr-3 font-semibold">Fecha</th>
                    <th className="py-1.5 pr-3 font-semibold">Tipo</th>
                    <th className="py-1.5 pr-3 font-semibold">Realizado por</th>
                    <th className="py-1.5 pr-3 font-semibold">Certificado</th>
                    <th className="py-1.5 pr-3 font-semibold">Error / incertidumbre</th>
                    <th className="py-1.5 pr-3 font-semibold">Resultado</th>
                    {canManage && <th />}
                </tr>
            </thead>
            <tbody>
                {equipo.calibrations.map((c) => (
                    <tr key={c.id} className="border-t align-top">
                        <td className="py-1.5 pr-3 whitespace-nowrap tabular-nums">{c.fecha}</td>
                        <td className="py-1.5 pr-3">{controles[c.tipo]}</td>
                        <td className="py-1.5 pr-3">
                            {c.realizado_por ?? '—'}
                            {c.acreditado_onac && (
                                <Badge variant="outline" className="ml-1.5 font-normal">
                                    ONAC
                                </Badge>
                            )}
                        </td>
                        <td className="py-1.5 pr-3">
                            {c.tiene_archivo ? (
                                <a
                                    href={`/equipos-medicion/${equipo.id}/calibraciones/${c.id}/certificado`}
                                    className="inline-flex items-center gap-1 underline underline-offset-2"
                                >
                                    <FileDown className="size-3.5" /> {c.certificado || c.archivo_nombre}
                                </a>
                            ) : (
                                (c.certificado ?? '—')
                            )}
                        </td>
                        <td className="py-1.5 pr-3">
                            {c.error_encontrado ?? '—'}
                            {c.incertidumbre && <span className="text-muted-foreground"> ± {c.incertidumbre}</span>}
                        </td>
                        <td className="max-w-xs py-1.5 pr-3">
                            {c.resultado === 'conforme' ? (
                                <span className="text-emerald-700 dark:text-emerald-400">Conforme</span>
                            ) : (
                                <>
                                    <span className="text-destructive font-medium">No conforme</span>
                                    {c.impacto_mediciones && <div className="text-muted-foreground">{c.impacto_mediciones}</div>}
                                    {c.acpm_action ? (
                                        <Link href="/acpm" className="text-muted-foreground block underline underline-offset-2">
                                            {c.acpm_action.codigo} · {c.acpm_action.estado.replace('_', ' ')}
                                        </Link>
                                    ) : (
                                        canManage && (
                                            <button
                                                type="button"
                                                className="text-primary block underline underline-offset-2"
                                                onClick={() => onAccion(c)}
                                            >
                                                Crear acción correctiva en ACPM
                                            </button>
                                        )
                                    )}
                                </>
                            )}
                        </td>
                        {canManage && (
                            <td className="py-1.5 text-right">
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-7"
                                    aria-label="Eliminar registro"
                                    onClick={() =>
                                        confirm('¿Eliminar este registro y su certificado?') &&
                                        router.delete(`/equipos-medicion/${equipo.id}/calibraciones/${c.id}`, { preserveScroll: true })
                                    }
                                >
                                    <Trash2 className="size-3.5" />
                                </Button>
                            </td>
                        )}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

type EquipoForm = Omit<Equipo, 'id' | 'calibrations' | 'situacion' | 'frecuencia_meses'> & { frecuencia_meses: number | '' };

const CAMPOS_TEXTO: [keyof EquipoForm, string, string?][] = [
    ['marca', 'Marca'],
    ['modelo', 'Modelo'],
    ['serie', 'Serie'],
    ['magnitud', 'Magnitud', 'Nivel sonoro, iluminancia, masa…'],
    ['unidad', 'Unidad', 'dB, lux, kg, °C'],
    ['rango', 'Rango', '30 a 130'],
    ['resolucion', 'Resolución', '0,1'],
    ['error_maximo', 'Error máximo permitido', '± 1,5 dB'],
    ['ubicacion', 'Ubicación'],
    ['responsable', 'Responsable'],
];

function EquipoDialog({
    equipo,
    controles,
    estados,
    onClose,
}: {
    equipo: Equipo | null;
    controles: Props['controles'];
    estados: Props['estados'];
    onClose: () => void;
}) {
    const { data, setData, post, put, processing, errors } = useForm<EquipoForm>({
        codigo: equipo?.codigo ?? '',
        nombre: equipo?.nombre ?? '',
        marca: equipo?.marca ?? '',
        modelo: equipo?.modelo ?? '',
        serie: equipo?.serie ?? '',
        magnitud: equipo?.magnitud ?? '',
        unidad: equipo?.unidad ?? '',
        rango: equipo?.rango ?? '',
        resolucion: equipo?.resolucion ?? '',
        error_maximo: equipo?.error_maximo ?? '',
        uso: equipo?.uso ?? '',
        ubicacion: equipo?.ubicacion ?? '',
        responsable: equipo?.responsable ?? '',
        control: equipo?.control ?? 'calibracion',
        frecuencia_meses: equipo?.frecuencia_meses ?? 12,
        estado: equipo?.estado ?? 'en_uso',
        observaciones: equipo?.observaciones ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (equipo) put(`/equipos-medicion/${equipo.id}`, opts);
        else post('/equipos-medicion', opts);
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{equipo ? `Editar ${equipo.codigo}` : 'Nuevo equipo de medición'}</DialogTitle>
                    <DialogDescription>La hoja de vida del equipo: qué mide, con qué error se le tolera y cada cuánto se controla.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-[10rem_1fr]">
                        <div className="grid gap-2">
                            <Label htmlFor="codigo">Código</Label>
                            <Input id="codigo" value={data.codigo} onChange={(e) => setData('codigo', e.target.value)} />
                            <InputError message={errors.codigo} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="nombre">Equipo</Label>
                            <Input id="nombre" value={data.nombre} placeholder="Sonómetro" onChange={(e) => setData('nombre', e.target.value)} />
                            <InputError message={errors.nombre} />
                        </div>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {CAMPOS_TEXTO.map(([campo, label, ayuda]) => (
                            <div key={campo} className="grid gap-2">
                                <Label htmlFor={campo}>{label}</Label>
                                <Input
                                    id={campo}
                                    value={(data[campo] as string | null) ?? ''}
                                    placeholder={ayuda}
                                    onChange={(e) => setData(campo, e.target.value)}
                                />
                                <InputError message={errors[campo]} />
                            </div>
                        ))}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="uso">Uso</Label>
                        <Input
                            id="uso"
                            value={data.uso ?? ''}
                            placeholder="Mediciones de ruido en planta para el PVE auditivo"
                            onChange={(e) => setData('uso', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="control">Control</Label>
                            <select
                                id="control"
                                value={data.control}
                                onChange={(e) => setData('control', e.target.value as Control)}
                                className={selectCls}
                            >
                                {(Object.keys(controles) as Control[]).map((c) => (
                                    <option key={c} value={c}>
                                        {controles[c]}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="frecuencia">Cada (meses)</Label>
                            <Input
                                id="frecuencia"
                                type="number"
                                min={1}
                                max={60}
                                value={data.frecuencia_meses}
                                onChange={(e) => setData('frecuencia_meses', e.target.value ? Number(e.target.value) : '')}
                            />
                            <InputError message={errors.frecuencia_meses} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="estado">Estado</Label>
                            <select
                                id="estado"
                                value={data.estado}
                                onChange={(e) => setData('estado', e.target.value as EstadoEquipo)}
                                className={selectCls}
                            >
                                {(Object.keys(estados) as EstadoEquipo[]).map((s) => (
                                    <option key={s} value={s}>
                                        {estados[s]}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="observaciones">Observaciones</Label>
                        <textarea
                            id="observaciones"
                            rows={2}
                            value={data.observaciones ?? ''}
                            onChange={(e) => setData('observaciones', e.target.value)}
                            className={textareaCls}
                        />
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

function CalibracionDialog({ equipo, controles, onClose }: { equipo: Equipo; controles: Props['controles']; onClose: () => void }) {
    const { data, setData, post, processing, errors, progress } = useForm<{
        fecha: string;
        tipo: Control;
        realizado_por: string;
        acreditado_onac: boolean;
        certificado: string;
        error_encontrado: string;
        incertidumbre: string;
        resultado: 'conforme' | 'no_conforme';
        impacto_mediciones: string;
        observaciones: string;
        archivo: File | null;
    }>({
        fecha: '',
        tipo: equipo.control,
        realizado_por: '',
        acreditado_onac: equipo.control === 'calibracion',
        certificado: '',
        error_encontrado: '',
        incertidumbre: '',
        resultado: 'conforme',
        impacto_mediciones: '',
        observaciones: '',
        archivo: null,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/equipos-medicion/${equipo.id}/calibraciones`, { preserveScroll: true, forceFormData: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {equipo.codigo} · {equipo.nombre}
                    </DialogTitle>
                    <DialogDescription>
                        {equipo.error_maximo
                            ? `Error máximo permitido: ${equipo.error_maximo}.`
                            : 'Sin error máximo permitido definido en la hoja de vida.'}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="cal-fecha">Fecha</Label>
                            <Input id="cal-fecha" type="date" value={data.fecha} onChange={(e) => setData('fecha', e.target.value)} />
                            <InputError message={errors.fecha} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="cal-tipo">Tipo</Label>
                            <select
                                id="cal-tipo"
                                value={data.tipo}
                                onChange={(e) => setData('tipo', e.target.value as Control)}
                                className={selectCls}
                            >
                                {(Object.keys(controles) as Control[]).map((c) => (
                                    <option key={c} value={c}>
                                        {controles[c]}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="cal-por">Realizado por</Label>
                        <Input
                            id="cal-por"
                            value={data.realizado_por}
                            placeholder="Laboratorio o persona"
                            onChange={(e) => setData('realizado_por', e.target.value)}
                        />
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={data.acreditado_onac}
                                onChange={(e) => setData('acreditado_onac', e.target.checked)}
                                className="size-4"
                            />
                            Laboratorio acreditado ante el ONAC
                        </label>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="cal-cert">N.º de certificado</Label>
                            <Input id="cal-cert" value={data.certificado} onChange={(e) => setData('certificado', e.target.value)} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="cal-error">Error encontrado</Label>
                            <Input id="cal-error" value={data.error_encontrado} onChange={(e) => setData('error_encontrado', e.target.value)} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="cal-inc">Incertidumbre</Label>
                            <Input id="cal-inc" value={data.incertidumbre} onChange={(e) => setData('incertidumbre', e.target.value)} />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="cal-resultado">Resultado</Label>
                        <select
                            id="cal-resultado"
                            value={data.resultado}
                            onChange={(e) => setData('resultado', e.target.value as 'conforme' | 'no_conforme')}
                            className={selectCls}
                        >
                            <option value="conforme">Conforme: dentro del error máximo permitido</option>
                            <option value="no_conforme">No conforme: fuera de tolerancia</option>
                        </select>
                    </div>
                    {data.resultado === 'no_conforme' && (
                        <div className="grid gap-2">
                            <Label htmlFor="cal-impacto">¿Qué pasa con las mediciones hechas desde la última calibración buena?</Label>
                            <textarea
                                id="cal-impacto"
                                rows={3}
                                value={data.impacto_mediciones}
                                placeholder="Qué mediciones se hicieron con el equipo, si su resultado cambia con este error y qué se hará (repetirlas, avisar al cliente…)."
                                onChange={(e) => setData('impacto_mediciones', e.target.value)}
                                className={textareaCls}
                            />
                            <InputError message={errors.impacto_mediciones} />
                            <p className="text-muted-foreground text-xs">El equipo quedará fuera de servicio hasta una calibración conforme.</p>
                        </div>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="cal-archivo">Certificado (PDF o imagen)</Label>
                        <Input
                            id="cal-archivo"
                            type="file"
                            accept=".pdf,.png,.jpg,.jpeg"
                            onChange={(e) => setData('archivo', e.target.files?.[0] ?? null)}
                        />
                        {progress && <progress value={progress.percentage} max="100" className="w-full" />}
                        <InputError message={errors.archivo} />
                    </div>
                    <DialogFooter className="gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Registrar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function AccionDialog({ equipo, cal, onClose }: { equipo: Equipo; cal: Calibracion; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ accion: '', responsable: equipo.responsable ?? '', fecha_limite: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/equipos-medicion/${equipo.id}/calibraciones/${cal.id}/accion`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Acción correctiva en ACPM</DialogTitle>
                    <DialogDescription>
                        {equipo.codigo} no conforme el {cal.fecha}
                        {cal.error_encontrado ? ` (error ${cal.error_encontrado})` : ''}.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-2">
                        <Label htmlFor="accion">Acción</Label>
                        <textarea
                            id="accion"
                            rows={3}
                            value={data.accion}
                            placeholder="Ajustar y recalibrar el equipo, repetir las mediciones afectadas…"
                            onChange={(e) => setData('accion', e.target.value)}
                            className={textareaCls}
                        />
                        <InputError message={errors.accion} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="acc-resp">Responsable</Label>
                            <Input id="acc-resp" value={data.responsable} onChange={(e) => setData('responsable', e.target.value)} />
                            <InputError message={errors.responsable} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="acc-fecha">Fecha límite</Label>
                            <Input id="acc-fecha" type="date" value={data.fecha_limite} onChange={(e) => setData('fecha_limite', e.target.value)} />
                            <InputError message={errors.fecha_limite} />
                        </div>
                    </div>
                    <DialogFooter className="gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Crear en ACPM
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
