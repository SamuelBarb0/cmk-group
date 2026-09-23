<?php

namespace App\Http\Controllers;

use App\Jobs\MapearImportacionJob;
use App\Models\DataImport;
use App\Services\Ai\MapeadorImportacion;
use App\Support\Importacion\Aplicador;
use App\Support\Importacion\Destinos;
use App\Support\Importacion\LectorTabular;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Importación asistida por IA: sube un Excel del cliente, la IA propone el
 * mapeo, el consultor lo revisa sobre la vista previa y confirma.
 *
 * Nada se escribe en el módulo hasta «Importar», y lo importado se puede
 * deshacer entero (se guardan los ids creados).
 *
 * Permisos: todo -> sst.manage (es carga masiva de datos).
 */
class ImportacionController extends Controller
{
    /** Filas válidas que se mandan a la vista previa (los errores van todos). */
    private const PREVIA_VALIDAS = 50;

    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        return Inertia::render('importar/index', [
            'needsClient' => ! $this->context->has(),
            'importaciones' => $this->context->has()
                ? DataImport::query()->with('user:id,name')->latest()->limit(50)->get()
                    ->map(fn (DataImport $i) => [
                        ...$i->only(['id', 'destino', 'nombre_original', 'hoja', 'estado', 'resultado']),
                        'usuario' => $i->user?->name,
                        'fecha' => $i->created_at->format('Y-m-d H:i'),
                    ])
                : [],
            'destinos' => Destinos::paraVista(),
            'permitidos' => Destinos::permitidos($this->context->get()),
            // Registros dentro de los que se puede importar (las capacitaciones,
            // para una lista de asistentes), por destino.
            'padres' => $this->context->has()
                ? collect(Destinos::todos())->filter(fn ($d) => isset($d['padre']))->map(fn ($d) => ($d['padre']['opciones'])())->all()
                : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->context->has(), 422, 'Selecciona un cliente antes de importar.');

        $data = $request->validate([
            'destino' => ['required', Rule::in(Destinos::permitidos($this->context->get()))],
            // `extensions` y no `mimes`: un .xlsx sale como octet-stream en finfo
            // (el mismo tropiezo de la subida de plantillas del 3-sep).
            // xlsb entra para que el lector dé el mensaje concreto («guárdalo como .xlsx»).
            'archivo' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls,ods,csv,xlsb'],
            'padre_id' => ['nullable', 'integer'],
        ], [], ['archivo' => 'archivo', 'padre_id' => 'capacitación']);

        // Un destino con padre (asistentes -> capacitación) lo exige, y tiene que
        // ser de ESTA empresa: `exists` no pasa por el TenantScope, `existe` sí.
        $padre = Destinos::get($data['destino'])['padre'] ?? null;
        if ($padre && (empty($data['padre_id']) || ! ($padre['existe'])((int) $data['padre_id']))) {
            throw ValidationException::withMessages(['padre_id' => "Elige la {$padre['label']} en la que se importa."]);
        }

        $archivo = $request->file('archivo');
        $ext = strtolower($archivo->getClientOriginalExtension());
        $ruta = $archivo->storeAs('tenants/'.$this->context->id().'/importaciones', Str::uuid().'.'.$ext, 'local');

        try {
            $hojas = LectorTabular::leer(Storage::disk('local')->path($ruta), $ext);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($ruta);
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }

        $resumen = collect($hojas)->map(fn ($f, $n) => ['nombre' => $n, 'filas' => count($f)])->values()->all();
        $import = DataImport::create([
            'user_id' => $request->user()?->id,
            'destino' => $data['destino'],
            'padre_id' => $padre ? (int) $data['padre_id'] : null,
            'archivo' => $ruta,
            'nombre_original' => $archivo->getClientOriginalName(),
            'hojas' => $resumen,
            'estado' => 'subido',
        ]);

        // Con una sola hoja no hay nada que elegir: directo a la IA.
        if (count($resumen) === 1) {
            $this->encolarMapeo($import, $resumen[0]['nombre']);
        }

        return to_route('importar.show', $import);
    }

    public function show(DataImport $importacion): Response
    {
        $destino = Destinos::get($importacion->destino);
        $previa = null;
        $encabezados = [];
        $distintos = [];

        if (in_array($importacion->estado, ['listo', 'aplicado', 'deshecho'], true) && $importacion->mapeo) {
            $filas = $importacion->filas();
            $previa = $this->previa($importacion, $destino, $filas);
            $encabezados = $this->encabezados($filas, $importacion->mapeo);
            $distintos = MapeadorImportacion::distintos(array_slice($filas, $importacion->mapeo['fila_inicio']));
        }

        return Inertia::render('importar/show', [
            'needsClient' => false,
            'importacion' => [
                ...$importacion->only(['id', 'destino', 'nombre_original', 'hojas', 'hoja', 'estado', 'mapeo', 'mapeo_editado', 'error', 'resultado']),
                'aplicado_at' => $importacion->aplicado_at?->format('Y-m-d H:i'),
            ],
            'destino' => Destinos::paraVista()[$importacion->destino],
            'padre' => isset($destino['padre']) && $importacion->padre_id
                ? collect(($destino['padre']['opciones'])())->firstWhere('id', $importacion->padre_id)['nombre'] ?? null
                : null,
            'modulo' => $destino['modulo'],
            'encabezados' => $encabezados,
            'distintos' => $distintos,
            'previa' => $previa,
        ]);
    }

    /** Elegir hoja (o volver a pedirle el mapeo a la IA). */
    public function mapear(Request $request, DataImport $importacion): RedirectResponse
    {
        $this->exigeNoAplicada($importacion);
        $nombres = array_column($importacion->hojas, 'nombre');
        $hoja = $request->validate(['hoja' => ['required', Rule::in($nombres)]])['hoja'];

        $this->encolarMapeo($importacion, $hoja);

        return back();
    }

    /** Corrección manual del mapeo. No llama a la IA: la vista previa se recalcula. */
    public function actualizar(Request $request, DataImport $importacion): RedirectResponse
    {
        $this->exigeNoAplicada($importacion);
        $destino = Destinos::get($importacion->destino);
        $campos = array_keys($destino['campos']);

        $data = $request->validate([
            'fila_inicio' => ['required', 'integer', 'min:0'],
            'columnas' => ['present', 'array'],
            'columnas.*' => ['nullable', 'integer', 'min:0', 'max:'.(LectorTabular::MAX_COLUMNAS - 1)],
            'nombre_completo' => ['nullable', 'array'],
            'nombre_completo.columna' => ['nullable', 'integer', 'min:0'],
            'nombre_completo.orden' => ['nullable', Rule::in(['nombres_apellidos', 'apellidos_nombres'])],
            // Lista y no mapa: un valor del Excel como «N.A.» usado de clave
            // se lee como anidamiento y validate() lo descartaría en silencio.
            'valores' => ['present', 'array'],
            'valores.*.campo' => ['required', Rule::in($campos)],
            'valores.*.original' => ['required', 'string', 'max:255'],
            'valores.*.destino' => ['nullable', 'string', 'max:255'],
            'fijos' => ['present', 'array'],
            'fijos.*' => ['nullable', 'string', 'max:255'],
        ]);

        $mapeo = $importacion->mapeo ?? [];
        $mapeo['fila_inicio'] = $data['fila_inicio'];
        $mapeo['columnas'] = collect($campos)->mapWithKeys(fn ($c) => [$c => $data['columnas'][$c] ?? null])->all();
        $mapeo['nombre_completo'] = [
            'columna' => $data['nombre_completo']['columna'] ?? null,
            'orden' => $data['nombre_completo']['orden'] ?? 'nombres_apellidos',
        ];
        // Traducciones indexadas por el valor normalizado; las vacías
        // («sin traducir») se descartan.
        $valores = [];
        foreach ($data['valores'] as $v) {
            if (($v['destino'] ?? '') !== '') {
                $valores[$v['campo']][Aplicador::normalizar($v['original'])] = $v['destino'];
            }
        }
        $mapeo['valores'] = $valores;
        $mapeo['fijos'] = collect($data['fijos'])->only($campos)->filter(fn ($v) => $v !== null && $v !== '')->all();

        $importacion->update(['mapeo' => $mapeo, 'mapeo_editado' => true]);

        return back()->with('success', 'Mapeo actualizado.');
    }

    /** Crea los registros de las filas válidas. Todo o nada. */
    public function aplicar(DataImport $importacion): RedirectResponse
    {
        $this->exigeNoAplicada($importacion);
        abort_unless($importacion->estado === 'listo', 422, 'La importación aún no tiene un mapeo listo.');

        $destino = Destinos::get($importacion->destino);
        $r = $this->aplicarMapeo($importacion, $destino, $importacion->filas());
        $validas = array_values(array_filter($r['filas'], fn ($f) => $f['estado'] === 'valida'));

        if ($validas === []) {
            throw ValidationException::withMessages(['importacion' => 'No hay filas válidas para importar.']);
        }

        // Los asistentes van DENTRO de su capacitación.
        $delPadre = isset($destino['padre']) ? [$destino['padre']['campo'] => $importacion->padre_id] : [];

        $ids = DB::transaction(function () use ($validas, $destino, $delPadre): array {
            $modelo = $destino['modelo'];
            $ids = [];
            foreach ($validas as $f) {
                $ids[] = $modelo::create($f['datos'] + $delPadre)->id;
            }

            return $ids;
        });

        $importacion->update([
            'estado' => 'aplicado',
            'aplicado_at' => now(),
            'resultado' => ['creados' => $ids] + $r['resumen'],
        ]);

        return back()->with('success', count($ids).' registro(s) importado(s).');
    }

    /**
     * Borra lo que creó esta importación. Solo lo suyo: los ids guardados, y
     * a través del TenantScope, así que nunca toca otra empresa.
     */
    public function deshacer(DataImport $importacion): RedirectResponse
    {
        abort_unless($importacion->estado === 'aplicado', 422, 'Solo se puede deshacer una importación aplicada.');
        $destino = Destinos::get($importacion->destino);
        $ids = $importacion->resultado['creados'] ?? [];

        // Los asistentes no llevan tenant propio: además de los ids, se exige
        // que sean de la capacitación de esta importación.
        $borrados = DB::transaction(fn () => $destino['modelo']::query()->whereKey($ids)
            ->when(isset($destino['padre']), fn ($q) => $q->where($destino['padre']['campo'], $importacion->padre_id))
            ->delete());

        $importacion->update(['estado' => 'deshecho', 'resultado' => ['borrados' => $borrados] + ($importacion->resultado ?? [])]);

        return back()->with('success', "Importación deshecha: {$borrados} registro(s) eliminado(s).");
    }

    public function destroy(DataImport $importacion): RedirectResponse
    {
        Storage::disk('local')->delete($importacion->archivo);
        $importacion->delete();

        return to_route('importar.index')->with('success', 'Importación eliminada. Los registros ya importados se conservan.');
    }

    // ------------------------------------------------------------------ apoyo

    private function encolarMapeo(DataImport $import, string $hoja): void
    {
        $import->update(['hoja' => $hoja, 'estado' => 'mapeando', 'mapeo' => null, 'error' => null]);
        MapearImportacionJob::dispatch($import->id);
    }

    private function exigeNoAplicada(DataImport $import): void
    {
        abort_if(in_array($import->estado, ['aplicado', 'deshecho'], true), 422, 'Esta importación ya se aplicó.');
    }

    /**
     * El mapeo aplicado a las filas, con lo que ya existe (duplicados) y la
     * nómina (campos de empleado). Lo usan la vista previa y «Importar»: lo que
     * se ve es exactamente lo que se guarda.
     */
    private function aplicarMapeo(DataImport $import, array $destino, array $filas): array
    {
        $existentes = match (true) {
            isset($destino['existentes']) => ($destino['existentes'])($import->padre_id),
            $destino['clave'] !== null => $destino['modelo']::query()->pluck($destino['clave'])->all(),
            default => [],
        };
        $usaNomina = isset($destino['completar'])
            || collect($destino['campos'])->contains(fn ($c) => $c['tipo'] === 'empleado');

        return Aplicador::aplicar(
            $destino, $filas, $import->mapeo, $this->context->id(),
            array_map('strval', $existentes),
            $usaNomina ? Aplicador::contextoEmpleados() : [],
        );
    }

    private function previa(DataImport $import, array $destino, array $filas): array
    {
        $r = $this->aplicarMapeo($import, $destino, $filas);
        $validas = array_filter($r['filas'], fn ($f) => $f['estado'] === 'valida');
        $otras = array_filter($r['filas'], fn ($f) => $f['estado'] !== 'valida');
        // Todas las que tienen problema, y una muestra de las buenas.
        $muestra = array_values([...$otras, ...array_slice($validas, 0, self::PREVIA_VALIDAS)]);

        // Un campo de empleado guarda el id: para revisar hace falta el NOMBRE.
        // Solo para mostrar; lo que se guarda sigue siendo el id.
        $deEmpleado = array_keys(array_filter($destino['campos'], fn ($c) => $c['tipo'] === 'empleado'));
        if ($deEmpleado) {
            $nombres = collect(Aplicador::contextoEmpleados()['por_documento'])->mapWithKeys(fn ($e) => [$e['id'] => $e['nombre']]);
            foreach ($muestra as &$f) {
                foreach ($deEmpleado as $campo) {
                    if (isset($f['datos'][$campo])) {
                        $f['etiquetas'][$campo] = $nombres[$f['datos'][$campo]] ?? null;
                    }
                }
            }
            unset($f);
        }

        return ['resumen' => $r['resumen'], 'filas' => $muestra];
    }

    /**
     * Título de cada columna para los selectores: la fila de encabezado y,
     * si el encabezado está partido en dos filas, también la de arriba.
     *
     * @return list<array{indice: int, letra: string, titulo: string}>
     */
    private function encabezados(array $filas, array $mapeo): array
    {
        $fe = $mapeo['fila_encabezado'] ?? max(0, $mapeo['fila_inicio'] - 1);
        $ancho = collect($filas)->map(fn ($f) => count($f))->max() ?? 0;
        $out = [];
        for ($c = 0; $c < $ancho; $c++) {
            $partes = array_filter([$filas[$fe - 1][$c] ?? null, $filas[$fe][$c] ?? null]);
            $ejemplo = $filas[$mapeo['fila_inicio']][$c] ?? null;
            $out[] = [
                'indice' => $c,
                'letra' => LectorTabular::letra($c),
                'titulo' => implode(' / ', $partes) ?: ($ejemplo ? 'ej. '.mb_substr($ejemplo, 0, 30) : ''),
            ];
        }

        return $out;
    }
}
