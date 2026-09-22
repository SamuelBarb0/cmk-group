import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * Piezas que comparten las ocho pantallas del PESV.
 *
 * Se extraen aquí porque el estado vacío, las tarjetas de conteo y el aviso de
 * guardado son idénticos en todas; repetirlos garantizaría que se
 * desincronizaran a la primera corrección.
 */

/** Estado vacío cuando no hay cliente activo seleccionado. */
export function SinCliente({ titulo, descripcion }: { titulo: string; descripcion: string }) {
    return (
        <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 className="font-brand text-2xl font-bold tracking-tight">{titulo}</h1>
                <p className="text-muted-foreground text-sm">{descripcion}</p>
            </div>
            <Card>
                <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                    <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                        <Building2 className="size-7" />
                    </div>
                    <p className="font-medium">Selecciona un cliente para gestionar su PESV</p>
                    <Button asChild variant="outline" className="gap-2">
                        <Link href="/clientes">
                            <Building2 className="size-4" /> Ir a Clientes
                        </Link>
                    </Button>
                </CardContent>
            </Card>
        </div>
    );
}

export function StatCard({
    label,
    value,
    icon: Icon,
    danger,
}: {
    label: string;
    value: number | string;
    icon: React.ElementType;
    danger?: boolean;
}) {
    return (
        <Card>
            <CardContent className="flex items-center gap-4 p-5">
                <div
                    className={cn(
                        'flex size-11 items-center justify-center rounded-lg',
                        danger ? 'bg-red-600/10 text-red-600' : 'bg-primary/10 text-primary',
                    )}
                >
                    <Icon className="size-5" />
                </div>
                <div>
                    <div className="text-2xl font-bold tabular-nums">{value}</div>
                    <div className="text-muted-foreground text-sm">{label}</div>
                </div>
            </CardContent>
        </Card>
    );
}

/** Muestra el flash de éxito unos segundos, como en el resto de módulos. */
export function useNotice(success?: string | null) {
    const [notice, setNotice] = useState<string | null>(null);

    useEffect(() => {
        if (success) {
            setNotice(success);
            const t = setTimeout(() => setNotice(null), 3500);
            return () => clearTimeout(t);
        }
    }, [success]);

    return notice;
}

export function Notice({ mensaje }: { mensaje: string | null }) {
    if (!mensaje) return null;

    return <div className="rounded-lg border border-emerald-600/30 bg-emerald-600/10 px-4 py-2 text-sm text-emerald-700">{mensaje}</div>;
}

/** Etiquetas y color de cada estado de un paso del PESV. */
export const ESTADO_LABEL: Record<string, string> = {
    pendiente: 'Pendiente',
    en_proceso: 'En proceso',
    cumple: 'Cumple',
    no_cumple: 'No cumple',
    no_aplica: 'No aplica',
};

const ESTADO_CLASS: Record<string, string> = {
    pendiente: 'bg-muted text-muted-foreground',
    en_proceso: 'bg-amber-500/15 text-amber-700',
    cumple: 'bg-emerald-600/15 text-emerald-700',
    no_cumple: 'bg-red-600/15 text-red-700',
    no_aplica: 'bg-slate-500/15 text-slate-600',
};

export function EstadoBadge({ estado }: { estado: string }) {
    return (
        <Badge variant="secondary" className={cn('font-medium', ESTADO_CLASS[estado])}>
            {ESTADO_LABEL[estado] ?? estado}
        </Badge>
    );
}

/** Días que faltan para un vencimiento, en texto legible. */
export function textoVencimiento(dias: number) {
    if (dias < 0) return `vencido hace ${Math.abs(dias)} d.`;
    if (dias === 0) return 'vence hoy';
    return `vence en ${dias} d.`;
}
