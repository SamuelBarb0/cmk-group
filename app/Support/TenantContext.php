<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Contenedor del tenant "activo" durante el ciclo de vida del request.
 *
 * - Usuario de un cliente  -> el middleware fija su tenant_id (bloqueado).
 * - Personal de CMK        -> puede seleccionar un cliente para trabajar,
 *                             o dejarlo en null para la vista consolidada
 *                             (dashboard maestro de todos los clientes).
 *
 * Se registra como singleton en el contenedor de servicios.
 */
class TenantContext
{
    private ?int $tenantId = null;

    private ?Tenant $tenant = null;

    /** Indica si el contexto ya fue resuelto para este request. */
    private bool $resolved = false;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->tenantId = $tenant?->id;
        $this->resolved = true;
    }

    public function setId(?int $tenantId): void
    {
        $this->tenantId = $tenantId;
        $this->tenant = null;
        $this->resolved = true;
    }

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function get(): ?Tenant
    {
        if ($this->tenant === null && $this->tenantId !== null) {
            $this->tenant = Tenant::find($this->tenantId);
        }

        return $this->tenant;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function markResolved(): void
    {
        $this->resolved = true;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }
}
