import { Breadcrumbs } from '@/components/breadcrumbs';
import { useCodigosSig } from '@/components/codigo-sig';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { usePermissions } from '@/hooks/use-permissions';
import { type BreadcrumbItem as BreadcrumbItemType, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Presentation } from 'lucide-react';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const { can } = usePermissions();
    const { props } = usePage<SharedData>();
    // En la pantalla de un módulo, la presentación sale de ese módulo del mapa.
    const codigo = useCodigosSig()[0];
    const puedePresentar = can('documents.manage') && props.tenant !== null;

    return (
        <header className="border-sidebar-border/50 flex h-16 shrink-0 items-center gap-2 border-b px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {puedePresentar && codigo && (
                <Button asChild variant="outline" size="sm" className="ml-auto gap-1.5">
                    <Link href={`/presentaciones?modulo=${codigo}`} title={`Generar una presentación de ${codigo} con los datos del cliente`}>
                        <Presentation className="size-4" /> Presentación
                    </Link>
                </Button>
            )}
        </header>
    );
}
