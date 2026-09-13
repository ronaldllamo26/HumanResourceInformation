import { router } from '@inertiajs/react';
import { Download, FileSpreadsheet, TriangleAlert } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Button,
    Card,
    Field,
    Select,
    TBody,
    TD,
    TFoot,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
} from '@/Components/ui';
import { formatCurrency, formatDate } from '@/lib/utils';

/** Money columns are right-aligned; identifiers and names are not. */
const NUMERIC = new Set([
    'basis',
    'employee_share',
    'employer_share',
    'total',
    'gross',
    'contributions',
    'taxable',
    'tax',
]);

export default function Compliance({
    report,
    reportLabel,
    columns,
    rows,
    totals,
    missingIds,
    reports,
    runs,
    selectedRun,
    runSummary,
}) {
    const keys = Object.keys(columns);

    const applyFilter = (key, value) => {
        router.get(
            '/hr/payroll/compliance',
            { report, run: selectedRun, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AppLayout
            title="Compliance Reports"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Payroll', href: '/hr/payroll' },
                { label: 'Compliance' },
            ]}
            actions={
                rows.length > 0 && (
                    <Button
                        size="sm"
                        href={`/hr/payroll/compliance/export?report=${report}&run=${selectedRun}`}
                    >
                        <Download className="h-4 w-4" />
                        <span className="hidden sm:inline">Export CSV</span>
                    </Button>
                )
            }
        >
            <Card className="mb-5">
                <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-end">
                    <Field label="Report" className="w-full lg:w-72">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={report}
                                onChange={(event) => applyFilter('report', event.target.value)}
                                options={reports}
                            />
                        )}
                    </Field>

                    <Field label="Payroll Run" className="w-full lg:w-80">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={selectedRun ?? ''}
                                onChange={(event) => applyFilter('run', event.target.value)}
                                placeholder="No approved runs yet"
                                options={runs}
                            />
                        )}
                    </Field>

                    {runSummary && (
                        <p className="pb-2 text-xs text-muted-foreground lg:ml-auto">
                            {runSummary.employee_count} employee(s) ·{' '}
                            {formatDate(runSummary.start_date)} –{' '}
                            {formatDate(runSummary.end_date)}
                        </p>
                    )}
                </div>
            </Card>

            {missingIds.length > 0 && (
                <Card className="mb-5">
                    <div className="flex items-start gap-3 p-4">
                        <TriangleAlert className="mt-0.5 h-5 w-5 shrink-0 text-warning" />
                        <div>
                            <p className="text-sm font-medium text-foreground">
                                {missingIds.length} employee(s) have no number on file
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                They cannot be included in a filing until the ID is recorded on
                                their 201 file: {missingIds.join(', ')}
                            </p>
                        </div>
                    </div>
                </Card>
            )}

            <Card>
                <div className="border-b border-border p-4">
                    <p className="text-sm font-medium text-foreground">{reportLabel}</p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Read back from issued payslips, not recomputed — these are the figures
                        the employees were actually paid.
                    </p>
                </div>

                <Table>
                    <THead>
                        <TR>
                            {keys.map((key) => (
                                <TH
                                    key={key}
                                    className={NUMERIC.has(key) ? 'text-right' : undefined}
                                >
                                    {columns[key]}
                                </TH>
                            ))}
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={keys.length}
                                icon={FileSpreadsheet}
                                title="Nothing to report"
                                description="Approve a payroll run and its contributions will appear here for remittance."
                            />
                        ) : (
                            rows.map((row) => (
                                <TR key={row.employee_id}>
                                    {keys.map((key) => (
                                        <TD
                                            key={key}
                                            className={
                                                NUMERIC.has(key)
                                                    ? 'whitespace-nowrap text-right tabular-nums'
                                                    : undefined
                                            }
                                        >
                                            {NUMERIC.has(key) ? (
                                                formatCurrency(row[key])
                                            ) : key === 'identifier' && !row.has_id ? (
                                                <span className="text-warning">{row[key]}</span>
                                            ) : (
                                                row[key]
                                            )}
                                        </TD>
                                    ))}
                                </TR>
                            ))
                        )}
                    </TBody>

                    {rows.length > 0 && (
                        <TFoot>
                            <TR>
                                <TD colSpan={3} className="text-sm font-medium">
                                    Total
                                </TD>
                                {keys.slice(3).map((key) => (
                                    <TD
                                        key={key}
                                        className="whitespace-nowrap text-right text-sm font-semibold tabular-nums"
                                    >
                                        {totals[key] !== undefined
                                            ? formatCurrency(totals[key])
                                            : ''}
                                    </TD>
                                ))}
                            </TR>
                        </TFoot>
                    )}
                </Table>
            </Card>
        </AppLayout>
    );
}
