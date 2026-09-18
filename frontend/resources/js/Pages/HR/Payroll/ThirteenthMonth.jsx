import { router } from '@inertiajs/react';
import { CalendarClock, Download, Users, Wallet } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Button,
    Card,
    CardHeader,
    Select,
    StatCard,
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

export default function ThirteenthMonth({ rows, totals, filters, years, deadline }) {
    const changeYear = (year) =>
        router.get(
            '/hr/payroll/13th-month',
            { year },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const overdue = deadline.days_remaining < 0;

    return (
        <AppLayout
            title="13th-Month Pay"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Payroll', href: '/hr/payroll' },
                { label: '13th-Month Pay' },
            ]}
            actions={
                rows.length > 0 && (
                    <Button
                        size="sm"
                        href={`/hr/payroll/13th-month/export?year=${filters.year}`}
                    >
                        <Download className="h-4 w-4" />
                        <span className="hidden sm:inline">Export CSV</span>
                    </Button>
                )
            }
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Employees"
                    value={totals.employees}
                    icon={Users}
                    tone={totals.employees > 0 ? 'primary' : 'muted'}
                />

                <StatCard
                    label="Basic Salary Earned"
                    value={formatCurrency(totals.basic_earned)}
                    icon={Wallet}
                    tone={totals.basic_earned > 0 ? 'info' : 'muted'}
                />

                {/* The figure that has to be paid by 24 December. Primary, not
                    a warning — it is an obligation, not a fault. */}
                <StatCard
                    label="Total 13th-Month Pay"
                    value={formatCurrency(totals.amount)}
                    icon={Wallet}
                    tone={totals.amount > 0 ? 'primary' : 'muted'}
                />
            </div>

            {/* PD 851 sets the deadline; a report that doesn't say so is just a table. */}
            <Card className="mb-5">
                <div className="flex items-start gap-3 p-4">
                    <CalendarClock
                        className={`mt-0.5 h-5 w-5 shrink-0 ${
                            overdue ? 'text-destructive' : 'text-muted-foreground'
                        }`}
                    />
                    <div>
                        <p className="text-sm font-medium text-foreground">
                            Payable on or before {formatDate(deadline.date)}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {overdue
                                ? `The Presidential Decree 851 deadline passed ${Math.abs(deadline.days_remaining)} day(s) ago.`
                                : `${deadline.days_remaining} day(s) left under Presidential Decree 851.`}
                        </p>
                    </div>
                </div>
            </Card>

            <Card>
                <CardHeader
                    title={`${filters.year} 13th-Month Pay`}
                    action={
                        <Select
                            value={String(filters.year)}
                            onChange={(event) => changeYear(event.target.value)}
                            aria-label="Filter by year"
                            className="w-full sm:w-32"
                            options={years.map((year) => ({
                                value: String(year),
                                label: String(year),
                            }))}
                        />
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Department</TH>
                            <TH className="text-right">Periods Paid</TH>
                            <TH className="text-right">Basic Earned</TH>
                            <TH className="text-right">13th-Month Pay</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={5}
                                icon={Wallet}
                                title={`No finalised payroll for ${filters.year}`}
                                description="13th-month pay is computed from approved and paid runs only — a draft is still being corrected."
                            />
                        ) : (
                            rows.map((row) => (
                                <TR key={row.employee_id}>
                                    <TD>
                                        <p className="text-sm font-medium text-foreground">
                                            {row.employee_name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {row.employee_number}
                                        </p>
                                    </TD>
                                    <TD className="text-sm text-muted-foreground">
                                        {row.department ?? '—'}
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {row.periods_paid}
                                    </TD>
                                    <TD className="whitespace-nowrap text-right text-sm tabular-nums text-muted-foreground">
                                        {formatCurrency(row.basic_earned)}
                                    </TD>
                                    <TD className="whitespace-nowrap text-right text-sm font-medium tabular-nums text-foreground">
                                        {formatCurrency(row.amount)}
                                    </TD>
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
                                <TD className="whitespace-nowrap text-right text-sm font-semibold tabular-nums">
                                    {formatCurrency(totals.basic_earned)}
                                </TD>
                                <TD className="whitespace-nowrap text-right text-sm font-semibold tabular-nums">
                                    {formatCurrency(totals.amount)}
                                </TD>
                            </TR>
                        </TFoot>
                    )}
                </Table>
            </Card>
        </AppLayout>
    );
}
