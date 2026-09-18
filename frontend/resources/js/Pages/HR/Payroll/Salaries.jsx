import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    ArrowDown,
    ArrowUp,
    Banknote,
    CalendarClock,
    Plus,
    Trash2,
    TriangleAlert,
    Wallet,
} from 'lucide-react';
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

/** A raise, a cut, or neither — the arrow carries the sign, not colour alone. */
function Movement({ amount }) {
    if (!amount) {
        return <span className="text-xs text-muted-foreground">No change</span>;
    }

    const up = amount > 0;
    const Icon = up ? ArrowUp : ArrowDown;

    return (
        <span
            className={`inline-flex items-center gap-1 text-xs font-medium tabular-nums ${
                up ? 'text-success' : 'text-destructive'
            }`}
        >
            <Icon className="h-3 w-3" aria-hidden="true" />
            {formatCurrency(Math.abs(amount))}
        </span>
    );
}

export default function Salaries({ adjustments, filters, reasons, summary, employees, can }) {
    const [open, setOpen] = useState(false);
    const [removing, setRemoving] = useState(null);

    const form = useForm({
        employee_id: '',
        new_salary: '',
        effective_date: new Date().toISOString().slice(0, 10),
        reason: 'merit',
        remarks: '',
    });

    const selected = useMemo(
        () =>
            employees.find(
                (employee) => String(employee.value) === String(form.data.employee_id),
            ),
        [employees, form.data.employee_id],
    );

    // Shown while typing, never enforced. HR pays outside a band deliberately
    // often enough that refusing the entry would be the wrong call.
    const bandWarning = useMemo(() => {
        const salary = Number(form.data.new_salary);

        if (!selected || !salary) return null;

        if (selected.min_salary !== null && salary < selected.min_salary) {
            return `Below the ${selected.position} band (${formatCurrency(selected.min_salary)} minimum).`;
        }

        if (selected.max_salary !== null && salary > selected.max_salary) {
            return `Above the ${selected.position} band (${formatCurrency(selected.max_salary)} maximum).`;
        }

        return null;
    }, [selected, form.data.new_salary]);

    const currentHint = useMemo(() => {
        if (!selected) return undefined;

        const band =
            selected.min_salary !== null && selected.max_salary !== null
                ? ` · band ${formatCurrency(selected.min_salary)}–${formatCurrency(selected.max_salary)}`
                : '';

        return `Currently on ${formatCurrency(selected.current_salary)}${band}`;
    }, [selected]);

    const submit = (event) => {
        event.preventDefault();

        form.post('/hr/payroll/salaries', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    const remove = () =>
        router.delete(`/hr/payroll/salaries/${removing.id}`, {
            preserveScroll: true,
            onSuccess: () => setRemoving(null),
        });

    const filter = (key, value) =>
        router.get(
            '/hr/payroll/salaries',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const rows = adjustments.data;

    return (
        <AppLayout
            title="Salaries & Adjustments"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Payroll', href: '/hr/payroll' },
                { label: 'Salaries & Adjustments' },
            ]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Monthly Payroll"
                    value={formatCurrency(summary.payroll)}
                    icon={Wallet}
                    tone={summary.payroll > 0 ? 'primary' : 'muted'}
                />

                <StatCard
                    label="Adjustments This Year"
                    value={summary.this_year}
                    icon={Banknote}
                    tone={summary.this_year > 0 ? 'info' : 'muted'}
                />

                {/* Not a warning — a future-dated raise is a decision already
                    taken, waiting for its date. `salaries:apply-due` moves the
                    cached rate when it arrives; money never waits on that. */}
                <StatCard
                    label="Scheduled Ahead"
                    value={summary.scheduled}
                    icon={CalendarClock}
                    tone={summary.scheduled > 0 ? 'info' : 'muted'}
                />
            </div>

            <Card>
                <CardHeader
                    title="Salary history"
                    action={
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Select
                                value={filters.employee_id ?? ''}
                                onChange={(event) => filter('employee_id', event.target.value)}
                                aria-label="Filter by employee"
                                className="w-full sm:w-48"
                                options={[{ value: '', label: 'All employees' }, ...employees]}
                            />
                            <Select
                                value={filters.reason ?? ''}
                                onChange={(event) => filter('reason', event.target.value)}
                                aria-label="Filter by reason"
                                className="w-full sm:w-40"
                                options={[{ value: '', label: 'All reasons' }, ...reasons]}
                            />
                            {can.create && (
                                <Button onClick={() => setOpen(true)}>
                                    <Plus className="h-4 w-4" />
                                    Set Salary
                                </Button>
                            )}
                        </div>
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Effective</TH>
                            <TH>Reason</TH>
                            <TH className="text-right">From</TH>
                            <TH className="text-right">To</TH>
                            <TH className="text-right">Change</TH>
                            <TH />
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={7}
                                icon={Banknote}
                                title="No salary adjustments recorded"
                                description="Set a rate when someone is hired, regularised, promoted, or given an increase."
                            />
                        ) : (
                            rows.map((row) => (
                                <TR key={row.id}>
                                    <TD>
                                        <p className="font-medium text-foreground">
                                            {row.employee.full_name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {row.employee.position ?? 'No position'}
                                        </p>
                                    </TD>
                                    <TD>
                                        <span className="text-sm">
                                            {formatDate(row.effective_date)}
                                        </span>
                                        {row.is_scheduled && (
                                            <Badge variant="warning" className="ml-2">
                                                Scheduled
                                            </Badge>
                                        )}
                                    </TD>
                                    <TD>
                                        <span className="text-sm">{row.reason_label}</span>
                                        {row.outside_band && (
                                            <Badge variant="muted" className="ml-2">
                                                <TriangleAlert className="h-3 w-3" />
                                                {row.outside_band} band
                                            </Badge>
                                        )}
                                    </TD>
                                    <TD className="text-right tabular-nums text-muted-foreground">
                                        {formatCurrency(row.previous_salary)}
                                    </TD>
                                    <TD className="text-right font-medium tabular-nums">
                                        {formatCurrency(row.new_salary)}
                                    </TD>
                                    <TD className="text-right">
                                        <Movement amount={row.difference} />
                                    </TD>
                                    <TD className="text-right">
                                        {row.can_delete && (
                                            <button
                                                type="button"
                                                onClick={() => setRemoving(row)}
                                                className="rounded p-1 text-muted-foreground transition-colors hover:text-destructive"
                                                aria-label="Remove adjustment"
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </button>
                                        )}
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>

                <Pagination links={adjustments.meta.links ?? []} meta={adjustments.meta} />
            </Card>

            <Modal
                show={open}
                onClose={() => setOpen(false)}
                title="Set a salary"
                description="Recorded as a dated change, so the rate before it is kept rather than overwritten."
                maxWidth="2xl"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={form.processing}>
                            Record Adjustment
                        </Button>
                    </>
                }
            >
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Employee"
                        required
                        error={form.errors.employee_id}
                        className="sm:col-span-2"
                        hint={currentHint}
                    >
                        <Select
                            value={form.data.employee_id}
                            onChange={(event) =>
                                form.setData('employee_id', event.target.value)
                            }
                            options={[{ value: '', label: 'Select an employee' }, ...employees]}
                        />
                    </Field>

                    <Field
                        label="New monthly salary"
                        required
                        error={form.errors.new_salary}
                        hint={bandWarning ?? undefined}
                    >
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            value={form.data.new_salary}
                            onChange={(event) => form.setData('new_salary', event.target.value)}
                        />
                    </Field>

                    <Field
                        label="Effective date"
                        required
                        error={form.errors.effective_date}
                        hint="A future date takes effect on its own."
                    >
                        <DateInput
                            value={form.data.effective_date}
                            onChange={(event) =>
                                form.setData('effective_date', event.target.value)
                            }
                        />
                    </Field>

                    <Field label="Reason" required error={form.errors.reason}>
                        <Select
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.target.value)}
                            options={reasons}
                        />
                    </Field>

                    <Field label="Remarks" error={form.errors.remarks}>
                        <Textarea
                            rows={2}
                            value={form.data.remarks}
                            onChange={(event) => form.setData('remarks', event.target.value)}
                        />
                    </Field>
                </form>
            </Modal>

            <Modal
                show={removing !== null}
                onClose={() => setRemoving(null)}
                title="Remove this adjustment?"
                description="The employee's current rate goes back to the one in force before it. Payslips already issued keep the figures they were computed on."
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setRemoving(null)}>
                            Cancel
                        </Button>
                        <Button onClick={remove}>Remove</Button>
                    </>
                }
            />
        </AppLayout>
    );
}
