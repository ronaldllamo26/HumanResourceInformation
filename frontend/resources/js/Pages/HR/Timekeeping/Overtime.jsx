import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CircleCheck, Clock, Hourglass, Plus, Timer, XCircle } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
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
import { formatDate, withFilters } from '@/lib/utils';
import DecisionModal from './Partials/DecisionModal';
import { minutes, StateBadge, TIMEKEEPING_CRUMBS } from './Partials/shared';

const STATUSES = [
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'cancelled', label: 'Cancelled' },
];

export default function Overtime({ requests, filters, summary, can }) {
    const [filing, setFiling] = useState(false);
    const [deciding, setDeciding] = useState(null);
    const form = useForm({ work_date: '', hours: '', reason: '' });

    const openFile = () => {
        form.clearErrors();
        form.setData({
            work_date: new Date().toISOString().slice(0, 10),
            hours: '',
            reason: '',
        });
        setFiling(true);
    };

    const submit = (event) => {
        event.preventDefault();
        form.post('/hr/timekeeping/overtime', {
            preserveScroll: true,
            onSuccess: () => setFiling(false),
        });
    };

    const cancel = (request) => {
        if (window.confirm('Cancel this overtime request?')) {
            router.post(
                `/hr/timekeeping/overtime/${request.id}/cancel`,
                {},
                { preserveScroll: true },
            );
        }
    };

    const rows = requests.data ?? [];

    return (
        <AppLayout
            title="Overtime Requests"
            breadcrumbs={[...TIMEKEEPING_CRUMBS, { label: 'Overtime Requests' }]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Waiting for a decision"
                    value={summary.pending}
                    icon={Hourglass}
                    tone={summary.pending > 0 ? 'warning' : 'muted'}
                    href={withFilters('/hr/timekeeping/overtime', filters, {
                        status: 'pending',
                    })}
                />
                <StatCard
                    label="Approved hours"
                    value={summary.approved_hours}
                    icon={CircleCheck}
                    tone={summary.approved_hours > 0 ? 'success' : 'muted'}
                    href={withFilters('/hr/timekeeping/overtime', filters, {
                        status: 'approved',
                    })}
                />
                <StatCard
                    label="Rejected"
                    value={summary.rejected}
                    icon={XCircle}
                    tone="muted"
                    href={withFilters('/hr/timekeeping/overtime', filters, {
                        status: 'rejected',
                    })}
                />
            </div>

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center">
                    <Select
                        value={filters.status ?? ''}
                        onChange={(event) =>
                            router.get(
                                withFilters(
                                    '/hr/timekeeping/overtime',
                                    {},
                                    { status: event.target.value },
                                ),
                                {},
                                {
                                    preserveState: true,
                                    replace: true,
                                },
                            )
                        }
                        placeholder="All statuses"
                        aria-label="Status"
                        className="w-full sm:w-48"
                        options={STATUSES}
                    />
                    {can.create && (
                        <Button onClick={openFile} className="sm:ml-auto">
                            <Plus className="h-4 w-4" />
                            File Overtime
                        </Button>
                    )}
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Date</TH>
                            <TH>Employee</TH>
                            <TH className="text-right">Hours asked</TH>
                            <TH className="text-right">DTR past shift</TH>
                            <TH>Reason</TH>
                            <TH>Status</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={7}
                                icon={Timer}
                                title="No overtime requests"
                                description="Hours past the shift are paid only once a request for them is approved."
                            />
                        ) : (
                            rows.map((request) => (
                                <TR key={request.id}>
                                    <TD className="whitespace-nowrap text-sm font-medium text-foreground">
                                        {formatDate(request.work_date)}
                                    </TD>
                                    <TD>
                                        <p className="text-sm text-foreground">
                                            {request.employee}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {request.employee_number}
                                        </p>
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {request.hours.toFixed(2)}
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {request.recorded_overtime_minutes === null
                                            ? 'No record'
                                            : minutes(request.recorded_overtime_minutes)}
                                    </TD>
                                    <TD className="max-w-64 text-sm text-muted-foreground">
                                        <p className="truncate">{request.reason}</p>
                                        {request.decision_remarks && (
                                            <p className="truncate text-xs">
                                                {request.decided_by}: {request.decision_remarks}
                                            </p>
                                        )}
                                    </TD>
                                    <TD>
                                        <StateBadge status={request.status} />
                                    </TD>
                                    <TD className="text-right">
                                        <div className="flex justify-end gap-1">
                                            {request.can.decide && (
                                                <Button
                                                    size="sm"
                                                    onClick={() => setDeciding(request)}
                                                >
                                                    Decide
                                                </Button>
                                            )}
                                            {request.can.cancel && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => cancel(request)}
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
                <Pagination links={requests.links ?? []} meta={requests} />
            </Card>

            <Modal
                show={filing}
                onClose={() => setFiling(false)}
                title="File Overtime"
                description="For hours you worked (or will work) past your shift. Your supervisor or HR decides."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Date" required error={form.errors.work_date}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={form.data.work_date}
                                    onChange={(event) =>
                                        form.setData('work_date', event.target.value)
                                    }
                                    required
                                />
                            )}
                        </Field>
                        <Field label="Hours" required error={form.errors.hours}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    step="0.5"
                                    min="0.5"
                                    max="12"
                                    value={form.data.hours}
                                    onChange={(event) =>
                                        form.setData('hours', event.target.value)
                                    }
                                    required
                                />
                            )}
                        </Field>
                    </div>
                    <Field label="Reason" required error={form.errors.reason}>
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={3}
                                value={form.data.reason}
                                onChange={(event) => form.setData('reason', event.target.value)}
                                placeholder="e.g. Delivery to the client ran past 5 PM"
                                required
                            />
                        )}
                    </Field>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setFiling(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            <Clock className="h-4 w-4" />
                            File Request
                        </Button>
                    </div>
                </form>
            </Modal>

            <DecisionModal
                target={deciding}
                onClose={() => setDeciding(null)}
                url={deciding ? `/hr/timekeeping/overtime/${deciding.id}/decide` : ''}
                title="Decide on overtime"
            >
                {deciding && (
                    <div className="rounded-md bg-secondary/50 p-3 text-sm">
                        <p className="font-medium text-foreground">
                            {deciding.employee} · {formatDate(deciding.work_date)}
                        </p>
                        <p className="mt-1 text-muted-foreground">
                            Asks for {deciding.hours.toFixed(2)} hour(s). The DTR shows{' '}
                            {deciding.recorded_overtime_minutes === null
                                ? 'no record for that day'
                                : `${minutes(deciding.recorded_overtime_minutes)} past the shift`}
                            .
                        </p>
                        <p className="mt-1 text-muted-foreground">{deciding.reason}</p>
                    </div>
                )}
            </DecisionModal>
        </AppLayout>
    );
}
