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
        @page { margin: 12mm 10mm 14mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #111; }
        
        /* Header table layout for Dompdf compatibility */
        .header-table { width: 100%; border-collapse: collapse; border-bottom: 2px solid #1E3A8A; padding-bottom: 8px; margin-bottom: 10px; }
        .header-table td { border: none; padding: 0; vertical-align: top; }
        
        .company-name { font-size: 11px; font-weight: bold; color: #1E3A8A; letter-spacing: 0.04em; text-transform: uppercase; }
        h1 { font-size: 14px; margin: 3px 0 2px 0; color: #111827; }
        .sub { font-size: 9px; color: #4B5563; margin: 0; }
        .meta { font-size: 8px; color: #6B7280; line-height: 1.4; }
        .confidential-badge { display: inline-block; padding: 2px 6px; background-color: #FEF2F2; color: #991B1B; border: 1px solid #FCA5A5; font-size: 7.5px; font-weight: bold; border-radius: 2px; text-transform: uppercase; margin-bottom: 4px; }
        
        table.data-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.data-table th, table.data-table td { padding: 4px 5px; border-bottom: 1px solid #E5E7EB; text-align: left; vertical-align: top; }
        table.data-table th { background: #1E3A8A; color: #ffffff; border-bottom: 1px solid #1E3A8A; font-size: 8px; text-transform: uppercase; letter-spacing: .04em; }
        table.data-table td.r, table.data-table th.r { text-align: right; }
        table.data-table tbody tr:nth-child(even) { background-color: #F9FAFB; }
        table.data-table tfoot td { border-top: 2px solid #1E3A8A; border-bottom: none; font-weight: bold; background: #F3F4F6; }
        
        .note { margin-top: 8px; font-size: 8px; color: #4B5563; font-style: italic; }
        .empty { padding: 18px 0; text-align: center; color: #777; }
        
        .footer-notice { margin-top: 14px; border-top: 1px solid #E5E7EB; padding-top: 6px; font-size: 7.5px; color: #9CA3AF; text-align: center; }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                @if (!empty($logo))
                    <img src="{{ $logo }}" style="height: 34px; margin-bottom: 4px;" alt="Logo"><br>
                @endif
                <span class="company-name">{{ $company }}</span>
                <h1>{{ $report['title'] }}</h1>
                <p class="sub">{{ $report['subtitle'] }}</p>
            </td>
            <td style="width: 40%; text-align: right;">
                <span class="confidential-badge">Official HR Document · Confidential</span>
                <div class="meta" style="margin-top: 4px;">
                    <strong>Generated:</strong> {{ now()->format('M j, Y g:i A') }}<br>
                    @if ($printedBy) <strong>Requested by:</strong> {{ $printedBy }}<br> @endif
                    <strong>Record Count:</strong> {{ count($report['rows']) }} row(s)
                </div>
            </td>
        </tr>
    </table>

    @if (count($report['rows']) === 0)
        <p class="empty">Nothing matched these filters.</p>
    @else
        <table class="data-table">
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

    <div class="footer-notice">
        {{ $company }} · Confidential Human Resource Records · Protected under the Data Privacy Act of 2012 (RA 10173)
    </div>
</body>
</html>
