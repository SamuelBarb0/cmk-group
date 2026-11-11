import { ModuloNav, SUBMODULOS } from '@/components/modulo-nav';
import { NavUser } from '@/components/nav-user';
import { PesvNav } from '@/components/pesv-nav';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
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
    useSidebar,
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
    ChevronRight,
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
import { type ReactNode, useEffect, useState } from 'react';
import AppLogo from './app-logo';

/**
 * Ítem del sidebar. `tree` marca los que no son un enlace suelto sino un árbol
 * propio (el PESV, que despliega sus 4 fases y 24 pasos). Los módulos con
 * submódulos (ver `SUBMODULOS`) se despliegan solos por su `module`.
 */
export type NavEntry = NavItem & { tree?: 'pesv' };

/**
 * Grupo del sidebar. `area`: es un área de módulos y se pliega; los grupos
 * fijos (General, Consultoría, Administración) son cortos y van siempre abiertos.
 */
export type NavGroup = { label: string; area?: boolean; items: NavEntry[] };

/** Grupos de navegación con el permiso requerido por cada ítem. */
export const navGroups: NavGroup[] = [
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
        // La empresa y su gente: los datos que alimentan al resto de módulos.
        label: 'Organización',
        area: true,
        items: [
            { title: 'Organización', url: '/organizacion', icon: Building2, permission: 'sst.view' },
            { title: 'Contexto', url: '/contexto', icon: Compass, permission: 'sst.view', module: 'contexto' },
            { title: 'Empleados', url: '/empleados', icon: Contact, permission: 'sst.view' },
            { title: 'Cargos y competencias', url: '/cargos', icon: BriefcaseBusiness, permission: 'sst.view', module: 'cargos' },
            { title: 'Comités', url: '/comites', icon: UsersRound, permission: 'sst.view', module: 'comites' },
        ],
    },
    {
        label: 'SG-SST',
        area: true,
        items: [
            { title: 'Diagnóstico SG-SST', url: '/diagnostico', icon: Gauge, permission: 'sst.view', module: 'diagnostico' },
            { title: 'Plan de Trabajo', url: '/plan-trabajo', icon: CalendarRange, permission: 'sst.view', module: 'plan-trabajo' },
            { title: 'Matriz IPERC', url: '/iperc', icon: TriangleAlert, permission: 'sst.view', module: 'iperc' },
            { title: 'Riesgos y oportunidades', url: '/riesgos-oportunidades', icon: Radar, permission: 'sst.view', module: 'riesgos-oportunidades' },
            { title: 'Capacitaciones', url: '/capacitaciones', icon: GraduationCap, permission: 'sst.view', module: 'capacitaciones' },
            { title: 'EPP', url: '/epp', icon: HardHat, permission: 'sst.view', module: 'epp' },
            { title: 'Emergencias', url: '/emergencias', icon: Flame, permission: 'sst.view', module: 'emergencias' },
            { title: 'Salud ocupacional', url: '/salud-ocupacional', icon: Stethoscope, permission: 'sst.view', module: 'salud-ocupacional' },
            { title: 'Programas de gestión', url: '/programas', icon: Target, permission: 'sst.view', module: 'programas' },
        ],
    },
    {
        // Lo que ocurre en el día a día y se consulta a diario.
        label: 'Incidentes',
        area: true,
        items: [
            { title: 'Actos y condiciones', url: '/reportes-ac', icon: MessageSquareWarning, permission: 'incidents.view', module: 'reportes-ac' },
            { title: 'Accidentalidad', url: '/accidentes', icon: Siren, permission: 'incidents.view', module: 'accidentes' },
            { title: 'Ausentismo', url: '/ausentismo', icon: CalendarX, permission: 'sst.view', module: 'ausentismo' },
        ],
    },
    {
        // No se pliega: su único ítem ya es un árbol plegable (fases y pasos).
        label: 'PESV',
        items: [{ title: 'PESV', url: '/pesv', permission: 'pesv.view', module: 'pesv', tree: 'pesv' }],
    },
    {
        label: 'Ambiental y calidad',
        area: true,
        items: [
            { title: 'Aspectos ambientales', url: '/aspectos-ambientales', icon: Leaf, permission: 'sst.view', module: 'aspectos-ambientales' },
            { title: 'Gestión ambiental', url: '/ambiental', icon: Recycle, permission: 'sst.view', module: 'ambiental' },
            { title: 'Calidad', url: '/calidad', icon: BadgeCheck, permission: 'sst.view', module: 'calidad' },
            { title: 'Equipos de medición', url: '/equipos-medicion', icon: Ruler, permission: 'sst.view', module: 'equipos-medicion' },
            { title: 'Mantenimiento', url: '/mantenimiento', icon: Wrench, permission: 'sst.view', module: 'mantenimiento' },
            { title: 'Contratistas', url: '/contratistas', icon: Handshake, permission: 'sst.view', module: 'contratistas' },
        ],
    },
    {
        label: 'Documentación',
        area: true,
        items: [
            { title: 'Control documental', url: '/control-documental', icon: Library, permission: 'documents.view', module: 'control-documental' },
            { title: 'Documentos', url: '/documentos', icon: FileText, permission: 'documents.view', module: 'documentos' },
            { title: 'Documentos IA', url: '/documentos-ia', icon: Sparkles, permission: 'documents.view', module: 'documentos-ia' },
            { title: 'Presentaciones', url: '/presentaciones', icon: Presentation, permission: 'documents.view' },
            { title: 'Importar Excel', url: '/importar', icon: FileSpreadsheet, permission: 'sst.manage', module: 'importar' },
        ],
    },
    {
        // Medir, verificar y mejorar el sistema: el ciclo Verificar-Actuar.
        label: 'Mejora y control',
        area: true,
        items: [
            { title: 'Requisitos legales', url: '/requisitos-legales', icon: Scale, permission: 'sst.view', module: 'requisitos-legales' },
            { title: 'Indicadores', url: '/indicadores', icon: FileBarChart, permission: 'sst.view', module: 'indicadores' },
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
            { title: 'Auditoría', url: '/auditoria', icon: ShieldCheck, permission: 'audit.view', module: 'auditoria' },
            { title: 'Formatos', url: '/formatos', icon: ClipboardCheck, permission: 'inspections.view', module: 'inspecciones' },
            { title: 'Reportes', url: '/reportes', icon: FileBarChart, permission: 'reports.view', module: 'reportes' },
        ],
    },
    {
        label: 'Administración',
        items: [{ title: 'Configuración', url: '/configuracion', icon: Settings, permission: 'settings.manage' }],
    },
];

/** Grupos que no son áreas de módulos: navegación general y administración. */
const SIN_AREA = ['General', 'Consultoría', 'Administración'];

/**
 * Las áreas de módulos del menú con la clave de cada módulo (la de
 * `modulos_contratados`, o la ruta cuando el módulo es base). El dashboard
 * agrupa con ESTO para que el mapa del panel sea el mismo del menú.
 */
export function areasDeModulos(): { area: string; claves: string[] }[] {
    return navGroups
        .filter((g) => !SIN_AREA.includes(g.label))
        .map((g) => ({ area: g.label, claves: g.items.map((i) => i.module ?? i.url.replace(/^\//, '')) }));
}

/** Entradas base del grupo Organización: alimentan al resto y van primero. */
const BASE = ['/organizacion', '/empleados'];

/** Áreas abiertas o cerradas a mano, recordadas entre visitas. */
const CLAVE_AREAS = 'sidebar-areas';

function areasGuardadas(): Record<string, boolean> {
    if (typeof window === 'undefined') return {};
    try {
        return JSON.parse(localStorage.getItem(CLAVE_AREAS) ?? '{}') ?? {};
    } catch {
        return {};
    }
}

/**
 * ¿La página actual es de este ítem? Exacto por segmento: «/reportes» no debe
 * abrir su área cuando se está en «/reportes-ac».
 */
function enItem(url: string, item: NavEntry) {
    return url === item.url || url.startsWith(item.url + '/') || url.startsWith(item.url + '?');
}

/**
 * Un área de módulos plegable. Con el sidebar en modo icono no hay etiqueta que
 * pulsar, así que ahí se muestra siempre abierta para no esconder los iconos.
 */
function Area({
    label,
    abierta,
    onCambio,
    children,
}: {
    label: string;
    abierta: boolean;
    onCambio: (abierta: boolean) => void;
    children: ReactNode;
}) {
    const { state, isMobile } = useSidebar();
    const soloIconos = state === 'collapsed' && !isMobile;

    return (
        <Collapsible open={soloIconos || abierta} onOpenChange={onCambio} className="group/area">
            <SidebarGroup>
                <SidebarGroupLabel asChild>
                    <CollapsibleTrigger className="hover:text-sidebar-foreground w-full cursor-pointer">
                        {label}
                        <ChevronRight className="ml-auto transition-transform duration-200 group-data-[state=open]/area:rotate-90" />
                    </CollapsibleTrigger>
                </SidebarGroupLabel>
                <CollapsibleContent>{children}</CollapsibleContent>
            </SidebarGroup>
        </Collapsible>
    );
}

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

    const grupos = navGroups
        .map((group) => ({
            ...group,
            visibles: group.items
                .filter((item) => (!item.permission || can(item.permission)) && moduloHabilitado(item))
                .sort((a, b) => orden(a).localeCompare(orden(b))),
        }))
        .filter((group) => group.visibles.length > 0);

    // Área de la página actual: siempre se abre al llegar a ella.
    const areaActual = grupos.find((g) => g.area && g.visibles.some((item) => enItem(url, item)))?.label;

    const [abiertas, setAbiertas] = useState<Record<string, boolean>>(() => {
        const guardadas = areasGuardadas();
        return areaActual ? { ...guardadas, [areaActual]: true } : guardadas;
    });

    useEffect(() => {
        if (areaActual) setAbiertas((previas) => (previas[areaActual] ? previas : { ...previas, [areaActual]: true }));
    }, [areaActual]);

    useEffect(() => {
        try {
            localStorage.setItem(CLAVE_AREAS, JSON.stringify(abiertas));
        } catch {
            // Sin almacenamiento (modo privado): las áreas solo duran la sesión.
        }
    }, [abiertas]);

    const menu = (visibles: NavEntry[]) => (
        <SidebarMenu>
            {visibles.map((item) =>
                item.tree === 'pesv' ? (
                    <PesvNav key={item.title} />
                ) : item.module && SUBMODULOS[item.module] ? (
                    <ModuloNav key={item.title} item={item} modulo={item.module} codigo={codigo(item)} />
                ) : (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton asChild isActive={url.startsWith(item.url)} tooltip={{ children: item.title }}>
                            <Link href={item.url} prefetch>
                                {item.icon && <item.icon />}
                                <span>
                                    {codigo(item) && <span className="text-sidebar-foreground/50 mr-1.5 font-mono text-[10px]">{codigo(item)}</span>}
                                    {item.title}
                                </span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ),
            )}
        </SidebarMenu>
    );

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
                {grupos.map((group) =>
                    group.area ? (
                        <Area
                            key={group.label}
                            label={group.label}
                            abierta={abiertas[group.label] ?? false}
                            onCambio={(abierta) => setAbiertas((previas) => ({ ...previas, [group.label]: abierta }))}
                        >
                            {menu(group.visibles)}
                        </Area>
                    ) : (
                        <SidebarGroup key={group.label}>
                            <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
                            {menu(group.visibles)}
                        </SidebarGroup>
                    ),
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
