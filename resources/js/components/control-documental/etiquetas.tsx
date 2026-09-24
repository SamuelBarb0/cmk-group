import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/** Piezas compartidas por las tres pantallas del control documental. */

export type Sistema = 'sst' | 'pesv' | 'iso45001' | 'iso9001' | 'iso14001';
export type EstadoDocumento = 'borrador' | 'en_revision' | 'en_aprobacion' | 'vigente' | 'obsoleto';
export type EstadoVersion = 'borrador' | 'en_revision' | 'en_aprobacion' | 'vigente' | 'obsoleta';

export const SISTEMAS: Sistema[] = ['sst', 'pesv', 'iso45001', 'iso9001', 'iso14001'];

export const NOMBRE_SISTEMA: Record<Sistema, string> = {
    sst: 'SG-SST',
    pesv: 'PESV',
    iso45001: 'ISO 45001',
    iso9001: 'ISO 9001',
    iso14001: 'ISO 14001',
};

const CLS_SISTEMA: Record<Sistema, string> = {
    sst: 'border-amber-500/40 text-amber-700 dark:text-amber-400',
    pesv: 'border-sky-500/40 text-sky-700 dark:text-sky-400',
    iso45001: 'border-rose-500/40 text-rose-700 dark:text-rose-400',
    iso9001: 'border-violet-500/40 text-violet-700 dark:text-violet-400',
    iso14001: 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400',
};

const ETIQUETA_ESTADO: Record<EstadoDocumento | EstadoVersion, string> = {
    borrador: 'Borrador',
    en_revision: 'En revisión',
    en_aprobacion: 'En aprobación',
    vigente: 'Vigente',
    obsoleto: 'Obsoleto',
    obsoleta: 'Obsoleta',
};

const CLS_ESTADO: Record<EstadoDocumento | EstadoVersion, string> = {
    borrador: 'bg-muted text-muted-foreground',
    en_revision: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    en_aprobacion: 'bg-blue-500/15 text-blue-700 dark:text-blue-400',
    vigente: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    obsoleto: 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-400 line-through',
    obsoleta: 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-400',
};

export function etiquetaEstado(estado: EstadoDocumento | EstadoVersion): string {
    return ETIQUETA_ESTADO[estado] ?? estado;
}

export function EstadoBadge({ estado }: { estado: EstadoDocumento | EstadoVersion }) {
    return <Badge className={cn('font-normal whitespace-nowrap', CLS_ESTADO[estado])}>{ETIQUETA_ESTADO[estado] ?? estado}</Badge>;
}

export function SistemaChips({ sistemas, className }: { sistemas: Sistema[]; className?: string }) {
    return (
        <div className={cn('flex flex-wrap gap-1', className)}>
            {SISTEMAS.filter((s) => sistemas.includes(s)).map((s) => (
                <span key={s} className={cn('rounded border px-1.5 py-px text-[11px] leading-4 whitespace-nowrap', CLS_SISTEMA[s])}>
                    {NOMBRE_SISTEMA[s]}
                </span>
            ))}
        </div>
    );
}

/** Casillas para elegir las normas que evidencia un documento. */
export function SistemasPicker({ value, onChange }: { value: Sistema[]; onChange: (v: Sistema[]) => void }) {
    return (
        <div className="flex flex-wrap gap-2">
            {SISTEMAS.map((s) => {
                const activo = value.includes(s);
                return (
                    <button
                        key={s}
                        type="button"
                        aria-pressed={activo}
                        onClick={() => onChange(activo ? value.filter((x) => x !== s) : [...value, s])}
                        className={cn(
                            'rounded-md border px-2.5 py-1 text-xs transition-colors',
                            activo ? cn('bg-background font-medium', CLS_SISTEMA[s]) : 'text-muted-foreground border-dashed',
                        )}
                    >
                        {NOMBRE_SISTEMA[s]}
                    </button>
                );
            })}
        </div>
    );
}

export const selectCls = 'border-input bg-background h-9 rounded-md border px-3 text-sm';
export const textareaCls = 'border-input bg-background w-full rounded-md border px-3 py-1.5 text-sm';

export function fechaCorta(iso: string | null): string {
    if (!iso) return '—';
    // «2026-09-23» a secas se lee como medianoche UTC, que en Colombia es el
    // día anterior. Al mediodía no hay huso que la mueva de fecha.
    const fecha = iso.length === 10 ? new Date(`${iso}T12:00:00`) : new Date(iso);
    return fecha.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' });
}

export function fechaHora(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleString('es-CO', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
