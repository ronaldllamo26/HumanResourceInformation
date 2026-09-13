import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle2, ClipboardList, Hourglass, Plus, XCircle } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
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
import { formatDate, initials } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

const BLANK = {
    log_date: '',
    requested_time_in: '',
    requested_break_out: '',
    requested_break_in: '',
    requested_time_out: '',
    requested_status: '',
    reason: '',
};

const PUNCHES = [
    ['time_in', 'Time In'],
    ['break_out', 'Break Out'],
    ['break_in', 'Break In'],
    ['time_out', 'Time Out'],
];

export default function Adjustments({
    requests,
    summary,
    filters,
    statuses,
    attendanceStatuses,
    can,
}) {
    const [fileOpen, setFileOpen] = useState(false);
    const [decision, setDecision] = useState(null); // { request, status }

    const form = useForm(BLANK);
    const decisionForm = useForm({ status: '', remarks: '' });

    const rows = requests.data ?? [];
    const meta = requests.meta ?? {};

    const applyFilter = (value) =>
        router.get(
            '/hr/timekeeping/adjustments',
            { status: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const submit = (event) => {
        event.preventDefault();

        form.post('/hr/timekeeping/adjustments', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setFileOpen(false);
            },
        });
    };

    const submitDecision = (event) => {
        event.preventDefault();

        // transform() returns undefined, so it cannot be chained onto post().
        decisionForm.setData('status', decision.status);

        decisionForm.post(`/hr/timekeeping/adjustments/${decision.request.id}/decide`, {
            preserveScroll: true,
            onSuccess: () => {
                decisionForm.reset();
                setDecision(null);
            },
        });
    };

    return (
        <AppLayout
            title="DTR Corrections"
            breadcrumbs={[
                { label: 'Timekeeping', href: '/hr/timekeeping' },
                { label: 'DTR Corrections' },
            ]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Awaiting Decision"
                    value={summary.pending}
                    icon={Hourglass}
                    tone={summary.pending > 0 ? 'warning' : 'muted'}
                    hint="nothing changes until one is taken"
                />
                <StatCard
                    label="Approved"
                    value={summary.approved}
                    icon={CheckCircle2}
                    tone={summary.approved > 0 ? 'success' : 'muted'}
                    hint="applied to the time record"
                />
                <StatCard
                    label="Rejected"
                    value={summary.rejected}
                    icon={XCircle}
                    tone={summary.rejected > 0 ? 'info' : 'muted'}
                    hint="record left as it was"
                />
            </div>

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center">
                    <Select
                        className="w-full sm:w-52"
                        value={filters.status ?? ''}
                        onChange={(event) => applyFilter(event.target.value)}
                        placeholder="All statuses"
                        options={statuses.map((status) => ({
                            value: status,
                            label: titleCase(status),
                        }))}
                    />

                    {can.create && (
                        <Button className="sm:ml-auto" onClick={() => setFileOpen(true)}>
                            <Plus className="h-4 w-4" aria-hidden="true" />
                            Request Correction
                        </Button>
                    )}
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Date</TH>
                            <TH>Currently</TH>
                            <TH>Requested</TH>
                            <TH>Reason</TH>
                            <TH>Status</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={7}
                                icon={ClipboardList}
                                title="No correction requests"
                                description="A discrepancy on a DTR is raised here and decided by a supervisor or HR before it changes the record."
                            />
                        ) : (
                            rows.map((row) => (
                                <TR key={row.id}>
                                    <TD>
                                        <div className="flex items-center gap-2.5">
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                {initials(row.employee_name)}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {row.employee_name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {row.employee_number}
                                                </p>
                                            </div>
                                        </div>
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {formatDate(row.log_date)}
                                    </TD>

                                    <TD className="whitespace-nowrap text-xs">
                                        {row.current ? (
                                            <Punches value={row.current} />
                                        ) : (
                                            // Not a gap in the display — there
                                            // is genuinely no row, and that is
                                            // what makes this a create rather
                                            // than a correction.
                                            <span className="text-muted-foreground">
                                                No record for this day
                                            </span>
                                        )}
                                    </TD>

                                    <TD className="whitespace-nowrap text-xs">
                                        <Punches value={row.requested} />
                                    </TD>

                                    <TD className="max-w-[220px] text-sm text-muted-foreground">
                                        <p className="truncate" title={row.reason}>
                                            {row.reason}
                                        </p>
                                        {row.remarks && (
                                            <p
                                                className="truncate text-xs italic"
                                                title={row.remarks}
                                            >
                                                {row.decided_by}: {row.remarks}
                                            </p>
                                        )}
                                    </TD>

                                    <TD>
                                        <Badge status={row.status} />
                                    </TD>

                                    <TD>
                                        <div className="flex justify-end gap-1">
                                            {row.can.decide && (
                                                <>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setDecision({
                                                                request: row,
                                                                status: 'approved',
                                                            })
                                                        }
                                                    >
                                                        Approve
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setDecision({
                                                                request: row,
                                                                status: 'rejected',
                                                            })
                                                        }
                                                    >
                                                        Reject
                                                    </Button>
                                                </>
                                            )}
                                            {row.can.cancel && !row.can.decide && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        router.post(
                                                            `/hr/timekeeping/adjustments/${row.id}/cancel`,
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    Cancel
                                                </Button>
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

            <Modal
                show={fileOpen}
                onClose={() => setFileOpen(false)}
                title="Request a DTR correction"
                description="Your own record. Leave a punch blank to leave it as it is — a blank is not a request to erase it."
                maxWidth="2xl"
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Date" required error={form.errors.log_date}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={form.data.log_date}
                                    onChange={(event) =>
                                        form.setData('log_date', event.target.value)
                                    }
                                    error={form.errors.log_date}
                                />
                            )}
                        </Field>

                        <Field
                            label="Status"
                            hint="Only where punches are not the point."
                            error={form.errors.requested_status}
                        >
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={form.data.requested_status}
                                    onChange={(event) =>
                                        form.setData('requested_status', event.target.value)
                                    }
                                    placeholder="Leave as computed"
                                    options={attendanceStatuses.map((status) => ({
                                        value: status,
                                        label: titleCase(status),
                                    }))}
                                />
                            )}
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-4">
                        {PUNCHES.map(([field, label]) => (
                            <Field
                                key={field}
                                label={label}
                                error={form.errors[`requested_${field}`]}
                            >
                                {({ id }) => (
                                    <Input
                                        id={id}
                                        type="time"
                                        value={form.data[`requested_${field}`]}
                                        onChange={(event) =>
                                            form.setData(
                                                `requested_${field}`,
                                                event.target.value,
                                            )
                                        }
                                        error={form.errors[`requested_${field}`]}
                                    />
                                )}
                            </Field>
                        ))}
                    </div>

                    <Field
                        label="Reason"
                        required
                        hint="What was wrong with the day. An approver has to decide on this."
                        error={form.errors.reason}
                    >
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={3}
                                value={form.data.reason}
                                onChange={(event) => form.setData('reason', event.target.value)}
                                error={form.errors.reason}
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

            <Modal
                show={Boolean(decision)}
                onClose={() => setDecision(null)}
                title={
                    decision?.status === 'approved'
                        ? 'Approve this correction?'
                        : 'Reject this correction?'
                }
                maxWidth="lg"
            >
                <form onSubmit={submitDecision} className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        {decision?.status === 'approved' ? (
                            <>
                                The time record for{' '}
                                <span className="font-medium text-foreground">
                                    {decision?.request.employee_name}
                                </span>{' '}
                                on {formatDate(decision?.request.log_date)} will be rewritten
                                and recomputed. Payroll figures drawn from it will change.
                            </>
                        ) : (
                            <>The time record will be left exactly as it is.</>
                        )}
                    </p>

                    <Field label="Remarks" error={decisionForm.errors.remarks}>
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={3}
                                value={decisionForm.data.remarks}
                                onChange={(event) =>
                                    decisionForm.setData('remarks', event.target.value)
                                }
                                error={decisionForm.errors.remarks}
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
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

/** The four punches on one line, with a status where one was asked for. */
function Punches({ value }) {
    const times = PUNCHES.map(([field]) => value[field]).filter(Boolean);

    return (
        <span className="tabular-nums text-foreground">
            {times.length > 0 ? times.join(' · ') : '—'}
            {value.status && (
                <span className="ml-1.5 text-muted-foreground">{titleCase(value.status)}</span>
            )}
        </span>
    );
}
