import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CircleAlert, FilePenLine, Lock, LockOpen, Timer, Users } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Button,
    Card,
    CardHeader,
    Field,
    InputError,
    Modal,
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
import { formatDate } from '@/lib/utils';
import { minutes, StateBadge, TIMEKEEPING_CRUMBS } from './Partials/shared';

export default function CutoffShow({ period, cutoff, outstanding, rows, can, errors }) {
    const [reopening, setReopening] = useState(false);
    const [closing, setClosing] = useState(false);
    const reopen = useForm({ reason: '' });
    const closed = cutoff.status === 'closed';
    const range = `from=${period.start_date}&to=${period.end_date}`;

    const close = () => {
        if (!window.confirm(`Close ${period.name}? Its time records will be frozen.`)) return;

        setClosing(true);
        router.post(
            `/hr/timekeeping/cutoffs/${period.id}/close`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setClosing(false),
            },
        );
    };

    const submitReopen = (event) => {
        event.preventDefault();
        reopen.post(`/hr/timekeeping/cutoffs/${period.id}/reopen`, {
            preserveScroll: true,
            onSuccess: () => {
                reopen.reset();
                setReopening(false);
            },
        });
    };

    return (
        <AppLayout
            title={`Cutoff · ${period.name}`}
            breadcrumbs={[
                ...TIMEKEEPING_CRUMBS,
                { label: 'Cutoff Closing', href: '/hr/timekeeping/cutoffs' },
                { label: period.name },
            ]}
        >
            <Card className="mb-5">
                <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center">
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <h2 className="text-base font-semibold text-foreground">
                                {period.name}
                            </h2>
                            <StateBadge status={cutoff.status} />
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {formatDate(period.start_date)} – {formatDate(period.end_date)}
                            {closed &&
                                cutoff.closed_by &&
                                ` · closed by ${cutoff.closed_by} on ${formatDate(cutoff.closed_at)}`}
                        </p>
                        {!closed && cutoff.reopen_reason && (
                            <p className="mt-1 text-xs text-muted-foreground">
                                Reopened by {cutoff.reopened_by}: “{cutoff.reopen_reason}”
                            </p>
                        )}
                        <InputError message={errors?.cutoff} />
                    </div>
                    {!closed && can.close && (
                        <Button onClick={close} loading={closing}>
                            <Lock className="h-4 w-4" />
                            Close Cutoff
                        </Button>
                    )}
                    {closed && can.reopen && (
                        <Button variant="outline" onClick={() => setReopening(true)}>
                            <LockOpen className="h-4 w-4" />
                            Reopen
                        </Button>
                    )}
                </div>
            </Card>

            {!closed && (
                <div className="mb-5 grid gap-4 sm:grid-cols-3">
                    <StatCard
                        label="No time-out"
                        value={outstanding.incomplete}
                        icon={CircleAlert}
                        tone={outstanding.incomplete > 0 ? 'destructive' : 'muted'}
                        hint="complete the record or approve a correction"
                        href={`/hr/timekeeping?status=incomplete&${range}`}
                    />
                    <StatCard
                        label="Overtime to decide"
                        value={outstanding.pending_overtime}
                        icon={Timer}
                        tone={outstanding.pending_overtime > 0 ? 'warning' : 'muted'}
                        href="/hr/timekeeping/overtime?status=pending"
                    />
                    <StatCard
                        label="Corrections to decide"
                        value={outstanding.pending_corrections}
                        icon={FilePenLine}
                        tone={outstanding.pending_corrections > 0 ? 'warning' : 'muted'}
                        href="/hr/timekeeping/corrections?status=pending"
                    />
                </div>
            )}

            <Card>
                <CardHeader
                    title="What payroll will read"
                    description="Each employee's totals for the period. Absences already covered by approved leave are not counted; overtime is approved requests only."
                />
                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Assignment</TH>
                            <TH className="text-right">Days</TH>
                            <TH className="text-right">Hours</TH>
                            <TH className="text-right">Late</TH>
                            <TH className="text-right">Undertime</TH>
                            <TH className="text-right">Absent</TH>
                            <TH className="text-right">OT hrs</TH>
                            <TH className="text-right">Night diff</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={9} icon={Users} title="No active employees" />
                        ) : (
                            rows.map((row) => (
                                <TR key={row.id}>
                                    <TD>
                                        <a
                                            href={`/hr/timekeeping?employee=${row.id}&${range}`}
                                            className="text-sm text-foreground hover:underline"
                                        >
                                            {row.name}
                                        </a>
                                        <p className="text-xs text-muted-foreground">
                                            {row.number}
                                        </p>
                                    </TD>
                                    <TD className="text-sm text-muted-foreground">
                                        {row.assignment}
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {row.recorded_days === 0 ? (
                                            <span className="text-warning">No records</span>
                                        ) : (
                                            row.days_worked
                                        )}
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {row.hours_worked}
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {minutes(row.late_minutes)}
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {minutes(row.undertime_minutes)}
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        <span
                                            className={
                                                row.absent_days > 0 ? 'text-destructive' : ''
                                            }
                                        >
                                            {row.absent_days || '—'}
                                        </span>
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {row.overtime_hours || '—'}
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {minutes(row.night_diff_minutes)}
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            <Modal
                show={reopening}
                onClose={() => setReopening(false)}
                title="Reopen this cutoff"
                description="Reopening lets the records move again — and payroll may already have been computed from them. Say why."
            >
                <form onSubmit={submitReopen} className="space-y-4">
                    <Field label="Reason" required error={reopen.errors.reason}>
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={3}
                                value={reopen.data.reason}
                                onChange={(event) =>
                                    reopen.setData('reason', event.target.value)
                                }
                                required
                            />
                        )}
                    </Field>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setReopening(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" loading={reopen.processing}>
                            Reopen Cutoff
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
