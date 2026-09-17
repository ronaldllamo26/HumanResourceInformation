{{--
    The printed face of every report.

    Deliberately plain HTML and inline CSS: dompdf understands a small subset
    of CSS and none of Tailwind's, so the design tokens the screens use cannot
    reach here. Colours are kept to black on white with grey rules, which is
    also what a report photocopied in an office actually needs.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }}</title>
    <style>
        @page { margin: 14mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #111; }
        h1 { font-size: 14px; margin: 0; }
        .sub { font-size: 9px; color: #555; margin: 2px 0 0; }
        .meta { font-size: 8px; color: #777; margin: 1px 0 0; }
        header { border-bottom: 1px solid #999; padding-bottom: 6px; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 5px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; border-bottom: 1px solid #999; font-size: 8px; text-transform: uppercase; letter-spacing: .04em; }
        td.r, th.r { text-align: right; }
        tfoot td { border-top: 1px solid #999; border-bottom: none; font-weight: bold; background: #fafafa; }
        .note { margin-top: 8px; font-size: 8px; color: #555; }
        .empty { padding: 18px 0; text-align: center; color: #777; }
    </style>
</head>
<body>
    <header>
        <h1>{{ $report['title'] }}</h1>
        <p class="sub">{{ $report['subtitle'] }}</p>
        <p class="meta">
            {{ $company }} · printed {{ now()->format('M j, Y g:i A') }}@if ($printedBy) by {{ $printedBy }}@endif
            · {{ count($report['rows']) }} row(s)
        </p>
    </header>

    @if (count($report['rows']) === 0)
        <p class="empty">Nothing matched these filters.</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($report['columns'] as $column)
                        <th class="{{ ($column['align'] ?? null) === 'right' ? 'r' : '' }}">{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($report['rows'] as $row)
                    <tr>
                        @foreach ($report['columns'] as $column)
                            <td class="{{ ($column['align'] ?? null) === 'right' ? 'r' : '' }}">
                                {{ $row[$column['key']] ?? '—' }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            @if ($report['totals'])
                <tfoot>
                    <tr>
                        @foreach ($report['columns'] as $column)
                            <td class="{{ ($column['align'] ?? null) === 'right' ? 'r' : '' }}">
                                {{ $report['totals'][$column['key']] ?? '' }}
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    @endif

    @if ($report['note'])
        <p class="note">{{ $report['note'] }}</p>
    @endif
</body>
</html>
