import { CodigoSig } from '@/components/codigo-sig';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowDown, ArrowLeft, ArrowUp, Info, Plus, Save, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';

type Tipo = 'text' | 'textarea' | 'date' | 'number' | 'select' | 'checklist' | 'firma';

interface CampoSchema {
    key: string;
    label: string;
    tipo: Tipo;
    requerido?: boolean;
    opciones?: string[];
    items?: string[];
}
interface Schema {
    secciones: { titulo: string; campos: CampoSchema[] }[];
}
interface FormatoProp {
    id?: number;
    codigo: string;
    nombre: string;
    categoria: string;
    grupo: string;
    descripcion: string | null;
    orden?: number;
    schema: Schema;
}
interface Props {
    formato: FormatoProp | null;
    registros: number | null;
    tipos: Record<Tipo, string>;
    grupos: Record<string, string>;
    categorias: string[];
}

// Estado local del editor: cada campo lleva un uid para que React no confunda
// filas al reordenar, y las opciones/ítems se editan como texto (uno por línea).
interface Campo {
    uid: number;
    key: string;
    label: string;
    tipo: Tipo;
    requerido: boolean;
    lista: string;
}
interface Seccion {
    uid: number;
    titulo: string;
    campos: Campo[];
}

const inputCls = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

export default function EditorFormato({ formato, registros, tipos, grupos, categorias }: Props) {
    const errors = usePage<SharedData>().props.errors as Record<string, string>;
    const uid = useRef(0);
    const nuevoUid = () => ++uid.current;

    const esNuevo = !formato?.id;
    const [datos, setDatos] = useState({
        codigo: formato?.codigo ?? '',
        nombre: formato?.nombre ?? '',
        categoria: formato?.categoria ?? categorias[0],
        grupo: formato?.grupo ?? 'inspeccion',
        descripcion: formato?.descripcion ?? '',
        orden: formato?.orden ?? '',
    });
    const [secciones, setSecciones] = useState<Seccion[]>(() =>
        (formato?.schema.secciones ?? [{ titulo: 'Datos generales', campos: [{ key: '', label: '', tipo: 'text' as Tipo }] }]).map((s) => ({
            uid: nuevoUid(),
            titulo: s.titulo,
            campos: s.campos.map((c) => ({
                uid: nuevoUid(),
                key: c.key,
                label: c.label,
                tipo: c.tipo,
                requerido: !!c.requerido,
                lista: (c.tipo === 'select' ? c.opciones : c.tipo === 'checklist' ? c.items : [])?.join('\n') ?? '',
            })),
        })),
    );
    const [guardando, setGuardando] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Formatos', href: '/formatos' },
        { title: 'Catálogo', href: '/formatos/catalogo' },
        { title: esNuevo ? 'Nuevo' : datos.nombre || 'Editar', href: '#' },
    ];

    // --- Edición de secciones y campos -------------------------------------
    function setSeccion(i: number, cambio: Partial<Seccion>) {
        setSecciones((ss) => ss.map((s, j) => (j === i ? { ...s, ...cambio } : s)));
    }
    function setCampo(i: number, k: number, cambio: Partial<Campo>) {
        setSecciones((ss) => ss.map((s, j) => (j === i ? { ...s, campos: s.campos.map((c, m) => (m === k ? { ...c, ...cambio } : c)) } : s)));
    }
    function mover<T>(lista: T[], i: number, d: -1 | 1): T[] {
        const n = [...lista];
        const j = i + d;
        if (j < 0 || j >= n.length) return lista;
        [n[i], n[j]] = [n[j], n[i]];
        return n;
    }
    const campoVacio = (): Campo => ({ uid: nuevoUid(), key: '', label: '', tipo: 'text', requerido: false, lista: '' });

    // --- Guardar -----------------------------------------------------------
    function guardar() {
        const payload = {
            ...datos,
            orden: datos.orden === '' ? null : Number(datos.orden),
            schema: {
                secciones: secciones.map((s) => ({
                    titulo: s.titulo,
                    campos: s.campos.map((c) => {
                        const lista = c.lista
                            .split('\n')
                            .map((l) => l.trim())
                            .filter(Boolean);
                        return {
                            key: c.key || null,
                            label: c.label,
                            tipo: c.tipo,
                            requerido: c.requerido,
                            ...(c.tipo === 'select' ? { opciones: lista } : {}),
                            ...(c.tipo === 'checklist' ? { items: lista } : {}),
                        };
                    }),
                })),
            },
        };
        const opts = { preserveScroll: true, onStart: () => setGuardando(true), onFinish: () => setGuardando(false) };
        if (esNuevo) router.post(route('formatos.catalogo.store'), payload, opts);
        else router.put(route('formatos.catalogo.update', formato!.id), payload, opts);
    }

    const err = (path: string) => errors[path];
    const errCampo = (i: number, k: number) =>
        Object.entries(errors)
            .filter(([p]) => p.startsWith(`schema.secciones.${i}.campos.${k}.`))
            .map(([, m]) => m);
    const hayErrores = Object.keys(errors).length > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={esNuevo ? 'Nuevo formato' : `Editar ${datos.nombre}`} />
            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <Button variant="ghost" size="icon" asChild aria-label="Volver al catálogo">
                            <Link href={route('formatos.catalogo.index')}>
                                <ArrowLeft className="size-4" />
                            </Link>
                        </Button>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            <CodigoSig className="mr-2" />
                            {esNuevo ? 'Nuevo formato' : 'Editar formato'}
                        </h1>
                    </div>
                    <Button onClick={guardar} disabled={guardando} className="gap-2">
                        <Save className="size-4" /> {guardando ? 'Guardando…' : 'Guardar'}
                    </Button>
                </div>

                {!esNuevo && (registros ?? 0) > 0 && (
                    <div className="flex items-start gap-2 rounded-lg border border-blue-600/30 bg-blue-600/10 px-4 py-2.5 text-sm text-blue-800 dark:text-blue-300">
                        <Info className="mt-0.5 size-4 shrink-0" />
                        <span>
                            Este formato ya tiene {registros} registro(s) diligenciado(s). Conservan la versión con la que se llenaron: los cambios
                            aplican a los registros nuevos.
                        </span>
                    </div>
                )}
                {hayErrores && (
                    <div className="border-destructive/30 bg-destructive/10 text-destructive flex items-start gap-2 rounded-lg border px-4 py-2.5 text-sm">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <span>Hay datos por corregir: están marcados en rojo abajo.</span>
                    </div>
                )}

                {/* Datos del formato */}
                <Card>
                    <CardContent className="grid gap-4 p-5 md:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="nombre">Nombre</Label>
                            <Input id="nombre" value={datos.nombre} onChange={(e) => setDatos({ ...datos, nombre: e.target.value })} />
                            {err('nombre') && <p className="text-destructive text-xs">{err('nombre')}</p>}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="codigo">Código</Label>
                            <Input
                                id="codigo"
                                className="font-mono uppercase"
                                placeholder="INS-EXT-01"
                                value={datos.codigo}
                                onChange={(e) => setDatos({ ...datos, codigo: e.target.value.toUpperCase() })}
                            />
                            {err('codigo') && <p className="text-destructive text-xs">{err('codigo')}</p>}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="grupo">Tipo</Label>
                            <select
                                id="grupo"
                                className={inputCls}
                                value={datos.grupo}
                                onChange={(e) => setDatos({ ...datos, grupo: e.target.value })}
                            >
                                {Object.entries(grupos).map(([v, l]) => (
                                    <option key={v} value={v}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="categoria">Sistema</Label>
                                <select
                                    id="categoria"
                                    className={inputCls}
                                    value={datos.categoria}
                                    onChange={(e) => setDatos({ ...datos, categoria: e.target.value })}
                                >
                                    {categorias.map((c) => (
                                        <option key={c}>{c}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="orden">Orden</Label>
                                <Input
                                    id="orden"
                                    type="number"
                                    min={0}
                                    placeholder="Al final"
                                    value={datos.orden}
                                    onChange={(e) => setDatos({ ...datos, orden: e.target.value })}
                                />
                            </div>
                        </div>
                        <div className="grid gap-1.5 md:col-span-2">
                            <Label htmlFor="descripcion">Descripción (opcional)</Label>
                            <textarea
                                id="descripcion"
                                rows={2}
                                className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                value={datos.descripcion}
                                onChange={(e) => setDatos({ ...datos, descripcion: e.target.value })}
                            />
                        </div>
                    </CardContent>
                </Card>

                {err('schema.secciones') && <p className="text-destructive text-sm">{err('schema.secciones')}</p>}

                {/* Secciones */}
                {secciones.map((s, i) => (
                    <Card key={s.uid}>
                        <CardContent className="flex flex-col gap-4 p-5">
                            <div className="flex items-center gap-2">
                                <span className="text-muted-foreground w-6 text-sm font-semibold">{i + 1}.</span>
                                <Input
                                    aria-label="Título de la sección"
                                    placeholder="Título de la sección"
                                    className={cn('font-semibold', err(`schema.secciones.${i}.titulo`) && 'border-destructive')}
                                    value={s.titulo}
                                    onChange={(e) => setSeccion(i, { titulo: e.target.value })}
                                />
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    disabled={i === 0}
                                    onClick={() => setSecciones((ss) => mover(ss, i, -1))}
                                    aria-label="Subir sección"
                                >
                                    <ArrowUp className="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    disabled={i === secciones.length - 1}
                                    onClick={() => setSecciones((ss) => mover(ss, i, 1))}
                                    aria-label="Bajar sección"
                                >
                                    <ArrowDown className="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    onClick={() =>
                                        confirm(`¿Quitar la sección «${s.titulo || i + 1}» y sus campos?`) &&
                                        setSecciones((ss) => ss.filter((_, j) => j !== i))
                                    }
                                    aria-label="Quitar sección"
                                >
                                    <Trash2 className="text-destructive size-4" />
                                </Button>
                            </div>
                            {err(`schema.secciones.${i}.campos`) && <p className="text-destructive text-xs">{err(`schema.secciones.${i}.campos`)}</p>}

                            <div className="flex flex-col gap-3">
                                {s.campos.map((c, k) => {
                                    const errs = errCampo(i, k);
                                    const conLista = c.tipo === 'select' || c.tipo === 'checklist';
                                    return (
                                        <div key={c.uid} className={cn('bg-muted/30 rounded-lg border p-3', errs.length && 'border-destructive')}>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Input
                                                    aria-label="Etiqueta del campo"
                                                    placeholder="Etiqueta (lo que ve quien diligencia)"
                                                    className="min-w-48 flex-1"
                                                    value={c.label}
                                                    onChange={(e) => setCampo(i, k, { label: e.target.value })}
                                                />
                                                <select
                                                    aria-label="Tipo de campo"
                                                    className={cn(inputCls, 'sm:w-auto')}
                                                    value={c.tipo}
                                                    onChange={(e) => setCampo(i, k, { tipo: e.target.value as Tipo })}
                                                >
                                                    {Object.entries(tipos).map(([v, l]) => (
                                                        <option key={v} value={v}>
                                                            {l}
                                                        </option>
                                                    ))}
                                                </select>
                                                <label className="flex items-center gap-1.5 text-xs">
                                                    <input
                                                        type="checkbox"
                                                        checked={c.requerido}
                                                        onChange={(e) => setCampo(i, k, { requerido: e.target.checked })}
                                                    />
                                                    Obligatorio
                                                </label>
                                                <div className="ml-auto flex">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        disabled={k === 0}
                                                        onClick={() => setSeccion(i, { campos: mover(s.campos, k, -1) })}
                                                        aria-label="Subir campo"
                                                    >
                                                        <ArrowUp className="size-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        disabled={k === s.campos.length - 1}
                                                        onClick={() => setSeccion(i, { campos: mover(s.campos, k, 1) })}
                                                        aria-label="Bajar campo"
                                                    >
                                                        <ArrowDown className="size-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() => setSeccion(i, { campos: s.campos.filter((_, m) => m !== k) })}
                                                        aria-label="Quitar campo"
                                                    >
                                                        <Trash2 className="text-destructive size-4" />
                                                    </Button>
                                                </div>
                                            </div>
                                            {conLista && (
                                                <textarea
                                                    aria-label={c.tipo === 'select' ? 'Opciones' : 'Ítems a verificar'}
                                                    rows={Math.min(10, Math.max(3, c.lista.split('\n').length + 1))}
                                                    placeholder={c.tipo === 'select' ? 'Una opción por línea' : 'Un ítem a verificar por línea'}
                                                    className="border-input bg-background mt-2 w-full rounded-md border px-3 py-2 text-sm"
                                                    value={c.lista}
                                                    onChange={(e) => setCampo(i, k, { lista: e.target.value })}
                                                />
                                            )}
                                            {c.key && <div className="text-muted-foreground mt-1 font-mono text-[10px]">clave: {c.key}</div>}
                                            {errs.map((m) => (
                                                <p key={m} className="text-destructive mt-1 text-xs">
                                                    {m}
                                                </p>
                                            ))}
                                        </div>
                                    );
                                })}
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                className="gap-2 self-start"
                                onClick={() => setSeccion(i, { campos: [...s.campos, campoVacio()] })}
                            >
                                <Plus className="size-4" /> Agregar campo
                            </Button>
                        </CardContent>
                    </Card>
                ))}

                <div className="flex flex-wrap justify-between gap-3">
                    <Button
                        variant="outline"
                        className="gap-2"
                        onClick={() => setSecciones((ss) => [...ss, { uid: nuevoUid(), titulo: '', campos: [campoVacio()] }])}
                    >
                        <Plus className="size-4" /> Agregar sección
                    </Button>
                    <Button onClick={guardar} disabled={guardando} className="gap-2">
                        <Save className="size-4" /> {guardando ? 'Guardando…' : 'Guardar'}
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
