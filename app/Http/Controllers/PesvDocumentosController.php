<?php

namespace App\Http\Controllers;

use App\Support\Pesv\SemaforoDocumentos;
use App\Support\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Semáforo de documentos de conductores y vehículos (RE-SST-54 de CMK):
 * los vencimientos que ya están en las fichas, vistos juntos.
 */
class PesvDocumentosController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('pesv/documentos', ['needsClient' => true]);
        }

        $filas = SemaforoDocumentos::filas();

        return Inertia::render('pesv/documentos', [
            'needsClient' => false,
            'filas' => $filas,
            'resumen' => SemaforoDocumentos::resumen($filas),
            'reglas' => ['no_cumple' => SemaforoDocumentos::DIAS_NO_CUMPLE, 'por_vencer' => SemaforoDocumentos::DIAS_POR_VENCER],
        ]);
    }
}
