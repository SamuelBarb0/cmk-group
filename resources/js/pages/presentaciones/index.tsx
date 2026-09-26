import { selectCls, textareaCls } from '@/components/control-documental/etiquetas';
import InputError from '@/components/input-error';
import { ModuloPage } from '@/components/modulo-page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/hooks/use-permissions';
import { router, useForm } from '@inertiajs/react';
import { Download, Presentation as IconoPresentacion, Loader2, Sparkles, Trash2, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';

interface Presentacion {
    id: number;
    titulo: string | null;
    modulo: string | null;
    submodulo: string | null;
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
    moduloInicial: string | null;
    submoduloInicial: string | null;
    periodo: { desde: string; hasta: string };
}

export default function Presentaciones({
    needsClient,
    presentaciones,
    modulos,
    propositos,
    submodulos,
    moduloInicial,
    submoduloInicial,
    periodo,
}: Props) {
    const { can } = usePermissions();
    const canManage = can('documents.manage');
    const { data, setData, post, processing, errors } = useForm({
        modulo: moduloInicial ?? '',
        submodulo: submoduloInicial ?? '',
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

    const nombreModulo = (m: string | null, sub: string | null) =>
        m ? `${m} · ${modulos.find((x) => x.codigo === m)?.nombre ?? ''}${sub ? ` › ${submodulos[m]?.[sub] ?? sub}` : ''}` : 'Sistema completo';

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
                            <div className="grid gap-3 md:grid-cols-[2fr_1.4fr_7rem]">
                                <div className="grid gap-2">
                                    <Label htmlFor="modulo">Módulo</Label>
                                    <select
                                        id="modulo"
                                        value={data.modulo}
                                        onChange={(e) => setData((d) => ({ ...d, modulo: e.target.value, submodulo: '' }))}
                                        className={selectCls}
                                    >
                                        <option value="">Sistema completo (todos los módulos)</option>
                                        {modulos.map((m) => (
                                            <option key={m.codigo} value={m.codigo}>
                                                {m.codigo} · {m.nombre}
                                                {m.contratado ? '' : ' (no contratado)'}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.modulo} />
                                    {data.modulo && Object.keys(submodulos[data.modulo] ?? {}).length > 0 && (
                                        <>
                                            <Label htmlFor="submodulo" className="mt-1">
                                                Parte del módulo
                                            </Label>
                                            <select
                                                id="submodulo"
                                                value={data.submodulo}
                                                onChange={(e) => setData('submodulo', e.target.value)}
                                                className={selectCls}
                                            >
                                                <option value="">Todo el módulo {data.modulo}</option>
                                                {Object.entries(submodulos[data.modulo]).map(([clave, nombre]) => (
                                                    <option key={clave} value={clave}>
                                                        {clave.includes('.') ? `   ↳ ${nombre.split(' · ').slice(1).join(' · ')}` : nombre}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError message={errors.submodulo} />
                                        </>
                                    )}
                                </div>
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
                                            <td className="px-4 py-2.5 text-xs">{nombreModulo(p.modulo, p.submodulo)}</td>
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
