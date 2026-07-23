import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
    roles: string[];
    permissions: string[];
    is_cmk: boolean;
    role_label: string | null;
}

export interface Tenant {
    id: number;
    name: string;
}

export interface Company {
    name: string;
    legal_name: string;
    nit: string;
    email: string;
    phones: string[];
    domain: string;
    addresses: { label: string; line: string; city: string; zip: string }[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** Permiso requerido para mostrar el ítem (opcional). */
    permission?: string;
    /** Clave del módulo contratable; se oculta si la empresa activa no lo contrató. */
    module?: string;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    tenant: Tenant | null;
    /** Módulos contratados por la empresa activa (null = todos / sin cliente activo). */
    modulos_contratados: string[] | null;
    company: Company;
    flash?: { success: string | null; error?: string | null };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
