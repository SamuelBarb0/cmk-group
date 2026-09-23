import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

/** [texto, marca] — la marca es «diferible» (operador) o «cuando aplique» (vehículo). */
type Item = [string, boolean?];
export type Catalogo = Record<string, Record<string, Item>>;
type Respuesta = { estado: string; obs: string | null };

const OPCIONES = [
    { valor: 'cumple', label: 'Cumple', activo: 'bg-emerald-600 text-white border-emerald-600' },
    { valor: 'no_cumple', label: 'No cumple', activo: 'bg-red-600 text-white border-red-600' },
    { valor: 'no_aplica', label: 'N/A', activo: 'bg-slate-500 text-white border-slate-500' },
];

export const RESULTADO: Record<string, { texto: string; clase: string }> = {
    cumple: { texto: 'Cumple', clase: 'bg-emerald-600/10 text-emerald-700 dark:text-emerald-400' },
    no_cumple: { texto: 'No cumple', clase: 'bg-red-600/10 text-red-700 dark:text-red-400' },
    pendiente: { texto: 'Pendiente', clase: 'bg-amber-500/10 text-amber-700 dark:text-amber-400' },
};

/**
 * Lista de requisitos del paso 11 (operador o vehículo). Se guarda entera:
 * es un chequeo que se hace de una vez, con su fecha, no pregunta por pregunta.
 */
export function ListaRequisitos({
    catalogo,
    respuestas,
    fecha,
    observaciones,
    placa,
    placas,
    url,
    canManage,
    marca,
}: {
    catalogo: Catalogo;
    respuestas: Record<string, Respuesta>;
    fecha: string | null;
    observaciones: string | null;
    /** Solo para el operador: placa asignada. */
    placa?: string | null;
    placas?: string[];
    url: string;
    canManage: boolean;
    /** Texto de la marca de los ítems (p. ej. «Hasta un mes después del ingreso»). */
    marca: string;
}) {
    const hoy = new Date().toISOString().slice(0, 10);
    const form = useForm<{
        fecha: string;
        placa_asignada: string;
        observaciones: string;
        respuestas: Record<string, Respuesta>;
    }>({
        fecha: fecha ?? hoy,
        placa_asignada: placa ?? '',
        observaciones: observaciones ?? '',
        respuestas: { ...respuestas },
    });

    const marcar = (clave: string, estado: string) =>
        form.setData('respuestas', { ...form.data.respuestas, [clave]: { estado, obs: form.data.respuestas[clave]?.obs ?? null } });
    const anotar = (clave: string, obs: string) =>
        form.setData('respuestas', { ...form.data.respuestas, [clave]: { estado: form.data.respuestas[clave]?.estado ?? '', obs } });
    const todas = (estado: string) =>
        form.setData(
            'respuestas',
            Object.fromEntries(
                Object.values(catalogo)
                    .flatMap((g) => Object.keys(g))
                    .map((k) => [k, { estado: form.data.respuestas[k]?.estado || estado, obs: form.data.respuestas[k]?.obs ?? null }]),
            ),
        );

    const total = Object.values(catalogo).reduce((n, g) => n + Object.keys(g).length, 0);
    const respondidas = Object.values(form.data.respuestas).filter((r) => r?.estado).length;

    const guardar: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(url, { preserveScroll: true });
    };

    return (
        <form onSubmit={guardar} className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-3">
                <div className="grid gap-1.5">
                    <Label htmlFor="req_fecha">Fecha de verificación</Label>
                    <Input
                        id="req_fecha"
                        type="date"
                        max={hoy}
                        disabled={!canManage}
                        value={form.data.fecha}
                        onChange={(e) => form.setData('fecha', e.target.value)}
                    />
                    <InputError message={form.errors.fecha} />
                </div>
                {placas && (
                    <div className="grid gap-1.5">
                        <Label htmlFor="req_placa">Placa asignada</Label>
                        <select
                            id="req_placa"
                            disabled={!canManage}
                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            value={form.data.placa_asignada}
                            onChange={(e) => form.setData('placa_asignada', e.target.value)}
                        >
                            <option value="">Sin vehículo asignado</option>
                            {placas.map((p) => (
                                <option key={p}>{p}</option>
                            ))}
                        </select>
                    </div>
                )}
                <div className="text-muted-foreground flex items-end gap-2 text-xs sm:justify-end">
                    {respondidas} de {total} verificados
                    {canManage && respondidas < total && (
                        <button type="button" className="text-primary hover:underline" onClick={() => todas('cumple')}>
                            Marcar el resto como «cumple»
                        </button>
                    )}
                </div>
            </div>

            {Object.entries(catalogo).map(([grupo, items]) => (
                <div key={grupo}>
                    {Object.keys(catalogo).length > 1 && (
                        <h3 className="text-muted-foreground mb-1.5 text-xs font-semibold tracking-wide uppercase">{grupo}</h3>
                    )}
                    <ul className="divide-border divide-y rounded-lg border">
                        {Object.entries(items).map(([clave, [texto, conMarca]]) => {
                            const r = form.data.respuestas[clave];
                            return (
                                <li key={clave} className="flex flex-col gap-2 p-2.5 md:flex-row md:items-center">
                                    <div className="min-w-0 flex-1 text-sm">
                                        {texto}
                                        {conMarca && <span className="text-muted-foreground ml-1.5 text-xs">({marca})</span>}
                                    </div>
                                    <Input
                                        aria-label={`Observación: ${texto}`}
                                        placeholder="Observación"
                                        disabled={!canManage}
                                        className="h-8 md:w-56"
                                        value={r?.obs ?? ''}
                                        onChange={(e) => anotar(clave, e.target.value)}
                                    />
                                    <div className="flex shrink-0 gap-1" role="group" aria-label={texto}>
                                        {OPCIONES.map((o) => (
                                            <button
                                                key={o.valor}
                                                type="button"
                                                disabled={!canManage}
                                                aria-pressed={r?.estado === o.valor}
                                                onClick={() => marcar(clave, o.valor)}
                                                className={cn(
                                                    'rounded-md border px-2 py-1 text-xs font-medium',
                                                    r?.estado === o.valor ? o.activo : 'text-muted-foreground hover:bg-muted',
                                                )}
                                            >
                                                {o.label}
                                            </button>
                                        ))}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}

            <div className="grid gap-1.5">
                <Label htmlFor="req_obs">Observaciones generales</Label>
                <textarea
                    id="req_obs"
                    rows={2}
                    disabled={!canManage}
                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    value={form.data.observaciones}
                    onChange={(e) => form.setData('observaciones', e.target.value)}
                />
            </div>
            {canManage && (
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        Guardar verificación
                    </Button>
                </div>
            )}
        </form>
    );
}

/** Chip del semáforo de documentos: V vigente, P por vencer, E no cumple. */
export function ChipDocumento({ documento, vence, estado }: { documento: string; vence: string | null; estado: string }) {
    const cls: Record<string, string> = {
        V: 'border-emerald-600/40 bg-emerald-600/10 text-emerald-800 dark:text-emerald-300',
        P: 'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-300',
        E: 'border-red-600/40 bg-red-600/10 text-red-800 dark:text-red-300',
        sin_dato: 'text-muted-foreground border-dashed',
    };
    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs', cls[estado] ?? cls.sin_dato)}>
            <span className="font-semibold">{estado === 'sin_dato' ? '—' : estado}</span>
            {documento}
            <span className="opacity-75">{vence ?? 'sin fecha'}</span>
        </span>
    );
}
