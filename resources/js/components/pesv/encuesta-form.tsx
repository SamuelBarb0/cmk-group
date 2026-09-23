import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export interface Pregunta {
    clave: string;
    texto: string;
    tipo: 'texto' | 'numero' | 'fecha' | 'unica' | 'multiple';
    opciones?: string[];
    requerida?: boolean;
    largo?: boolean;
    min?: number;
    max?: number;
    si?: [string, string];
}
export interface Seccion {
    titulo: string;
    preguntas: Pregunta[];
}
export type Respuestas = Record<string, string | string[] | undefined>;

/** ¿La pregunta aplica con lo respondido? Misma regla que el servidor. */
export function aplica(p: Pregunta, r: Respuestas): boolean {
    if (!p.si) return true;
    const v = r[p.si[0]];
    return Array.isArray(v) ? v.includes(p.si[1]) : v === p.si[1];
}

/**
 * Formulario de la encuesta de movilidad (RE-SST-36). Controlado: el padre
 * guarda las respuestas y decide cómo se envían.
 */
export function EncuestaForm({
    secciones,
    valores,
    onChange,
    errores = {},
}: {
    secciones: Seccion[];
    valores: Respuestas;
    onChange: (v: Respuestas) => void;
    errores?: Record<string, string>;
}) {
    const set = (clave: string, v: string | string[]) => onChange({ ...valores, [clave]: v });
    const alternar = (clave: string, opcion: string) => {
        const actual = (valores[clave] as string[] | undefined) ?? [];
        set(clave, actual.includes(opcion) ? actual.filter((o) => o !== opcion) : [...actual, opcion]);
    };

    return (
        <div className="space-y-6">
            {secciones.map((s) => {
                const visibles = s.preguntas.filter((p) => aplica(p, valores));
                return (
                    <fieldset key={s.titulo} className="space-y-4">
                        <legend className="font-brand text-primary mb-1 text-base font-bold">{s.titulo}</legend>
                        {visibles.map((p) => {
                            const id = `enc_${p.clave}`;
                            const error = errores[`respuestas.${p.clave}`];
                            return (
                                <div key={p.clave} className="grid gap-1.5">
                                    <Label htmlFor={p.tipo === 'unica' || p.tipo === 'multiple' ? undefined : id} className="leading-snug">
                                        {p.texto}
                                        {p.requerida && <span className="text-destructive"> *</span>}
                                        {p.tipo === 'multiple' && <span className="text-muted-foreground font-normal"> (puede elegir varias)</span>}
                                    </Label>
                                    {p.tipo === 'unica' || p.tipo === 'multiple' ? (
                                        <div
                                            className="flex flex-wrap gap-1.5"
                                            role={p.tipo === 'unica' ? 'radiogroup' : 'group'}
                                            aria-label={p.texto}
                                        >
                                            {p.opciones!.map((o) => {
                                                const activo =
                                                    p.tipo === 'unica'
                                                        ? valores[p.clave] === o
                                                        : ((valores[p.clave] as string[] | undefined) ?? []).includes(o);
                                                return (
                                                    <button
                                                        key={o}
                                                        type="button"
                                                        role={p.tipo === 'unica' ? 'radio' : 'checkbox'}
                                                        aria-checked={activo}
                                                        onClick={() => (p.tipo === 'unica' ? set(p.clave, o) : alternar(p.clave, o))}
                                                        className={cn(
                                                            'rounded-md border px-2.5 py-1.5 text-left text-sm transition-colors',
                                                            activo ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted',
                                                        )}
                                                    >
                                                        {o}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    ) : p.largo ? (
                                        <textarea
                                            id={id}
                                            rows={3}
                                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                            value={(valores[p.clave] as string) ?? ''}
                                            onChange={(e) => set(p.clave, e.target.value)}
                                        />
                                    ) : (
                                        <Input
                                            id={id}
                                            type={p.tipo === 'numero' ? 'number' : p.tipo === 'fecha' ? 'date' : 'text'}
                                            min={p.min}
                                            max={p.max}
                                            step={p.tipo === 'numero' ? 'any' : undefined}
                                            className="max-w-md"
                                            value={(valores[p.clave] as string) ?? ''}
                                            onChange={(e) => set(p.clave, e.target.value)}
                                        />
                                    )}
                                    {error && <p className="text-destructive text-xs">{error}</p>}
                                </div>
                            );
                        })}
                    </fieldset>
                );
            })}
        </div>
    );
}
