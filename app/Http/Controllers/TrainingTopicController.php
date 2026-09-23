<?php

namespace App\Http\Controllers;

use App\Models\Training;
use App\Models\TrainingTopic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Biblioteca de temas de capacitación: crear temas y cargar o reemplazar su
 * material (la presentación) desde la plataforma, sin pasar por el seeder ni
 * subir archivos al servidor a mano.
 *
 * La biblioteca es GLOBAL (la ven todas las empresas): solo la toca el
 * administrador de CMK. Lo editado aquí queda marcado (`editado_at`) y
 * TrainingTopicsSeeder deja de pisarlo en los despliegues.
 */
class TrainingTopicController extends Controller
{
    private const ROL_CATALOGO_GLOBAL = 'consultor_admin';

    public function index(Request $request): Response
    {
        $this->autorizar($request);

        // Capacitaciones de TODAS las empresas que salieron de cada tema.
        $usos = Training::withoutTenantScope()
            ->selectRaw('training_topic_id, count(*) as total')
            ->groupBy('training_topic_id')
            ->pluck('total', 'training_topic_id');

        return Inertia::render('capacitaciones/temas', [
            'topics' => TrainingTopic::orderBy('orden')->orderBy('id')->get()->map(fn (TrainingTopic $t) => [
                ...$t->only(['id', 'codigo', 'titulo', 'categoria', 'descripcion', 'duracion_sugerida', 'orden', 'activo', 'editado_por']),
                'editado_at' => $t->editado_at?->toDateTimeString(),
                'material' => $t->tieneArchivo() ? [
                    'extension' => Str::lower(pathinfo($t->archivo, PATHINFO_EXTENSION)),
                    'bytes' => Storage::disk('local')->size($t->archivo),
                ] : null,
                'usos' => (int) ($usos[$t->id] ?? 0),
            ]),
            'categorias' => TrainingTopic::CATEGORIAS,
            'extensiones' => TrainingTopic::EXTENSIONES,
            'maxBytes' => $this->maxBytes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->autorizar($request);

        $data = $this->validar($request);
        $tema = new TrainingTopic([
            ...$data,
            'orden' => $data['orden'] ?? ((int) TrainingTopic::max('orden') + 10),
            'activo' => true,
            'editado_at' => now(),
            'editado_por' => $request->user()->name,
        ]);
        if ($request->hasFile('archivo')) {
            $tema->archivo = $this->guardarArchivo($request->file('archivo'), $tema->codigo);
        }
        $tema->save();

        return back()->with('success', "Tema «{$tema->titulo}» creado.");
    }

    /**
     * Edita los datos del tema y, si viene archivo, reemplaza el material. Va
     * por POST: PHP no lee archivos de un PUT multipart.
     */
    public function update(Request $request, TrainingTopic $tema): RedirectResponse
    {
        $this->autorizar($request);

        $data = $this->validar($request, $tema);
        $anterior = $tema->archivo;

        $tema->fill([
            ...$data,
            'orden' => $data['orden'] ?? $tema->orden,
            'editado_at' => now(),
            'editado_por' => $request->user()->name,
        ]);
        if ($request->hasFile('archivo')) {
            $tema->archivo = $this->guardarArchivo($request->file('archivo'), $tema->codigo);
        }
        $tema->save();

        // El archivo viejo se borra solo DESPUÉS de guardar el nuevo, y solo si
        // ningún otro tema lo usa.
        if ($anterior && $anterior !== $tema->archivo && ! TrainingTopic::where('archivo', $anterior)->exists()) {
            Storage::disk('local')->delete($anterior);
        }

        return back()->with('success', $request->hasFile('archivo')
            ? "Material de «{$tema->titulo}» actualizado."
            : "Tema «{$tema->titulo}» guardado.");
    }

    /**
     * Retirar saca el tema de la biblioteca de las empresas sin tocar las
     * capacitaciones ya registradas. No se borra: esas capacitaciones lo
     * referencian.
     */
    public function toggle(Request $request, TrainingTopic $tema): RedirectResponse
    {
        $this->autorizar($request);

        $tema->update(['activo' => ! $tema->activo]);

        return back()->with('success', $tema->activo
            ? "Tema «{$tema->titulo}» activado."
            : "Tema «{$tema->titulo}» retirado de la biblioteca.");
    }

    private function validar(Request $request, ?TrainingTopic $actual = null): array
    {
        $request->merge(['codigo' => Str::upper(trim((string) $request->input('codigo')))]);

        return $request->validate([
            'codigo' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9-]*$/', Rule::unique('training_topics', 'codigo')->ignore($actual?->id)],
            'titulo' => ['required', 'string', 'max:255'],
            'categoria' => ['required', Rule::in(TrainingTopic::CATEGORIAS)],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'duracion_sugerida' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:9999'],
            // Por extensión y no por MIME: los .ppt viejos se detectan como
            // «CDFV2» y `mimes` los rechazaría siendo válidos.
            'archivo' => ['nullable', 'file', 'extensions:'.implode(',', TrainingTopic::EXTENSIONES), 'max:'.intdiv($this->maxBytes(), 1024)],
        ], [
            'codigo.regex' => 'El código solo admite letras, números y guiones (ej. CAP-ALTURAS).',
            'archivo.extensions' => 'El material debe ser '.implode(', ', TrainingTopic::EXTENSIONES).'.',
            'archivo.uploaded' => 'El archivo no se pudo subir: supera el límite del servidor.',
        ]);
    }

    /** Nombre nuevo en cada carga: reemplazar nunca deja el tema sin archivo a medias. */
    private function guardarArchivo(UploadedFile $archivo, string $codigo): string
    {
        $nombre = Str::slug($codigo).'-'.now()->format('YmdHis').'.'.Str::lower($archivo->getClientOriginalExtension());

        return $archivo->storeAs('capacitaciones', $nombre, 'local');
    }

    /** Lo que PHP deja subir de verdad: el menor entre archivo y POST completo. */
    private function maxBytes(): int
    {
        $bytes = function (string $valor): int {
            $valor = trim($valor);
            $n = (int) $valor;

            return match (Str::lower(substr($valor, -1))) {
                'g' => $n * 1024 ** 3,
                'm' => $n * 1024 ** 2,
                'k' => $n * 1024,
                default => $n,
            };
        };
        $limites = array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))]);

        // Un poco por debajo del POST: el formulario también ocupa.
        return (int) (($limites ? min($limites) : 8 * 1024 ** 2) * 0.98);
    }

    private function autorizar(Request $request): void
    {
        abort_unless($request->user()?->hasRole(self::ROL_CATALOGO_GLOBAL), 403, 'Solo el administrador de CMK puede editar la biblioteca de capacitaciones.');
    }
}
