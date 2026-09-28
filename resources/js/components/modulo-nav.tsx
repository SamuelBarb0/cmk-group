import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { SidebarMenuButton, SidebarMenuItem, SidebarMenuSub, SidebarMenuSubButton, SidebarMenuSubItem } from '@/components/ui/sidebar';
import { usePartes } from '@/hooks/use-partes';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

/** Un submódulo en el menú. `base`: no es parte contratable, siempre se muestra. */
export type Submodulo = { parte: string; title: string; base?: boolean };

/**
 * Submódulos de cada módulo, en el orden de sus pestañas. Las claves son las
 * mismas que `?parte=` entiende cada página (ver `usePestana`) y, salvo las
 * `base`, las mismas de `config('cmk.submodulos')`.
 */
export const SUBMODULOS: Record<string, Submodulo[]> = {
    contexto: [
        { parte: 'alcance', title: 'Alcance y cambio climático' },
        { parte: 'dofa', title: 'DOFA / PESTEL' },
        { parte: 'partes', title: 'Partes interesadas' },
        { parte: 'procesos', title: 'Procesos' },
    ],
    ambiental: [
        { parte: 'residuos', title: 'Residuos y RESPEL' },
        { parte: 'consumos', title: 'Consumos' },
        { parte: 'quimicos', title: 'Productos químicos' },
    ],
    epp: [
        { parte: 'catalogo', title: 'Catálogo', base: true },
        { parte: 'matriz', title: 'Matriz por cargo' },
        { parte: 'entregas', title: 'Entregas' },
    ],
    emergencias: [
        { parte: 'brigada', title: 'Brigada' },
        { parte: 'simulacros', title: 'Simulacros' },
        { parte: 'equipos', title: 'Equipos' },
        { parte: 'directorio', title: 'Directorio MEDEVAC' },
    ],
    'salud-ocupacional': [
        { parte: 'trabajadores', title: 'Estado por trabajador', base: true },
        { parte: 'examenes', title: 'Exámenes' },
        { parte: 'profesiograma', title: 'Profesiograma' },
    ],
    calidad: [
        { parte: 'pqrs', title: 'PQRS' },
        { parte: 'salidas', title: 'Salidas no conformes' },
        { parte: 'satisfaccion', title: 'Satisfacción' },
    ],
    comunicaciones: [
        { parte: 'matriz', title: 'Matriz de comunicaciones' },
        { parte: 'registro', title: 'Registro' },
    ],
};

/**
 * Un módulo con submódulos: se despliega en el menú y cada submódulo lleva
 * directo a su pestaña. Si la empresa contrató una sola parte no tiene sentido
 * desplegar nada y queda como enlace simple.
 */
export function ModuloNav({ item, modulo, codigo }: { item: NavItem; modulo: string; codigo?: string }) {
    const { url } = usePage();
    const { tiene } = usePartes(modulo);
    const subs = SUBMODULOS[modulo].filter((s) => s.base || tiene(s.parte));

    const enModulo = url === item.url || url.startsWith(item.url + '?') || url.startsWith(item.url + '/');
    const parteActual = new URL(url, 'http://local').searchParams.get('parte');
    // Sin `?parte=` la página abre la primera pestaña disponible.
    const activa = (s: Submodulo) => enModulo && (parteActual ? parteActual === s.parte : s === subs[0]);

    const etiqueta = (
        <span>
            {codigo && <span className="text-sidebar-foreground/50 mr-1.5 font-mono text-[10px]">{codigo}</span>}
            {item.title}
        </span>
    );

    if (subs.length <= 1) {
        return (
            <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={enModulo} tooltip={{ children: item.title }}>
                    <Link href={item.url} prefetch>
                        {item.icon && <item.icon />}
                        {etiqueta}
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        );
    }

    return (
        <Collapsible asChild defaultOpen={enModulo} className="group/modulo">
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton isActive={enModulo} tooltip={{ children: item.title }}>
                        {item.icon && <item.icon />}
                        {etiqueta}
                        <ChevronRight className="ml-auto transition-transform duration-200 group-data-[state=open]/modulo:rotate-90" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>

                <CollapsibleContent>
                    <SidebarMenuSub>
                        {subs.map((s) => (
                            <SidebarMenuSubItem key={s.parte}>
                                <SidebarMenuSubButton asChild isActive={activa(s)}>
                                    <Link href={`${item.url}?parte=${s.parte}`} preserveScroll>
                                        <span className="truncate">{s.title}</span>
                                    </Link>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}
