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
import { type NavItem } from '@/types';
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
            { title: 'Diagnóstico SG-SST', url: '/diagnostico', icon: Gauge, permission: 'sst.view' },
            { title: 'Matriz IPERC', url: '/iperc', icon: TriangleAlert, permission: 'sst.view' },
            { title: 'Plan de Trabajo', url: '/plan-trabajo', icon: CalendarRange, permission: 'sst.view' },
            { title: 'Indicadores', url: '/indicadores', icon: FileBarChart, permission: 'sst.view' },
            { title: 'SST', url: '/sst', icon: HardHat, permission: 'sst.view' },
            { title: 'HSEQ', url: '/hseq', icon: Leaf, permission: 'hseq.view' },
            { title: 'PESV', url: '/pesv', icon: Truck, permission: 'pesv.view' },
            { title: 'Documentos', url: '/documentos', icon: FileText, permission: 'documents.view' },
            { title: 'Documentos IA', url: '/documentos-ia', icon: Sparkles, permission: 'documents.view' },
        ],
    },
    {
        label: 'Campo y control',
        items: [
            { title: 'Inspecciones', url: '/inspecciones', icon: ClipboardCheck, permission: 'inspections.view' },
            { title: 'Reportes', url: '/reportes', icon: FileBarChart, permission: 'reports.view' },
            { title: 'Auditoría', url: '/auditoria', icon: ShieldCheck, permission: 'audit.view' },
        ],
    },
    {
        label: 'Administración',
        items: [{ title: 'Configuración', url: '/configuracion', icon: Settings, permission: 'settings.manage' }],
    },
];

export function AppSidebar() {
    const { can } = usePermissions();
    const { url } = usePage();

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
                    const visible = group.items.filter((item) => !item.permission || can(item.permission));
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
