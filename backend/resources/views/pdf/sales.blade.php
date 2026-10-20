@extends('pdf.layout')
@section('title', 'Reporte de ventas')
@php
    $m = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', '.');
    $i = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, ',', '.');
    $p = fn ($v) => $v === null ? '—' : ($v > 0 ? '+' : '').number_format((float) $v, 1, ',', '.').' %';
@endphp
@section('content')
<h1>Reporte de ventas</h1>
<p class="meta">
    Periodo: {{ $summary['period']['from'] }} a {{ $summary['period']['to'] }}
    · {{ $summary['comparison'] === 'custom' ? 'Comparado con' : 'Periodo anterior' }}: {{ $summary['previous_period']['from'] }} a {{ $summary['previous_period']['to'] }}<br>
    Filtros: {{ $filters }}<br>
    Margen estimado con el costo actual (no el histórico), solo sobre líneas con costo válido.
</p>
@foreach ($warnings as $w)
    <div class="warn">{{ $w }}</div>
@endforeach

<h2>Indicadores</h2>
<table>
    <thead><tr><th>Indicador</th><th class="n">Periodo</th><th class="n">Comparado</th><th class="n">Variación</th></tr></thead>
    <tbody>
    @foreach ($metrics as $key => $label)
        @php $money = in_array($key, ['net_total', 'tax_total', 'grand_total', 'discount_total', 'average_ticket', 'estimated_margin']); @endphp
        <tr>
            <td>{{ $label }}</td>
            <td class="n">{{ $money ? $m($summary['current'][$key]) : $i($summary['current'][$key]) }}</td>
            <td class="n">{{ $money ? $m($summary['previous'][$key]) : $i($summary['previous'][$key]) }}</td>
            <td class="n">{{ $p($summary['change_pct'][$key]) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

@foreach ($sections as $section)
    <h2>{{ $section['title'] }}</h2>
    <table>
        <thead>
            <tr>
                <th>{{ $section['label'] }}</th><th class="n">Venta neta</th><th class="n">Impuesto</th><th class="n">Total facturado</th>
                <th class="n">Unidades</th><th class="n">Docs.</th><th class="n">Clientes</th><th class="n">Margen est.</th>
                @if ($section['compared'])<th class="n">Comparado</th><th class="n">Variación</th>@endif
            </tr>
        </thead>
        <tbody>
        @forelse ($section['rows'] as $r)
            <tr>
                <td>{{ $r['label'] }}</td><td class="n">{{ $m($r['net_total']) }}</td><td class="n">{{ $m($r['tax_total']) }}</td>
                <td class="n">{{ $m($r['grand_total']) }}</td><td class="n">{{ $i($r['units']) }}</td><td class="n">{{ $i($r['documents']) }}</td>
                <td class="n">{{ $i($r['customers']) }}</td><td class="n">{{ $m($r['estimated_margin']) }}</td>
                @if ($section['compared'])
                    <td class="n">{{ $m($r['previous_net_total'] ?? null) }}</td>
                    <td class="n">{{ array_key_exists('net_change_pct', $r) ? ($r['net_change_pct'] === null ? 'Sin ventas antes' : $p($r['net_change_pct'])) : '—' }}</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="10">Sin ventas en el periodo.</td></tr>
        @endforelse
        </tbody>
    </table>
    @if ($section['total'] > count($section['rows']))
        <p class="note">Se muestran las primeras {{ count($section['rows']) }} de {{ $section['total'] }} filas, ordenadas por venta neta. El detalle completo está en la exportación a Excel.</p>
    @endif
    @if ($section['note'])<p class="note">{{ $section['note'] }}</p>@endif
@endforeach
@endsection
