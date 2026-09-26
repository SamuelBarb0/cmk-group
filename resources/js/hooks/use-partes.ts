import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Partes contratadas de un módulo por la empresa activa (config('cmk.submodulos')).
 * Sin cliente activo, o sin selección para el módulo, todas las partes están.
 */
export function usePartes(modulo: string) {
    const partes = usePage<SharedData>().props.partes_contratadas?.[modulo];
    const tiene = (parte: string) => !partes || partes.includes(parte);

    /** La primera parte contratada de una lista (para la pestaña inicial). */
    const primera = <T extends string>(orden: readonly T[]): T => orden.find((p) => tiene(p)) ?? orden[0];

    return { tiene, primera };
}
