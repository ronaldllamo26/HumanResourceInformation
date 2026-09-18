import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    CalendarCheck,
    CalendarDays,
    CheckCircle2,
    Hourglass,
    Paperclip,
    Plus,
    X,
    XCircle,
} from 'lucide-react';
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

/** Where a request sits in the employee → supervisor → HR chain. */
const STAGE_LABEL = {
    pending: 'Awaiting supervisor',
    supervisor_approved: 'Awaiting HR',
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

/**
 * Filters the tiles set that no control on this screen can show.
 *
 * `awaiting` is two statuses at once, so the status dropdown cannot express
 * it. `filed_from` asks when a request was *filed*, which is a different
 * question from the dates somebody is away — the dashboard's Leave card counts
 * what was filed this month, and its tiles carry that window along so they
 * open the rows they counted rather than every request ever.
 */
const TILE_FILTERS = [
    { key: 'awaiting', label: () => 'Awaiting a decision' },
    { key: 'filed_from', label: (value) => `Filed since ${formatDate(value)}` },
];

export default function Index({
    requests,
    summary,
    filters,
    statuses,
    leaveTypes,
    employees,
    myBalances,
    can,
    ownEmployeeId,
}) {
    const [fileOpen, setFileOpen] = useState(false);
    const [decision, setDecision] = useState(null); // { request, action }

    const form = useForm({
        // You file your own leave now, so there is nothing to pick.
        employee_id: ownEmployeeId ?? '',
        leave_type_id: '',
        start_date: '',
        end_date: '',
        is_half_day: false,
        half_day_period: 'morning',
        reason: '',
        attachment: null,
    });

    const decisionForm = useForm({ remarks: '' });

    const selectedType = useMemo(
        () => leaveTypes.find((type) => String(type.id) === String(form.data.leave_type_id)),
        [leaveTypes, form.data.leave_type_id],
    );

    const availableCredits = useMemo(() => {
        const match = myBalances.find(
            (balance) => String(balance.leave_type_id) === String(form.data.leave_type_id),
        );

        return match?.available;
    }, [myBalances, form.data.leave_type_id]);

    const applyFilter = (key, value) => {
        router.get(
            '/hr/leave',
            {
                ...filters,
                // The status dropdown drops the tile's `awaiting` filter: the
                // two narrow one axis, and leaving one behind ands them.
                ...(key === 'status' ? { awaiting: undefined } : {}),
                [key]: value || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const submit = (event) => {
        event.preventDefault();

        form.post('/hr/leave', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.setData('employee_id', ownEmployeeId ?? '');
                setFileOpen(false);
            },
        });
    };

    const submitDecision = (event) => {
        event.preventDefault();

        decisionForm.post(`/hr/leave/${decision.request.id}/${decision.action}`, {
            preserveScroll: true,
            onSuccess: () => {
                decisionForm.reset();
                setDecision(null);
            },
        });
    };

    const rows = requests.data ?? [];
    const meta = requests.meta ?? {};

    // Clicking a figure opens the rows it counted. `status` and `awaiting`
    // narrow the same axis, so setting either clears the other.
    const drillTo = (changes) =>
        withFilters('/hr/leave', filters, changes, ['status', 'awaiting']);

    const approvalRate = summary.total > 0 ? (summary.approved / summary.total) * 100 : 0;

    return (
        <AppLayout
            title="Leave & Absence"
            breadcrumbs={[{ label: 'Human Resource' }, { label: 'Leave & Absence' }]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Total Requests"
                    value={summary.total}
                    icon={CalendarDays}
                    tone="primary"
                    href={drillTo({})}
                />

                {/* The work queue, and the only tile on this screen anybody
                    has to act on — warning while it holds anything, grey the
                    moment it is empty. */}
                <StatCard
                    label="Awaiting Action"
                    value={summary.pending}
                    icon={Hourglass}
                    tone={summary.pending > 0 ? 'warning' : 'muted'}
                    /* `awaiting`, not `status=pending`: the tile counts rows
                       left mid-workflow too. */
                    href={drillTo({ awaiting: '1' })}
                />

                <MeterCard
                    label="Approved"
                    value={summary.approved}
                    percent={approvalRate}
                    badge={`${Math.round(approvalRate)}%`}
                    icon={CheckCircle2}
                    tone="success"
                    iconTone="success"
                    href={drillTo({ status: 'approved' })}
                />

                {/* Not a count of requests but of days off the ledger — the
                    figure payroll and the balances screen both read. */}
                <StatCard
                    label="Approved Days"
                    value={summary.approved_days}
                    icon={CalendarCheck}
                    tone={summary.approved_days > 0 ? 'info' : 'muted'}
                    href={drillTo({ status: 'approved' })}
                />
            </div>

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:flex-wrap">
                    <Select
                        value={filters.status ?? ''}
                        onChange={(event) => applyFilter('status', event.target.value)}
                        placeholder="All statuses"
                        className="w-full sm:w-52"
                        aria-label="Filter by status"
                        options={statuses.map((status) => ({
                            value: status,
                            label: STAGE_LABEL[status] ?? titleCase(status),
                        }))}
                    />

                    <Select
                        value={filters.leave_type_id ?? ''}
                        onChange={(event) => applyFilter('leave_type_id', event.target.value)}
                        placeholder="All leave types"
                        className="w-full sm:w-48"
                        aria-label="Filter by leave type"
                        options={leaveTypes.map((type) => ({
                            value: type.id,
                            label: `${type.code} — ${type.name}`,
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

                    {/* Filters no dropdown here can show — `awaiting` is two
                        statuses at once, `filed_from` is when a request was
                        filed rather than when somebody is away. Both are set
                        by tiles, and a list narrowed by a filter with no
                        visible control is a list nobody can explain. */}
                    {TILE_FILTERS.filter(({ key }) => filters[key]).map(({ key, label }) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => applyFilter(key, '')}
                            className="flex h-9 shrink-0 items-center gap-1.5 self-end rounded-full border border-info/30 bg-info/10 px-3 text-xs font-medium text-info transition-colors hover:bg-info/20"
                        >
                            {label(filters[key])}
                            <X className="h-3.5 w-3.5" aria-hidden="true" />
                        </button>
                    ))}

                    {can.create && (
                        <Button className="lg:ml-auto" onClick={() => setFileOpen(true)}>
                            <Plus className="h-4 w-4" />
                            File Leave
                        </Button>
                    )}
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Reference</TH>
                            <TH>Employee</TH>
                            <TH>Type</TH>
                            <TH>Dates</TH>
                            <TH className="text-right">Days</TH>
                            <TH>Stage</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={7}
                                icon={CalendarDays}
                                title="No leave requests"
                                description="Filed leave moves from the supervisor to HR before credits are deducted."
                            />
                        ) : (
                            rows.map((request) => (
                                <TR key={request.id}>
                                    <TD>
                                        <span className="text-sm font-medium text-foreground">
                                            {request.reference_number}
                                        </span>
                                        {request.attachment_url && (
                                            <a
                                                href={request.attachment_url}
                                                className="mt-0.5 flex items-center gap-1 text-xs text-primary hover:underline"
                                            >
                                                <Paperclip className="h-3 w-3" />
                                                Attachment
                                            </a>
                                        )}
                                    </TD>

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

                                    <TD>
                                        <Badge
                                            variant={
                                                request.leave_type?.is_paid
                                                    ? 'primary'
                                                    : 'muted'
                                            }
                                        >
                                            {request.leave_type?.code}
                                        </Badge>
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {formatDate(request.start_date)}
                                        {request.start_date !== request.end_date && (
                                            <> – {formatDate(request.end_date)}</>
                                        )}
                                        {request.is_half_day && (
                                            <span className="block text-xs">
                                                Half day ({request.half_day_period})
                                            </span>
                                        )}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-foreground">
                                        {request.days_requested}
                                    </TD>

                                    <TD>
                                        <Badge status={request.status}>
                                            {STAGE_LABEL[request.status] ?? request.status}
                                        </Badge>
                                        {request.hr_remarks && (
                                            <p
                                                className="mt-0.5 max-w-[16rem] truncate text-xs text-muted-foreground"
                                                title={request.hr_remarks}
                                            >
                                                {request.hr_remarks}
                                            </p>
                                        )}
                                    </TD>

                                    <TD>
                                        <div className="flex items-center justify-end gap-1">
                                            {request.can.decide && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setDecision({
                                                            request,
                                                            action: 'approve',
                                                        })
                                                    }
                                                >
                                                    <CheckCircle2 className="h-4 w-4 text-success" />
                                                    Approve
                                                </Button>
                                            )}

                                            {request.can.reject && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setDecision({
                                                            request,
                                                            action: 'reject',
                                                        })
                                                    }
                                                >
                                                    <XCircle className="h-4 w-4 text-destructive" />
                                                    Reject
                                                </Button>
                                            )}

                                            {!request.can.decide &&
                                                !request.can.reject &&
                                                request.can.cancel && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            router.post(
                                                                `/hr/leave/${request.id}/cancel`,
                                                                {},
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                    >
                                                        Cancel
                                                    </Button>
                                                )}

                                            {!request.can.decide &&
                                                !request.can.reject &&
                                                !request.can.cancel && (
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

            {/* File leave */}
            <Modal
                show={fileOpen}
                onClose={() => setFileOpen(false)}
                title="File Leave"
                description="Rest days and holidays inside the range do not consume credits."
                maxWidth="2xl"
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Leave Type"
                            required
                            error={form.errors.leave_type_id}
                            hint={
                                availableCredits !== undefined
                                    ? `${availableCredits} day(s) available`
                                    : undefined
                            }
                        >
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={form.data.leave_type_id}
                                    onChange={(event) =>
                                        form.setData('leave_type_id', event.target.value)
                                    }
                                    placeholder="Select leave type"
                                    error={form.errors.leave_type_id}
                                    options={leaveTypes.map((type) => ({
                                        value: type.id,
                                        label: `${type.code} — ${type.name}`,
                                    }))}
                                />
                            )}
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Start Date" required error={form.errors.start_date}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={form.data.start_date}
                                    onChange={(event) => {
                                        form.setData((current) => ({
                                            ...current,
                                            start_date: event.target.value,
                                            // A half day is always a single date.
                                            end_date: current.is_half_day
                                                ? event.target.value
                                                : current.end_date || event.target.value,
                                        }));
                                    }}
                                    error={form.errors.start_date}
                                />
                            )}
                        </Field>

                        <Field label="End Date" required error={form.errors.end_date}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={form.data.end_date}
                                    disabled={form.data.is_half_day}
                                    onChange={(event) =>
                                        form.setData('end_date', event.target.value)
                                    }
                                    error={form.errors.end_date}
                                />
                            )}
                        </Field>
                    </div>

                    <div className="flex flex-wrap items-end gap-5">
                        <label className="flex items-center gap-2.5">
                            <input
                                type="checkbox"
                                checked={form.data.is_half_day}
                                onChange={(event) =>
                                    form.setData((current) => ({
                                        ...current,
                                        is_half_day: event.target.checked,
                                        end_date: event.target.checked
                                            ? current.start_date
                                            : current.end_date,
                                    }))
                                }
                                className="h-4 w-4 rounded border-input text-primary focus:ring-ring/30"
                            />
                            <span className="text-sm text-foreground">Half day</span>
                        </label>

                        {form.data.is_half_day && (
                            <Field
                                label="Period"
                                className="w-40"
                                error={form.errors.half_day_period}
                            >
                                {({ id }) => (
                                    <Select
                                        id={id}
                                        value={form.data.half_day_period}
                                        onChange={(event) =>
                                            form.setData('half_day_period', event.target.value)
                                        }
                                        options={[
                                            { value: 'morning', label: 'Morning' },
                                            { value: 'afternoon', label: 'Afternoon' },
                                        ]}
                                    />
                                )}
                            </Field>
                        )}
                    </div>

                    <Field label="Reason" required error={form.errors.reason}>
                        {({ id }) => (
                            <Textarea
                                id={id}
                                value={form.data.reason}
                                onChange={(event) => form.setData('reason', event.target.value)}
                                error={form.errors.reason}
                            />
                        )}
                    </Field>

                    <Field
                        label={
                            selectedType?.requires_attachment
                                ? 'Supporting Document (required)'
                                : 'Supporting Document'
                        }
                        hint="PDF, JPG, or PNG — max 5 MB"
                        error={form.errors.attachment}
                    >
                        {({ id }) => (
                            <input
                                id={id}
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png"
                                onChange={(event) =>
                                    form.setData('attachment', event.target.files[0] ?? null)
                                }
                                className="block w-full text-xs text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-xs file:font-medium file:text-secondary-foreground hover:file:bg-secondary/70"
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
                    decision?.action === 'reject'
                        ? 'Reject this leave request?'
                        : 'Approve and deduct credits?'
                }
                maxWidth="md"
            >
                <form onSubmit={submitDecision} className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        <span className="font-medium text-foreground">
                            {decision?.request.employee?.full_name}
                        </span>{' '}
                        — {decision?.request.days_requested} day(s) of{' '}
                        {decision?.request.leave_type?.name} from{' '}
                        {formatDate(decision?.request.start_date)}.
                    </p>

                    <Field
                        label="Remarks"
                        required={decision?.action === 'reject'}
                        error={decisionForm.errors.remarks}
                    >
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={2}
                                value={decisionForm.data.remarks}
                                onChange={(event) =>
                                    decisionForm.setData('remarks', event.target.value)
                                }
                                error={decisionForm.errors.remarks}
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={() => setDecision(null)}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={decision?.action === 'reject' ? 'destructive' : 'primary'}
                            loading={decisionForm.processing}
                        >
                            {decision?.action === 'reject' ? 'Reject' : 'Confirm'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
