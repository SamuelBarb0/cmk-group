<?php

namespace App\Services\Reportes\Secciones;

use App\Models\ContextIssue;
use App\Models\ContextProfile;
use App\Models\InterestedParty;
use App\Models\Process;
use App\Services\Contexto\DocumentosContexto;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/**
 * Contexto de la organización (M02): es foto del estado actual, no del
 * periodo. Alimenta la entrada «cambios en el contexto y las partes
 * interesadas» de la revisión por la dirección.
 */
class Contexto extends Seccion
{
    public function clave(): string
    {
        return 'contexto';
    }

    public function titulo(): string
    {
        return 'Contexto de la organización';
    }

    public function modulo(): ?string
    {
        return 'contexto';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $perfil = ContextProfile::query()->first();
        $cuestiones = ContextIssue::query()->get();
        $partes = InterestedParty::query()->get();
        $procesos = Process::query()->orderBy('orden')->get();
        $completo = DocumentosContexto::completitud($perfil, $cuestiones, $partes, $procesos);

        $amenazasAltas = $cuestiones->where('dofa', 'amenaza')->where('impacto', 'alto');
        $caracterizados = $procesos->filter(fn (Process $p) => $p->caracterizado())->count();

        $this->cifra('Cuestiones internas', $cuestiones->where('origen', 'interno')->count());
        $this->cifra('Cuestiones externas', $cuestiones->where('origen', 'externo')->count());
        $this->cifra('Amenazas de impacto alto', $amenazasAltas->count());
        $this->cifra('Partes interesadas', $partes->count());
        $this->cifra('Necesidades que son requisito', $partes->where('es_requisito', true)->count());
        $this->cifra('Procesos caracterizados', "{$caracterizados} de {$procesos->count()}",
            $procesos->count() && $caracterizados < $procesos->count() ? 'Hay procesos del mapa sin caracterizar.' : null);
        $this->cifra('¿Cambio climático pertinente?', match ($perfil?->cambio_climatico) {
            true => 'Sí', false => 'No', default => 'Sin decidir',
        }, $completo['clima'] ? null : 'Falta decidir y justificar si el cambio climático es pertinente (4.1).');
        $this->cifra('Última revisión del contexto', $perfil?->revisado_at?->format('Y-m-d') ?? 'Nunca',
            ($perfil?->revisionVencida() ?? true) ? 'El contexto no se ha revisado en el último año.' : null);

        $this->tabla('Amenazas de impacto alto', ['Amenaza', 'PESTEL', 'Tratamiento'],
            $amenazasAltas->map(fn (ContextIssue $c) => [self::corto($c->descripcion, 90), $c->pestel ? ucfirst($c->pestel) : '—', self::corto($c->tratamiento ?: 'Sin tratamiento definido', 70)]),
            'Sin amenazas de impacto alto registradas.');
    }
}
