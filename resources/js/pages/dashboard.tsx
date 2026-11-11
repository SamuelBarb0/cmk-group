import { type DashboardProps } from '@/components/dashboard/tipos';
import { VistaCliente } from '@/components/dashboard/vista-cliente';
import { VistaConsultor } from '@/components/dashboard/vista-consultor';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

/**
 * Dashboard: primero cómo está la empresa (encabezado con el puntaje 0312),
 * después qué hacer hoy. El consultor sin cliente activo ve su portafolio.
 */
export default function Dashboard() {
    const { props } = usePage<SharedData & DashboardProps>();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="selection:bg-primary/15 mx-auto flex h-full w-full max-w-[90rem] flex-1 flex-col gap-6 p-4 pb-24 md:p-6 md:pb-24">
                {props.view === 'master' ? <VistaConsultor props={props} /> : <VistaCliente props={props} />}
            </div>
        </AppLayout>
    );
}
