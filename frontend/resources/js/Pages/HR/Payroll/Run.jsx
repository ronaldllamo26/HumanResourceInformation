import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    ArrowLeft,
    BadgeCheck,
    Banknote,
    Building2,
    Handshake,
    Play,
    Receipt,
    Send,
    TriangleAlert,
    Wallet,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    Field,
    Modal,
    Pagination,
    MeterCard,
    StatCard,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
    Textarea,
} from '@/Components/ui';
import { formatCurrency, formatDate, initials } from '@/lib/utils';
import AnomalyPanel from './Partials/AnomalyPanel';
import ReadinessPanel from './Partials/ReadinessPanel';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

export default function Run({ run, payslips, readiness, anomalies, breakdown, filters, can }) {
    const [action, setAction] = useState(null); // 'approve' | 'cancel'

    const form = useForm({ remarks: '' });

    const submit = (event) => {
        event.preventDefault();

        form.post(`/hr/payroll/runs/${run.id}/${action}`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setAction(null);
            },
        });
    };

    const rows = payslips.data ?? [];

    // What proportion of the gross is being withheld. Contributions and tax
    // are the bulk of it, and a run whose deductions look wrong looks wrong
    // here first — before anybody opens a payslip.
    const deductionShare =
        run.total_gross > 0 ? (run.total_deductions / run.total_gross) * 100 : 0;

    return (
        <AppLayout
            title={`Payroll Run ${run.run_number}`}
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Payroll', href: '/hr/payroll' },
                { label: run.run_number },
            ]}
            actions={
                <div className="flex items-center gap-1.5">
                    {can.recompute && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/hr/payroll/periods/${run.period_id ?? ''}/generate`,
                                )
                            }
                            disabled={!run.period_id}
                        >
                            <Play className="h-4 w-4" />
                            <span className="hidden sm:inline">Recompute</span>
                        </Button>
                    )}

                    {can.submit && (
                        <Button
                            size="sm"
                            onClick={() =>
                                router.post(
                                    `/hr/payroll/runs/${run.id}/submit`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Send className="h-4 w-4" />
                            <span className="hidden sm:inline">Submit for Approval</span>
                        </Button>
                    )}

                    {can.approve && (
                        <Button size="sm" onClick={() => setAction('approve')}>
                            <BadgeCheck className="h-4 w-4" />
                            <span className="hidden sm:inline">Approve</span>
                        </Button>
                    )}

                    {can.markPaid && (
                        <Button
                            size="sm"
                            onClick={() =>
                                router.post(
                                    `/hr/payroll/runs/${run.id}/paid`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Banknote className="h-4 w-4" />
                            <span className="hidden sm:inline">Mark Paid</span>
                        </Button>
                    )}

                    {can.cancel && (
                        <Button
                            size="sm"
                            variant="destructive"
                            onClick={() => setAction('cancel')}
                        >
                            Cancel
                        </Button>
                    )}
                </div>
            }
        >
            <div className="mb-4">
                <Button href="/hr/payroll" variant="ghost" size="sm">
                    <ArrowLeft className="h-4 w-4" />
                    Back to payroll periods
                </Button>
            </div>

            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Employees"
                    value={run.employee_count}
                    icon={Receipt}
                    tone={run.employee_count > 0 ? 'primary' : 'muted'}
                    hint={run.period.name}
                />

                <StatCard
                    label="Gross Pay"
                    value={formatCurrency(run.total_gross)}
                    icon={Wallet}
                    tone="info"
                    hint="before contributions and tax"
                />

                {/* As a share of gross. The figure on its own says nothing —
                    ₱400,000 withheld is unremarkable against ₱2m and alarming
                    against ₱600k, and this is the screen where that is caught. */}
                <MeterCard
                    label="Deductions"
                    value={formatCurrency(run.total_deductions)}
                    percent={deductionShare}
                    badge={`${Math.round(deductionShare)}%`}
                    icon={TriangleAlert}
                    tone={deductionShare > 40 ? 'warning' : 'info'}
                    iconTone="warning"
                    hint="of gross — SSS, PhilHealth, Pag-IBIG, tax, loans"
                />

                <StatCard
                    label="Net Pay"
                    value={formatCurrency(run.total_net)}
                    icon={Banknote}
                    tone="success"
                    hint="what lands in the bank"
                />
            </div>

            <ReadinessPanel readiness={readiness} />
            <AnomalyPanel anomalies={anomalies} />

            <Card className="mb-5">
                <div className="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p className="text-xs text-muted-foreground">Status</p>
                        <div className="mt-1">
                            <Badge status={run.status}>{titleCase(run.status)}</Badge>
                        </div>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">Cut-off</p>
                        <p className="mt-1 text-sm text-foreground">
                            {formatDate(run.period.start_date)} –{' '}
                            {formatDate(run.period.end_date)}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">Processed by</p>
                        <p className="mt-1 text-sm text-foreground">
                            {run.processed_by ?? '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">Approved by</p>
                        <p className="mt-1 text-sm text-foreground">{run.approved_by ?? '—'}</p>
                    </div>

                    {run.remarks && (
                        <div className="sm:col-span-2 lg:col-span-4">
                            <p className="text-xs text-muted-foreground">Remarks</p>
                            <p className="mt-1 text-sm text-foreground">{run.remarks}</p>
                        </div>
                    )}
                </div>
            </Card>

            {/* What the run costs per client — the figure the agency bills
                out. Clients first, the agency's own overhead last. */}
            {breakdown.length > 1 && (
                <Card className="mb-5">
                    <CardHeader
                        title="Cost by Client"
                        description="One statutory run, split by who it is billed to."
                    />
                    <Table>
                        <THead>
                            <TR>
                                <TH>Billed To</TH>
                                <TH className="text-right">Staff</TH>
                                <TH className="text-right">Gross</TH>
                                <TH className="text-right">Deductions</TH>
                                <TH className="text-right">Net Pay</TH>
                                <TH className="text-right" />
                            </TR>
                        </THead>

                        <TBody>
                            {breakdown.map((row) => (
                                <TR key={row.client_id ?? 'internal'}>
                                    <TD>
                                        <span className="flex items-center gap-2">
                                            {row.client_id ? (
                                                <Handshake
                                                    className="h-4 w-4 shrink-0 text-primary"
                                                    aria-hidden="true"
                                                />
                                            ) : (
                                                <Building2
                                                    className="h-4 w-4 shrink-0 text-muted-foreground"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            <span className="font-medium text-foreground">
                                                {row.label}
                                            </span>
                                            {row.code && (
                                                <span className="font-mono text-xs text-muted-foreground">
                                                    {row.code}
                                                </span>
                                            )}
                                        </span>
                                    </TD>
                                    <TD className="text-right tabular-nums">
                                        {row.employee_count}
                                    </TD>
                                    <TD className="text-right tabular-nums text-muted-foreground">
                                        {formatCurrency(row.gross)}
                                    </TD>
                                    <TD className="text-right tabular-nums text-muted-foreground">
                                        {formatCurrency(row.deductions)}
                                    </TD>
                                    <TD className="text-right font-medium tabular-nums text-foreground">
                                        {formatCurrency(row.net)}
                                    </TD>
                                    <TD className="text-right">
                                        <Link
                                            href={`/hr/payroll/runs/${run.id}?${
                                                row.client_id
                                                    ? `client_id=${row.client_id}`
                                                    : 'employment_category=internal'
                                            }`}
                                            className="text-xs font-medium text-primary hover:underline"
                                        >
                                            View payslips
                                        </Link>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </Card>
            )}

            <Card>
                {(filters.client_id || filters.employment_category) && (
                    <div className="flex items-center justify-between gap-3 border-b border-border px-5 py-3">
                        <p className="text-sm text-muted-foreground">
                            Showing{' '}
                            <span className="font-medium text-foreground">
                                {breakdown.find(
                                    (row) =>
                                        String(row.client_id ?? '') ===
                                        String(filters.client_id ?? ''),
                                )?.label ?? 'a filtered set'}
                            </span>{' '}
                            only.
                        </p>
                        <Link
                            href={`/hr/payroll/runs/${run.id}`}
                            className="text-xs font-medium text-primary hover:underline"
                        >
                            Show everyone
                        </Link>
                    </div>
                )}

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH className="text-right">Days</TH>
                            <TH className="text-right">OT Hrs</TH>
                            <TH className="text-right">Gross</TH>
                            <TH className="text-right">Deductions</TH>
                            <TH className="text-right">Net Pay</TH>
                            <TH className="text-right">Payslip</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={7}
                                icon={Receipt}
                                title="No payslips in this run"
                                description="Recompute the run to generate payslips."
                            />
                        ) : (
                            rows.map((payslip) => (
                                <TR key={payslip.id}>
                                    <TD>
                                        <div className="flex items-center gap-2.5">
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                {initials(payslip.employee.full_name)}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {payslip.employee.full_name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {payslip.employee.employee_number}
                                                </p>
                                            </div>
                                        </div>
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {payslip.days_worked}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {payslip.overtime_hours || '—'}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-foreground">
                                        {formatCurrency(payslip.gross_pay)}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {formatCurrency(payslip.deductions_total)}
                                    </TD>

                                    <TD className="text-right text-sm font-medium tabular-nums text-foreground">
                                        {formatCurrency(payslip.net_pay)}
                                    </TD>

                                    <TD>
                                        <div className="flex justify-end">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                href={`/hr/payroll/payslips/${payslip.id}`}
                                            >
                                                View
                                            </Button>
                                        </div>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>

                <Pagination links={payslips.meta.links ?? []} meta={payslips.meta} />
            </Card>

            <Modal
                show={Boolean(action)}
                onClose={() => setAction(null)}
                title={action === 'approve' ? 'Approve this payroll run?' : 'Cancel this run?'}
                maxWidth="md"
            >
                <form onSubmit={submit} className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        {action === 'approve' ? (
                            <>
                                {run.run_number} totals{' '}
                                <span className="font-medium text-foreground">
                                    {formatCurrency(run.total_net)}
                                </span>{' '}
                                across {run.employee_count} employee(s). Approving locks the
                                figures and applies loan amortisations — it cannot be recomputed
                                afterwards.
                            </>
                        ) : (
                            <>
                                {run.run_number} will be cancelled. Its payslips stay on record
                                for audit, but nothing is paid out.
                            </>
                        )}
                    </p>

                    <Field label="Remarks" error={form.errors.remarks}>
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={2}
                                value={form.data.remarks}
                                onChange={(event) =>
                                    form.setData('remarks', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={() => setAction(null)}>
                            Back
                        </Button>
                        <Button
                            type="submit"
                            variant={action === 'cancel' ? 'destructive' : 'primary'}
                            loading={form.processing}
                        >
                            {action === 'approve' ? 'Approve Run' : 'Cancel Run'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
