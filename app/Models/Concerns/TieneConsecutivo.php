<?php

namespace App\Models\Concerns;

/**
 * Consecutivo anual por empresa, al estilo de ACPM: «PREFIJO-2026-001». El
 * modelo declara `CONSECUTIVO` (prefijo) y `CAMPO_CONSECUTIVO` (columna).
 */
trait TieneConsecutivo
{
    protected static function bootTieneConsecutivo(): void
    {
        static::creating(function (self $modelo): void {
            $campo = static::CAMPO_CONSECUTIVO;
            $modelo->{$campo} ??= static::siguienteConsecutivo($modelo->tenant_id);
        });
    }

    public static function siguienteConsecutivo(?int $tenantId): string
    {
        $prefijo = static::CONSECUTIVO.'-'.now()->year.'-';
        $ultimo = static::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where(static::CAMPO_CONSECUTIVO, 'like', $prefijo.'%')
            ->orderByDesc(static::CAMPO_CONSECUTIVO)
            ->value(static::CAMPO_CONSECUTIVO);
        $n = $ultimo ? ((int) substr($ultimo, strlen($prefijo))) + 1 : 1;

        return $prefijo.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
