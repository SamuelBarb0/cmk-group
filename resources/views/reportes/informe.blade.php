<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $meta['titulo'] }} · {{ $informe['empresa']['nombre'] }}</title>
<style>
    @page { margin: 105px 48px 60px 48px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1f2937; line-height: 1.4; }
    header { position: fixed; top: -85px; left: 0; right: 0; height: 60px; border-bottom: 1.5px solid #16243F; }
    header img { height: 44px; }
    header .derecha { position: absolute; right: 0; top: 10px; text-align: right; color: #6E7277; font-size: 8px; }
    header .derecha b { color: #16243F; }
    footer { position: fixed; bottom: -40px; left: 0; right: 0; text-align: center; color: #888; font-size: 7.5px; }
    h1 { font-size: 20px; color: #16243F; margin: 0 0 4px; }
    h2 { font-size: 13px; color: #16243F; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #d0d5dd; page-break-after: avoid; }
    h3 { font-size: 10px; color: #16243F; margin: 10px 0 4px; page-break-after: avoid; }
    .empresa { font-size: 12px; font-weight: bold; margin: 0; }
    .gris { color: #6E7277; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    td, th { border: 0.6px solid #dddddd; padding: 3px 5px; vertical-align: top; }
    th { background: #16243F; color: #fff; font-weight: bold; text-align: left; font-size: 8px; }
    tr { page-break-inside: avoid; }
    thead { display: table-header-group; }
    .pares td.k { background: #F2F4F7; font-weight: bold; width: 45%; }
    .alerta { color: #B42318; font-weight: bold; }
    .atencion { margin: 0; padding-left: 14px; }
    .atencion li { margin-bottom: 3px; }
    .nota { font-style: italic; color: #6E7277; font-size: 7.5px; margin: 3px 0; }
    .obs p { margin: 0 0 6px; }
    .firmas { margin-top: 50px; }
    .firmas td { border: none; width: 50%; padding-top: 40px; }
    .firmas .linea { border-top: 0.8px solid #999; width: 80%; padding-top: 3px; }
</style>
</head>
<body>
<header>
    <img src="{{ $logo }}" alt="CMK GROUP">
    <div class="derecha">
        <b>{{ mb_strtoupper($company['legal_name'] ?? 'CMK GROUP S.A.S.') }} · NIT {{ $company['nit'] ?? '' }}</b><br>
        {{ $meta['titulo'] }} · {{ $informe['empresa']['nombre'] }}
    </div>
</header>
<footer>{{ $company['name'] ?? 'CMK GROUP' }} · {{ $company['domain'] ?? '' }}</footer>

<h1>{{ mb_strtoupper($meta['titulo']) }}</h1>
<p class="empresa">{{ $informe['empresa']['razon_social'] }}</p>
<p class="gris" style="margin-top:0">NIT {{ $informe['empresa']['nit'] ?: '—' }}@if($informe['empresa']['ciudad']) · {{ $informe['empresa']['ciudad'] }}@endif</p>
<table class="pares">
    <tr><td class="k">Periodo</td><td>{{ $informe['periodo']['etiqueta'] }}</td></tr>
    <tr><td class="k">Fecha del informe</td><td>{{ $informe['generado']['fecha'] }}</td></tr>
    <tr><td class="k">Elaborado por</td><td>{{ $informe['generado']['por'] }} — {{ $company['name'] ?? 'CMK GROUP' }}</td></tr>
</table>

<h2>{{ $meta['atencion_titulo'] }}</h2>
@if ($informe['atencion'] === [])
    <p>{{ $meta['atencion_vacia'] }}</p>
@else
    <ul class="atencion">
        @foreach ($informe['atencion'] as $a)
            <li><span class="alerta">{{ $a['seccion'] }}:</span> {{ $a['texto'] }}</li>
        @endforeach
    </ul>
@endif

@if (filled($observaciones))
    <h2>{{ $meta['observaciones_titulo'] }}</h2>
    <div class="obs">
        @foreach (preg_split('/\R{2,}/', trim($observaciones)) as $parrafo)
            <p>{!! nl2br(e($parrafo)) !!}</p>
        @endforeach
    </div>
@endif

@foreach ($informe['secciones'] as $s)
    <h2>{{ $s['titulo'] }}</h2>
    @if ($s['cifras'])
        <table class="pares">
            @foreach ($s['cifras'] as $c)
                <tr><td class="k">{{ $c['etiqueta'] }}</td><td @class(['alerta' => $c['alerta']])>{{ $c['valor'] }}</td></tr>
            @endforeach
        </table>
    @endif
    @foreach ($s['tablas'] as $t)
        @continue($t['filas'] === [] && $t['vacio'] === '')
        <h3>{{ $t['titulo'] }}</h3>
        @if ($t['filas'] === [])
            <p class="nota">{{ $t['vacio'] }}</p>
        @else
            <table>
                <thead><tr>@foreach ($t['columnas'] as $col)<th>{{ $col }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($t['filas'] as $fila)
                        <tr>@foreach ($fila as $v)<td>{{ $v }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
            @if ($t['omitidas'] > 0)
                <p class="nota">… y {{ $t['omitidas'] }} más. El detalle completo está en la exportación a Excel del módulo.</p>
            @endif
        @endif
    @endforeach
    @foreach ($s['notas'] as $n)
        <p class="nota">{{ $n }}</p>
    @endforeach
@endforeach

<table class="firmas">
    <tr>
        @foreach ($meta['firmas'] as [$rol, $nombre, $cargo])
            <td><div class="linea"><b>{{ $rol }}{{ $nombre ? ': '.$nombre : '' }}</b><br><span class="gris">{{ $cargo }}</span></div></td>
        @endforeach
    </tr>
</table>
</body>
</html>
