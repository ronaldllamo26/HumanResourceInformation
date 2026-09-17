import { router } from '@inertiajs/react';
import { FileDown, FileText, Printer, Table2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Button,
    Card,
    DateInput,
    Field,
    Select,
    Table,
    TableEmpty,
    TBody,
    TD,
    TFoot,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { withFilters } from '@/lib/utils';

/**
 * One screen for every report. The preview, the PDF and the CSV are built from
 * the same server-side result, so what downloads is what was read here.
 */
export default function Index({ reports, report, filters, result, options }) {
    const definition = reports.find((entry) => entry.value === report) ?? reports[0];
    const takes = (filter) => definition.filters.includes(filter);

    const apply = (changes) =>
        router.get(
            withFilters('/hr/reports', { report, ...filters }, changes),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );

    const exportUrl = (format) =>
        withFilters(`/hr/reports/export/${format}`, { report, ...filters });

    const align = (column) => (column.align === 'right' ? 'text-right tabular-nums' : '');

    return (
        <AppLayout
            title="Reports"
            breadcrumbs={[{ label: 'AI & Analytics' }, { label: 'Reports' }]}
        >
            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:flex-wrap lg:items-end">
                    <Field label="Report" className="w-full lg:w-64">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={report}
                                onChange={(event) => apply({ report: event.target.value })}
                                options={reports}
                            />
                        )}
                    </Field>

                    {takes('dates') && (
                        <>
                            <Field label="From" className="w-full lg:w-40">
                                {({ id }) => (
                                    <DateInput
                                        id={id}
                                        value={filters.from ?? ''}
                                        onChange={(event) =>
                                            apply({ from: event.target.value })
                                        }
                                    />
                                )}
                            </Field>
                            <Field label="To" className="w-full lg:w-40">
                                {({ id }) => (
                                    <DateInput
                                        id={id}
                                        value={filters.to ?? ''}
                                        onChange={(event) => apply({ to: event.target.value })}
                                    />
                                )}
                            </Field>
                        </>
                    )}

                    {takes('period') && (
                        <Field label="Payroll period" className="w-full lg:w-56">
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={filters.period ?? ''}
                                    onChange={(event) => apply({ period: event.target.value })}
                                    placeholder="Latest started"
                                    options={options.periods}
                                />
                            )}
                        </Field>
                    )}

                    {takes('department') && (
                        <Field label="Department" className="w-full lg:w-48">
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={filters.department ?? ''}
                                    onChange={(event) =>
                                        apply({ department: event.target.value })
                                    }
                                    placeholder="All departments"
                                    options={options.departments}
                                />
                            )}
                        </Field>
                    )}

                    {takes('client') && (
                        <Field label="Client" className="w-full lg:w-48">
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={filters.client ?? ''}
                                    onChange={(event) => apply({ client: event.target.value })}
                                    placeholder="All clients"
                                    options={options.clients}
                                />
                            )}
                        </Field>
                    )}

                    {takes('category') && (
                        <Field label="Workforce" className="w-full lg:w-44">
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={filters.category ?? ''}
                                    onChange={(event) =>
                                        apply({ category: event.target.value })
                                    }
                                    placeholder="Internal + deployed"
                                    options={options.categories}
                                />
                            )}
                        </Field>
                    )}

                    {takes('status') && (
                        <Field label="Record status" className="w-full lg:w-40">
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={filters.status ?? ''}
                                    onChange={(event) => apply({ status: event.target.value })}
                                    placeholder="All"
                                    options={options.statuses}
                                />
                            )}
                        </Field>
                    )}

                    {takes('leave_status') && (
                        <Field label="Leave status" className="w-full lg:w-44">
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={filters.leave_status ?? ''}
                                    onChange={(event) =>
                                        apply({ leave_status: event.target.value })
                                    }
                                    placeholder="All"
                                    options={options.leaveStatuses}
                                />
                            )}
                        </Field>
                    )}

                    <div className="flex flex-wrap gap-2 lg:ml-auto">
                        <Button variant="outline" href={exportUrl('csv')} external>
                            <Table2 className="h-4 w-4" />
                            CSV
                        </Button>
                        <Button href={exportUrl('pdf')} external>
                            <FileDown className="h-4 w-4" />
                            PDF
                        </Button>
                        <Button
                            variant="ghost"
                            onClick={() => window.print()}
                            aria-label="Print"
                        >
                            <Printer className="h-4 w-4" />
                            <span className="hidden sm:inline">Print</span>
                        </Button>
                    </div>
                </div>

                <div className="border-b border-border px-4 py-3">
                    <h2 className="text-sm font-semibold text-foreground">{result.title}</h2>
                    <p className="text-xs text-muted-foreground">{result.subtitle}</p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {definition.description}
                    </p>
                </div>

                <Table>
                    <THead>
                        <TR>
                            {result.columns.map((column) => (
                                <TH key={column.key} className={align(column)}>
                                    {column.label}
                                </TH>
                            ))}
                        </TR>
                    </THead>
                    <TBody>
                        {result.rows.length === 0 ? (
                            <TableEmpty
                                colSpan={result.columns.length}
                                icon={FileText}
                                title="Nothing matched these filters"
                                description="Widen the dates, or clear a filter. The download would be empty too."
                            />
                        ) : (
                            result.rows.map((row, index) => (
                                <TR key={index}>
                                    {result.columns.map((column) => (
                                        <TD
                                            key={column.key}
                                            className={`text-sm ${align(column)}`}
                                        >
                                            {row[column.key] ?? '—'}
                                        </TD>
                                    ))}
                                </TR>
                            ))
                        )}
                    </TBody>
                    {result.totals && result.rows.length > 0 && (
                        <TFoot>
                            <TR>
                                {result.columns.map((column) => (
                                    <TD
                                        key={column.key}
                                        className={`text-sm font-semibold ${align(column)}`}
                                    >
                                        {result.totals[column.key] ?? ''}
                                    </TD>
                                ))}
                            </TR>
                        </TFoot>
                    )}
                </Table>

                <div className="space-y-1 border-t border-border px-4 py-3">
                    {result.truncated && (
                        <p className="text-xs text-warning">
                            Showing the first {result.rows.length} of {result.row_count} rows.
                            The PDF and CSV carry all {result.row_count}.
                        </p>
                    )}
                    {result.note && (
                        <p className="text-xs text-muted-foreground">{result.note}</p>
                    )}
                </div>
            </Card>
        </AppLayout>
    );
}
