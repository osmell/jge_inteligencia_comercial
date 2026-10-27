@extends('pdf.layout')
@section('title', 'Metas de ventas')
@php
    $m = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, '.', ',');
    $p = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, '.', ',').' %';
    $labels = ['met' => 'Meta cumplida', 'warning' => 'Cerca de la meta', 'danger' => 'Por debajo', 'no_goal' => 'Sin meta', 'exempt' => 'Exento'];
    $tone = ['met' => 'ok', 'warning' => 'mid', 'danger' => 'bad', 'no_goal' => '', 'exempt' => ''];
@endphp
@section('content')
<h1>Metas de ventas · {{ $month }}</h1>
<p class="meta">
    Meta de venta neta (sin IVA) por vendedor, prorrateada por alta, baja y ausencias.<br>
    Semáforo: cumplida desde {{ $thresholds['goal_pct'] }} %, cerca desde {{ $thresholds['warning_pct'] }} %, por debajo en otro caso.
    @if ($state === 'current') Alerta de ritmo: menos de {{ $thresholds['pace_alert_pct'] }} % de lo esperado a la fecha.@endif
</p>
<h2>Totales</h2>
<table>
    <tbody><tr>
        <td>Meta efectiva: <b>{{ $m($totals['effective_goal']) }}</b></td>
        <td>Vendido: <b>{{ $m($totals['actual']) }}</b></td>
        <td>Cumplimiento: <b>{{ $p($totals['compliance_pct']) }}</b></td>
        <td>Cumplidas {{ $totals['met'] }} · Cerca {{ $totals['warning'] }} · Por debajo {{ $totals['danger'] }} · Sin meta {{ $totals['no_goal'] }}</td>
    </tr></tbody>
</table>
<p class="note">{{ $totals['note'] }}</p>

<h2>Por vendedor</h2>
<table>
    <thead><tr>
        <th>Vendedor</th><th class="n">Meta del mes</th><th class="n">Días elegibles</th><th class="n">Meta efectiva</th><th class="n">Vendido</th>
        <th class="n">Cumpl.</th><th>Estado</th><th class="n">Proyección</th><th class="n">Ritmo</th>
    </tr></thead>
    <tbody>
    @forelse ($rows as $r)
        <tr>
            <td>{{ $r['seller'] }}</td><td class="n">{{ $m($r['goal_amount']) }}</td>
            <td class="n">{{ $r['eligible_days'] }} / {{ $r['days_in_month'] }}</td><td class="n">{{ $m($r['effective_goal']) }}</td>
            <td class="n">{{ $m($r['actual']) }}</td><td class="n">{{ $p($r['compliance_pct']) }}</td>
            <td class="{{ $tone[$r['status']] }}">{{ $labels[$r['status']] }}</td>
            <td class="n">{{ $r['projected'] === null ? '—' : $m($r['projected']).' ('.$p($r['projected_pct']).')' }}</td>
            <td class="n {{ $r['behind_pace'] ? 'bad' : '' }}">{{ $r['pace_pct'] === null ? '—' : $p($r['pace_pct']).($r['behind_pace'] ? ' · atrasado' : '') }}</td>
        </tr>
    @empty
        <tr><td colspan="9">No hay vendedores con ventas ni metas en este mes.</td></tr>
    @endforelse
    </tbody>
</table>
@endsection
