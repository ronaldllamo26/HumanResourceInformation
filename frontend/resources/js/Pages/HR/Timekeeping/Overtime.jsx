import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle2, Clock3, Hourglass, Plus, Timer, XCircle } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    DateInput,
    Field,
    Input,
    InputError,
    Modal,
    Pagination,
    Select,
    MeterCard,
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
import { formatDate, initials, withFilters } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

export default function Overtime({
    requests,
    summary,
    filters,
    statuses,
    employees,
    can,
    ownEmployeeId,
}) {
    const [fileOpen, setFileOpen] = useState(false);
    const [decision, setDecision] = useState(null); // { request, status }

    /*
     * Always the signed-in user's own record. The form used to offer HR a
     * picker for whose overtime to file; it does not, because HR also decides
     * on these — see OvertimeRequestPolicy::create. The id still rides in the
     * payload and is still checked server-side, since the missing picker is
     * not the rule.
     */
    const form = useForm({
        employee_id: ownEmployeeId ?? '',
        date: '',
        start_time: '',
        end_time: '',
        reason: '',
    });

    const decisionForm = useForm({ status: '', remarks: '' });

    const applyFilter = (key, value) => {
        router.get(
            '/hr/timekeeping/overtime',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const submit = (event) => {
        event.preventDefault();

        form.post('/hr/timekeeping/overtime', {
            preserveScroll: true,
            onSuccess: () => {
                // reset() restores the initial data, which already holds the
                // filer's own id — nothing further to put back.
                form.reset();
                setFileOpen(false);
            },
        });
    };

    const submitDecision = (event) => {
        event.preventDefault();

        // Set, then posted. `useForm`'s transform() returns undefined, so
        // chaining throws on the post and approving an overtime request
        // silently does nothing.
        decisionForm.transform((data) => ({ ...data, status: decision.status }));

        decisionForm.post(`/hr/timekeeping/overtime/${decision.request.id}/decide`, {
            preserveScroll: true,
            onSuccess: () => {
                decisionForm.reset();
                setDecision(null);
            },
        });
    };

    const rows = requests.data ?? [];
    const meta = requests.meta ?? {};

    // Clicking a figure opens the rows it counted, keeping the employee filter.
    const drillTo = (changes) =>
        withFilters('/hr/timekeeping/overtime', filters, changes, ['status']);

    const approvalRate = summary.total > 0 ? (summary.approved / summary.total) * 100 : 0;

    return (
        <AppLayout
            title="Timekeeping & Attendance"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Timekeeping', href: '/hr/timekeeping' },
                { label: 'Overtime' },
            ]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Total Requests"
                    value={summary.total}
                    icon={Clock3}
                    tone="primary"
                    hint="filed so far"
                    href={drillTo({})}
                />

                {/* The queue. Attendance records overtime raw; nothing here is
                    paid until one of these is decided, so a number sitting in
                    this tile is money nobody has ruled on. */}
                <StatCard
                    label="Pending"
                    value={summary.pending}
                    icon={Hourglass}
                    tone={summary.pending > 0 ? 'warning' : 'muted'}
                    hint={summary.pending > 0 ? 'unpaid until decided' : 'nothing waiting'}
                    href={drillTo({ status: 'pending' })}
                />

                <MeterCard
                    label="Approved"
                    value={summary.approved}
                    percent={approvalRate}
                    badge={`${Math.round(approvalRate)}%`}
                    icon={CheckCircle2}
                    tone="success"
                    iconTone="success"
                    hint={`of ${summary.total} filed`}
                    href={drillTo({ status: 'approved' })}
                />

                <StatCard
                    label="Approved Hours"
                    value={summary.approved_hours}
                    icon={Timer}
                    tone={summary.approved_hours > 0 ? 'info' : 'muted'}
                    hint="what payroll will pay"
                    href={drillTo({ status: 'approved' })}
                />
            </div>

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 sm:flex-row">
                    <Select
                        value={filters.status ?? ''}
                        onChange={(event) => applyFilter('status', event.target.value)}
                        placeholder="All statuses"
                        className="w-full sm:w-48"
                        aria-label="Filter by status"
                        options={statuses.map((status) => ({
                            value: status,
                            label: titleCase(status),
                        }))}
                    />

                    <Select
                        value={filters.employee_id ?? ''}
                        onChange={(event) => applyFilter('employee_id', event.target.value)}
                        placeholder="All employees"
                        className="w-full sm:w-56"
                        aria-label="Filter by employee"
                        options={employees.map((employee) => ({
                            value: employee.id,
                            label: employee.full_name,
                        }))}
                    />

                    {can.create && (
                        <Button className="sm:ml-auto" onClick={() => setFileOpen(true)}>
                            <Plus className="h-4 w-4" />
                            File Overtime
                        </Button>
                    )}
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Date</TH>
                            <TH>Window</TH>
                            <TH className="text-right">Hours</TH>
                            <TH>Reason</TH>
                            <TH>Status</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={7}
                                icon={Clock3}
                                title="No overtime requests"
                                description="Filed overtime appears here for supervisor and HR approval."
                            />
                        ) : (
                            rows.map((request) => (
                                <TR key={request.id}>
                                    <TD>
                                        <div className="flex items-center gap-2.5">
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                {initials(request.employee?.full_name)}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {request.employee?.full_name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {request.employee?.employee_number}
                                                </p>
                                            </div>
                                        </div>
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {formatDate(request.date)}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm tabular-nums text-foreground">
                                        {request.start_time} – {request.end_time}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-foreground">
                                        {request.hours.toFixed(2)}
                                    </TD>

                                    <TD className="max-w-xs">
                                        <p
                                            className="truncate text-sm text-muted-foreground"
                                            title={request.reason}
                                        >
                                            {request.reason}
                                        </p>
                                        {request.remarks && (
                                            <p className="truncate text-xs text-muted-foreground/70">
                                                {request.approver}: {request.remarks}
                                            </p>
                                        )}
                                    </TD>

                                    <TD>
                                        <Badge status={request.status} />
                                    </TD>

                                    <TD>
                                        <div className="flex items-center justify-end gap-1">
                                            {request.can.decide && (
                                                <>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setDecision({
                                                                request,
                                                                status: 'approved',
                                                            })
                                                        }
                                                    >
                                                        <CheckCircle2 className="h-4 w-4 text-success" />
                                                        Approve
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setDecision({
                                                                request,
                                                                status: 'rejected',
                                                            })
                                                        }
                                                    >
                                                        <XCircle className="h-4 w-4 text-destructive" />
                                                        Reject
                                                    </Button>
                                                </>
                                            )}

                                            {!request.can.decide && request.can.cancel && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        router.post(
                                                            `/hr/timekeeping/overtime/${request.id}/cancel`,
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    Cancel
                                                </Button>
                                            )}

                                            {!request.can.decide && !request.can.cancel && (
                                                <span className="text-xs text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </div>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>

                <Pagination links={meta.links ?? []} meta={meta} />
            </Card>

            {/* File overtime */}
            <Modal
                show={fileOpen}
                onClose={() => setFileOpen(false)}
                title="File Overtime"
                description="Your own overtime. An end time at or before the start is treated as running past midnight."
                maxWidth="lg"
            >
                <form onSubmit={submit} className="space-y-4">
                    {/* The error still has somewhere to land: the id is posted
                        and checked even though no control sets it. */}
                    <InputError message={form.errors.employee_id} />

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Date" required error={form.errors.date}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={form.data.date}
                                    onChange={(event) =>
                                        form.setData('date', event.target.value)
                                    }
                                    error={form.errors.date}
                                />
                            )}
                        </Field>

                        <Field label="Start" required error={form.errors.start_time}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="time"
                                    value={form.data.start_time}
                                    onChange={(event) =>
                                        form.setData('start_time', event.target.value)
                                    }
                                    error={form.errors.start_time}
                                />
                            )}
                        </Field>

                        <Field label="End" required error={form.errors.end_time}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="time"
                                    value={form.data.end_time}
                                    onChange={(event) =>
                                        form.setData('end_time', event.target.value)
                                    }
                                    error={form.errors.end_time}
                                />
                            )}
                        </Field>
                    </div>

                    <Field label="Reason" required error={form.errors.reason}>
                        {({ id }) => (
                            <Textarea
                                id={id}
                                value={form.data.reason}
                                onChange={(event) => form.setData('reason', event.target.value)}
                                error={form.errors.reason}
                                placeholder="What work requires the extra hours?"
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setFileOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            Submit Request
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Approve / reject */}
            <Modal
                show={Boolean(decision)}
                onClose={() => setDecision(null)}
                title={
                    decision?.status === 'approved' ? 'Approve overtime?' : 'Reject overtime?'
                }
                maxWidth="md"
            >
                <form onSubmit={submitDecision} className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        <span className="font-medium text-foreground">
                            {decision?.request.employee?.full_name}
                        </span>{' '}
                        — {decision?.request.hours.toFixed(2)} hours on{' '}
                        {formatDate(decision?.request.date)}.
                    </p>

                    <Field label="Remarks" error={decisionForm.errors.remarks}>
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={2}
                                value={decisionForm.data.remarks}
                                onChange={(event) =>
                                    decisionForm.setData('remarks', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={() => setDecision(null)}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={
                                decision?.status === 'approved' ? 'primary' : 'destructive'
                            }
                            loading={decisionForm.processing}
                        >
                            {decision?.status === 'approved' ? 'Approve' : 'Reject'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
