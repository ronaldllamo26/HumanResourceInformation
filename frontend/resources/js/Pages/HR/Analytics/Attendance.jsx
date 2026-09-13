import { router } from '@inertiajs/react';
import { useState } from 'react';
import {
    CalendarDays,
    Clock,
    DollarSign,
    Filter,
    Flame,
    Timer,
    TrendingUp,
    TriangleAlert,
    UserX,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    DateInput,
    Table,
    TableEmpty,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { cn } from '@/lib/utils';

function StatTile({ label, value, hint, tone = 'text-foreground', icon: Icon }) {
    return (
        <div className="rounded-xl border border-border bg-card p-4 transition-all hover:border-border/80">
            <div className="flex items-center justify-between">
                <p className="text-xs font-medium text-muted-foreground">{label}</p>
                {Icon && (
                    <span className="grid h-8 w-8 place-items-center rounded-lg bg-secondary text-foreground">
                        <Icon className="h-4 w-4" aria-hidden="true" />
                    </span>
                )}
            </div>
            <p className={cn('mt-2 text-2xl font-bold tabular-nums', tone)}>{value}</p>
            {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

export default function Attendance({
    filters = {},
    summary = {},
    dayOfWeekPatterns = [],
    monthlyOtTrend = [],
    departmentAttendance = [],
    frequentTardiness = [],
}) {
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    const applyFilter = (e) => {
        e.preventDefault();
        router.get(
            '/hr/analytics/attendance',
            { from, to },
            { preserveState: true, replace: true },
        );
    };

    const maxOtHours = Math.max(...monthlyOtTrend.map((m) => m.hours), 1);

    return (
        <AppLayout
            title="Attendance & Cost Insights"
            breadcrumbs={[{ label: 'AI & Analytics' }, { label: 'Attendance & Cost Insights' }]}
        >
            {/* Header & Date Range Filter */}
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-xl font-bold tracking-tight text-foreground sm:text-2xl">
                        Attendance Patterns & Labor Cost Insights
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Identify absenteeism peaks, monitor overtime hours, and analyze
                        punctuality trends.
                    </p>
                </div>

                <form onSubmit={applyFilter} className="flex items-center gap-2">
                    <DateInput
                        value={from}
                        onChange={(e) => setFrom(e.target.value)}
                        className="w-36 text-xs"
                    />
                    <span className="text-xs text-muted-foreground">to</span>
                    <DateInput
                        value={to}
                        onChange={(e) => setTo(e.target.value)}
                        className="w-36 text-xs"
                    />
                    <Button type="submit" size="sm" variant="outline">
                        <Filter className="h-3.5 w-3.5" />
                        Apply
                    </Button>
                </form>
            </div>

            {/* Top KPIs */}
            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Overall Attendance Rate"
                    value={`${summary.attendance_rate}%`}
                    hint={`${summary.present_count} present vs ${summary.absent_count} absences recorded`}
                    icon={CalendarDays}
                    tone={summary.attendance_rate >= 90 ? 'text-success' : 'text-warning'}
                />
                <StatTile
                    label="Approved Overtime"
                    value={`${summary.total_ot_hours} hrs`}
                    hint="Total approved overtime rendering across staff"
                    icon={Clock}
                    tone="text-primary"
                />
                <StatTile
                    label="Tardiness Instances"
                    value={summary.late_count}
                    hint={`${summary.total_late_minutes} total minutes lost to lateness`}
                    icon={Timer}
                    tone={summary.late_count > 20 ? 'text-destructive' : 'text-foreground'}
                />
                <StatTile
                    label="Undertime Logs"
                    value={summary.undertime_count}
                    hint={`${summary.total_undertime_minutes} total undertime minutes recorded`}
                    icon={TriangleAlert}
                />
            </div>

            {/* Middle Section: Day of Week & Overtime Trends */}
            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                {/* Day-of-Week Absenteeism Pattern */}
                <Card>
                    <CardHeader
                        title="Day-of-the-Week Attendance Patterns"
                        description="Identify absenteeism dips (e.g. Monday/Friday spikes)."
                    />
                    <CardBody>
                        <div className="space-y-4">
                            {dayOfWeekPatterns.map((day) => (
                                <div key={day.day} className="space-y-1.5">
                                    <div className="flex items-center justify-between text-xs">
                                        <span className="font-semibold text-foreground">
                                            {day.day}
                                        </span>
                                        <span className="tabular-nums text-muted-foreground">
                                            <span className="font-medium text-success">
                                                {day.rate}% rate
                                            </span>{' '}
                                            · <span>{day.present} present</span> ·{' '}
                                            <span className="text-destructive">
                                                {day.absent} absent
                                            </span>{' '}
                                            ·{' '}
                                            <span className="text-warning">
                                                {day.late} late
                                            </span>
                                        </span>
                                    </div>
                                    <div className="h-2.5 overflow-hidden rounded-full bg-secondary">
                                        <div
                                            className={cn(
                                                'h-full rounded-full transition-all duration-300',
                                                day.rate >= 90
                                                    ? 'bg-success'
                                                    : day.rate >= 80
                                                      ? 'bg-primary'
                                                      : 'bg-warning',
                                            )}
                                            style={{ width: `${day.rate}%` }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardBody>
                </Card>

                {/* Monthly Overtime Trajectory */}
                <Card>
                    <CardHeader
                        title="Overtime Trajectory (Last 6 Months)"
                        description="Monthly volume of approved overtime hours rendered."
                    />
                    <CardBody>
                        <div className="space-y-4">
                            {monthlyOtTrend.map((m) => (
                                <div key={m.month} className="space-y-1.5">
                                    <div className="flex items-center justify-between text-xs">
                                        <span className="font-semibold text-foreground">
                                            {m.month}
                                        </span>
                                        <span className="font-medium tabular-nums text-foreground">
                                            {m.hours} hours
                                        </span>
                                    </div>
                                    <div className="h-2.5 overflow-hidden rounded-full bg-secondary">
                                        <div
                                            className="h-full rounded-full bg-primary transition-all duration-300"
                                            style={{
                                                width: `${(m.hours / maxOtHours) * 100}%`,
                                            }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardBody>
                </Card>
            </div>

            {/* Bottom Section: Department Comparison & Punctuality Watchlist */}
            <div className="grid gap-6 lg:grid-cols-2">
                {/* Department Attendance Ranking */}
                <Card>
                    <CardHeader
                        title="Department Attendance Performance"
                        description="Attendance rates ranked across operating departments."
                    />
                    <CardBody>
                        <div className="space-y-3.5">
                            {departmentAttendance.map((dept) => (
                                <div key={dept.name} className="space-y-1.5">
                                    <div className="flex items-center justify-between text-xs">
                                        <span className="font-medium text-foreground">
                                            {dept.name}
                                        </span>
                                        <span className="tabular-nums text-muted-foreground">
                                            <span className="font-semibold text-foreground">
                                                {dept.rate}%
                                            </span>{' '}
                                            ({dept.present} in / {dept.absent} out)
                                        </span>
                                    </div>
                                    <div className="h-2 overflow-hidden rounded-full bg-secondary">
                                        <div
                                            className={cn(
                                                'h-full rounded-full transition-all duration-300',
                                                dept.rate >= 90
                                                    ? 'bg-success'
                                                    : dept.rate >= 80
                                                      ? 'bg-primary'
                                                      : 'bg-warning',
                                            )}
                                            style={{ width: `${dept.rate}%` }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardBody>
                </Card>

                {/* Chronic Tardiness Watchlist */}
                <Card>
                    <CardHeader
                        title="Punctuality Watchlist"
                        description="Personnel with the highest tardiness counts in this period."
                    />
                    <CardBody className="p-0">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Employee</TH>
                                    <TH>Department</TH>
                                    <TH className="text-right">Late Days</TH>
                                    <TH className="text-right">Total Min</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {frequentTardiness.length === 0 ? (
                                    <TableEmpty
                                        message="No chronic tardiness recorded in this period."
                                        colSpan={4}
                                    />
                                ) : (
                                    frequentTardiness.map((row) => (
                                        <TR key={row.id}>
                                            <TD className="font-medium text-foreground">
                                                {row.name}
                                            </TD>
                                            <TD className="text-muted-foreground">
                                                {row.department}
                                            </TD>
                                            <TD className="text-right">
                                                <Badge variant="warning">
                                                    {row.late_count}x
                                                </Badge>
                                            </TD>
                                            <TD className="text-right tabular-nums text-foreground">
                                                {row.late_minutes}m
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardBody>
                </Card>
            </div>
        </AppLayout>
    );
}
