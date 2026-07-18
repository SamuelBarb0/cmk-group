import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Acceso a roles/permisos del usuario autenticado (compartidos por Inertia).
 * Uso: const { can, hasRole, isCmk } = usePermissions();
 */
export function usePermissions() {
    const { auth } = usePage<SharedData>().props;

    const permissions = auth?.permissions ?? [];
    const roles = auth?.roles ?? [];

    return {
        can: (permission: string) => permissions.includes(permission),
        canAny: (list: string[]) => list.some((p) => permissions.includes(p)),
        hasRole: (role: string) => roles.includes(role),
        isCmk: auth?.is_cmk ?? false,
        roleLabel: auth?.role_label ?? null,
        permissions,
        roles,
    };
}
