import { CodigoSig } from '@/components/codigo-sig';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Building2, CheckCircle2 } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * Cascarón común de una pantalla de módulo.
 *
 * Las pantallas de módulo repiten siempre lo mismo: el layout, el título con el
 * nombre del cliente activo, el aviso de «guardado» que se desvanece, y la
 * tarjeta de «selecciona un cliente» cuando el consultor no tiene ninguno
 * elegido. Estaba copiado en cada página, con pequeñas diferencias que no eran
 * intencionales sino restos de haberlo copiado mal.
 *
 * Lo usan los módulos nuevos (requisitos legales, actos y condiciones,
 * accidentalidad, ausentismo y ACPM). Las pantallas anteriores siguen con su
 * copia: migrarlas es un cambio aparte y no hacía falta para esto.
 */
interface Props {
    titulo: string;
    descripcion: string;
    /** El guard: sin cliente activo no hay datos que mostrar. */
    needsClient: boolean;
    /** Botón de acción principal (normalmente «Nuevo …»). */
    accion?: React.ReactNode;
    /** Barra de filtros opcional, debajo de la cabecera. */
    filtros?: React.ReactNode;
    children: React.ReactNode;
}

export function ModuloPage({ titulo, descripcion, needsClient, accion, filtros, children }: Props) {
    const page = usePage<SharedData>();
    const flash = page.props.flash;
    const tenant = page.props.tenant as { id: number; name: string } | null;
    const [notice, setNotice] = useState<string | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: titulo, href: '#' },
    ];

    useEffect(() => {
        if (flash?.success) {
            setNotice(flash.success);
            const t = setTimeout(() => setNotice(null), 3500);
            return () => clearTimeout(t);
        }
    }, [flash?.success]);

    if (needsClient) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title={titulo} />
                <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            <CodigoSig className="mr-2" />
                            {titulo}
                        </h1>
                        <p className="text-muted-foreground text-sm">{descripcion}</p>
                    </div>
                    <Card>
                        <CardContent className="flex min-h-60 flex-col items-center justify-center gap-3 text-center">
                            <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                                <Building2 className="size-7" />
                            </div>
                            <p className="font-medium">Selecciona un cliente para ver esta información</p>
                            <Button asChild variant="outline" className="gap-2">
                                <Link href="/clientes">
                                    <Building2 className="size-4" /> Ir a Clientes
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={titulo} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            <CodigoSig className="mr-2" />
                            {titulo}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {descripcion}
                            {tenant ? (
                                <>
                                    {' '}
                                    · <span className="font-medium">{tenant.name}</span>
                                </>
                            ) : null}
                        </p>
                    </div>
                    {accion}
                </div>

                {filtros}

                {notice && (
                    <div className="flex items-center gap-2 rounded-lg border border-green-600/30 bg-green-600/10 px-4 py-2.5 text-sm text-green-700 dark:text-green-400">
                        <CheckCircle2 className="size-4" /> {notice}
                    </div>
                )}

                {children}
            </div>
        </AppLayout>
    );
}

/** Tarjeta de cifra. `alerta` la pinta en rojo cuando el número exige actuar. */
export function StatCard({
    label,
    value,
    icon: Icon,
    alerta,
    sufijo,
}: {
    label: string;
    value: number | string;
    icon: React.ElementType;
    alerta?: boolean;
    sufijo?: string;
}) {
    return (
        <Card>
            <CardContent className="flex items-center gap-4 p-5">
                <div
                    className={
                        alerta
                            ? 'bg-destructive/10 text-destructive flex size-11 items-center justify-center rounded-lg'
                            : 'bg-primary/10 text-primary flex size-11 items-center justify-center rounded-lg'
                    }
                >
                    <Icon className="size-5" />
                </div>
                <div>
                    <div className="text-2xl font-bold tabular-nums">
                        {value}
                        {sufijo}
                    </div>
                    <div className="text-muted-foreground text-sm">{label}</div>
                </div>
            </CardContent>
        </Card>
    );
}
