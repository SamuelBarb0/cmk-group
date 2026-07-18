import { SVGAttributes } from 'react';

/**
 * Isotipo CMK GROUP: hexágono con la "K" en azul marino y gris.
 * Derivado del logo oficial. Usa currentColor no; colores de marca fijos
 * para mantener consistencia sobre fondos claros u oscuros.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
            <path d="M28 6 L72 6 L96 50 L72 94 L28 94 L4 50 Z" fill="#16243F" />
            <path d="M24 24 L37 24 L37 76 L24 76 Z" fill="#F5F4F1" />
            <path d="M39 50 L62 22 L76 22 L52 50 Z" fill="#F5F4F1" />
            <path d="M52 50 L76 78 L62 78 L39 50 Z" fill="#8A8F96" />
        </svg>
    );
}
