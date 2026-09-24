<?php

namespace App\Http\Controllers;

use App\Models\ControlledDocument;
use App\Models\Process;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mapa de procesos de la empresa activa (catálogo configurable por empresa).
 *
 * Permisos: documents.manage
 */
class ProcesoController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        $datos = $this->validated($request);
        Process::create($datos + ['orden' => (Process::query()->max('orden') ?? 0) + 1]);

        return back()->with('success', "Proceso {$datos['sigla']} creado.");
    }

    public function update(Request $request, Process $proceso): RedirectResponse
    {
        $datos = $this->validated($request, $proceso);

        // La sigla va dentro del código de sus documentos: cambiarla dejaría
        // códigos que ya no dicen quién es el dueño.
        if ($datos['sigla'] !== $proceso->sigla && $this->tieneDocumentos($proceso)) {
            throw ValidationException::withMessages(['sigla' => 'El proceso ya tiene documentos codificados con la sigla '.$proceso->sigla.'; no se puede cambiar.']);
        }

        $proceso->update($datos);

        return back()->with('success', 'Proceso actualizado.');
    }

    public function destroy(Process $proceso): RedirectResponse
    {
        if ($this->tieneDocumentos($proceso)) {
            return back()->withErrors(['proceso' => 'El proceso '.$proceso->sigla.' es dueño de documentos; no se puede eliminar.']);
        }

        $proceso->delete();

        return back()->with('success', 'Proceso eliminado.');
    }

    /** Incluye los documentos borrados: siguen ocupando su código. */
    private function tieneDocumentos(Process $proceso): bool
    {
        return ControlledDocument::withTrashed()->where('process_id', $proceso->id)->exists();
    }

    private function validated(Request $request, ?Process $proceso = null): array
    {
        $request->merge(['sigla' => strtoupper(trim((string) $request->input('sigla')))]);

        return $request->validate([
            'sigla' => ['required', 'regex:/^[A-Z]{3}$/',
                Rule::unique('processes', 'sigla')->where('tenant_id', $this->context->id())->ignore($proceso?->id)],
            'nombre' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::in(Process::TIPOS)],
        ], ['sigla.regex' => 'La sigla son exactamente tres letras (p. ej. SST).']);
    }
}
