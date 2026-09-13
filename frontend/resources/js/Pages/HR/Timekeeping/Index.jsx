import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    CalendarX,
    Clock,
    Download,
    Plus,
    Timer,
    TriangleAlert,
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
    MeterCard,
    SplitStatCard,
    StatCard,
    Table,
    TableEmpty,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { initials } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

/** 95 -> "1h 35m"; 0 stays a dash so the table reads as mostly empty. */
const duration = (minutes) => {
    if (!minutes) return '—';

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return hours > 0 ? `${hours}h ${rest}m` : `${rest}m`;
};

/**
 * 1296.18 -> "1,296"; 69.63 -> "69.6".
 *
 * The stored figures carry two decimals because they are sums of minutes
 * divided by 60, not because anyone needs hundredths of an hour. Below ten
 * the first decimal still says something — 8.5 hours is half a shift — and
 * above it the thousands separator does more for legibility than any digit
 * to the right of the point.
 */
const hours = (value) => {
    const number = Number(value) || 0;

    return number >= 100
        ? Math.round(number).toLocaleString()
        : number.toFixed(1).replace(/\.0$/, '');
};

const BLANK_ENTRY = {
    employee_id: '',
    log_date: '',
    shift_id: '',
    time_in: '',
    break_out: '',
    break_in: '',
    time_out: '',
    status: '',
    remarks: '',
};

const iso = (date) => date.toISOString().slice(0, 10);

/**
 * The cutoffs the screen is actually read by: a whole month, or either half
 * of it.
 *
 * Presets rather than a period dropdown, because the range is already two
 * date inputs — a third control naming the same thing would be a second
 * source of truth for what "this cutoff" means. These only move the two dates
 * that were already there, off whichever month is on screen.
 */
const PRESETS = [
    {
        key: 'month',
        label: 'Whole month',
        range: (base) => [
            new Date(Date.UTC(base.getUTCFullYear(), base.getUTCMonth(), 1)),
            new Date(Date.UTC(base.getUTCFullYear(), base.getUTCMonth() + 1, 0)),
        ],
    },
    {
        key: 'first',
        label: '1–15',
        range: (base) => [
            new Date(Date.UTC(base.getUTCFullYear(), base.getUTCMonth(), 1)),
            new Date(Date.UTC(base.getUTCFullYear(), base.getUTCMonth(), 15)),
        ],
    },
    {
        key: 'second',
        label: '16–end',
        range: (base) => [
            new Date(Date.UTC(base.getUTCFullYear(), base.getUTCMonth(), 16)),
            new Date(Date.UTC(base.getUTCFullYear(), base.getUTCMonth() + 1, 0)),
        ],
    },
];

export default function Index({ rows, summary, filters, shifts, employees, statuses, can }) {
    const [entryOpen, setEntryOpen] = useState(false);
    const [importOpen, setImportOpen] = useState(false);

    const entry = useForm(BLANK_ENTRY);
    const upload = useForm({ file: null });

    // Per-row import problems, flashed back after the batch runs.
    const importErrors = usePage().props.importErrors ?? [];

    const submitImport = (event) => {
        event.preventDefault();

        upload.post('/hr/timekeeping/import', {
            preserveScroll: true,
            onSuccess: () => {
                upload.reset();
                setImportOpen(false);
            },
        });
    };

    const submitEntry = (event) => {
        event.preventDefault();

        entry.post('/hr/timekeeping', {
            preserveScroll: true,
            onSuccess: () => {
                entry.reset();
                setEntryOpen(false);
            },
        });
    };

    const applyFilter = (changes) =>
        router.get(
            '/hr/timekeeping',
            { ...filters, ...changes },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const applyPreset = (preset) => {
        const [start, end] = preset.range(new Date(`${filters.from}T00:00:00Z`));

        applyFilter({ from: iso(start), to: iso(end) });
    };

    /*
     * A row opens that person's own screen, carrying the range with it.
     *
     * It was an accordion first, and the days did not fit: they are a
     * different unit from the summary line, they want a Monday-to-Sunday grid,
     * and unfolding one inside a paginated table puts somebody's fortnight in
     * a strip four columns wide.
     */
    const open = (employeeId) =>
        router.get(`/hr/timekeeping/employee/${employeeId}`, {
            from: filters.from,
            to: filters.to,
        });

    const list = rows.data ?? [];
    const meta = rows.meta ?? {};

    const exportHref = `/hr/timekeeping/export?from=${filters.from}&to=${filters.to}`;

    // 157 of 205 is the reading that means something; 157 on its own is a
    // number whose scale the reader has to go and find.
    const attendanceRate = summary.records > 0 ? (summary.present / summary.records) * 100 : 0;

    return (
        <AppLayout
            title="Records"
            breadcrumbs={[{ label: 'Human Resource' }, { label: 'Records' }]}
        >
            {/*
             * The tiles no longer link anywhere, and that is not a regression.
             * They used to open the rows they counted because those rows were
             * days buried in a paginated list. Present and Absences are now
             * the totals of two columns of the table directly beneath them,
             * and every row opens to the days behind it — the figure can show
             * its own rows without going anywhere.
             */}
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MeterCard
                    label="Days Present"
                    value={summary.present}
                    percent={attendanceRate}
                    badge={`${Math.round(attendanceRate)}%`}
                    icon={UserCheck}
                    tone={attendanceRate >= 90 ? 'success' : 'warning'}
                    iconTone="success"
                    hint={`of ${summary.records} record(s) in range`}
                />

                {/* Warning, and grey at zero — "0 absent" in amber reads as a
                    problem when it is the opposite. */}
                <StatCard
                    label="Absences"
                    value={summary.absent}
                    icon={CalendarX}
                    tone={summary.absent > 0 ? 'warning' : 'muted'}
                    hint={
                        summary.records > 0
                            ? `${((summary.absent / summary.records) * 100).toFixed(1)}% of the range`
                            : undefined
                    }
                />

                <StatCard
                    label="Late Instances"
                    value={summary.late}
                    icon={TriangleAlert}
                    tone={summary.late > 0 ? 'warning' : 'muted'}
                    hint={`${duration(summary.late_minutes)} lost in total`}
                />

                {/* Two figures that only mean something beside each other —
                    70 overtime hours is a different story against 1,296 worked
                    than against 200. */}
                <SplitStatCard
                    label="Hours Worked"
                    icon={Timer}
                    tone="info"
                    stats={[
                        { label: 'Total', value: hours(summary.total_hours) },
                        {
                            label: 'Overtime',
                            value: hours(summary.overtime_hours),
                            tone: 'warning',
                        },
                    ]}
                />
            </div>

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:flex-wrap lg:items-end">
                    <Field label="From" className="w-full sm:w-40">
                        {({ id }) => (
                            <DateInput
                                id={id}
                                value={filters.from ?? ''}
                                onChange={(event) => applyFilter({ from: event.target.value })}
                            />
                        )}
                    </Field>

                    <Field label="To" className="w-full sm:w-40">
                        {({ id }) => (
                            <DateInput
                                id={id}
                                value={filters.to ?? ''}
                                onChange={(event) => applyFilter({ to: event.target.value })}
                            />
                        )}
                    </Field>

                    <div className="flex flex-wrap gap-2 self-end">
                        {PRESETS.map((preset) => (
                            <Button
                                key={preset.key}
                                variant="outline"
                                size="sm"
                                className="h-9"
                                onClick={() => applyPreset(preset)}
                            >
                                {preset.label}
                            </Button>
                        ))}
                    </div>

                    {/* Actions end the filter row: `lg:ml-auto` pushes them
                        right of the last control, and the row's `items-end`
                        lines them up with the inputs rather than the labels. */}
                    <div className="flex flex-wrap gap-2 lg:ml-auto">
                        <Button variant="outline" href={exportHref} external>
                            <Download className="h-4 w-4" />
                            <span className="hidden sm:inline">Export</span>
                        </Button>

                        {can.manage && (
                            <>
                                <Button variant="outline" onClick={() => setImportOpen(true)}>
                                    <Upload className="h-4 w-4" />
                                    <span className="hidden sm:inline">Import</span>
                                </Button>
                                <Button onClick={() => setEntryOpen(true)}>
                                    <Plus className="h-4 w-4" />
                                    Record Time
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Assignment</TH>
                            <TH className="text-right">Days In</TH>
                            <TH className="text-right">Absent</TH>
                            <TH className="text-right">Late</TH>
                            <TH className="text-right">UT</TH>
                            <TH className="text-right">OT</TH>
                            <TH className="text-right">Hours</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {list.length === 0 ? (
                            <TableEmpty
                                colSpan={8}
                                icon={Clock}
                                title="Nobody to show"
                                description="Widen the date range, or record a time entry."
                            />
                        ) : (
                            list.map((row) => (
                                <TR
                                    key={row.employee_id}
                                    clickable
                                    onClick={() => open(row.employee_id)}
                                >
                                    <TD>
                                        <div className="flex items-center gap-2.5">
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                {initials(row.full_name)}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {row.full_name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {row.employee_number}
                                                </p>
                                            </div>
                                        </div>
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {row.client ?? row.department ?? '—'}
                                    </TD>

                                    {/* The figure the screen exists for, so it
                                        carries the weight the others do not.
                                        Zero is muted rather than red: somebody
                                        may simply not have been scheduled. */}
                                    <TD className="text-right text-sm font-semibold tabular-nums">
                                        <span
                                            className={
                                                row.days_present
                                                    ? 'text-foreground'
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            {row.days_present}
                                        </span>
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums">
                                        <span
                                            className={
                                                row.days_absent
                                                    ? 'text-warning'
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            {row.days_absent || '—'}
                                        </span>
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums">
                                        <span
                                            className={
                                                row.late_count
                                                    ? 'text-destructive'
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            {row.late_count || '—'}
                                        </span>
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {duration(row.undertime_minutes)}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums">
                                        <span
                                            className={
                                                row.overtime_hours
                                                    ? 'text-success'
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            {row.overtime_hours
                                                ? hours(row.overtime_hours)
                                                : '—'}
                                        </span>
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-foreground">
                                        {row.total_hours ? hours(row.total_hours) : '—'}
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>

                <Pagination links={meta.links ?? []} meta={meta} />
            </Card>

            {/* Manual time entry */}
            <Modal
                show={entryOpen}
                onClose={() => setEntryOpen(false)}
                title="Record Time Entry"
                description="Late, undertime, overtime, and night differential are computed from the shift."
                maxWidth="2xl"
            >
                <form onSubmit={submitEntry} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Employee" required error={entry.errors.employee_id}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={entry.data.employee_id}
                                    onChange={(event) =>
                                        entry.setData('employee_id', event.target.value)
                                    }
                                    placeholder="Select employee"
                                    error={entry.errors.employee_id}
                                    options={employees.map((employee) => ({
                                        value: employee.id,
                                        label: employee.full_name,
                                    }))}
                                />
                            )}
                        </Field>

                        <Field label="Date" required error={entry.errors.log_date}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={entry.data.log_date}
                                    onChange={(event) =>
                                        entry.setData('log_date', event.target.value)
                                    }
                                    error={entry.errors.log_date}
                                />
                            )}
                        </Field>
                    </div>

                    <Field
                        label="Shift"
                        hint="Leave blank to use the employee's assigned schedule."
                        error={entry.errors.shift_id}
                    >
                        {({ id }) => (
                            <Select
                                id={id}
                                value={entry.data.shift_id}
                                onChange={(event) =>
                                    entry.setData('shift_id', event.target.value)
                                }
                                placeholder="From schedule"
                                options={shifts.map((shift) => ({
                                    value: shift.id,
                                    label: `${shift.name} (${String(shift.start_time).slice(0, 5)}–${String(shift.end_time).slice(0, 5)})`,
                                }))}
                            />
                        )}
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-4">
                        {[
                            ['time_in', 'Time In'],
                            ['break_out', 'Break Out'],
                            ['break_in', 'Break In'],
                            ['time_out', 'Time Out'],
                        ].map(([field, label]) => (
                            <Field key={field} label={label} error={entry.errors[field]}>
                                {({ id }) => (
                                    <Input
                                        id={id}
                                        type="time"
                                        value={entry.data[field]}
                                        onChange={(event) =>
                                            entry.setData(field, event.target.value)
                                        }
                                        error={entry.errors[field]}
                                    />
                                )}
                            </Field>
                        ))}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Status Override"
                            hint="Leave blank to derive from the punches."
                            error={entry.errors.status}
                        >
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={entry.data.status}
                                    onChange={(event) =>
                                        entry.setData('status', event.target.value)
                                    }
                                    placeholder="Derive automatically"
                                    options={statuses.map((status) => ({
                                        value: status,
                                        label: titleCase(status),
                                    }))}
                                />
                            )}
                        </Field>

                        <Field label="Remarks" error={entry.errors.remarks}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={entry.data.remarks}
                                    onChange={(event) =>
                                        entry.setData('remarks', event.target.value)
                                    }
                                />
                            )}
                        </Field>
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setEntryOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={entry.processing}>
                            Save Entry
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Biometric / CSV import */}
            <Modal
                show={importOpen}
                onClose={() => setImportOpen(false)}
                title="Import Time Records"
                description="Upload a biometric device export. Each row is computed exactly like a hand-keyed entry."
                maxWidth="lg"
            >
                <form onSubmit={submitImport} className="space-y-4">
                    <div className="rounded-lg border border-border bg-secondary/40 p-4">
                        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Expected columns
                        </p>
                        <code className="scrollbar-thin block overflow-x-auto whitespace-pre text-xs text-foreground">
                            employee_number,date,time_in,time_out{'\n'}
                            PPM-2026-0001,2026-08-01,08:00,17:00
                        </code>
                        <p className="mt-2 text-xs text-muted-foreground">
                            <span className="font-medium text-foreground">employee_number</span>{' '}
                            and <span className="font-medium text-foreground">date</span> are
                            required. <code>break_out</code>, <code>break_in</code>, and{' '}
                            <code>device_id</code> are optional. A bad row is skipped and
                            reported — the rest of the file still imports.
                        </p>
                    </div>

                    <Field label="CSV File" required hint="Max 5 MB" error={upload.errors.file}>
                        {({ id }) => (
                            <input
                                id={id}
                                type="file"
                                accept=".csv,text/csv"
                                onChange={(event) =>
                                    upload.setData('file', event.target.files[0] ?? null)
                                }
                                className="block w-full text-xs text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-xs file:font-medium file:text-secondary-foreground hover:file:bg-secondary/70"
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setImportOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={upload.processing}>
                            Import
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Rows the importer could not read */}
            {importErrors.length > 0 && (
                <Card className="mt-5 border-destructive/30">
                    <div className="px-5 py-4">
                        <p className="mb-2 text-sm font-semibold text-destructive">
                            Skipped rows from the last import
                        </p>
                        <ul className="space-y-1">
                            {importErrors.map((message, index) => (
                                <li key={index} className="text-xs text-muted-foreground">
                                    {message}
                                </li>
                            ))}
                        </ul>
                    </div>
                </Card>
            )}
        </AppLayout>
    );
}
