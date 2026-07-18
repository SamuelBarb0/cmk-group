import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Building2, HardHat, Leaf, ShieldCheck, Truck, UserCircle2, Users } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

interface ClientRow {
    id: number;
    name: string;
    city: string | null;
    nit: string | null;
    is_active: boolean;
    users_count: number;
}

interface DashboardProps {
    view: 'master' | 'client';
    stats: Record<string, number>;
    clients?: ClientRow[];
    tenant?: { id: number; name: string; nit: string | null; city: string | null } | null;
}

const modules = [
    { key: 'sst', title: 'SST', desc: 'Matriz de peligros, plan anual, indicadores y accidentes.', url: '/sst', icon: HardHat },
    { key: 'hseq', title: 'HSEQ', desc: 'Gestión ambiental, calidad y auditorías.', url: '/hseq', icon: Leaf },
    { key: 'pesv', title: 'PESV', desc: 'Gestión vial, conductores, vehículos y capacitaciones.', url: '/pesv', icon: Truck },
];

function StatCard({ label, value, icon: Icon }: { label: string; value: number; icon: React.ElementType }) {
    return (
        <Card>
            <CardContent className="flex items-center gap-4 p-5">
                <div className="bg-primary/10 text-primary flex size-11 items-center justify-center rounded-lg">
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

function ModuleGrid({ can }: { can: (p: string) => boolean }) {
    const visible = modules.filter((m) => can(`${m.key}.view`));
    if (visible.length === 0) return null;

    return (
        <div>
            <h2 className="font-brand mb-3 text-sm font-semibold tracking-wide text-muted-foreground uppercase">Módulos</h2>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {visible.map((m) => (
                    <Link key={m.key} href={m.url} prefetch className="group">
                        <Card className="h-full transition-colors group-hover:border-primary/50">
                            <CardHeader className="flex flex-row items-center gap-3 space-y-0">
                                <div className="bg-primary text-primary-foreground flex size-10 items-center justify-center rounded-lg">
                                    <m.icon className="size-5" />
                                </div>
                                <CardTitle className="font-brand text-lg">{m.title}</CardTitle>
                            </CardHeader>
                            <CardContent className="text-muted-foreground text-sm">{m.desc}</CardContent>
                        </Card>
                    </Link>
                ))}
            </div>
        </div>
    );
}

export default function Dashboard() {
    const { props } = usePage<SharedData & DashboardProps>();
    const { auth } = props;
    const { can, roleLabel } = usePermissions();
    const isMaster = props.view === 'master';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Encabezado */}
                <div className="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h1 className="font-brand text-2xl font-bold tracking-tight">
                            {isMaster ? 'Panel de consultoría' : (props.tenant?.name ?? 'Mi empresa')}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Hola, {auth.user.name}
                            {roleLabel ? ` · ${roleLabel}` : ''}
                        </p>
                    </div>
                    {isMaster ? (
                        <Badge variant="secondary" className="gap-1.5">
                            <UserCircle2 className="size-3.5" /> Vista consolidada
                        </Badge>
                    ) : (
                        props.tenant?.nit && <Badge variant="outline">NIT {props.tenant.nit}</Badge>
                    )}
                </div>

                {/* Métricas */}
                {isMaster ? (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard label="Clientes" value={props.stats.clients ?? 0} icon={Building2} />
                        <StatCard label="Clientes activos" value={props.stats.clients_active ?? 0} icon={ShieldCheck} />
                        <StatCard label="Usuarios cliente" value={props.stats.client_users ?? 0} icon={Users} />
                        <StatCard label="Equipo CMK" value={props.stats.cmk_users ?? 0} icon={UserCircle2} />
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard label="Usuarios de la empresa" value={props.stats.users ?? 0} icon={Users} />
                    </div>
                )}

                {/* Módulos */}
                <ModuleGrid can={can} />

                {/* Clientes recientes (solo vista maestra) */}
                {isMaster && props.clients && props.clients.length > 0 && (
                    <div>
                        <h2 className="font-brand mb-3 text-sm font-semibold tracking-wide text-muted-foreground uppercase">Clientes recientes</h2>
                        <Card>
                            <div className="divide-border divide-y">
                                {props.clients.map((c) => (
                                    <div key={c.id} className="flex items-center justify-between gap-4 px-5 py-3">
                                        <div className="min-w-0">
                                            <div className="truncate font-medium">{c.name}</div>
                                            <div className="text-muted-foreground truncate text-sm">
                                                {[c.city, c.nit ? `NIT ${c.nit}` : null].filter(Boolean).join(' · ')}
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-3">
                                            <span className="text-muted-foreground text-sm">{c.users_count} usuarios</span>
                                            <Badge variant={c.is_active ? 'default' : 'secondary'}>{c.is_active ? 'Activo' : 'Inactivo'}</Badge>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </Card>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
