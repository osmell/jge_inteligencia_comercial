@extends('pdf.layout')
@section('title', $document->title)
@php
    use App\Services\Reports\Output\ReportPdf;
    $tone = ['good' => 'ok', 'warn' => 'mid', 'bad' => 'bad', 'none' => ''];
@endphp
@section('content')
<h1>{{ $document->title }}</h1>
<p class="meta">
    @foreach ($document->lines as $line){{ $line }}<br>@endforeach
</p>
@foreach ($document->notes as $note)
    <div class="warn">{{ $note }}</div>
@endforeach

<h2>Cifras clave</h2>
<table>
    <tbody>
    @foreach (array_chunk($document->stats, 3) as $group)
        <tr>
            @foreach ($group as $stat)
                <td>{{ $stat['label'] }}: <b>{{ ReportPdf::format($stat['value'], $stat['kind']) }}</b></td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>

@foreach ($document->sections as $section)
    @php
        // Una tabla ancha se parte en bloques de columnas (cada uno repite las que identifican la fila) para que quepa en la hoja.
        $anchors = array_slice($section->columns, 0, $section->anchors);
        $rest = array_slice($section->columns, $section->anchors, null, true);
        $perBlock = max(1, 10 - $section->anchors);
        $blocks = count($section->columns) > 11 ? array_chunk($rest, $perBlock, true) : [$rest];
    @endphp
    @foreach ($blocks as $n => $block)
        <h2>{{ $section->title }}@if (count($blocks) > 1) · parte {{ $n + 1 }} de {{ count($blocks) }}@endif</h2>
        @php $shown = array_slice($section->columns, 0, $section->anchors, true) + $block; @endphp
        <table>
            <thead><tr>
                @foreach ($shown as $column)
                    <th @class(['n' => $column->kind !== 'text'])>{{ $column->label }}</th>
                @endforeach
            </tr></thead>
            <tbody>
            @forelse ($section->rows as $row)
                <tr>
                    @foreach ($shown as $i => $column)
                        @php $cell = $row[$i] ?? null; @endphp
                        @if ($column->leveled && is_array($cell))
                            <td class="n {{ $tone[$cell['level']] ?? '' }}">{{ ReportPdf::format($cell['value'], $column->kind) }}<br><small>{{ $levels[$cell['level']] ?? '' }}</small></td>
                        @else
                            <td @class(['n' => $column->kind !== 'text'])>{{ ReportPdf::format($cell, $column->kind) }}</td>
                        @endif
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($shown) }}">Sin información en el periodo.</td></tr>
            @endforelse
            </tbody>
        </table>
    @endforeach
    @if ($section->note)<p class="note">{{ $section->note }}</p>@endif
@endforeach
@endsection
