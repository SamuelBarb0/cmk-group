<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Perfil de un cargo de la empresa (ISO 7.2 y 5.3, Dec. 1072 2.2.4.6.8 y
 * 2.2.4.6.11). Segregado por tenant.
 *
 * Los trabajadores no apuntan a esta tabla: guardan su cargo como texto, igual
 * que el profesiograma y la matriz de EPP, y se enlazan por el nombre
 * normalizado (ver `normalizar`).
 */
class JobPosition extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'nombre', 'process_id', 'reporta_a', 'objetivo', 'funciones',
        'responsabilidades_sig', 'autoridad', 'orden',
    ];

    /** «  Auxiliar   de BODEGA » y «auxiliar de bodega» son el mismo cargo. */
    public static function normalizar(?string $cargo): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $cargo)));
    }

    /**
     * Trabajadores activos con este cargo.
     *
     * @return Collection<int, Employee>
     */
    public function empleados(): Collection
    {
        $nombre = self::normalizar($this->nombre);

        return Employee::query()->where('is_active', true)->whereNotNull('cargo')
            ->orderBy('apellidos')->orderBy('nombres')->get()
            ->filter(fn (Employee $e) => self::normalizar($e->cargo) === $nombre)
            ->values();
    }

    /** ¿Tiene lo mínimo de un perfil: funciones y responsabilidades en el SIG? */
    public function perfilCompleto(): bool
    {
        return filled($this->funciones) && filled($this->responsabilidades_sig);
    }

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    /** @return HasMany<JobPositionRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(JobPositionRequirement::class)->orderBy('orden')->orderBy('id');
    }
}
