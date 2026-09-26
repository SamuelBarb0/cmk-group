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
    BadgeCheck,
    BriefcaseBusiness,
    Building2,
    CalendarRange,
    CalendarX,
    ClipboardCheck,
    Compass,
    Contact,
    FileBarChart,
    FileSpreadsheet,
    FileText,
    Flame,
    Gauge,
    GraduationCap,
    Handshake,
    HardHat,
    LayoutGrid,
    Leaf,
    Library,
    ListChecks,
    Megaphone,
    MessageSquareWarning,
    Presentation,
    Radar,
    Recycle,
    Ruler,
    Scale,
    Settings,
    ShieldCheck,
    Siren,
    Sparkles,
    Stethoscope,
    Target,
    TriangleAlert,
    Users,
    UsersRound,
    Wrench,
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
            { title: 'Contexto', url: '/contexto', icon: Compass, permission: 'sst.view', module: 'contexto' },
            { title: 'Empleados', url: '/empleados', icon: Contact, permission: 'sst.view' },
            { title: 'Diagnóstico SG-SST', url: '/diagnostico', icon: Gauge, permission: 'sst.view', module: 'diagnostico' },
            { title: 'PESV', url: '/pesv', permission: 'pesv.view', module: 'pesv', tree: 'pesv' },
            { title: 'Matriz IPERC', url: '/iperc', icon: TriangleAlert, permission: 'sst.view', module: 'iperc' },
            { title: 'Riesgos y oportunidades', url: '/riesgos-oportunidades', icon: Radar, permission: 'sst.view', module: 'riesgos-oportunidades' },
            { title: 'Aspectos ambientales', url: '/aspectos-ambientales', icon: Leaf, permission: 'sst.view', module: 'aspectos-ambientales' },
            { title: 'Gestión ambiental', url: '/ambiental', icon: Recycle, permission: 'sst.view', module: 'ambiental' },
            { title: 'Plan de Trabajo', url: '/plan-trabajo', icon: CalendarRange, permission: 'sst.view', module: 'plan-trabajo' },
            { title: 'Indicadores', url: '/indicadores', icon: FileBarChart, permission: 'sst.view', module: 'indicadores' },
            { title: 'Capacitaciones', url: '/capacitaciones', icon: GraduationCap, permission: 'sst.view', module: 'capacitaciones' },
            { title: 'Cargos y competencias', url: '/cargos', icon: BriefcaseBusiness, permission: 'sst.view', module: 'cargos' },
            { title: 'Control documental', url: '/control-documental', icon: Library, permission: 'documents.view', module: 'control-documental' },
            { title: 'Documentos', url: '/documentos', icon: FileText, permission: 'documents.view', module: 'documentos' },
            { title: 'Documentos IA', url: '/documentos-ia', icon: Sparkles, permission: 'documents.view', module: 'documentos-ia' },
            { title: 'Presentaciones', url: '/presentaciones', icon: Presentation, permission: 'documents.view' },
            { title: 'Importar Excel', url: '/importar', icon: FileSpreadsheet, permission: 'sst.manage', module: 'importar' },
            { title: 'Comités', url: '/comites', icon: UsersRound, permission: 'sst.view', module: 'comites' },
            { title: 'EPP', url: '/epp', icon: HardHat, permission: 'sst.view', module: 'epp' },
            { title: 'Emergencias', url: '/emergencias', icon: Flame, permission: 'sst.view', module: 'emergencias' },
            { title: 'Salud ocupacional', url: '/salud-ocupacional', icon: Stethoscope, permission: 'sst.view', module: 'salud-ocupacional' },
            { title: 'Programas de gestión', url: '/programas', icon: Target, permission: 'sst.view', module: 'programas' },
            { title: 'Mantenimiento', url: '/mantenimiento', icon: Wrench, permission: 'sst.view', module: 'mantenimiento' },
            { title: 'Calidad', url: '/calidad', icon: BadgeCheck, permission: 'sst.view', module: 'calidad' },
            { title: 'Equipos de medición', url: '/equipos-medicion', icon: Ruler, permission: 'sst.view', module: 'equipos-medicion' },
            { title: 'Contratistas', url: '/contratistas', icon: Handshake, permission: 'sst.view', module: 'contratistas' },
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
            { title: 'Comunicaciones', url: '/comunicaciones', icon: Megaphone, permission: 'sst.view', module: 'comunicaciones' },
            {
                title: 'Revisión por la dirección',
                url: '/revision-direccion',
                icon: Presentation,
                permission: 'reports.view',
                module: 'revision-direccion',
            },
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

/** Entradas base del grupo Módulos: alimentan al resto y van primero. */
const BASE = ['/organizacion', '/empleados'];

export function AppSidebar() {
    const { can } = usePermissions();
    const { url, props } = usePage<SharedData>();
    // Módulos contratados por la empresa activa (null = todos / sin cliente activo).
    const modulosContratados = props.modulos_contratados ?? null;

    const moduloHabilitado = (item: NavEntry) => !item.module || modulosContratados === null || modulosContratados.includes(item.module);
    // Código del mapa documental del SIG de cada módulo (M01–M20).
    const codigo = (item: NavEntry) => (item.module ? props.codigos_sig?.[item.module]?.[0] : undefined);
    // En cada grupo, los módulos van en el orden del mapa: primero los base
    // (Organización, Empleados), luego M01…M20 y al final lo que no tiene
    // código (herramientas). El sort es estable: los grupos sin códigos no cambian.
    const orden = (item: NavEntry) => codigo(item) ?? (BASE.includes(item.url) ? '0' : 'Z');

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
                    const visible = group.items
                        .filter((item) => (!item.permission || can(item.permission)) && moduloHabilitado(item))
                        .sort((a, b) => orden(a).localeCompare(orden(b)));
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
                                                    <span>
                                                        {codigo(item) && (
                                                            <span className="text-sidebar-foreground/50 mr-1.5 font-mono text-[10px]">
                                                                {codigo(item)}
                                                            </span>
                                                        )}
                                                        {item.title}
                                                    </span>
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
