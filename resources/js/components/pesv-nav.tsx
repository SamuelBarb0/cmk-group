import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { SidebarMenuButton, SidebarMenuItem, SidebarMenuSub, SidebarMenuSubButton, SidebarMenuSubItem } from '@/components/ui/sidebar';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, TrafficCone } from 'lucide-react';

/** Un paso del PESV, tal como lo comparte HandleInertiaRequests. */
type PesvPaso = {
    numero: number;
    fase: number;
    fase_nombre: string;
    titulo: string;
};

/**
 * Pantallas de caracterización del Paso 5.
 *
 * Van aparte de los pasos porque no son pasos: son los datos que el Paso 5
 * exige y que el resto de la fase 2 reutiliza (vehículos, rutas, contratistas).
 */
const CARACTERIZACION = [
    { title: 'Sedes', url: '/pesv/sedes' },
    { title: 'Colaboradores', url: '/pesv/colaboradores' },
    { title: 'Contratistas', url: '/pesv/contratistas' },
    { title: 'Vehículos', url: '/pesv/vehiculos' },
    { title: 'Rutas', url: '/pesv/rutas' },
    { title: 'Siniestros viales', url: '/pesv/siniestros' },
    { title: 'Encuesta de movilidad', url: '/pesv/encuesta' },
    { title: 'Matriz de riesgos viales', url: '/pesv/riesgos-viales' },
    { title: 'Semáforo de documentos', url: '/pesv/documentos' },
    { title: 'Infracciones de tránsito', url: '/pesv/infracciones' },
];

/**
 * Árbol de navegación del PESV: 4 fases con sus 24 pasos.
 *
 * Los títulos vienen del backend (prop compartida `pesv_pasos`) y no de una
 * copia en TypeScript, para que no se desincronicen del catálogo sembrado.
 */
export function PesvNav() {
    const { url, props } = usePage<SharedData>();
    const pasos = (props.pesv_pasos ?? []) as PesvPaso[];

    const enPesv = url.startsWith('/pesv');

    // Fases en orden, con sus pasos. Se agrupa aquí y no en el backend para que
    // la prop compartida siga siendo una lista plana y barata de cachear.
    const fases = pasos.reduce<{ fase: number; nombre: string; pasos: PesvPaso[] }[]>((acc, paso) => {
        const existente = acc.find((f) => f.fase === paso.fase);
        if (existente) {
            existente.pasos.push(paso);
        } else {
            acc.push({ fase: paso.fase, nombre: paso.fase_nombre, pasos: [paso] });
        }
        return acc;
    }, []);

    const pasoActivo = (numero: number) => url === `/pesv/paso/${numero}`;

    return (
        <Collapsible asChild defaultOpen={enPesv} className="group/pesv">
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton isActive={enPesv} tooltip={{ children: 'PESV' }}>
                        <TrafficCone />
                        <span>PESV</span>
                        <ChevronRight className="ml-auto transition-transform duration-200 group-data-[state=open]/pesv:rotate-90" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>

                <CollapsibleContent>
                    <SidebarMenuSub>
                        <SidebarMenuSubItem>
                            <SidebarMenuSubButton asChild isActive={url === '/pesv'}>
                                <Link href="/pesv" prefetch>
                                    <span>Plan y avance</span>
                                </Link>
                            </SidebarMenuSubButton>
                        </SidebarMenuSubItem>

                        {fases.map((fase) => {
                            const faseActiva = fase.pasos.some((p) => pasoActivo(p.numero));

                            return (
                                <Collapsible key={fase.fase} defaultOpen={faseActiva} className="group/fase">
                                    <SidebarMenuSubItem>
                                        <CollapsibleTrigger asChild>
                                            <SidebarMenuSubButton className="cursor-pointer">
                                                <span className="truncate">
                                                    Fase {fase.fase} · {fase.nombre}
                                                </span>
                                                <ChevronRight className="ml-auto size-3 shrink-0 transition-transform duration-200 group-data-[state=open]/fase:rotate-90" />
                                            </SidebarMenuSubButton>
                                        </CollapsibleTrigger>

                                        <CollapsibleContent>
                                            <ul className="border-sidebar-border ml-3 flex flex-col gap-0.5 border-l pl-2">
                                                {fase.pasos.map((paso) => (
                                                    <li key={paso.numero}>
                                                        <SidebarMenuSubButton asChild isActive={pasoActivo(paso.numero)} size="sm">
                                                            <Link href={`/pesv/paso/${paso.numero}`} prefetch>
                                                                <span className="truncate">
                                                                    {paso.numero}. {paso.titulo}
                                                                </span>
                                                            </Link>
                                                        </SidebarMenuSubButton>
                                                    </li>
                                                ))}
                                            </ul>
                                        </CollapsibleContent>
                                    </SidebarMenuSubItem>
                                </Collapsible>
                            );
                        })}

                        <Collapsible defaultOpen={CARACTERIZACION.some((c) => url.startsWith(c.url))} className="group/carac">
                            <SidebarMenuSubItem>
                                <CollapsibleTrigger asChild>
                                    <SidebarMenuSubButton className="cursor-pointer">
                                        <span className="truncate">Caracterización</span>
                                        <ChevronRight className="ml-auto size-3 shrink-0 transition-transform duration-200 group-data-[state=open]/carac:rotate-90" />
                                    </SidebarMenuSubButton>
                                </CollapsibleTrigger>

                                <CollapsibleContent>
                                    <ul className="border-sidebar-border ml-3 flex flex-col gap-0.5 border-l pl-2">
                                        {CARACTERIZACION.map((item) => (
                                            <li key={item.url}>
                                                <SidebarMenuSubButton asChild isActive={url.startsWith(item.url)} size="sm">
                                                    <Link href={item.url} prefetch>
                                                        <span>{item.title}</span>
                                                    </Link>
                                                </SidebarMenuSubButton>
                                            </li>
                                        ))}
                                    </ul>
                                </CollapsibleContent>
                            </SidebarMenuSubItem>
                        </Collapsible>
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}
