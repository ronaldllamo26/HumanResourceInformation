import { router, useForm } from '@inertiajs/react';
import { memo, useCallback, useMemo, useRef, useState } from 'react';
import {
    CalendarRange,
    ClipboardList,
    Lock,
    Save,
    TriangleAlert,
    Users,
    Wand2,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Button,
    Card,
    CardBody,
    CardHeader,
    MeterCard,
    SearchInput,
    Select,
    StatCard,
} from '@/Components/ui';
import { cn, formatDate } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

/**
 * Short codes for the grid.
 *
 * Derived from the statuses the server sends rather than hard-coded, so a
 * status added to AttendanceLog::STATUSES appears here instead of silently
 * dropping out of the sheet. Anything unmapped falls back to its first letter.
 */
const CODES = {
    present: 'P',
    absent: 'A',
    late: 'L',
    undertime: 'U',
    on_leave: 'LV',
    holiday: 'H',
    rest_day: 'R',
};

/*
 * Tone is valence, as everywhere else: absent is the thing somebody has to
 * chase, late and undertime are worth a look, a rest day is neither.
 */
const TONES = {
    present: 'bg-success/15 text-success',
    absent: 'bg-destructive/15 text-destructive',
    late: 'bg-warning/20 text-warning-foreground',
    undertime: 'bg-warning/20 text-warning-foreground',
    on_leave: 'bg-info/15 text-info',
    holiday: 'bg-info/15 text-info',
    rest_day: 'bg-muted text-muted-foreground',
};

const codeFor = (status) => CODES[status] ?? status?.charAt(0).toUpperCase() ?? '';

/**
 * The stored status of every cell, keyed by employee then date.
 *
 * Nested rather than flat so changing one person's day replaces only that
 * person's object — which is what lets each row memoise and keeps a forty-row
 * grid responsive while somebody types down a column.
 */
function initialState(rows) {
    const state = {};

    rows.forEach((row) => {
        state[row.employee_id] = {};

        Object.entries(row.cells).forEach(([date, cell]) => {
            state[row.employee_id][date] = cell.status ?? '';
        });
    });

    return state;
}

const SheetRow = memo(function SheetRow({
    row,
    days,
    values,
    statuses,
    today,
    editable,
    onChange,
    onFillRow,
}) {
    return (
        <tr className="border-b border-border last:border-0 hover:bg-secondary/30">
            <th
                scope="row"
                className="sticky left-0 z-10 min-w-[220px] max-w-[220px] border-r border-border bg-card px-3 py-2 text-left align-middle"
            >
                <p className="truncate text-sm font-medium text-foreground">{row.full_name}</p>
                <p className="truncate text-xs text-muted-foreground">
                    {row.employee_number}
                    {row.client
                        ? ` · ${row.client}`
                        : row.department
                          ? ` · ${row.department}`
                          : ''}
                </p>
            </th>

            {days.map((day) => {
                const cell = row.cells[day.date];
                const value = values?.[day.date] ?? '';
                const isFuture = day.date > today;

                if (cell.locked) {
                    return (
                        <td
                            key={day.date}
                            className="border-r border-border/60 p-1 text-center"
                        >
                            <span
                                className="inline-flex items-center gap-1 rounded bg-secondary px-1.5 py-1 text-[10px] font-medium text-muted-foreground"
                                title={`Clocked ${cell.time_in ?? '—'} to ${cell.time_out ?? '—'} (${cell.source}). Edit on Records.`}
                            >
                                <Lock className="h-2.5 w-2.5" aria-hidden="true" />
                                {codeFor(cell.status)}
                            </span>
                        </td>
                    );
                }

                return (
                    <td
                        key={day.date}
                        className={cn(
                            'border-r border-border/60 p-1 text-center',
                            day.holiday && 'bg-info/5',
                            !day.holiday && day.is_weekend && 'bg-secondary/40',
                        )}
                    >
                        <select
                            aria-label={`${row.full_name} — ${day.date}`}
                            title={
                                cell.suggested && !value
                                    ? `Suggested: ${titleCase(cell.suggested)}`
                                    : undefined
                            }
                            disabled={!editable || isFuture}
                            value={value}
                            onChange={(event) =>
                                onChange(row.employee_id, day.date, event.target.value)
                            }
                            className={cn(
                                'h-7 w-full min-w-[46px] cursor-pointer rounded border border-transparent',
                                'text-center text-xs font-semibold',
                                'focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring',
                                'disabled:cursor-not-allowed disabled:opacity-40',
                                value
                                    ? (TONES[value] ?? 'bg-secondary text-foreground')
                                    : cell.suggested
                                      ? 'bg-secondary/60 text-muted-foreground'
                                      : 'bg-transparent text-muted-foreground',
                            )}
                        >
                            <option value="">{cell.suggested ? '·' : '—'}</option>
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {codeFor(status)}
                                </option>
                            ))}
                        </select>
                    </td>
                );
            })}

            <td className="px-2 py-1 text-center">
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={!editable}
                    onClick={() => onFillRow(row.employee_id)}
                    title="Fill this employee's remaining blank days with Present"
                >
                    Fill
                </Button>
            </td>
        </tr>
    );
});

export default function Period({
    period,
    periods,
    days,
    rows,
    filters,
    departments,
    clients,
    statuses,
    today,
    can,
}) {
    const [values, setValues] = useState(() => initialState(rows));
    // The sheet as it arrived. Only what differs from this is submitted, so
    // opening the screen and pressing Save writes nothing at all.
    const baseline = useRef(initialState(rows));
    const form = useForm({});

    const change = useCallback((employeeId, date, status) => {
        setValues((previous) => ({
            ...previous,
            [employeeId]: { ...previous[employeeId], [date]: status },
        }));
    }, []);

    /** Every day the sheet is allowed to write: today and earlier, unlocked. */
    const openDates = useMemo(
        () => days.filter((day) => day.date <= today).map((day) => day.date),
        [days, today],
    );

    const isOpen = useCallback(
        (row, date) => !row.cells[date].locked && date <= today,
        [today],
    );

    const fillRow = useCallback(
        (employeeId) => {
            const row = rows.find((candidate) => candidate.employee_id === employeeId);

            setValues((previous) => {
                const next = { ...previous[employeeId] };

                openDates.forEach((date) => {
                    if (isOpen(row, date) && !next[date]) {
                        next[date] = 'present';
                    }
                });

                return { ...previous, [employeeId]: next };
            });
        },
        [rows, openDates, isOpen],
    );

    /** Fills every blank open day across the sheet with the same status. */
    const fillAll = useCallback(
        (status) => {
            setValues((previous) => {
                const next = { ...previous };

                rows.forEach((row) => {
                    const forRow = { ...next[row.employee_id] };

                    openDates.forEach((date) => {
                        if (isOpen(row, date) && !forRow[date]) {
                            forRow[date] = status;
                        }
                    });

                    next[row.employee_id] = forRow;
                });

                return next;
            });
        },
        [rows, openDates, isOpen],
    );

    /*
     * Rest days and holidays are proposed by the calendar, never applied by
     * it — the same bargain the document scanner makes with a filled form.
     * Pressing this is the person agreeing; Save is still a second step.
     */
    const suggestions = useMemo(
        () =>
            rows.reduce(
                (total, row) =>
                    total +
                    openDates.filter(
                        (date) =>
                            row.cells[date].suggested &&
                            !values[row.employee_id]?.[date] &&
                            isOpen(row, date),
                    ).length,
                0,
            ),
        [rows, openDates, values, isOpen],
    );

    const applySuggestions = useCallback(() => {
        setValues((previous) => {
            const next = { ...previous };

            rows.forEach((row) => {
                const forRow = { ...next[row.employee_id] };

                openDates.forEach((date) => {
                    const suggested = row.cells[date].suggested;

                    if (suggested && !forRow[date] && isOpen(row, date)) {
                        forRow[date] = suggested;
                    }
                });

                next[row.employee_id] = forRow;
            });

            return next;
        });
    }, [rows, openDates, isOpen]);

    /** The cells that differ from what was loaded — the whole payload. */
    const changed = useMemo(() => {
        const list = [];

        rows.forEach((row) => {
            days.forEach((day) => {
                if (row.cells[day.date].locked) {
                    return;
                }

                const current = values[row.employee_id]?.[day.date] ?? '';
                const original = baseline.current[row.employee_id]?.[day.date] ?? '';

                if (current !== original) {
                    list.push({
                        employee_id: row.employee_id,
                        log_date: day.date,
                        status: current || null,
                    });
                }
            });
        });

        return list;
    }, [rows, days, values]);

    const encoded = useMemo(() => {
        let filled = 0;
        let open = 0;

        rows.forEach((row) => {
            openDates.forEach((date) => {
                if (row.cells[date].locked) {
                    filled += 1;
                    open += 1;

                    return;
                }

                open += 1;

                if (values[row.employee_id]?.[date]) {
                    filled += 1;
                }
            });
        });

        return { filled, open, percent: open === 0 ? 0 : Math.round((filled / open) * 100) };
    }, [rows, openDates, values]);

    /*
     * A filter change is a fresh visit, not a partial one — a different
     * cutoff is a different sheet. Unsaved work would go with it, so it is
     * worth one question first.
     */
    const navigate = (params) => {
        if (changed.length > 0 && !window.confirm('Discard the unsaved days on this sheet?')) {
            return;
        }

        router.get(
            '/hr/timekeeping/period',
            { ...filters, period_id: period.id, ...params },
            {
                preserveScroll: true,
                replace: true,
            },
        );
    };

    const save = () => {
        // transform() returns undefined, so it cannot be chained onto post().
        form.transform(() => ({
            from: period.start_date,
            to: period.end_date,
            cells: changed,
        }));

        form.post('/hr/timekeeping/period', {
            preserveScroll: true,
            onSuccess: () => {
                // The saved sheet is the new baseline, so a second Save with
                // nothing further changed submits nothing.
                baseline.current = JSON.parse(JSON.stringify(values));
            },
        });
    };

    return (
        <AppLayout
            title="Period DTR"
            breadcrumbs={[
                { label: 'Timekeeping', href: '/hr/timekeeping' },
                { label: 'Period DTR' },
            ]}
        >
            <div className="space-y-4 pb-24">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <StatCard
                        label="On this sheet"
                        value={rows.length}
                        icon={Users}
                        hint={period.name}
                    />
                    <StatCard
                        label="Days in cutoff"
                        value={days.length}
                        icon={CalendarRange}
                        tone="info"
                        hint={`${formatDate(period.start_date)} – ${formatDate(period.end_date)}`}
                    />
                    <MeterCard
                        label="Encoded"
                        value={`${encoded.filled} · ${encoded.percent}%`}
                        percent={encoded.percent}
                        icon={ClipboardList}
                        tone={encoded.percent === 100 ? 'success' : 'warning'}
                        hint={`of ${encoded.open} employee-days up to today`}
                    />
                </div>

                {!period.is_payroll_period && (
                    <Card>
                        <CardBody className="flex items-start gap-3">
                            <TriangleAlert
                                className="mt-0.5 h-4 w-4 shrink-0 text-warning"
                                aria-hidden="true"
                            />
                            <p className="text-sm text-muted-foreground">
                                No payroll period covers these dates yet, so the sheet is
                                showing the calendar half-month. Records still save normally —
                                the cutoff is only named once someone creates the period under{' '}
                                <span className="font-medium text-foreground">
                                    Payroll → Runs
                                </span>
                                .
                            </p>
                        </CardBody>
                    </Card>
                )}

                <Card>
                    <CardHeader
                        title="Attendance by cutoff"
                        description="One row per employee, one column per day. A day already carrying clocked times is locked here and changed on Records."
                        action={
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Select
                                    className="w-full sm:w-56"
                                    value={period.id ?? ''}
                                    onChange={(event) =>
                                        navigate({ period_id: event.target.value || undefined })
                                    }
                                    placeholder={
                                        period.is_payroll_period ? undefined : period.name
                                    }
                                    options={periods.map((row) => ({
                                        value: row.id,
                                        label: `${row.name} (${formatDate(row.start_date)} – ${formatDate(row.end_date)})`,
                                    }))}
                                />
                                {can.manage && (
                                    <Button
                                        onClick={save}
                                        disabled={changed.length === 0 || form.processing}
                                        loading={form.processing}
                                    >
                                        <Save className="h-4 w-4" aria-hidden="true" />
                                        Save
                                    </Button>
                                )}
                            </div>
                        }
                    />

                    <CardBody className="space-y-3">
                        <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
                            {/*
                             * Searched on Enter rather than per keystroke,
                             * which is what every other list here does. A
                             * visit rebuilds the sheet and drops unsaved
                             * days, so navigate() has to ask first — and a
                             * question per letter typed is not a question.
                             */}
                            <div className="w-full lg:w-64">
                                <SearchInput
                                    defaultValue={filters.search ?? ''}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            navigate({
                                                search: event.target.value || undefined,
                                            });
                                        }
                                    }}
                                    placeholder="Search name or number, then Enter"
                                    aria-label="Search employees"
                                />
                            </div>
                            <Select
                                className="w-full lg:w-52"
                                value={filters.department_id ?? ''}
                                onChange={(event) =>
                                    navigate({ department_id: event.target.value || undefined })
                                }
                                placeholder="All departments"
                                options={departments.map((department) => ({
                                    value: department.id,
                                    label: department.name,
                                }))}
                            />
                            <Select
                                className="w-full lg:w-52"
                                value={filters.client_id ?? ''}
                                onChange={(event) =>
                                    navigate({ client_id: event.target.value || undefined })
                                }
                                placeholder="All clients"
                                options={clients.map((client) => ({
                                    value: client.id,
                                    label: client.is_active
                                        ? client.name
                                        : `${client.name} (inactive)`,
                                }))}
                            />

                            {can.manage && (
                                <div className="flex flex-wrap gap-2 lg:ml-auto">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={applySuggestions}
                                        disabled={suggestions === 0}
                                        title="Fill blank rest days and holidays the work calendar already knows about"
                                    >
                                        <Wand2 className="h-3.5 w-3.5" aria-hidden="true" />
                                        Apply {suggestions} suggestion(s)
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => fillAll('present')}
                                    >
                                        Fill blanks with Present
                                    </Button>
                                </div>
                            )}
                        </div>

                        {form.errors.cells && (
                            <p className="text-sm text-destructive">{form.errors.cells}</p>
                        )}

                        {/*
                         * Thirty-one columns is thirty-one columns: the grid
                         * scrolls sideways rather than shrinking a day to a
                         * width no code fits in. The negative margin lets it
                         * run to the card's edge instead of stopping inside
                         * its padding.
                         */}
                        <div className="-mx-4 overflow-x-auto px-4 sm:-mx-6 sm:px-6">
                            <table className="w-full border-collapse">
                                <thead>
                                    <tr className="border-b border-border">
                                        <th className="sticky left-0 z-10 min-w-[220px] max-w-[220px] border-r border-border bg-card px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                            Employee
                                        </th>
                                        {days.map((day) => (
                                            <th
                                                key={day.date}
                                                title={day.holiday ?? undefined}
                                                className={cn(
                                                    'border-r border-border/60 px-1 py-2 text-center text-[11px] font-semibold',
                                                    day.holiday
                                                        ? 'bg-info/10 text-info'
                                                        : day.is_weekend
                                                          ? 'bg-secondary/40 text-muted-foreground'
                                                          : 'text-muted-foreground',
                                                )}
                                            >
                                                <span className="block text-foreground">
                                                    {day.day}
                                                </span>
                                                <span className="block font-normal">
                                                    {day.weekday}
                                                </span>
                                            </th>
                                        ))}
                                        <th className="px-2 py-2 text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                            Row
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <SheetRow
                                            key={row.employee_id}
                                            row={row}
                                            days={days}
                                            values={values[row.employee_id]}
                                            statuses={statuses}
                                            today={today}
                                            editable={can.manage}
                                            onChange={change}
                                            onFillRow={fillRow}
                                        />
                                    ))}
                                    {rows.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={days.length + 2}
                                                className="px-3 py-10 text-center text-sm text-muted-foreground"
                                            >
                                                Nobody matches these filters for this cutoff.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            {statuses.map((status) => (
                                <span key={status} className="inline-flex items-center gap-1.5">
                                    <span
                                        className={cn(
                                            'inline-flex h-5 w-6 items-center justify-center rounded text-[10px] font-semibold',
                                            TONES[status] ?? 'bg-secondary text-foreground',
                                        )}
                                    >
                                        {codeFor(status)}
                                    </span>
                                    {titleCase(status)}
                                </span>
                            ))}
                        </div>
                    </CardBody>
                </Card>
            </div>

            {/*
             * A grid is scrolled, and a Save that lives in the card header is
             * off screen by the tenth row. The bar appears only when there is
             * something to save, so it is never furniture.
             */}
            {can.manage && changed.length > 0 && (
                <div className="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-card/95 px-4 py-3 backdrop-blur">
                    <div className="mx-auto flex max-w-7xl items-center gap-3">
                        <p className="text-sm text-muted-foreground">
                            <span className="font-semibold text-foreground">
                                {changed.length}
                            </span>{' '}
                            unsaved day(s) on this sheet.
                        </p>
                        <Button
                            className="ml-auto"
                            onClick={save}
                            loading={form.processing}
                            disabled={form.processing}
                        >
                            <Save className="h-4 w-4" aria-hidden="true" />
                            Save changes
                        </Button>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
