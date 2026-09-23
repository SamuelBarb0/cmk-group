<?php

namespace App\Jobs;

use App\Models\DataImport;
use App\Services\Ai\MapeadorImportacion;
use App\Support\Importacion\Destinos;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Pide a la IA el mapeo de una importación, en segundo plano: igual que la
 * redacción de documentos, así la petición web responde al instante y la
 * llamada no choca con el tiempo máximo del servidor (el 504 de Protección).
 */
class MapearImportacionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240;

    public int $tries = 1;

    public function __construct(public int $importId) {}

    public function handle(MapeadorImportacion $mapeador): void
    {
        // En cola no hay TenantContext: se busca sin el scope por tenant.
        $import = DataImport::withoutTenantScope()->find($this->importId);
        if ($import === null || $import->estado !== 'mapeando') {
            return;   // borrada, o ya resuelta por otra vía
        }

        $mapeo = $mapeador->mapear(Destinos::get($import->destino), $import->filas(), $import->hoja);

        $import->update(['mapeo' => $mapeo, 'mapeo_editado' => false, 'estado' => 'listo', 'error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        DataImport::withoutTenantScope()
            ->where('id', $this->importId)
            ->where('estado', 'mapeando')
            ->update(['estado' => 'error', 'error' => $e?->getMessage() ?? 'Error desconocido']);
    }
}
