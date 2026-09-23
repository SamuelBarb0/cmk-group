<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\EvaluacionesContratistas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contratista, subcontratista, proveedor o tercero de la empresa.
 *
 * Nació en el PESV (caracterización del Paso 5; insumo de los pasos 11 y 18)
 * y desde el módulo de Contratistas es el registro general: el mismo
 * contratista se ve en los dos sitios. La tabla conserva su nombre.
 */
class PesvContractor extends Model
{
    use BelongsToTenant;

    public const TIPOS = ['contratista', 'subcontratista', 'tercero', 'proveedor', 'propietario_vehiculo'];

    public const PERSONAS = ['juridica', 'natural'];

    protected $fillable = [
        'nombre',
        'nit',
        'tipo',
        'persona',
        'actividad',
        'direccion',
        'ciudad',
        'representante_legal',
        'supervisor',
        'fecha_ingreso',
        'contacto_nombre',
        'contacto_telefono',
        'contacto_email',
        'num_conductores',
        'num_vehiculos',
        'tiene_pesv',
        'evaluado_at',
        'calificacion',
        'observaciones',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'evaluado_at' => 'date:Y-m-d',
            'fecha_ingreso' => 'date:Y-m-d',
            'num_conductores' => 'integer',
            'num_vehiculos' => 'integer',
            'calificacion' => 'integer',
            'tiene_pesv' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ContractorDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ContractorDocument::class)->orderBy('orden');
    }

    /** @return HasMany<ContractorEvaluation, $this> */
    public function evaluations(): HasMany
    {
        return $this->hasMany(ContractorEvaluation::class);
    }

    /**
     * Lo que el consultor necesita ver de un contratista, calculado de sus
     * evaluaciones y documentos (cargados). Nada de esto se guarda: guardado
     * se quedaría viejo el día que venza un documento o pasen los 4 meses.
     *
     * @return array<string, mixed>
     */
    public function situacion(int $anio): array
    {
        $ordenadas = $this->evaluations
            ->sortByDesc(fn (ContractorEvaluation $e) => $e->fecha->format('Y-m-d').sprintf('%010d', $e->id));

        $ultima = fn (string $uso) => $ordenadas->firstWhere('uso', $uso);
        $seleccion = $ultima('seleccion');
        $evaluacion = $ultima('evaluacion');
        $sst = $ultima('requisitos_sst');

        // Reevaluación anual (PR-TERCEROS 5.3.3): promedio de las evaluaciones del año.
        $delAnio = $this->evaluations->where('uso', 'evaluacion')->filter(fn ($e) => $e->fecha->year === $anio);
        $promedio = $delAnio->isNotEmpty() ? round((float) $delAnio->avg('porcentaje'), 1) : null;

        $proxima = $evaluacion?->fecha->copy()->addMonths(EvaluacionesContratistas::MESES_ENTRE_EVALUACIONES);

        $docs = $this->documents->map(fn (ContractorDocument $d) => $d->alerta())->filter();

        return [
            'seleccion' => $seleccion ? $seleccion->resumen() : null,
            'evaluacion' => $evaluacion ? $evaluacion->resumen() : null,
            'requisitos_sst' => $sst ? $sst->resumen() : null,
            'proxima_evaluacion' => $proxima?->toDateString(),
            'evaluacion_vencida' => $proxima !== null && $proxima->isPast(),
            'reevaluacion' => $promedio === null ? null : [
                'anio' => $anio,
                'evaluaciones' => $delAnio->count(),
                'porcentaje' => $promedio,
                'resultado' => EvaluacionesContratistas::clasificar($promedio),
            ],
            'documentos_vencidos' => $docs->where('alerta', 'vencido')->count(),
            'documentos_por_vencer' => $docs->where('alerta', 'por_vencer')->count(),
            'documentos_pendientes' => $docs->where('alerta', 'no_entregado')->count(),
        ];
    }

    /**
     * Lleva al registro lo que el PESV ya mostraba a mano: fecha y
     * calificación de la última evaluación. Si no hay evaluaciones no toca
     * nada, para no borrar lo que se escribió antes de existir este módulo;
     * salvo tras borrar una ($trasBorrar): entonces lo que hay ahí salió de
     * la evaluación borrada y dejarlo sería mostrar una nota que ya no existe.
     */
    public function sincronizarCalificacion(bool $trasBorrar = false): void
    {
        $ultima = $this->evaluations()->where('uso', 'evaluacion')->orderByDesc('fecha')->orderByDesc('id')->first();
        if (! $ultima) {
            if ($trasBorrar) {
                $this->forceFill(['evaluado_at' => null, 'calificacion' => null])->save();
            }

            return;
        }

        $this->forceFill([
            'evaluado_at' => $ultima->fecha,
            'calificacion' => (int) round((float) $ultima->porcentaje),
        ])->save();
    }
}
