import { router } from '@inertiajs/react';
import { CalendarOff, ChevronLeft, ChevronRight } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardBody, Select } from '@/Components/ui';
import { cn } from '@/lib/utils';

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export default function Calendar({
    month,
    monthLabel,
    previousMonth,
    nextMonth,
    leadingBlanks,
    daysInMonth,
    entries,
    departments,
    filters,
}) {
    const monthStart = `${month}-01`;

    const cells = [
        ...Array.from({ length: leadingBlanks }, () => null),
        ...Array.from({ length: daysInMonth }, (_, index) => index + 1),
    ];

    const dateKey = (day) => `${month}-${String(day).padStart(2, '0')}`;

    const goToMonth = (target) =>
        router.get(
            '/hr/leave/calendar',
            { month: target, department_id: filters.department_id || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const today = new Date().toISOString().slice(0, 10);

    return (
        <AppLayout
            title="Leave & Absence"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Leave', href: '/hr/leave' },
                { label: 'Calendar' },
            ]}
        >
            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center">
                    <div className="flex items-center gap-1">
                        <button
                            type="button"
                            onClick={() => goToMonth(previousMonth)}
                            aria-label="Previous month"
                            className="grid h-9 w-9 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </button>

                        <h2 className="min-w-40 text-center text-sm font-semibold text-foreground">
                            {monthLabel}
                        </h2>

                        <button
                            type="button"
                            onClick={() => goToMonth(nextMonth)}
                            aria-label="Next month"
                            className="grid h-9 w-9 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                        >
                            <ChevronRight className="h-4 w-4" />
                        </button>
                    </div>

                    <div className="sm:ml-auto sm:w-56">
                        <Select
                            value={filters.department_id ?? ''}
                            onChange={(event) =>
                                router.get(
                                    '/hr/leave/calendar',
                                    { month, department_id: event.target.value || undefined },
                                    {
                                        preserveState: true,
                                        preserveScroll: true,
                                        replace: true,
                                    },
                                )
                            }
                            placeholder="All departments"
                            aria-label="Filter by department"
                            options={departments.map((department) => ({
                                value: department.id,
                                label: department.name,
                            }))}
                        />
                    </div>
                </div>

                <CardBody>
                    {/* A month is seven columns wide whatever the screen is.
                        On a 375px phone that leaves about 38px a day — narrower
                        than the pill naming who is off, so every entry would
                        truncate to nothing and the grid would stop being a
                        calendar. Scrolling it keeps a day wide enough to read;
                        the negative margin lets the scroll run to the card's
                        edge rather than stopping inside its padding. */}
                    <div className="scrollbar-thin -mx-5 overflow-x-auto px-5">
                        <div className="min-w-[560px]">
                            {/* Weekday header — Monday first, matching ISO weekdays. */}
                            <div className="mb-2 grid grid-cols-7 gap-1.5">
                                {WEEKDAYS.map((day) => (
                                    <div
                                        key={day}
                                        className="px-1 text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"
                                    >
                                        {day}
                                    </div>
                                ))}
                            </div>

                            <div className="grid grid-cols-7 gap-1.5">
                                {cells.map((day, index) => {
                                    if (day === null) {
                                        return (
                                            <div
                                                key={`blank-${index}`}
                                                className="min-h-24 rounded-md"
                                            />
                                        );
                                    }

                                    const key = dateKey(day);
                                    const dayEntries = entries[key] ?? [];
                                    const isToday = key === today;
                                    const isWeekend = index % 7 >= 5;

                                    return (
                                        <div
                                            key={key}
                                            className={cn(
                                                'min-h-24 rounded-md border p-1.5 transition-colors',
                                                isToday
                                                    ? 'border-primary bg-primary/5'
                                                    : 'border-border bg-card',
                                                isWeekend && !isToday && 'bg-secondary/40',
                                            )}
                                        >
                                            <div className="mb-1 flex items-center justify-between">
                                                <span
                                                    className={cn(
                                                        'text-xs font-medium tabular-nums',
                                                        isToday
                                                            ? 'text-primary'
                                                            : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {day}
                                                </span>
                                                {dayEntries.length > 2 && (
                                                    <span className="text-[10px] text-muted-foreground">
                                                        {dayEntries.length}
                                                    </span>
                                                )}
                                            </div>

                                            <div className="space-y-1">
                                                {dayEntries
                                                    .slice(0, 2)
                                                    .map((entry, entryIndex) => (
                                                        <span
                                                            key={`${entry.id}-${entryIndex}`}
                                                            title={`${entry.employee} — ${entry.type_name}${
                                                                entry.status ===
                                                                'supervisor_approved'
                                                                    ? ' (awaiting HR)'
                                                                    : ''
                                                            }`}
                                                            className={cn(
                                                                'block truncate rounded px-1.5 py-0.5 text-[10px] font-medium',
                                                                entry.status === 'approved'
                                                                    ? 'bg-primary/15 text-primary'
                                                                    : 'bg-warning/15 text-warning',
                                                            )}
                                                        >
                                                            {entry.type_code} · {entry.employee}
                                                        </span>
                                                    ))}

                                                {dayEntries.length > 2 && (
                                                    <p className="px-1.5 text-[10px] text-muted-foreground">
                                                        +{dayEntries.length - 2} more
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </div>

                    {Object.keys(entries).length === 0 && (
                        <div className="mt-6 flex flex-col items-center gap-2 text-center">
                            <span className="grid h-11 w-11 place-items-center rounded-full bg-secondary text-muted-foreground">
                                <CalendarOff className="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p className="text-sm font-medium text-foreground">
                                Nobody is on leave in {monthLabel}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Approved and endorsed leave appears here.
                            </p>
                        </div>
                    )}

                    <div className="mt-5 flex flex-wrap items-center gap-4 border-t border-border pt-4">
                        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <span className="h-2.5 w-2.5 rounded-sm bg-primary/40" />
                            Approved
                        </span>
                        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <span className="h-2.5 w-2.5 rounded-sm bg-warning/40" />
                            Awaiting HR
                        </span>
                        <span className="ml-auto text-xs text-muted-foreground">
                            Showing {monthStart.slice(0, 7)}
                        </span>
                    </div>
                </CardBody>
            </Card>
        </AppLayout>
    );
}
