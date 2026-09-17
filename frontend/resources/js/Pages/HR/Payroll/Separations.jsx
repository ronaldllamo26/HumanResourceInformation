import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarClock, DoorOpen, Plus, Wallet } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    DateInput,
    Field,
    Input,
    Modal,
    Pagination,
    Select,
    StatCard,
    Table,
    TableEmpty,
    TBody,
    TD,
    Textarea,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { formatCurrency, formatDate } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

const STATUS_VARIANT = {
    draft: 'warning',
    cleared: 'primary',
    released: 'success',
};

/** How urgently the 30-day release deadline reads. */
function Deadline({ days, releasedAt }) {
    if (releasedAt) {
        return <span className="text-xs text-muted-foreground">Released</span>;
    }

    if (days < 0) {
        return <Badge variant="destructive">{Math.abs(days)} day(s) overdue</Badge>;
    }

    return <Badge variant={days <= 7 ? 'warning' : 'muted'}>{days} day(s) left</Badge>;
}

export default function Separations({
    separations,
    filters,
    reasons,
    statuses,
    releaseWithinDays,
    employees,
    can,
}) {
    const [open, setOpen] = useState(false);

    const form = useForm({
        employee_id: '',
        last_day: '',
        reason: 'resigned',
        days_unpaid: '',
        other_deductions: '',
        remarks: '',
    });

    const submit = (event) => {
        event.preventDefault();

        form.post('/hr/payroll/separations', {
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    const filter = (key, value) =>
        router.get(
            '/hr/payroll/separations',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const rows = separations.data;
    const pending = rows.filter((row) => !row.released_at);
    const overdue = pending.filter((row) => row.days_to_deadline < 0);
    const payable = pending.reduce((total, row) => total + row.net_final_pay, 0);

    return (
        <AppLayout
            title="Separation & Final Pay"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Payroll', href: '/hr/payroll' },
                { label: 'Separation & Final Pay' },
            ]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Awaiting Release"
                    value={pending.length}
                    icon={DoorOpen}
                    tone={pending.length > 0 ? 'warning' : 'muted'}
                    hint={pending.length > 0 ? 'settlements still open' : 'nothing outstanding'}
                />

                {/* Past a statutory deadline, so destructive rather than
                    warning: DOLE Labor Advisory 06-20 gives 30 days from the
                    last day, and this is what turns the list into a queue. */}
                <StatCard
                    label="Past the Deadline"
                    value={overdue.length}
                    icon={CalendarClock}
                    tone={overdue.length > 0 ? 'destructive' : 'muted'}
                    hint={`${releaseWithinDays} days from the last day`}
                />

                <StatCard
                    label="Final Pay Payable"
                    value={formatCurrency(payable)}
                    icon={Wallet}
                    tone={payable > 0 ? 'info' : 'muted'}
                    hint="across open settlements"
                />
            </div>

            <Card>
                <CardHeader
                    title="Separations"
                    action={
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Select
                                value={filters.status ?? ''}
                                onChange={(event) => filter('status', event.target.value)}
                                aria-label="Filter by status"
                                className="w-full sm:w-36"
                                options={[
                                    { value: '', label: 'All statuses' },
                                    ...statuses.map((status) => ({
                                        value: status,
                                        label: titleCase(status),
                                    })),
                                ]}
                            />
                            <Select
                                value={filters.reason ?? ''}
                                onChange={(event) => filter('reason', event.target.value)}
                                aria-label="Filter by reason"
                                className="w-full sm:w-40"
                                options={[
                                    { value: '', label: 'All reasons' },
                                    ...reasons.map((reason) => ({
                                        value: reason,
                                        label: titleCase(reason),
                                    })),
                                ]}
                            />
                            {can.create && (
                                <Button onClick={() => setOpen(true)}>
                                    <Plus className="h-4 w-4" />
                                    Open Separation
                                </Button>
                            )}
                        </div>
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Last Day</TH>
                            <TH>Reason</TH>
                            <TH>Status</TH>
                            <TH className="text-right">Net Final Pay</TH>
                            <TH>Release Deadline</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={6}
                                icon={DoorOpen}
                                title="No separations recorded"
                                description="Open one when an employee resigns, retires, or reaches the end of a contract."
                            />
                        ) : (
                            rows.map((row) => (
                                <TR key={row.id}>
                                    <TD>
                                        <Link
                                            href={`/hr/payroll/separations/${row.id}`}
                                            className="font-medium text-foreground hover:text-primary"
                                        >
                                            {row.employee.full_name}
                                        </Link>
                                        <p className="text-xs text-muted-foreground">
                                            {row.employee.employee_number}
                                        </p>
                                    </TD>
                                    <TD>{formatDate(row.last_day)}</TD>
                                    <TD>{titleCase(row.reason)}</TD>
                                    <TD>
                                        <Badge
                                            variant={STATUS_VARIANT[row.status] ?? 'default'}
                                        >
                                            {titleCase(row.status)}
                                        </Badge>
                                    </TD>
                                    <TD className="text-right font-medium">
                                        {formatCurrency(row.net_final_pay)}
                                    </TD>
                                    <TD>
                                        <Deadline
                                            days={row.days_to_deadline}
                                            releasedAt={row.released_at}
                                        />
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>

                <Pagination links={separations.meta.links ?? []} meta={separations.meta} />
            </Card>

            <Modal
                show={open}
                onClose={() => setOpen(false)}
                title="Open a separation"
                description="The settlement is computed from payroll, leave, and loan records as they stand today. It stays a draft until clearance is complete."
                maxWidth="2xl"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={form.processing}>
                            Compute Final Pay
                        </Button>
                    </>
                }
            >
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="Employee" required error={form.errors.employee_id}>
                        <Select
                            value={form.data.employee_id}
                            onChange={(event) =>
                                form.setData('employee_id', event.target.value)
                            }
                            options={[{ value: '', label: 'Select an employee' }, ...employees]}
                        />
                    </Field>

                    <Field label="Reason" required error={form.errors.reason}>
                        <Select
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.target.value)}
                            options={reasons.map((reason) => ({
                                value: reason,
                                label: titleCase(reason),
                            }))}
                        />
                    </Field>

                    <Field label="Last day of employment" required error={form.errors.last_day}>
                        <DateInput
                            value={form.data.last_day}
                            onChange={(event) => form.setData('last_day', event.target.value)}
                        />
                    </Field>

                    <Field
                        label="Unpaid days worked"
                        error={form.errors.days_unpaid}
                        hint="Days worked after the last payroll run closed."
                    >
                        <Input
                            type="number"
                            step="0.5"
                            min="0"
                            value={form.data.days_unpaid}
                            onChange={(event) =>
                                form.setData('days_unpaid', event.target.value)
                            }
                        />
                    </Field>

                    <Field
                        label="Other deductions"
                        error={form.errors.other_deductions}
                        hint="Unreturned property, damages, or other agreed offsets."
                    >
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            value={form.data.other_deductions}
                            onChange={(event) =>
                                form.setData('other_deductions', event.target.value)
                            }
                        />
                    </Field>

                    <Field
                        label="Remarks"
                        error={form.errors.remarks}
                        className="sm:col-span-2"
                    >
                        <Textarea
                            rows={3}
                            value={form.data.remarks}
                            onChange={(event) => form.setData('remarks', event.target.value)}
                        />
                    </Field>
                </form>
            </Modal>
        </AppLayout>
    );
}
