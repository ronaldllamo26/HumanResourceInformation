import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    AlarmClock,
    CalendarX,
    CircleAlert,
    ClipboardList,
    FilePenLine,
    Lock,
    Plus,
    Upload,
    UserCheck,
} from 'lucide-react';

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
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { formatDate, withFilters } from '@/lib/utils';
import { DayStatus, minutes, TIMEKEEPING_CRUMBS } from './Partials/shared';

const BLANK = { employee_id: '', work_date: '', time_in: '', time_out: '', remarks: '' };

export default function Records({ logs, filters, summary, statuses, employees, can }) {
    const [editing, setEditing] = useState(null);
    const [importing, setImporting] = useState(false);
    const form = useForm(BLANK);
    const upload = useForm({ file: null });

    const apply = (changes) =>
        router.get(
            withFilters('/hr/timekeeping', filters, { ...changes, page: undefined }),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );

    const openNew = () => {
        form.clearErrors();
        form.setData({ ...BLANK, work_date: new Date().toISOString().slice(0, 10) });
        setEditing('new');
    };

    const openEdit = (log) => {
        form.clearErrors();
        form.setData({
            employee_id: log.employee.id,
            work_date: log.work_date,
            time_in: log.time_in ?? '',
            time_out: log.time_out ?? '',
            remarks: log.remarks ?? '',
        });
        setEditing(log);
    };

    const close = () => setEditing(null);

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: close };

        if (editing === 'new') {
            form.post('/hr/timekeeping/records', options);
        } else {
            form.put(`/hr/timekeeping/records/${editing.id}`, options);
        }
    };

    const remove = (log) => {
        if (
            !window.confirm(
                `Delete ${log.employee.name}'s record for ${formatDate(log.work_date)}?`,
            )
        ) {
            return;
        }

        router.delete(`/hr/timekeeping/records/${log.id}`, { preserveScroll: true });
    };

    const submitImport = (event) => {
        event.preventDefault();
        upload.post('/hr/timekeeping/records/import', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                upload.reset();
                setImporting(false);
            },
        });
    };

    const rows = logs.data ?? [];
    const colSpan = 8;


    return (
        <AppLayout
            title="Daily Time Records"
            breadcrumbs={[...TIMEKEEPING_CRUMBS, { label: 'Daily Time Records' }]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Days worked"
                    value={summary.worked}
                    icon={UserCheck}
                    tone={summary.worked > 0 ? 'success' : 'muted'}
                    hint={`${summary.hours} hours on the job`}
                />
                <StatCard
                    label="Late days"
                    value={summary.late}
                    icon={AlarmClock}
                    tone={summary.late > 0 ? 'warning' : 'muted'}
                    hint="past the grace period"
                    href={withFilters('/hr/timekeeping', filters, { status: 'late' })}
                />
                <StatCard
                    label="Absences"
                    value={summary.absent}
                    icon={CalendarX}
                    tone={summary.absent > 0 ? 'destructive' : 'muted'}
                    hint="working days with no punch"
                    href={withFilters('/hr/timekeeping', filters, { status: 'absent' })}
                />
                <StatCard
                    label="No time-out"
                    value={summary.incomplete}
                    icon={CircleAlert}
                    tone={summary.incomplete > 0 ? 'destructive' : 'muted'}
                    hint="blocks payroll until fixed"
                    href={withFilters('/hr/timekeeping', filters, { status: 'incomplete' })}
                />
            </div>

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:flex-wrap lg:items-end">
                    <Field label="From" className="lg:w-40">
                        {({ id }) => (
                            <DateInput
                                id={id}
                                value={filters.from}
                                onChange={(event) => apply({ from: event.target.value })}
                            />
                        )}
                    </Field>
                    <Field label="To" className="lg:w-40">
                        {({ id }) => (
                            <DateInput
                                id={id}
                                value={filters.to}
                                onChange={(event) => apply({ to: event.target.value })}
                            />
                        )}
                    </Field>
                    {employees.length > 1 && (
                        <Field label="Employee" className="lg:w-64">
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={filters.employee ?? ''}
                                    onChange={(event) =>
                                        apply({ employee: event.target.value })
                                    }
                                    placeholder="Everyone"
                                    options={employees}
                                />
                            )}
                        </Field>
                    )}
                    <Field label="Status" className="lg:w-44">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.status ?? ''}
                                onChange={(event) => apply({ status: event.target.value })}
                                placeholder="All statuses"
                                options={statuses}
                            />
                        )}
                    </Field>

                    <div className="flex flex-wrap gap-2 lg:ml-auto">
                        {can.fileCorrection && !can.manage && (
                            <Button variant="outline" href="/hr/timekeeping/corrections">
                                <FilePenLine className="h-4 w-4" />
                                Request a correction
                            </Button>
                        )}
                        {can.manage && (
                            <>
                                <Button variant="outline" onClick={() => setImporting(true)}>
                                    <Upload className="h-4 w-4" />
                                    Import CSV
                                </Button>
                                <Button onClick={openNew}>
                                    <Plus className="h-4 w-4" />
                                    Add Record
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Date</TH>
                            <TH>Employee</TH>
                            <TH>In / Out</TH>
                            <TH>Status</TH>
                            <TH className="text-right">Hours</TH>
                            <TH className="text-right">Late</TH>
                            <TH className="text-right">Undertime</TH>
                            <TH className="text-right">Past shift</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={colSpan}
                                icon={ClipboardList}
                                title="No time records in this range"
                                description="Records appear here once they are entered, imported from the biometric device, or corrected."
                            />
                        ) : (
                            rows.map((log) => (
                                <TR key={log.id}>
                                    <TD className="whitespace-nowrap">
                                        <p className="text-sm font-medium text-foreground">
                                            {formatDate(log.work_date)}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {log.day}
                                            {log.shift ? ` · ${log.shift}` : ''}
                                        </p>
                                    </TD>
                                    <TD>
                                        <p className="text-sm text-foreground">
                                            {log.employee.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {log.employee.number}
                                        </p>
                                    </TD>
                                    <TD className="whitespace-nowrap text-sm tabular-nums text-foreground">
                                        {log.time_in ?? '—'} – {log.time_out ?? '—'}
                                        {log.next_day_out && (
                                            <span className="ml-1 text-xs text-muted-foreground">
                                                +1
                                            </span>
                                        )}
                                    </TD>
                                    <TD>
                                        <div className="flex items-center gap-1.5">
                                            <DayStatus status={log.status} />
                                            {log.locked && (
                                                <Lock
                                                    className="h-3.5 w-3.5 text-muted-foreground"
                                                    aria-label="Cutoff closed"
                                                />
                                            )}
                                        </div>
                                        {log.remarks && (
                                            <p className="mt-1 max-w-56 truncate text-xs text-muted-foreground">
                                                {log.remarks}
                                            </p>
                                        )}
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {log.hours ? log.hours.toFixed(2) : '—'}
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {minutes(log.late_minutes)}
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {minutes(log.undertime_minutes)}
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {minutes(log.overtime_minutes)}
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>

                <Pagination links={logs.links ?? []} meta={logs} />
            </Card>

            <Modal
                show={editing !== null}
                onClose={close}
                title={editing === 'new' ? 'Add Time Record' : 'Edit Time Record'}
            >
                <form onSubmit={submit} className="space-y-4">
                    {editing === 'new' ? (
                        <Field label="Employee" required error={form.errors.employee_id}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={form.data.employee_id}
                                    onChange={(event) =>
                                        form.setData('employee_id', event.target.value)
                                    }
                                    placeholder="Choose an employee"
                                    options={employees}
                                    required
                                />
                            )}
                        </Field>
                    ) : (
                        editing && (
                            <p className="text-sm text-foreground">
                                {editing.employee.name} · {formatDate(editing.work_date)}
                            </p>
                        )
                    )}

                    {editing === 'new' && (
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
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Time in"
                            error={form.errors.time_in}
                            hint="Blank for an absence, rest day or holiday"
                        >
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
                        <Field
                            label="Time out"
                            error={form.errors.time_out}
                            hint="Earlier than time in = next morning"
                        >
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

                    <Field label="Remarks" error={form.errors.remarks}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={form.data.remarks}
                                onChange={(event) =>
                                    form.setData('remarks', event.target.value)
                                }
                                placeholder="e.g. Keyed from the paper logbook"
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            Save Record
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal
                show={importing}
                onClose={() => setImporting(false)}
                title="Import from the biometric device"
                description="A CSV with the columns employee_number, date, time_in, time_out. Rows inside a closed cutoff or for an unknown employee are skipped and named."
            >
                <form onSubmit={submitImport} className="space-y-4">
                    <Field label="CSV file" required error={upload.errors.file}>
                        {({ id }) => (
                            <Input
                                id={id}
                                type="file"
                                accept=".csv,text/csv"
                                onChange={(event) =>
                                    upload.setData('file', event.target.files[0])
                                }
                                required
                            />
                        )}
                    </Field>

                    <pre className="overflow-x-auto rounded-md bg-secondary/50 p-3 text-xs text-muted-foreground">
                        {
                            'employee_number,date,time_in,time_out\nEMP-0001,2026-09-01,07:58,17:04'
                        }
                    </pre>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setImporting(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" loading={upload.processing}>
                            Import
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
