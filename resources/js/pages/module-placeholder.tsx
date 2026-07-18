import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Construction } from 'lucide-react';

interface Props {
    title: string;
    description: string;
    permission: string;
}

export default function ModulePlaceholder({ title, description }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title, href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="font-brand text-2xl font-bold tracking-tight">{title}</h1>
                    <p className="text-muted-foreground text-sm">{description}</p>
                </div>

                <Card className="flex-1">
                    <CardContent className="flex h-full min-h-80 flex-col items-center justify-center gap-3 text-center">
                        <div className="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-xl">
                            <Construction className="size-7" />
                        </div>
                        <div>
                            <p className="font-medium">Módulo en construcción</p>
                            <p className="text-muted-foreground mx-auto max-w-md text-sm">
                                Este módulo forma parte del alcance de las siguientes fases del proyecto. La estructura, los accesos por rol y la
                                segregación por cliente ya están habilitados.
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
