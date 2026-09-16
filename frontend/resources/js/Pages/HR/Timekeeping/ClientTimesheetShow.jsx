import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CircleCheck, Printer, Send, TriangleAlert } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Button,
    Card,
    Field,
    Input,
    InputError,
    Modal,
    Table,
    TBody,
    TD,
    Textarea,
    TFoot,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { formatDate } from '@/lib/utils';
import { minutes, StateBadge, TIMEKEEPING_CRUMBS } from './Partials/shared';

export default function ClientTimesheetShow({ timesheet, errors }) {
    const [answering, setAnswering] = useState(false);
    const form = useForm({ decision: 'confirm', confirmed_by_name: '', client_remarks: '' });

    const send = () =>
        router.post(
            `/hr/timekeeping/client-timesheets/${timesheet.id}/send`,
            {},
            { preserveScroll: true },
        );

    const submit = (decision) => {
        form.transform((data) => ({ ...data, decision }));
        form.post(`/hr/timekeeping/client-timesheets/${timesheet.id}/answer`, {
            preserveScroll: true,
            onSuccess: () => setAnswering(false),
        });
    };

    const { client, period, lines, totals } = timesheet;

    return (
        <AppLayout
            title={`Timesheet · ${client?.name}`}
            breadcrumbs={[
                ...TIMEKEEPING_CRUMBS,
                {
                    label: 'Client Timesheets',
                    href: `/hr/timekeeping/client-timesheets?period=${period.id}`,
                },
                { label: client?.name },
            ]}
        >
            <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
                <StateBadge status={timesheet.status} />
                <p className="text-xs text-muted-foreground">
                    Prepared by {timesheet.prepared_by ?? '—'} ·{' '}
                    {formatDate(timesheet.prepared_at)}
                </p>
                <div className="flex flex-wrap gap-2 sm:ml-auto">
                    <Button variant="outline" onClick={() => window.print()}>
                        <Printer className="h-4 w-4" />
                        Print
                    </Button>
                    {timesheet.status === 'draft' && (
                        <Button onClick={send}>
                            <Send className="h-4 w-4" />
                            Mark as sent
                        </Button>
                    )}
                    {timesheet.status === 'sent' && (
                        <Button onClick={() => setAnswering(true)}>
                            <CircleCheck className="h-4 w-4" />
                            Record client's answer
                        </Button>
                    )}
                </div>
            </div>
            <InputError message={errors?.timesheet} className="mb-3" />

            {timesheet.status === 'disputed' && (
                <Card className="mb-4 print:hidden">
                    <div className="flex items-start gap-3 p-4">
                        <TriangleAlert className="mt-0.5 h-5 w-5 shrink-0 text-destructive" />
                        <div className="text-sm">
                            <p className="font-medium text-foreground">
                                Disputed by {timesheet.confirmed_by_name} on{' '}
                                {formatDate(timesheet.confirmed_at)}
                            </p>
                            <p className="mt-0.5 text-muted-foreground">
                                {timesheet.client_remarks}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Correct the time records, then prepare the timesheet again from
                                Client Timesheets.
                            </p>
                        </div>
                    </div>
                </Card>
            )}

            <Card>
                <div className="border-b border-border p-5">
                    <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        Attendance timesheet for client confirmation
                    </p>
                    <h2 className="mt-1 text-lg font-semibold text-foreground">
                        {client?.name}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {period.name} · {formatDate(period.start_date)} –{' '}
                        {formatDate(period.end_date)}
                    </p>
                    {client?.address && (
                        <p className="text-xs text-muted-foreground">{client.address}</p>
                    )}
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Position</TH>
                            <TH className="text-right">Days</TH>
                            <TH className="text-right">Hours</TH>
                            <TH className="text-right">Late</TH>
                            <TH className="text-right">Undertime</TH>
                            <TH className="text-right">Absent</TH>
                            <TH className="text-right">OT hrs</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {lines.map((line) => (
                            <TR key={line.id}>
                                <TD>
                                    <p className="text-sm text-foreground">{line.name}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {line.number}
                                    </p>
                                </TD>
                                <TD className="text-sm text-muted-foreground">
                                    {line.position ?? '—'}
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    {line.days_worked}
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    {line.hours_worked.toFixed(2)}
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    {minutes(line.late_minutes)}
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    {minutes(line.undertime_minutes)}
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    {line.absent_days || '—'}
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    {line.overtime_hours || '—'}
                                </TD>
                            </TR>
                        ))}
                    </TBody>
                    <TFoot>
                        <TR>
                            <TD className="text-sm font-semibold" colSpan={2}>
                                Total · {lines.length} employee(s)
                            </TD>
                            <TD className="text-right text-sm font-semibold tabular-nums">
                                {totals.days_worked}
                            </TD>
                            <TD className="text-right text-sm font-semibold tabular-nums">
                                {totals.hours_worked.toFixed(2)}
                            </TD>
                            <TD />
                            <TD />
                            <TD className="text-right text-sm font-semibold tabular-nums">
                                {totals.absent_days}
                            </TD>
                            <TD className="text-right text-sm font-semibold tabular-nums">
                                {totals.overtime_hours}
                            </TD>
                        </TR>
                    </TFoot>
                </Table>

                <div className="grid gap-6 border-t border-border p-5 sm:grid-cols-2">
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Prepared by (PrimePower)
                        </p>
                        <p className="mt-6 border-t border-border pt-1 text-sm text-foreground">
                            {timesheet.prepared_by ?? ''}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Confirmed by (client representative)
                        </p>
                        <p className="mt-6 border-t border-border pt-1 text-sm text-foreground">
                            {timesheet.status === 'confirmed'
                                ? `${timesheet.confirmed_by_name} · ${formatDate(timesheet.confirmed_at)}`
                                : ''}
                        </p>
                    </div>
                </div>
            </Card>

            <Modal
                show={answering}
                onClose={() => setAnswering(false)}
                title="Record the client's answer"
                description="Type the name of the client representative who signed or replied."
            >
                <div className="space-y-4">
                    <Field
                        label="Client representative"
                        required
                        error={form.errors.confirmed_by_name}
                    >
                        {({ id }) => (
                            <Input
                                id={id}
                                value={form.data.confirmed_by_name}
                                onChange={(event) =>
                                    form.setData('confirmed_by_name', event.target.value)
                                }
                                placeholder={client?.contact_person ?? ''}
                                required
                            />
                        )}
                    </Field>
                    <Field
                        label="Client remarks"
                        hint="Required for a dispute"
                        error={form.errors.client_remarks}
                    >
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={3}
                                value={form.data.client_remarks}
                                onChange={(event) =>
                                    form.setData('client_remarks', event.target.value)
                                }
                            />
                        )}
                    </Field>
                    <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setAnswering(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            loading={form.processing}
                            onClick={() => submit('dispute')}
                        >
                            Disputed
                        </Button>
                        <Button
                            type="button"
                            loading={form.processing}
                            onClick={() => submit('confirm')}
                        >
                            Confirmed
                        </Button>
                    </div>
                </div>
            </Modal>
        </AppLayout>
    );
}
