import InputError from '@/components/input-error';
import { ModuloPage } from '@/components/modulo-page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { Link, useForm } from '@inertiajs/react';
import { FileSpreadsheet, Sparkles, Upload } from 'lucide-react';
import { FormEventHandler } from 'react';
import { ESTADOS } from './estados';

interface Destino {
    nombre: string;
    descripcion: string;
    padre: string | null;
}

interface Importacion {
    id: number;
    destino: string;
    nombre_original: string;
    hoja: string | null;
    estado: string;
    resultado: { creados?: number[]; borrados?: number } | null;
    usuario: string | null;
    fecha: string;
}

interface Props {
    needsClient: boolean;
    importaciones: Importacion[];
    destinos: Record<string, Destino>;
    permitidos: string[];
    padres: Record<string, { id: number; nombre: string }[]>;
}

export default function ImportarIndex({ needsClient, importaciones, destinos, permitidos, padres }: Props) {
    const form = useForm<{ destino: string; archivo: File | null; padre_id: string }>({ destino: permitidos[0] ?? '', archivo: null, padre_id: '' });
    const padre = destinos[form.data.destino]?.padre ?? null;
    const opcionesPadre = padres[form.data.destino] ?? [];

    const subir: FormEventHandler = (e) => {
        e.preventDefault();
        form.post('/importar', { forceFormData: true });
    };

    return (
        <ModuloPage
            titulo="Importar Excel"
            descripcion="Carga un Excel del cliente a un módulo: la IA propone qué columna va a qué campo y tú revisas antes de importar"
            needsClient={needsClient}
        >
            <Card>
                <CardContent className="p-5">
                    <form onSubmit={subir} className="grid gap-4 md:grid-cols-[1fr_1fr_auto] md:items-end">
                        <div className="space-y-1.5">
                            <Label htmlFor="destino">Cargar en</Label>
                            <select
                                id="destino"
                                value={form.data.destino}
                                onChange={(e) => form.setData('destino', e.target.value)}
                                className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                            >
                                {permitidos.map((k) => (
                                    <option key={k} value={k}>
                                        {destinos[k].nombre}
                                    </option>
                                ))}
                            </select>
                            {form.data.destino && <p className="text-muted-foreground text-xs">{destinos[form.data.destino]?.descripcion}</p>}
                            <InputError message={form.errors.destino} />
                            {padre && (
                                <div className="space-y-1.5 pt-1">
                                    <Label htmlFor="padre_id">{padre}</Label>
                                    <select
                                        id="padre_id"
                                        value={form.data.padre_id}
                                        onChange={(e) => form.setData('padre_id', e.target.value)}
                                        className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                    >
                                        <option value="">{opcionesPadre.length ? 'Elige…' : 'No hay ninguna todavía: créala en su módulo'}</option>
                                        {opcionesPadre.map((o) => (
                                            <option key={o.id} value={o.id}>
                                                {o.nombre}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={form.errors.padre_id} />
                                </div>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="archivo">Archivo (.xlsx, .xls, .ods o .csv, hasta 10 MB)</Label>
                            <input
                                id="archivo"
                                type="file"
                                accept=".xlsx,.xls,.ods,.csv"
                                onChange={(e) => form.setData('archivo', e.target.files?.[0] ?? null)}
                                className="border-input bg-background file:bg-muted block h-9 w-full rounded-md border text-sm file:mr-3 file:h-full file:border-0 file:px-3"
                            />
                            <InputError message={form.errors.archivo} />
                        </div>
                        <Button type="submit" disabled={form.processing || !form.data.archivo || (!!padre && !form.data.padre_id)} className="gap-2">
                            <Upload className="size-4" /> {form.processing ? 'Subiendo…' : 'Subir y analizar'}
                        </Button>
                    </form>
                    <p className="text-muted-foreground mt-4 flex items-start gap-2 text-xs">
                        <Sparkles className="mt-0.5 size-3.5 shrink-0" />
                        La IA solo ve los encabezados y una muestra para proponer el mapeo; los datos los convierte y valida la plataforma con las
                        mismas reglas del formulario de cada módulo. No se guarda nada hasta que confirmes, y una importación se puede deshacer.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {importaciones.length === 0 ? (
                        <div className="text-muted-foreground p-8 text-center text-sm">Todavía no hay importaciones para esta empresa.</div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[40rem] text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-2.5 font-medium">Archivo</th>
                                        <th className="px-4 py-2.5 font-medium">Módulo</th>
                                        <th className="px-4 py-2.5 font-medium">Estado</th>
                                        <th className="px-4 py-2.5 font-medium">Fecha</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {importaciones.map((i) => (
                                        <tr key={i.id} className="hover:bg-muted/30 border-t">
                                            <td className="px-4 py-2.5">
                                                <Link href={`/importar/${i.id}`} className="flex items-center gap-2 font-medium hover:underline">
                                                    <FileSpreadsheet className="size-4 text-green-700" /> {i.nombre_original}
                                                </Link>
                                                {i.hoja && <div className="text-muted-foreground ml-6 text-xs">Hoja «{i.hoja}»</div>}
                                            </td>
                                            <td className="px-4 py-2.5">{destinos[i.destino]?.nombre ?? i.destino}</td>
                                            <td className={cn('px-4 py-2.5 text-xs', ESTADOS[i.estado]?.[1])}>
                                                {ESTADOS[i.estado]?.[0] ?? i.estado}
                                                {i.estado === 'aplicado' && i.resultado?.creados && ` · ${i.resultado.creados.length} registro(s)`}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2.5 text-xs whitespace-nowrap">
                                                {i.fecha}
                                                {i.usuario && ` · ${i.usuario}`}
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
