import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CircleCheck, FilePenLine, Hourglass, Plus, XCircle } from 'lucide-react';
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
import { DayStatus, StateBadge, TIMEKEEPING_CRUMBS } from './Partials/shared';

const STATUSES = [
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'cancelled', label: 'Cancelled' },
];

function Punches({ timeIn, timeOut }) {
    return (
        <span className="tabular-nums">
            {timeIn ?? '—'} – {timeOut ?? '—'}
        </span>
    );
}

export default function Corrections({ corrections, filters, summary, can }) {
    const [filing, setFiling] = useState(false);
    const [deciding, setDeciding] = useState(null);
    const form = useForm({ work_date: '', time_in: '', time_out: '', reason: '' });

    const openFile = () => {
        form.clearErrors();
        form.setData({ work_date: '', time_in: '', time_out: '', reason: '' });
        setFiling(true);
    };

    const submit = (event) => {
        event.preventDefault();
        form.post('/hr/timekeeping/corrections', {
            preserveScroll: true,
            onSuccess: () => setFiling(false),
        });
    };

    const cancel = (correction) => {
        if (window.confirm('Cancel this correction request?')) {
            router.post(
                `/hr/timekeeping/corrections/${correction.id}/cancel`,
                {},
                { preserveScroll: true },
            );
        }
    };

    const rows = corrections.data ?? [];

    return (
        <AppLayout
            title="Time Corrections"
            breadcrumbs={[...TIMEKEEPING_CRUMBS, { label: 'Time Corrections' }]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Waiting for a decision"
                    value={summary.pending}
                    icon={Hourglass}
                    tone={summary.pending > 0 ? 'warning' : 'muted'}
                    href={withFilters('/hr/timekeeping/corrections', filters, {
                        status: 'pending',
                    })}
                />
                <StatCard
                    label="Approved"
                    value={summary.approved}
                    icon={CircleCheck}
                    tone={summary.approved > 0 ? 'success' : 'muted'}
                    href={withFilters('/hr/timekeeping/corrections', filters, {
                        status: 'approved',
                    })}
                />
                <StatCard
                    label="Rejected"
                    value={summary.rejected}
                    icon={XCircle}
                    tone="muted"
                    href={withFilters('/hr/timekeeping/corrections', filters, {
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
                                    '/hr/timekeeping/corrections',
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
                            Request Correction
                        </Button>
                    )}
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Date</TH>
                            <TH>Employee</TH>
                            <TH>Recorded now</TH>
                            <TH>Should be</TH>
                            <TH>Reason</TH>
                            <TH>Status</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={7}
                                icon={FilePenLine}
                                title="No correction requests"
                                description="When a day was recorded wrong — a missed time-out, a biometric that was down — the employee asks here."
                            />
                        ) : (
                            rows.map((correction) => (
                                <TR key={correction.id}>
                                    <TD className="whitespace-nowrap text-sm font-medium text-foreground">
                                        {formatDate(correction.work_date)}
                                    </TD>
                                    <TD>
                                        <p className="text-sm text-foreground">
                                            {correction.employee}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {correction.employee_number}
                                        </p>
                                    </TD>
                                    <TD className="whitespace-nowrap text-sm">
                                        {correction.current ? (
                                            <div className="flex items-center gap-2">
                                                <Punches
                                                    timeIn={correction.current.time_in}
                                                    timeOut={correction.current.time_out}
                                                />
                                                <DayStatus status={correction.current.status} />
                                            </div>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                No record
                                            </span>
                                        )}
                                    </TD>
                                    <TD className="whitespace-nowrap text-sm font-medium text-foreground">
                                        <Punches
                                            timeIn={correction.time_in}
                                            timeOut={correction.time_out}
                                        />
                                    </TD>
                                    <TD className="max-w-64 text-sm text-muted-foreground">
                                        <p className="truncate">{correction.reason}</p>
                                        {correction.decision_remarks && (
                                            <p className="truncate text-xs">
                                                {correction.decided_by}:{' '}
                                                {correction.decision_remarks}
                                            </p>
                                        )}
                                    </TD>
                                    <TD>
                                        <StateBadge status={correction.status} />
                                    </TD>
                                    <TD className="text-right">
                                        <div className="flex justify-end gap-1">
                                            {correction.can.decide && (
                                                <Button
                                                    size="sm"
                                                    onClick={() => setDeciding(correction)}
                                                >
                                                    Decide
                                                </Button>
                                            )}
                                            {correction.can.cancel && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => cancel(correction)}
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
                <Pagination links={corrections.links ?? []} meta={corrections} />
            </Card>

            <Modal
                show={filing}
                onClose={() => setFiling(false)}
                title="Request a Time Correction"
                description="Say what your record for that day should show. Leave a time blank to keep what is already recorded."
            >
                <form onSubmit={submit} className="space-y-4">
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
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Time in" error={form.errors.time_in}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="time"
                                    value={form.data.time_in}
                                    onChange={(event) =>
                                        form.setData('time_in', event.target.value)
                                    }
                                />
                            )}
                        </Field>
                        <Field label="Time out" error={form.errors.time_out}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="time"
                                    value={form.data.time_out}
                                    onChange={(event) =>
                                        form.setData('time_out', event.target.value)
                                    }
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
                                placeholder="e.g. The biometric was offline when I clocked out"
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
                            Submit Request
                        </Button>
                    </div>
                </form>
            </Modal>

            <DecisionModal
                target={deciding}
                onClose={() => setDeciding(null)}
                url={deciding ? `/hr/timekeeping/corrections/${deciding.id}/decide` : ''}
                title="Decide on a correction"
            >
                {deciding && (
                    <div className="space-y-1 rounded-md bg-secondary/50 p-3 text-sm">
                        <p className="font-medium text-foreground">
                            {deciding.employee} · {formatDate(deciding.work_date)}
                        </p>
                        <p className="text-muted-foreground">
                            Recorded now:{' '}
                            {deciding.current ? (
                                <Punches
                                    timeIn={deciding.current.time_in}
                                    timeOut={deciding.current.time_out}
                                />
                            ) : (
                                'no record'
                            )}
                        </p>
                        <p className="text-muted-foreground">
                            Asks for:{' '}
                            <Punches timeIn={deciding.time_in} timeOut={deciding.time_out} />
                        </p>
                        <p className="text-muted-foreground">{deciding.reason}</p>
                    </div>
                )}
            </DecisionModal>
        </AppLayout>
    );
}
