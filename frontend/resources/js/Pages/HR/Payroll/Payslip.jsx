import { usePage } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import { LogoMark } from '@/Components/layout/PrimePowerLogo';
import AppLayout from '@/Layouts/AppLayout';
import { Badge, Button, Card } from '@/Components/ui';
import { formatCurrency, formatDate } from '@/lib/utils';

const duration = (minutes) => {
    if (!minutes) return '—';

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return hours > 0 ? `${hours}h ${rest}m` : `${rest}m`;
};

function Row({ label, amount, strong = false }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-1.5">
            <span
                className={
                    strong
                        ? 'text-sm font-medium text-foreground'
                        : 'text-sm text-muted-foreground'
                }
            >
                {label}
            </span>
            <span
                className={
                    strong
                        ? 'text-sm font-semibold tabular-nums text-foreground'
                        : 'text-sm tabular-nums text-foreground'
                }
            >
                {formatCurrency(amount)}
            </span>
        </div>
    );
}

function Detail({ label, value }) {
    return (
        <div>
            <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">
                {label}
            </dt>
            <dd className="mt-0.5 text-sm text-foreground">{value || '—'}</dd>
        </div>
    );
}

export default function PayslipPage({ payslip }) {
    const { employee, period, attendance } = payslip;
    const brand = usePage().props.brand ?? {};

    return (
        <AppLayout
            title={`Payslip ${payslip.payslip_number}`}
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Payroll', href: '/hr/payroll/payslips' },
                { label: payslip.payslip_number },
            ]}
        >
            {/* Chrome that should not end up on paper. */}
            <div className="mb-4 print:hidden">
                <Button href="/hr/payroll/payslips" variant="ghost" size="sm">
                    <ArrowLeft className="h-4 w-4" />
                    Back to payslips
                </Button>
            </div>

            {payslip.run_status !== 'paid' && payslip.run_status !== 'approved' && (
                <div className="mb-4 rounded-lg border border-warning/30 bg-warning/10 px-4 py-3 print:hidden">
                    <p className="text-sm text-foreground">
                        This payslip belongs to a run that is still{' '}
                        <span className="font-medium">{payslip.run_status}</span>. The figures
                        can still change.
                    </p>
                </div>
            )}

            <Card className="mx-auto max-w-3xl print:max-w-none print:border-0 print:shadow-none">
                <div className="p-6 print:p-0">
                    {/* Header */}
                    <div className="flex flex-wrap items-start justify-between gap-4 border-b border-border pb-5">
                        <div className="flex items-center gap-2.5">
                            <LogoMark className="h-10 w-10" />
                            <div>
                                <p className="text-sm font-bold tracking-tight text-logo-primary">
                                    {(brand.name ?? 'PrimePower').toUpperCase()}
                                </p>
                                <p className="text-xs text-muted-foreground">{brand.tagline}</p>
                            </div>
                        </div>

                        <div className="text-right">
                            <p className="text-sm font-semibold text-foreground">PAYSLIP</p>
                            <p className="text-xs text-muted-foreground">
                                {payslip.payslip_number}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Pay date {formatDate(period.pay_date)}
                            </p>
                        </div>
                    </div>

                    {/* Employee and period */}
                    <dl className="grid grid-cols-2 gap-4 border-b border-border py-5 sm:grid-cols-4">
                        <Detail label="Employee" value={employee.full_name} />
                        <Detail label="Employee No." value={employee.employee_number} />
                        <Detail label="Position" value={employee.position} />
                        <Detail label="Department" value={employee.department} />
                        <Detail
                            label="Period"
                            value={`${formatDate(period.start_date)} – ${formatDate(period.end_date)}`}
                        />
                        <Detail label="SSS" value={employee.sss_number} />
                        <Detail label="PhilHealth" value={employee.philhealth_number} />
                        <Detail label="TIN" value={employee.tin} />
                    </dl>

                    {/* Attendance summary */}
                    <div className="grid grid-cols-2 gap-4 border-b border-border py-5 sm:grid-cols-4">
                        <Detail label="Days Worked" value={attendance.days_worked} />
                        <Detail label="Overtime" value={`${attendance.overtime_hours} hrs`} />
                        <Detail label="Tardiness" value={duration(attendance.late_minutes)} />
                        <Detail
                            label="Absences"
                            value={`${attendance.absent_days + attendance.unpaid_leave_days} day(s)`}
                        />
                    </div>

                    {/* Earnings and deductions */}
                    <div className="grid gap-6 py-5 sm:grid-cols-2">
                        <div>
                            <h3 className="mb-2 border-b border-border pb-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Earnings
                            </h3>
                            {payslip.earnings.map((line) => (
                                <Row key={line.label} label={line.label} amount={line.amount} />
                            ))}
                            <div className="mt-2 border-t border-border pt-2">
                                <Row label="Gross Pay" amount={payslip.gross_pay} strong />
                            </div>
                        </div>

                        <div>
                            <h3 className="mb-2 border-b border-border pb-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Deductions
                            </h3>
                            {payslip.deductions.length === 0 ? (
                                <p className="py-1.5 text-sm text-muted-foreground">None</p>
                            ) : (
                                payslip.deductions.map((line) => (
                                    <Row
                                        key={line.label}
                                        label={line.label}
                                        amount={line.amount}
                                    />
                                ))
                            )}
                            <div className="mt-2 border-t border-border pt-2">
                                <Row
                                    label="Total Deductions"
                                    amount={payslip.deductions_total}
                                    strong
                                />
                            </div>
                        </div>
                    </div>

                    {/* Net pay */}
                    <div className="flex items-baseline justify-between gap-4 rounded-lg bg-primary/10 px-5 py-4">
                        <span className="text-sm font-semibold text-foreground">NET PAY</span>
                        <span className="text-xl font-bold tabular-nums text-primary">
                            {formatCurrency(payslip.net_pay)}
                        </span>
                    </div>

                    {employee.bank_account_number && (
                        <p className="mt-2 text-right text-xs text-muted-foreground">
                            Credited to {employee.bank_name} ····
                            {String(employee.bank_account_number).slice(-4)}
                        </p>
                    )}

                    {/* Employer share — informational, never deducted */}
                    <div className="mt-5 border-t border-border pt-4">
                        <p className="mb-2 text-[11px] uppercase tracking-wide text-muted-foreground">
                            Employer contributions (not deducted from pay)
                        </p>
                        <div className="flex flex-wrap gap-x-6 gap-y-1 text-xs text-muted-foreground">
                            <span>
                                SSS {formatCurrency(payslip.employer_contributions.sss)}
                            </span>
                            <span>
                                PhilHealth{' '}
                                {formatCurrency(payslip.employer_contributions.philhealth)}
                            </span>
                            <span>
                                Pag-IBIG{' '}
                                {formatCurrency(payslip.employer_contributions.pagibig)}
                            </span>
                            <span className="font-medium text-foreground">
                                Total {formatCurrency(payslip.employer_contributions.total)}
                            </span>
                        </div>
                    </div>

                    <p className="mt-5 text-center text-[11px] text-muted-foreground">
                        This is a computer-generated payslip and does not require a signature.
                    </p>
                </div>
            </Card>

            {/* Below the payslip, not in the topbar: printing is what you do
                once you have read the thing, so the button belongs at the end
                of it. `print:hidden` because the button must not appear on the
                page it prints. */}
            <div className="mt-4 flex flex-col items-center gap-3 print:hidden">
                <Button variant="outline" onClick={() => window.print()}>
                    <Printer className="h-4 w-4" />
                    Print / Save as PDF
                </Button>

                <Badge variant="muted">Run status: {payslip.run_status}</Badge>
            </div>
        </AppLayout>
    );
}
