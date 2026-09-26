import { selectCls, textareaCls } from '@/components/control-documental/etiquetas';
import InputError from '@/components/input-error';
import { ModuloPage } from '@/components/modulo-page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Download, Presentation as IconoPresentacion, Loader2, Sparkles, Trash2, TriangleAlert, X } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Presentacion {
    id: number;
    titulo: string | null;
    modulo: string | null;
    /** Piezas: «M11», «M09:epp», «M09:epp.matriz». Vacío = sistema completo. */
    seleccion: string[] | null;
    proposito: string;
    diapositivas: number;
    desde: string;
    hasta: string;
    estado: 'generando' | 'lista' | 'error';
    error: string | null;
    created_at: string;
    user: { id: number; name: string } | null;
}

interface Props {
    needsClient: boolean;
    presentaciones: Presentacion[];
    modulos: { codigo: string; nombre: string; contratado: boolean }[];
    propositos: Record<string, string>;
    /** Partes de cada módulo del mapa: pantalla («epp») o parte de pantalla («epp.matriz») => nombre. */
    submodulos: Record<string, Record<string, string>>;
    seleccionInicial: string[];
    periodo: { desde: string; hasta: string };
}

export default function Presentaciones({ needsClient, presentaciones, modulos, propositos, submodulos, seleccionInicial, periodo }: Props) {
    const { can } = usePermissions();
    const canManage = can('documents.manage');
    const { data, setData, post, processing, errors } = useForm({
        seleccion: seleccionInicial,
        proposito: 'gerencia',
        diapositivas: 10,
        desde: periodo.desde,
        hasta: periodo.hasta,
        instrucciones: '',
    });

    // Mientras alguna se esté generando, se pregunta cada 5 s (como Documentos IA).
    const generando = presentaciones.some((p) => p.estado === 'generando');
    useEffect(() => {
        if (!generando) return;
        const t = setInterval(() => router.reload({ only: ['presentaciones'] }), 5000);
        return () => clearInterval(t);
    }, [generando]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/presentaciones', { preserveScroll: true, onSuccess: () => setData('instrucciones', '') });
    };

    /** «M09 › EPP · Matriz de EPP por cargo» */
    const nombrePieza = (pieza: string) => {
        const [m, sub] = pieza.split(':');
        return sub ? `${m} › ${submodulos[m]?.[sub] ?? sub}` : `${m} · ${modulos.find((x) => x.codigo === m)?.nombre ?? ''}`;
    };

    return (
        <ModuloPage
            titulo="Presentaciones"
            descripcion="PowerPoint armado por la IA con los datos reales del cliente: su organización, las cifras de sus módulos y sus documentos"
            needsClient={needsClient}
        >
            {canManage && (
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Sparkles className="size-4" /> Nueva presentación
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-3">
                            <SelectorPiezas
                                modulos={modulos}
                                submodulos={submodulos}
                                valor={data.seleccion}
                                nombre={nombrePieza}
                                onChange={(v) => setData('seleccion', v)}
                            />
                            <InputError
                                message={
                                    (errors as Record<string, string | undefined>).seleccion ??
                                    Object.entries(errors).find(([k]) => k.startsWith('seleccion.'))?.[1]
                                }
                            />
                            <div className="grid gap-3 md:grid-cols-[1fr_7rem]">
                                <div className="grid gap-2">
                                    <Label htmlFor="proposito">Para qué es</Label>
                                    <select
                                        id="proposito"
                                        value={data.proposito}
                                        onChange={(e) => setData('proposito', e.target.value)}
                                        className={selectCls}
                                    >
                                        {Object.entries(propositos).map(([k, v]) => (
                                            <option key={k} value={k}>
                                                {v}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="diapositivas">Diapositivas</Label>
                                    <Input
                                        id="diapositivas"
                                        type="number"
                                        min={4}
                                        max={20}
                                        value={data.diapositivas}
                                        onChange={(e) => setData('diapositivas', Number(e.target.value))}
                                    />
                                    <InputError message={errors.diapositivas} />
                                </div>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-[10rem_10rem_1fr]">
                                <div className="grid gap-2">
                                    <Label htmlFor="desde">Cifras desde</Label>
                                    <Input id="desde" type="date" value={data.desde} onChange={(e) => setData('desde', e.target.value)} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="hasta">Hasta</Label>
                                    <Input id="hasta" type="date" value={data.hasta} onChange={(e) => setData('hasta', e.target.value)} />
                                    <InputError message={errors.hasta} />
                                </div>
                                <div className="grid gap-2 sm:col-span-2 md:col-span-1">
                                    <Label htmlFor="instrucciones">Instrucciones (opcional)</Label>
                                    <textarea
                                        id="instrucciones"
                                        rows={1}
                                        value={data.instrucciones}
                                        placeholder="Enfatiza los accidentes del semestre y cierra con los compromisos del COPASST…"
                                        onChange={(e) => setData('instrucciones', e.target.value)}
                                        className={textareaCls}
                                    />
                                    <InputError message={errors.instrucciones} />
                                </div>
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <p className="text-muted-foreground text-xs">
                                    La IA usa solo datos de la plataforma: si algo no está registrado lo presenta como pendiente, no lo inventa.
                                    Revisa la presentación antes de exponerla.
                                </p>
                                <Button type="submit" disabled={processing} className="gap-2">
                                    <IconoPresentacion className="size-4" /> Generar presentación
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            )}

            <Card>
                <CardContent className="p-0">
                    {presentaciones.length === 0 ? (
                        <p className="text-muted-foreground p-8 text-center text-sm">Todavía no hay presentaciones de este cliente.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground bg-muted/40 text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2.5 font-semibold">Presentación</th>
                                        <th className="px-4 py-2.5 font-semibold">Módulo</th>
                                        <th className="px-4 py-2.5 font-semibold">Periodo</th>
                                        <th className="px-4 py-2.5 font-semibold">Creada</th>
                                        <th className="px-4 py-2.5" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {presentaciones.map((p) => (
                                        <tr key={p.id} className="border-t align-top">
                                            <td className="max-w-md px-4 py-2.5">
                                                {p.estado === 'generando' ? (
                                                    <span className="text-muted-foreground inline-flex items-center gap-2">
                                                        <Loader2 className="size-4 animate-spin" /> Generando…
                                                    </span>
                                                ) : p.estado === 'error' ? (
                                                    <span className="text-destructive inline-flex items-start gap-2">
                                                        <TriangleAlert className="mt-0.5 size-4 shrink-0" /> {p.error ?? 'No se pudo generar'}
                                                    </span>
                                                ) : (
                                                    <span className="font-medium">{p.titulo}</span>
                                                )}
                                                <div className="text-muted-foreground text-xs">
                                                    {propositos[p.proposito]} · {p.diapositivas} diapositivas
                                                </div>
                                            </td>
                                            <td className="px-4 py-2.5 text-xs">
                                                {(p.seleccion ?? []).length === 0 ? (
                                                    'Sistema completo'
                                                ) : (
                                                    <ul className="space-y-0.5">
                                                        {(p.seleccion ?? []).map((pieza) => (
                                                            <li key={pieza}>{nombrePieza(pieza)}</li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5 text-xs whitespace-nowrap tabular-nums">
                                                {p.desde} a {p.hasta}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2.5 text-xs whitespace-nowrap">
                                                {p.created_at.slice(0, 16).replace('T', ' ')}
                                                {p.user && <div>{p.user.name}</div>}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <div className="flex justify-end gap-1">
                                                    {p.estado === 'lista' && (
                                                        <Button asChild size="sm" variant="outline" className="gap-1.5">
                                                            <a href={`/presentaciones/${p.id}/descargar`}>
                                                                <Download className="size-3.5" /> .pptx
                                                            </a>
                                                        </Button>
                                                    )}
                                                    {canManage && p.estado !== 'generando' && (
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            aria-label="Eliminar presentación"
                                                            onClick={() =>
                                                                confirm('¿Eliminar esta presentación?') &&
                                                                router.delete(`/presentaciones/${p.id}`, { preserveScroll: true })
                                                            }
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    )}
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
        </ModuloPage>
    );
}

/**
 * Qué abarca la presentación: piezas de cualquier módulo del mapa. Cada módulo
 * se despliega en sus pantallas y partes; marcar el módulo completo cubre sus
 * piezas (se deshabilitan). Nada marcado = sistema completo.
 */
function SelectorPiezas({
    modulos,
    submodulos,
    valor,
    nombre,
    onChange,
}: {
    modulos: Props['modulos'];
    submodulos: Props['submodulos'];
    valor: string[];
    nombre: (pieza: string) => string;
    onChange: (v: string[]) => void;
}) {
    const [abiertos, setAbiertos] = useState<string[]>(() => [...new Set(valor.map((p) => p.split(':')[0]))]);
    const tiene = (p: string) => valor.includes(p);
    const alternar = (p: string) => onChange(tiene(p) ? valor.filter((x) => x !== p) : [...valor, p]);
    // Marcar el módulo completo reemplaza sus piezas sueltas.
    const alternarModulo = (m: string) => onChange(tiene(m) ? valor.filter((x) => x !== m) : [...valor.filter((x) => !x.startsWith(`${m}:`)), m]);
    const abrir = (m: string) => setAbiertos((a) => (a.includes(m) ? a.filter((x) => x !== m) : [...a, m]));

    return (
        <div className="grid gap-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Label>Qué abarca</Label>
                {valor.length > 0 && (
                    <button type="button" className="text-primary text-xs font-medium hover:underline" onClick={() => onChange([])}>
                        Quitar todo (sistema completo)
                    </button>
                )}
            </div>
            <div className="flex min-h-9 flex-wrap items-center gap-1.5 rounded-md border px-2 py-1.5">
                {valor.length === 0 ? (
                    <span className="text-muted-foreground px-1 text-sm">Sistema completo: todos los módulos</span>
                ) : (
                    valor.map((p) => (
                        <Badge key={p} variant="secondary" className="gap-1 font-normal">
                            {nombre(p)}
                            <button type="button" onClick={() => alternar(p)} aria-label={`Quitar ${nombre(p)}`}>
                                <X className="size-3" />
                            </button>
                        </Badge>
                    ))
                )}
            </div>
            <div className="max-h-72 divide-y overflow-y-auto rounded-md border">
                {modulos.map((m) => {
                    const piezas = Object.entries(submodulos[m.codigo] ?? {});
                    const abierto = abiertos.includes(m.codigo);
                    const completo = tiene(m.codigo);
                    const sueltas = valor.filter((x) => x.startsWith(`${m.codigo}:`)).length;
                    return (
                        <div key={m.codigo}>
                            <div className="flex items-center gap-2 px-3 py-1.5 text-sm">
                                <Checkbox
                                    checked={completo ? true : sueltas > 0 ? 'indeterminate' : false}
                                    onCheckedChange={() => alternarModulo(m.codigo)}
                                    aria-label={`${m.codigo} ${m.nombre} completo`}
                                />
                                <button
                                    type="button"
                                    className="flex flex-1 items-center gap-2 text-left"
                                    onClick={() => piezas.length > 0 && abrir(m.codigo)}
                                    aria-expanded={abierto}
                                >
                                    <span className="text-muted-foreground font-mono text-xs">{m.codigo}</span>
                                    <span className={cn('flex-1', !m.contratado && 'text-muted-foreground')}>
                                        {m.nombre}
                                        {!m.contratado && <span className="text-xs"> · no contratado</span>}
                                    </span>
                                    {sueltas > 0 && <span className="text-muted-foreground text-xs tabular-nums">{sueltas} partes</span>}
                                    {piezas.length > 0 &&
                                        (abierto ? (
                                            <ChevronDown className="text-muted-foreground size-4" />
                                        ) : (
                                            <ChevronRight className="text-muted-foreground size-4" />
                                        ))}
                                </button>
                            </div>
                            {abierto && (
                                <div className="bg-muted/30 space-y-1 border-t py-1.5 pr-3 pl-9">
                                    {piezas.map(([clave, etiqueta]) => {
                                        const pieza = `${m.codigo}:${clave}`;
                                        const parte = clave.includes('.');
                                        return (
                                            <label
                                                key={clave}
                                                className={cn(
                                                    'flex cursor-pointer items-center gap-2 text-xs',
                                                    parte && 'pl-5',
                                                    completo && 'opacity-50',
                                                )}
                                            >
                                                <Checkbox
                                                    checked={completo || tiene(pieza)}
                                                    disabled={completo}
                                                    onCheckedChange={() => alternar(pieza)}
                                                    aria-label={`${m.codigo} ${etiqueta}`}
                                                />
                                                <span>{parte ? etiqueta.split(' · ').slice(1).join(' · ') : etiqueta}</span>
                                            </label>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
