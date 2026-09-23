<?php

namespace App\Support\Importacion;

use App\Http\Controllers\AbsenceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\IpercController;
use App\Http\Controllers\LegalRequirementController;
use App\Http\Controllers\PesvVehicleController;
use App\Http\Controllers\TrainingController;
use App\Models\Absence;
use App\Models\Employee;
use App\Models\IpercRow;
use App\Models\LegalRequirement;
use App\Models\PesvVehicle;
use App\Models\Tenant;
use App\Models\Training;
use App\Models\TrainingAttendee;

/**
 * A qué módulos se puede importar y qué campos tiene cada uno.
 *
 * Los tipos dicen cómo se transforma la celda (LA IA NO TRANSFORMA NADA:
 * solo dice qué columna va a qué campo y cómo se traducen los valores de las
 * listas cerradas). Las reglas de validación NO se copian aquí: se piden al
 * controlador del módulo, las mismas del formulario.
 */
final class Destinos
{
    /** @return array<string, array<string, mixed>> */
    public static function todos(): array
    {
        return [
            'empleados' => [
                'nombre' => 'Empleados (nómina)',
                'descripcion' => 'Una fila por trabajador.',
                'modulo' => 'empleados',
                'modelo' => Employee::class,
                // Si ya existe un empleado con ese documento en la empresa, la
                // fila se marca como duplicada y no se importa.
                'clave' => 'numero_documento',
                'nombre_completo' => true,
                'campos' => [
                    'nombres' => self::c('Nombres', 'texto', true),
                    'apellidos' => self::c('Apellidos', 'texto', true),
                    'tipo_documento' => self::c('Tipo de documento', 'lista', true, ['CC', 'CE', 'TI', 'PA', 'PEP'], 'CC cédula, CE extranjería, TI tarjeta de identidad, PA pasaporte, PEP permiso especial'),
                    'numero_documento' => self::c('Número de documento', 'documento', true),
                    'fecha_nacimiento' => self::c('Fecha de nacimiento', 'fecha'),
                    'genero' => self::c('Género', 'texto'),
                    'grupo_sanguineo' => self::c('Grupo sanguíneo (RH)', 'texto'),
                    'telefono' => self::c('Teléfono', 'texto'),
                    'email' => self::c('Correo', 'texto'),
                    'direccion' => self::c('Dirección', 'texto'),
                    'ciudad' => self::c('Ciudad', 'texto'),
                    'cargo' => self::c('Cargo', 'texto'),
                    'area' => self::c('Área o proceso', 'texto'),
                    'sede' => self::c('Sede', 'texto'),
                    'fecha_ingreso' => self::c('Fecha de ingreso', 'fecha'),
                    'tipo_contrato' => self::c('Tipo de contrato', 'texto'),
                    'salario' => self::c('Salario', 'numero'),
                    'eps' => self::c('EPS', 'texto'),
                    'afp' => self::c('Fondo de pensiones (AFP)', 'texto'),
                    'arl' => self::c('ARL', 'texto'),
                    'nivel_riesgo' => self::c('Clase de riesgo ARL', 'lista', false, ['I', 'II', 'III', 'IV', 'V'], 'Clase de riesgo de la ARL en números romanos'),
                ],
                'fijos' => ['is_active' => true],
                'reglas' => fn (int $tenantId) => EmployeeController::reglas($tenantId),
            ],

            'iperc' => [
                'nombre' => 'Matriz IPERC (GTC 45)',
                'descripcion' => 'Una fila por peligro identificado.',
                'modulo' => 'iperc',
                'modelo' => IpercRow::class,
                'clave' => null,
                'nombre_completo' => false,
                'campos' => [
                    'proceso' => self::c('Proceso', 'texto', true),
                    'zona' => self::c('Zona o lugar', 'texto'),
                    'actividad' => self::c('Actividad', 'texto', true),
                    'tarea' => self::c('Tarea', 'texto'),
                    'rutinaria' => self::c('Rutinaria', 'booleano'),
                    'clasificacion' => self::c('Clasificación del peligro', 'lista', true, ['Biológico', 'Físico', 'Químico', 'Biomecánico', 'Psicosocial', 'Condiciones de seguridad', 'Fenómenos naturales']),
                    'peligro' => self::c('Descripción del peligro', 'texto', true),
                    'efectos' => self::c('Efectos posibles', 'texto'),
                    'peor_consecuencia' => self::c('Peor consecuencia', 'texto'),
                    'control_fuente' => self::c('Control existente en la fuente', 'texto'),
                    'control_medio' => self::c('Control existente en el medio', 'texto'),
                    'control_individuo' => self::c('Control existente en el individuo', 'texto'),
                    'nd' => self::c('Nivel de deficiencia (ND)', 'lista', true, ['0', '2', '6', '10'], 'GTC 45: 10 muy alto, 6 alto, 2 medio, 0 bajo'),
                    'ne' => self::c('Nivel de exposición (NE)', 'lista', true, ['1', '2', '3', '4'], 'GTC 45: 4 continua, 3 frecuente, 2 ocasional, 1 esporádica'),
                    'nc' => self::c('Nivel de consecuencia (NC)', 'lista', true, ['10', '25', '60', '100'], 'GTC 45: 100 mortal, 60 muy grave, 25 grave, 10 leve'),
                    'criterio_controles' => self::c('Criterios para establecer controles', 'texto'),
                    'med_eliminacion' => self::c('Medida: eliminación', 'texto'),
                    'med_sustitucion' => self::c('Medida: sustitución', 'texto'),
                    'med_ingenieria' => self::c('Medida: controles de ingeniería', 'texto'),
                    'med_administrativos' => self::c('Medida: controles administrativos', 'texto'),
                    'med_epp' => self::c('Medida: EPP', 'texto'),
                    'expuestos' => self::c('Número de expuestos', 'entero'),
                ],
                'fijos' => [],
                'reglas' => fn (int $tenantId) => IpercController::reglas(),
            ],

            'requisitos_legales' => [
                'nombre' => 'Matriz de requisitos legales',
                'descripcion' => 'Una fila por requisito (norma + artículo).',
                'modulo' => 'requisitos-legales',
                'modelo' => LegalRequirement::class,
                'clave' => null,
                'nombre_completo' => false,
                'campos' => [
                    'norma' => self::c('Norma (tipo y número)', 'texto', true, null, 'p. ej. «Resolución 0312», «Decreto 1072»'),
                    'anio' => self::c('Año', 'entero'),
                    'articulo' => self::c('Artículo', 'texto'),
                    'tema' => self::c('Tema', 'texto'),
                    'entidad' => self::c('Entidad que la expide', 'texto'),
                    'requisito' => self::c('Requisito o descripción de lo que exige', 'texto', true),
                    'aplica' => self::c('Aplica', 'booleano'),
                    'cumplimiento' => self::c('Cumplimiento', 'lista', true, LegalRequirement::CUMPLIMIENTOS, 'Si la matriz no evalúa el cumplimiento, déjalo sin columna y usa un valor fijo'),
                    'forma_cumplimiento' => self::c('Forma de cumplimiento', 'texto'),
                    'evidencia' => self::c('Evidencia', 'texto'),
                    'responsable' => self::c('Responsable', 'texto'),
                    'fecha_verificacion' => self::c('Fecha de verificación', 'fecha'),
                    'observaciones' => self::c('Observaciones', 'texto'),
                ],
                'fijos' => ['aplica' => true],
                'reglas' => fn (int $tenantId) => LegalRequirementController::reglas(),
            ],

            'ausentismo' => [
                'nombre' => 'Ausentismo (incapacidades y permisos)',
                'descripcion' => 'Una fila por ausencia. El trabajador tiene que estar en la nómina: se busca por cédula o por nombre.',
                'modulo' => 'ausentismo',
                'modelo' => Absence::class,
                'clave' => null,
                'nombre_completo' => false,
                'campos' => [
                    'employee_id' => self::c('Trabajador (cédula o nombre)', 'empleado', true, null, 'La columna con la cédula; si no hay, la del nombre completo'),
                    'fecha_inicio' => self::c('Fecha de inicio', 'fecha', true),
                    'fecha_fin' => self::c('Fecha de fin', 'fecha', true),
                    'dias' => self::c('Días', 'entero', false, null, 'Si no hay columna se calculan con las fechas'),
                    'tipo' => self::c('Tipo de ausencia', 'lista', true, Absence::TIPOS, 'enfermedad_general (EG), accidente_trabajo (AT), enfermedad_laboral (EL), accidente_comun, licencia_maternidad (o paternidad), licencia_luto, permiso, otro'),
                    'diagnostico' => self::c('Diagnóstico', 'texto'),
                    'cie10' => self::c('Código CIE-10', 'texto'),
                    'entidad' => self::c('Entidad (EPS o ARL)', 'texto'),
                    'incapacidad_numero' => self::c('Número de incapacidad', 'texto'),
                    'prorroga' => self::c('Prórroga', 'booleano'),
                    'observaciones' => self::c('Observaciones', 'texto'),
                ],
                'fijos' => [],
                'reglas' => fn (int $tenantId) => AbsenceController::reglas($tenantId),
            ],

            'vehiculos' => [
                'nombre' => 'Vehículos del PESV',
                'descripcion' => 'Una fila por vehículo (inventario de la flota).',
                'modulo' => 'pesv',
                'modelo' => PesvVehicle::class,
                'clave' => 'placa',
                'nombre_completo' => false,
                'campos' => [
                    'placa' => self::c('Placa', 'placa', true),
                    'tipo' => self::c('Tipo de vehículo', 'lista', true, PesvVehicle::TIPOS, 'automovil, camioneta, campero, camion (también tractocamión, volqueta), bus (también buseta, microbús), motocicleta, maquinaria, otro'),
                    'marca' => self::c('Marca', 'texto'),
                    'linea' => self::c('Línea', 'texto'),
                    'modelo' => self::c('Modelo (año)', 'entero'),
                    'propiedad' => self::c('Propiedad', 'lista', true, PesvVehicle::PROPIEDADES, 'propio, arrendado, leasing, contratista (tercero), colaborador (del trabajador)'),
                    'propietario' => self::c('Propietario', 'texto'),
                    'soat_vence' => self::c('Vencimiento SOAT', 'fecha'),
                    'tecnomecanica_vence' => self::c('Vencimiento revisión técnico-mecánica', 'fecha'),
                    'poliza_vence' => self::c('Vencimiento póliza', 'fecha'),
                    'kilometraje' => self::c('Kilometraje', 'entero'),
                    'observaciones' => self::c('Observaciones', 'texto'),
                ],
                'fijos' => ['is_active' => true],
                'reglas' => fn (int $tenantId) => PesvVehicleController::reglas($tenantId),
            ],

            'asistentes' => [
                'nombre' => 'Asistentes a una capacitación',
                'descripcion' => 'La lista de asistencia de una capacitación que ya existe. Si la cédula está en la nómina, el asistente queda enlazado al trabajador.',
                'modulo' => 'capacitaciones',
                'modelo' => TrainingAttendee::class,
                'clave' => 'numero_documento',
                'nombre_completo' => false,
                // Se importa DENTRO de una capacitación, que se elige al subir.
                'padre' => [
                    'label' => 'Capacitación',
                    'campo' => 'training_id',
                    'opciones' => fn () => Training::query()->orderByDesc('fecha')->orderByDesc('id')->limit(200)->get(['id', 'titulo', 'fecha'])
                        ->map(fn ($t) => ['id' => $t->id, 'nombre' => $t->titulo.($t->fecha ? ' · '.$t->fecha->format('Y-m-d') : '')])->all(),
                    'existe' => fn (int $id) => Training::query()->whereKey($id)->exists(),
                ],
                'campos' => [
                    'numero_documento' => self::c('Número de documento', 'documento'),
                    'nombres' => self::c('Nombre completo', 'texto', true, null, 'Si la cédula está en la nómina y no hay nombre, se toma de ahí'),
                    'cargo' => self::c('Cargo', 'texto'),
                    'asistio' => self::c('Asistió', 'booleano', false, null, 'Si la lista es solo de asistentes, déjalo sin columna: se marcan todos como asistentes'),
                    'nota' => self::c('Nota de la evaluación (0-100)', 'numero'),
                ],
                'fijos' => ['asistio' => true],
                'reglas' => fn (int $tenantId) => TrainingController::reglasAsistente(),
                // Ya inscritos en ESA capacitación (no en toda la empresa).
                'existentes' => fn (?int $padreId) => TrainingAttendee::query()->where('training_id', $padreId)
                    ->whereNotNull('numero_documento')->pluck('numero_documento')->all(),
                // Con la cédula en la nómina: se enlaza y se completa lo que falte.
                'completar' => function (array $datos, array $contexto): array {
                    $emp = isset($datos['numero_documento']) ? ($contexto['por_documento'][$datos['numero_documento']] ?? null) : null;
                    if ($emp) {
                        $datos['employee_id'] = $emp['id'];
                        $datos['nombres'] ??= $emp['nombre'];
                        $datos['cargo'] ??= $emp['cargo'];
                    }

                    return $datos;
                },
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function get(string $clave): array
    {
        return self::todos()[$clave] ?? throw new \InvalidArgumentException("Destino desconocido: {$clave}");
    }

    /**
     * Destinos a los que puede importar la empresa: los de módulos base
     * (empleados no es contratable) y los que tiene contratados. Importar a un
     * módulo no contratado sería meter datos en una pantalla que no ve.
     *
     * @return list<string>
     */
    public static function permitidos(?Tenant $tenant): array
    {
        $contratables = array_keys(config('cmk.modulos_contratables', []));

        return array_keys(array_filter(self::todos(), fn ($d) => ! in_array($d['modulo'], $contratables, true)
            || ($tenant?->moduloHabilitado($d['modulo']) ?? true)));
    }

    /** Lo que ve el navegador: sin closures ni nombres de clase. */
    public static function paraVista(): array
    {
        return collect(self::todos())->map(fn ($d) => [
            'nombre' => $d['nombre'],
            'descripcion' => $d['descripcion'],
            'nombre_completo' => $d['nombre_completo'],
            'padre' => isset($d['padre']) ? $d['padre']['label'] : null,
            'campos' => $d['campos'],
        ])->all();
    }

    private static function c(string $label, string $tipo, bool $requerido = false, ?array $opciones = null, ?string $ayuda = null): array
    {
        return array_filter([
            'label' => $label,
            'tipo' => $tipo,
            'requerido' => $requerido,
            'opciones' => $opciones,
            'ayuda' => $ayuda,
        ], fn ($v) => $v !== null);
    }
}
