import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usePermissions } from '@/hooks/use-permissions';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarRange,
    ClipboardCheck,
    Contact,
    FileBarChart,
    Gauge,
    FileText,
    HardHat,
    LayoutGrid,
    Leaf,
    Settings,
    ShieldCheck,
    Sparkles,
    TriangleAlert,
    Truck,
    Users,
} from 'lucide-react';
import AppLogo from './app-logo';

/** Grupos de navegación con el permiso requerido por cada ítem. */
const navGroups: { label: string; items: NavItem[] }[] = [
    {
        label: 'General',
        items: [{ title: 'Dashboard', url: '/dashboard', icon: LayoutGrid }],
    },
    {
        label: 'Consultoría',
        items: [
            { title: 'Clientes', url: '/clientes', icon: Building2, permission: 'clients.view' },
            { title: 'Usuarios', url: '/usuarios', icon: Users, permission: 'users.view' },
        ],
    },
    {
        label: 'Módulos',
        items: [
            { title: 'Organización', url: '/organizacion', icon: Building2, permission: 'sst.view' },
            { title: 'Empleados', url: '/empleados', icon: Contact, permission: 'sst.view' },
            { title: 'Diagnóstico SG-SST', url: '/diagnostico', icon: Gauge, permission: 'sst.view', module: 'diagnostico' },
            { title: 'Matriz IPERC', url: '/iperc', icon: TriangleAlert, permission: 'sst.view', module: 'iperc' },
            { title: 'Plan de Trabajo', url: '/plan-trabajo', icon: CalendarRange, permission: 'sst.view', module: 'plan-trabajo' },
            { title: 'Indicadores', url: '/indicadores', icon: FileBarChart, permission: 'sst.view', module: 'indicadores' },
            { title: 'SST', url: '/sst', icon: HardHat, permission: 'sst.view', module: 'sst' },
            { title: 'HSEQ', url: '/hseq', icon: Leaf, permission: 'hseq.view', module: 'hseq' },
            { title: 'PESV', url: '/pesv', icon: Truck, permission: 'pesv.view', module: 'pesv' },
            { title: 'Documentos', url: '/documentos', icon: FileText, permission: 'documents.view', module: 'documentos' },
            { title: 'Documentos IA', url: '/documentos-ia', icon: Sparkles, permission: 'documents.view', module: 'documentos-ia' },
        ],
    },
    {
        label: 'Campo y control',
        items: [
            { title: 'Formatos', url: '/formatos', icon: ClipboardCheck, permission: 'inspections.view', module: 'inspecciones' },
            { title: 'Reportes', url: '/reportes', icon: FileBarChart, permission: 'reports.view', module: 'reportes' },
            { title: 'Auditoría', url: '/auditoria', icon: ShieldCheck, permission: 'audit.view', module: 'auditoria' },
        ],
    },
    {
        label: 'Administración',
        items: [{ title: 'Configuración', url: '/configuracion', icon: Settings, permission: 'settings.manage' }],
    },
];

export function AppSidebar() {
    const { can } = usePermissions();
    const { url, props } = usePage<SharedData>();
    // Módulos contratados por la empresa activa (null = todos / sin cliente activo).
    const modulosContratados = props.modulos_contratados ?? null;

    const moduloHabilitado = (item: NavItem) =>
        !item.module || modulosContratados === null || modulosContratados.includes(item.module);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {navGroups.map((group) => {
                    const visible = group.items.filter((item) => (!item.permission || can(item.permission)) && moduloHabilitado(item));
                    if (visible.length === 0) return null;

                    return (
                        <SidebarGroup key={group.label}>
                            <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
                            <SidebarMenu>
                                {visible.map((item) => (
                                    <SidebarMenuItem key={item.title}>
                                        <SidebarMenuButton asChild isActive={url.startsWith(item.url)} tooltip={{ children: item.title }}>
                                            <Link href={item.url} prefetch>
                                                {item.icon && <item.icon />}
                                                <span>{item.title}</span>
                                            </Link>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                ))}
                            </SidebarMenu>
                        </SidebarGroup>
                    );
                })}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
