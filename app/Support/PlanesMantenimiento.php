<?php

namespace App\Support;

/**
 * Planes tipo de mantenimiento para cargar de un clic en un activo.
 *
 * El de vehículo es el de «17. PROGRAMA DE MTTO» (PASO 17), con sus
 * frecuencias en km tal como las trae la hoja. Donde la hoja dice 0 o nada
 * (latonería «cada vez que suceda», escáner «cuando se prende un testigo")
 * el ítem queda sin frecuencia: es a demanda, no se puede vencer.
 *
 * El locativo es la lista de «7.1 P.G MANTENIMIENTO» (libro SGI). Esa hoja
 * no trae frecuencias, y no se inventan: el consultor las pone por empresa.
 */
final class PlanesMantenimiento
{
    /** @return array<string, array{nombre: string, items: list<array{actividad: string, valor: ?int, unidad: ?string}>}> */
    public static function todos(): array
    {
        return [
            'vehiculo' => [
                'nombre' => 'Vehículo (PASO 17 del PESV)',
                'items' => [
                    self::km('Motor: cambio de aceite con todos los filtros (aire, aire acondicionado, combustible, motor)', 5000),
                    self::km('Motor: cambio de cadena de repartición', 100000),
                    self::km('Suspensión: bujes, ejes, amortiguadores, barra estabilizadora, rodamientos, rótulas, tijeras, brazos axiales, engrase, muelles', 30000),
                    self::km('Suspensión: alineación, balanceo y rotación de llantas', 5000),
                    self::km('Suspensión: cambio de llantas', 40000),
                    self::km('Frenos: pastillas, bandas, rectificación de campanas y discos, cambio de líquido de frenos, mordazas, mangueras', 20000),
                    self::km('Eléctrico: sincronización, limpieza de inyectores, cambio de bujías, instalación de alta', 40000),
                    self::km('Eléctrico: revisión de luces, cocuyos, direccionales, parqueo, reversa, freno, iluminación', 1000),
                    self::km('Eléctrico: cambio de batería, revisión del alternador', 40000),
                    self::km('Carrocería: lavado de tapicería, cojinería, techos, desmanche y polichada', 30000),
                    self::km('Carrocería: lavado general, de motor y por debajo', 5000),
                    self::km('Transmisión: revisión de aceite de caja y transmisión, engrase de crucetas', 20000),
                    self::km('Transmisión: disco, prensa y balinera, cambio de retenedor de cardán', 100000),
                    self::aDemanda('Carrocería: latonería y pintura, cada vez que haya una anomalía o choque'),
                    self::aDemanda('Escáner: cuando se prende un testigo'),
                ],
            ],
            'locativo' => [
                'nombre' => 'Instalaciones (programa de mantenimiento locativo)',
                'items' => [
                    self::aDemanda('Mantenimiento locativo'),
                    self::aDemanda('Control de vectores'),
                    self::aDemanda('Lavado, limpieza y desinfección de los tanques de almacenamiento de agua'),
                    self::aDemanda('Mantenimiento de grifería y aparatos sanitarios'),
                    self::aDemanda('Revisión e inspección de mantenimiento'),
                ],
            ],
            'equipo' => [
                'nombre' => 'Máquinas, equipos y tecnología',
                'items' => [
                    self::aDemanda('Limpieza y mantenimiento preventivo'),
                    self::aDemanda('Revisión e inspección de mantenimiento'),
                    self::aDemanda('Mantenimiento de software'),
                ],
            ],
        ];
    }

    private static function km(string $actividad, int $km): array
    {
        return ['actividad' => $actividad, 'valor' => $km, 'unidad' => 'km'];
    }

    private static function aDemanda(string $actividad): array
    {
        return ['actividad' => $actividad, 'valor' => null, 'unidad' => null];
    }
}
