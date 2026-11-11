import { selectCls } from '@/components/control-documental/etiquetas';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';

/** Un documento que piden los pasos del PESV (lo manda el controlador). */
export interface OpcionPesv {
    codigo: string;
    nombre: string;
    pasos: number[];
}

/**
 * «Respalda en el PESV»: con esto el paso del PESV reconoce el documento en
 * «Lo que ya tiene la plataforma», aunque la empresa lo haya subido ella misma
 * y nunca haya pasado por Documentos IA. Sin PESV contratado no se muestra.
 */
export function RespaldaPesv({
    id,
    value,
    onChange,
    opciones,
    disabled,
    error,
}: {
    id: string;
    value: string;
    onChange: (codigo: string) => void;
    opciones: OpcionPesv[];
    disabled?: boolean;
    error?: string;
}) {
    if (opciones.length === 0) return null;

    return (
        // min-w-0 + w-full: las opciones son largas y, sin esto, el select se
        // ensancha hasta salirse de la tarjeta de la ficha.
        <div className="grid min-w-0 gap-1.5">
            <Label htmlFor={id}>Respalda en el PESV</Label>
            <select
                id={id}
                disabled={disabled}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className={selectCls + ' w-full min-w-0 truncate'}
            >
                <option value="">No respalda un paso del PESV</option>
                {opciones.map((o) => (
                    <option key={o.codigo} value={o.codigo}>
                        {o.pasos.length === 1 ? `Paso ${o.pasos[0]}` : `Pasos ${o.pasos.join(', ')}`} · {o.codigo} · {o.nombre}
                    </option>
                ))}
            </select>
            <p className="text-muted-foreground text-xs">
                Así el paso lo cuenta en «Lo que ya tiene la plataforma» y no hay que generarlo en Documentos IA.
            </p>
            <InputError message={error} />
        </div>
    );
}
