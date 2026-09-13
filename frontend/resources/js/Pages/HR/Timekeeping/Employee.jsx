import { router } from '@inertiajs/react';
import { ArrowLeft, CalendarX, Clock, Timer, TriangleAlert, UserCheck } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    DateInput,
    Field,
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
import { cn, formatDate, initials } from '@/lib/utils';

/** 95 -> "1h 35m"; 0 stays a dash so the table reads as mostly empty. */
const duration = (minutes) => {
    if (!minutes) return '—';

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return hours > 0 ? `${hours}h ${rest}m` : `${rest}m`;
};

const hours = (value) => {
    const number = Number(value) || 0;

    return number >= 100
        ? Math.round(number).toLocaleString()
        : number.toFixed(1).replace(/\.0$/, '');
};

/**
 * Monday first, because the working week does.
 *
 * The server builds every row from the Monday on or before the range, so these
 * seven headings line up with the seven cells whatever day the cutoff starts
 * on — a 16th that falls on a Wednesday still sits in the Wednesday column.
 */
const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/*
 * Tone is valence, as everywhere else: absent is the thing somebody has to
 * chase, late and undertime are worth a look, a rest day is neither. Matches
 * the Period DTR sheet, so one status does not read two ways across the
 * module.
 */
const TONES = {
    present: 'border-success/30 bg-success/10 text-success',
    late: 'border-warning/30 bg-warning/10 text-warning',
    undertime: 'border-warning/30 bg-warning/10 text-warning',
    absent: 'border-destructive/30 bg-destructive/10 text-destructive',
    on_leave: 'border-info/30 bg-info/10 text-info',
    holiday: 'border-info/30 bg-info/10 text-info',
    rest_day: 'border-border bg-muted text-muted-foreground',
};

const LABELS = {
    present: 'Present',
    late: 'Late',
    undertime: 'Undertime',
    absent: 'Absent',
    on_leave: 'Leave',
    holiday: 'Holiday',
    rest_day: 'Rest',
};

const iso = (date) => date.toISOString().slice(0, 10);

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

export default function EmployeeRecord({ employee, filters, weeks, days, summary, can }) {
    const rows = days.data ?? days ?? [];

    const apply = (changes) =>
        router.get(
            `/hr/timekeeping/employee/${employee.id}`,
            { ...filters, ...changes },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const applyPreset = (preset) => {
        const [start, end] = preset.range(new Date(`${filters.from}T00:00:00Z`));

        apply({ from: iso(start), to: iso(end) });
    };

    const attendanceRate = summary.records > 0 ? (summary.present / summary.records) * 100 : 0;

    // The range carries back, so returning lands on the cutoff you left.
    const backHref = `/hr/timekeeping?from=${filters.from}&to=${filters.to}`;

    return (
        <AppLayout
            title={employee.full_name}
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Records', href: backHref },
                { label: employee.full_name },
            ]}
        >
            <div className="space-y-4">
                <Card>
                    <CardBody className="flex flex-col gap-4 sm:flex-row sm:items-center">
                        <span className="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                            {initials(employee.full_name)}
                        </span>

                        <div className="min-w-0 flex-1">
                            <p className="truncate text-base font-semibold text-foreground">
                                {employee.full_name}
                            </p>
                            <p className="truncate text-sm text-muted-foreground">
                                {[
                                    employee.employee_number,
                                    employee.position,
                                    employee.client ?? employee.department,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </div>

                        <Button variant="outline" href={backHref} className="sm:ml-auto">
                            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
                            <span className="hidden sm:inline">Back to Records</span>
                        </Button>
                    </CardBody>
                </Card>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <MeterCard
                        label="Days In"
                        value={summary.present}
                        percent={attendanceRate}
                        badge={`${Math.round(attendanceRate)}%`}
                        icon={UserCheck}
                        tone={attendanceRate >= 90 ? 'success' : 'warning'}
                        iconTone="success"
                        hint={`of ${summary.records} day(s) recorded`}
                    />
                    <StatCard
                        label="Absences"
                        value={summary.absent}
                        icon={CalendarX}
                        tone={summary.absent > 0 ? 'warning' : 'muted'}
                    />
                    <StatCard
                        label="Late Days"
                        value={summary.late}
                        icon={TriangleAlert}
                        tone={summary.late > 0 ? 'warning' : 'muted'}
                        hint={`${duration(summary.late_minutes)} lost in total`}
                    />
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
                    <CardHeader
                        title="Attendance calendar"
                        description="Monday to Sunday. Days outside the cutoff are shown greyed rather than dropped, so a week that starts mid-cutoff is still a week."
                        action={
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <div className="flex gap-2">
                                    <Field label="From" className="w-full sm:w-36">
                                        {({ id }) => (
                                            <DateInput
                                                id={id}
                                                value={filters.from ?? ''}
                                                onChange={(event) =>
                                                    apply({ from: event.target.value })
                                                }
                                            />
                                        )}
                                    </Field>
                                    <Field label="To" className="w-full sm:w-36">
                                        {({ id }) => (
                                            <DateInput
                                                id={id}
                                                value={filters.to ?? ''}
                                                onChange={(event) =>
                                                    apply({ to: event.target.value })
                                                }
                                            />
                                        )}
                                    </Field>
                                </div>

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
                            </div>
                        }
                    />

                    <CardBody className="space-y-3">
                        {/*
                         * Seven columns is seven columns: the grid scrolls
                         * sideways rather than shrinking a day to a width no
                         * status fits in. The negative margin lets it run to
                         * the card's edge instead of stopping inside its
                         * padding, the same as the leave calendar.
                         */}
                        <div className="-mx-4 overflow-x-auto px-4 sm:-mx-6 sm:px-6">
                            {/* Seven day columns plus a total, which is the
                                figure a rest-day rule and an overtime pattern
                                are both read against and which no other screen
                                answers. */}
                            <div className="min-w-[660px]">
                                <div className="grid grid-cols-[repeat(7,1fr)_84px] gap-1.5 pb-1.5">
                                    {WEEKDAYS.map((weekday) => (
                                        <div
                                            key={weekday}
                                            className="text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"
                                        >
                                            {weekday}
                                        </div>
                                    ))}
                                    <div className="text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                        Week
                                    </div>
                                </div>

                                <div className="space-y-1.5">
                                    {weeks.map((week) => (
                                        <div
                                            key={week.starts_on}
                                            className="grid grid-cols-[repeat(7,1fr)_84px] gap-1.5"
                                        >
                                            {week.days.map((day) => (
                                                <CalendarDay key={day.date} day={day} />
                                            ))}

                                            <div className="flex min-h-[62px] flex-col justify-center rounded-md border border-border bg-secondary/40 p-1.5 text-center">
                                                <span className="text-sm font-semibold tabular-nums text-foreground">
                                                    {hours(week.hours_worked)}h
                                                </span>
                                                <span className="text-[10px] text-muted-foreground">
                                                    {week.days_present} day
                                                    {week.days_present === 1 ? '' : 's'}
                                                </span>
                                                {week.overtime_minutes > 0 && (
                                                    <span className="text-[10px] tabular-nums text-success">
                                                        +{duration(week.overtime_minutes)} OT
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            {Object.entries(LABELS).map(([status, label]) => (
                                <span key={status} className="inline-flex items-center gap-1.5">
                                    <span
                                        className={cn(
                                            'inline-block h-3 w-3 rounded-sm border',
                                            TONES[status],
                                        )}
                                    />
                                    {label}
                                </span>
                            ))}
                        </div>
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader
                        title="Day by day"
                        description="Every recorded day in the range, with the punches the figures were derived from."
                    />

                    <Table>
                        <THead>
                            <TR>
                                <TH>Date</TH>
                                <TH>Shift</TH>
                                <TH>In</TH>
                                <TH>Out</TH>
                                <TH className="text-right">Hours</TH>
                                <TH className="text-right">Late</TH>
                                <TH className="text-right">UT</TH>
                                <TH className="text-right">OT</TH>
                                <TH>Status</TH>
                                {can.manage && <TH className="text-right">Actions</TH>}
                            </TR>
                        </THead>

                        <TBody>
                            {rows.length === 0 ? (
                                <TableEmpty
                                    colSpan={can.manage ? 10 : 9}
                                    icon={Clock}
                                    title="No days recorded in this range"
                                    description="Encode the cutoff on Period DTR, or record a single day on Records."
                                />
                            ) : (
                                rows.map((log) => (
                                    <TR key={log.id}>
                                        <TD className="whitespace-nowrap text-sm text-foreground">
                                            {formatDate(log.log_date)}
                                        </TD>
                                        <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                            {log.shift?.name ?? '—'}
                                        </TD>
                                        <TD className="whitespace-nowrap text-sm tabular-nums text-foreground">
                                            {log.time_in ?? '—'}
                                        </TD>
                                        <TD className="whitespace-nowrap text-sm tabular-nums text-foreground">
                                            {log.time_out ?? '—'}
                                        </TD>
                                        <TD className="text-right text-sm tabular-nums text-foreground">
                                            {log.hours_worked
                                                ? log.hours_worked.toFixed(2)
                                                : '—'}
                                        </TD>
                                        <TD className="text-right text-sm tabular-nums">
                                            <span
                                                className={
                                                    log.late_minutes
                                                        ? 'text-destructive'
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                {duration(log.late_minutes)}
                                            </span>
                                        </TD>
                                        <TD className="text-right text-sm tabular-nums">
                                            <span
                                                className={
                                                    log.undertime_minutes
                                                        ? 'text-warning'
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                {duration(log.undertime_minutes)}
                                            </span>
                                        </TD>
                                        <TD className="text-right text-sm tabular-nums">
                                            <span
                                                className={
                                                    log.overtime_minutes
                                                        ? 'text-success'
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                {duration(log.overtime_minutes)}
                                            </span>
                                        </TD>
                                        <TD>
                                            <Badge status={log.status} />
                                        </TD>
                                        {can.manage && (
                                            <TD className="text-right">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        router.delete(
                                                            `/hr/timekeeping/${log.id}`,
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    Delete
                                                </Button>
                                            </TD>
                                        )}
                                    </TR>
                                ))
                            )}
                        </TBody>
                    </Table>
                </Card>
            </div>
        </AppLayout>
    );
}

/**
 * One square. A day with no record inside the cutoff is left blank rather than
 * called absent — nothing recorded is not the same claim as "did not come in",
 * and the DTR sheet is where the difference gets settled.
 */
function CalendarDay({ day }) {
    const outside = !day.in_range;

    return (
        <div
            title={day.holiday ?? undefined}
            className={cn(
                'min-h-[62px] rounded-md border p-1.5 text-left',
                outside
                    ? 'border-dashed border-border/60 bg-transparent opacity-40'
                    : (TONES[day.status] ?? 'border-border bg-card'),
            )}
        >
            <div className="flex items-baseline justify-between gap-1">
                <span className="text-xs font-semibold">{day.day}</span>
                {day.hours_worked ? (
                    <span className="text-[10px] tabular-nums opacity-80">
                        {day.hours_worked.toFixed(1)}h
                    </span>
                ) : null}
            </div>

            {!outside && day.status && (
                <p className="mt-0.5 truncate text-[10px] font-medium">
                    {LABELS[day.status] ?? day.status}
                </p>
            )}

            {!outside && day.time_in && (
                <p className="truncate text-[10px] tabular-nums opacity-70">
                    {day.time_in}
                    {day.time_out ? `–${day.time_out}` : ''}
                </p>
            )}

            {!outside && day.holiday && !day.status && (
                <p className="mt-0.5 truncate text-[10px] font-medium text-info">
                    {day.holiday}
                </p>
            )}
        </div>
    );
}
