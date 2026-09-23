import { NavUser } from '@/components/nav-user';
import { PesvNav } from '@/components/pesv-nav';
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
    ArrowLeftRight,
    Building2,
    CalendarRange,
    CalendarX,
    ClipboardCheck,
    Contact,
    FileBarChart,
    FileText,
    Flame,
    Gauge,
    GraduationCap,
    HardHat,
    LayoutGrid,
    ListChecks,
    MessageSquareWarning,
    Scale,
    Settings,
    ShieldCheck,
    Siren,
    Sparkles,
    Target,
    TriangleAlert,
    Users,
    UsersRound,
} from 'lucide-react';
import AppLogo from './app-logo';

/**
 * Ítem del sidebar. `tree` marca los que no son un enlace suelto sino un árbol
 * propio (hoy solo el PESV, que despliega sus 4 fases y 24 pasos).
 */
type NavEntry = NavItem & { tree?: 'pesv' };

/** Grupos de navegación con el permiso requerido por cada ítem. */
const navGroups: { label: string; items: NavEntry[] }[] = [
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
            { title: 'PESV', url: '/pesv', permission: 'pesv.view', module: 'pesv', tree: 'pesv' },
            { title: 'Matriz IPERC', url: '/iperc', icon: TriangleAlert, permission: 'sst.view', module: 'iperc' },
            { title: 'Plan de Trabajo', url: '/plan-trabajo', icon: CalendarRange, permission: 'sst.view', module: 'plan-trabajo' },
            { title: 'Indicadores', url: '/indicadores', icon: FileBarChart, permission: 'sst.view', module: 'indicadores' },
            { title: 'Capacitaciones', url: '/capacitaciones', icon: GraduationCap, permission: 'sst.view', module: 'capacitaciones' },
            { title: 'Documentos', url: '/documentos', icon: FileText, permission: 'documents.view', module: 'documentos' },
            { title: 'Documentos IA', url: '/documentos-ia', icon: Sparkles, permission: 'documents.view', module: 'documentos-ia' },
            { title: 'Comités', url: '/comites', icon: UsersRound, permission: 'sst.view', module: 'comites' },
            { title: 'EPP', url: '/epp', icon: HardHat, permission: 'sst.view', module: 'epp' },
            { title: 'Emergencias', url: '/emergencias', icon: Flame, permission: 'sst.view', module: 'emergencias' },
            { title: 'Programas de gestión', url: '/programas', icon: Target, permission: 'sst.view', module: 'programas' },
        ],
    },
    {
        // Lo que ocurre y lo que hay que hacer con lo que ocurre. Va aparte de
        // «Módulos» porque estos cuatro se consultan a diario, no cuando toca
        // armar un documento.
        label: 'Seguimiento',
        items: [
            { title: 'Requisitos legales', url: '/requisitos-legales', icon: Scale, permission: 'sst.view', module: 'requisitos-legales' },
            { title: 'Actos y condiciones', url: '/reportes-ac', icon: MessageSquareWarning, permission: 'incidents.view', module: 'reportes-ac' },
            { title: 'Accidentalidad', url: '/accidentes', icon: Siren, permission: 'incidents.view', module: 'accidentes' },
            { title: 'Ausentismo', url: '/ausentismo', icon: CalendarX, permission: 'sst.view', module: 'ausentismo' },
            { title: 'ACPM', url: '/acpm', icon: ListChecks, permission: 'sst.view', module: 'acpm' },
            { title: 'Gestión del cambio', url: '/gestion-cambio', icon: ArrowLeftRight, permission: 'sst.view', module: 'gestion-cambio' },
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

    const moduloHabilitado = (item: NavEntry) => !item.module || modulosContratados === null || modulosContratados.includes(item.module);

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
                                {visible.map((item) =>
                                    item.tree === 'pesv' ? (
                                        <PesvNav key={item.title} />
                                    ) : (
                                        <SidebarMenuItem key={item.title}>
                                            <SidebarMenuButton asChild isActive={url.startsWith(item.url)} tooltip={{ children: item.title }}>
                                                <Link href={item.url} prefetch>
                                                    {item.icon && <item.icon />}
                                                    <span>{item.title}</span>
                                                </Link>
                                            </SidebarMenuButton>
                                        </SidebarMenuItem>
                                    ),
                                )}
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
