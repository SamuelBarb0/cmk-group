<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Importacion\LectorTabular;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Una importación asistida de un Excel del cliente a un módulo. */
class DataImport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id', 'destino', 'archivo', 'nombre_original', 'hojas', 'hoja', 'estado',
        'mapeo', 'mapeo_editado', 'error', 'resultado', 'aplicado_at',
    ];

    protected function casts(): array
    {
        return [
            'hojas' => 'array',
            'mapeo' => 'array',
            'mapeo_editado' => 'boolean',
            'resultado' => 'array',
            'aplicado_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Las filas de la hoja elegida. Se relee el archivo cada vez en vez de
     * guardar las filas en la base: un Excel de miles de filas no cabe bien
     * en una columna JSON y el archivo ya está guardado.
     *
     * @return list<list<string|null>>
     */
    public function filas(): array
    {
        $hojas = LectorTabular::leer(Storage::disk('local')->path($this->archivo), pathinfo($this->archivo, PATHINFO_EXTENSION));

        return $hojas[$this->hoja] ?? [];
    }
}
