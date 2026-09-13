import { Link } from '@inertiajs/react';
import {
    Building2,
    CalendarClock,
    CalendarDays,
    ChevronRight,
    ClipboardCheck,
    Clock,
    IdCard,
    Shield,
    UserCheck,
    UserPlus,
    Users,
    Wallet,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Card,
    CardBody,
    CardHeader,
    MeterCard,
    SplitStatCard,
    StatCard,
    StatTile,
    TilePreview,
    TrendChart,
} from '@/Components/ui';
import { cn, formatCurrency, formatDate, initials } from '@/lib/utils';

/** Fixed order, never cycled — the set is only validated for four slots. */
const SERIES = ['bg-chart-1', 'bg-chart-2', 'bg-chart-3', 'bg-chart-4'];
const SERIES_TEXT = ['text-chart-1', 'text-chart-2', 'text-chart-3', 'text-chart-4'];

/**
 * First point to last, for the badge beside the trend chart.
 *
 * Null rather than zero when there is nothing to compare — a badge reading
 * "+0" on a one-point series claims a stability that was never measured.
 */
function trendDelta(series) {
    if (!Array.isArray(series) || series.length < 2) return null;

    return (Number(series.at(-1).value) || 0) - (Number(series[0].value) || 0);
}

/**
 * Where a percentage sits on the good -> bad ramp.
 *
 * Presentational only — these cut-offs colour a bar, they do not decide
 * anything. The rules that carry consequences (chronic lateness, absence
 * trends) live in config/timekeeping.php and are deliberately not restated
 * here, so nobody can mistake a shade for a threshold.
 */
function gradeForPercent(percent) {
    const value = Number(percent) || 0;

    if (value >= 95) return 'grade-1';
    if (value >= 90) return 'grade-2';
    if (value >= 80) return 'grade-3';
    if (value >= 70) return 'grade-4';
    if (value >= 50) return 'grade-5';

    return 'grade-6';
}

/**
 * Active headcount by department.
 *
 * One measure across categories, so every bar wears the same hue — colour would
 * encode nothing here. Counts are direct-labelled, so identity never rests on
 * colour alone.
 */
function HeadcountChart({ data }) {
    const max = Math.max(...data.map((row) => row.count), 1);
    const total = data.reduce((sum, row) => sum + row.count, 0);

    if (data.length === 0) {
        return <p className="text-sm text-muted-foreground">No departments configured yet.</p>;
    }

    return (
        <div className="space-y-1.5">
            {data.map((row) => {
                const share = total > 0 ? Math.round((row.count / total) * 100) : 0;

                return (
                    /*
                     * The whole row is the target, not the bar — Finance &
                     * Accounting's bar is a third of the width of Fleet
                     * Operations', and a link whose hit area shrinks with the
                     * value it represents is hardest to click exactly where
                     * there is least to see.
                     *
                     * `status=active` rides along because that is what the bar
                     * measured. Opening the department's whole history from a
                     * bar labelled "active" would be a different number.
                     */
                    <Link
                        key={row.name}
                        href={`/hr/employees?department_id=${row.id}&status=active`}
                        className="group -mx-2 grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3 rounded-md px-2 py-1.5 transition-colors hover:bg-secondary/60"
                        title={`${row.name}: ${row.count} active (${share}% of headcount)`}
                    >
                        <span className="truncate text-xs text-muted-foreground group-hover:text-foreground">
                            {row.name}
                        </span>

                        <span className="h-2.5 w-full overflow-hidden rounded-sm bg-muted">
                            <span
                                className="block h-full rounded-r-[4px] bg-chart-1 transition-[width] duration-500"
                                style={{
                                    width: `${Math.max((row.count / max) * 100, row.count > 0 ? 3 : 0)}%`,
                                }}
                            />
                        </span>

                        <span className="w-8 text-right text-xs font-medium tabular-nums text-foreground">
                            {row.count}
                        </span>
                    </Link>
                );
            })}
        </div>
    );
}

/**
 * Employment status mix as a donut.
 *
 * Part-to-whole across four categories, so identity *is* the job here — hence
 * the categorical palette. Every slice is also named and counted in the legend,
 * so the chart never depends on colour alone.
 */
function StatusDonut({ data }) {
    const total = data.reduce((sum, row) => sum + row.count, 0);

    if (total === 0) {
        return <p className="text-sm text-muted-foreground">No employee records yet.</p>;
    }

    const radius = 56;
    const circumference = 2 * Math.PI * radius;
    let offset = 0;

    return (
        <div className="flex flex-col items-center gap-5 sm:flex-row sm:items-center">
            <div className="relative shrink-0">
                <svg
                    viewBox="0 0 140 140"
                    className="h-36 w-36 -rotate-90"
                    role="img"
                    aria-label={`Employment status: ${data.map((r) => `${r.label} ${r.count}`).join(', ')}`}
                >
                    {data.map((row, index) => {
                        const length = (row.count / total) * circumference;
                        // A 2px gap keeps neighbouring slices from merging.
                        const dash = `${Math.max(length - 2, 0)} ${circumference - Math.max(length - 2, 0)}`;
                        const thisOffset = offset;
                        offset += length;

                        if (row.count === 0) return null;

                        return (
                            <circle
                                key={row.label}
                                cx="70"
                                cy="70"
                                r={radius}
                                fill="none"
                                strokeWidth="16"
                                className={cn(
                                    'stroke-current',
                                    SERIES_TEXT[index % SERIES.length],
                                )}
                                strokeDasharray={dash}
                                strokeDashoffset={-thisOffset}
                            />
                        );
                    })}
                </svg>

                <div className="absolute inset-0 grid place-items-center">
                    <div className="text-center">
                        <p className="text-2xl font-semibold tabular-nums text-foreground">
                            {total}
                        </p>
                        <p className="text-[10px] uppercase tracking-wide text-muted-foreground">
                            Total
                        </p>
                    </div>
                </div>
            </div>

            {/* The legend is the clickable half, not the ring. A slice is a
                2px-tall arc at the edge of the donut and two of them here are
                under 10% — a target that small is one somebody misses and then
                stops trying. The legend row is the full width of the column
                and names the thing it opens. */}
            <dl className="-mx-2 w-full">
                {data.map((row, index) => (
                    <Link
                        key={row.label}
                        href={`/hr/employees?employment_status=${row.filter}`}
                        className="flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-secondary/60"
                    >
                        <span
                            className={cn(
                                'h-2.5 w-2.5 shrink-0 rounded-sm',
                                SERIES[index % SERIES.length],
                            )}
                            aria-hidden="true"
                        />
                        <dt className="flex-1 truncate text-xs text-muted-foreground">
                            {row.label}
                        </dt>
                        <dd className="text-xs font-medium tabular-nums text-foreground">
                            {row.count}
                        </dd>
                        <dd className="w-9 text-right text-xs tabular-nums text-muted-foreground">
                            {Math.round((row.count / total) * 100)}%
                        </dd>
                    </Link>
                ))}
            </dl>
        </div>
    );
}

export default function Dashboard({
    statistics,
    headcountByDepartment,
    headcountTrend,
    statusMix,
    recentHires,
    attendanceToday,
    approvals,
    payroll,
    leaveToday,
    leaveSummary,
    payrollSummary,
    onboardingSummary,
    profile,
    can,
}) {
    const headcountChange = statistics.headcount_change ?? 0;

    /*
     * The Leave card counts what was filed this month, so every one of its
     * links has to carry that window as well as the status it names. The month
     * start comes from the controller that did the counting rather than being
     * worked out again here — two derivations of "this month" is one too many,
     * and the day they disagree is the 1st.
     */
    const leaveFiled = ({ status }) =>
        `/hr/leave?status=${status}&filed_from=${leaveSummary.filed_from}`;

    // Company-wide summaries arrive as null for a role that may not read them,
    // so the card is never drawn empty — it is simply not there.
    const companyCards = [leaveSummary, payrollSummary].filter(Boolean).length;

    return (
        <AppLayout title="Dashboard" breadcrumbs={[{ label: 'Overview' }]}>
            {/* Whose screen this is, above the company's own figures. For a
                rank-and-file login it is the only band here they can act on. */}
            <ProfileCard profile={profile} />

            {/* Headline figures */}
            <div className="mb-5 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                {/* Each tile links to the screen its figure came from, so a
                    number that raises a question is one click from its
                    answer. */}
                <StatCard
                    floating
                    href="/hr/employees"
                    label="Total Employees"
                    value={statistics.total}
                    icon={Users}
                    hint={`${statistics.active} active · ${statistics.probationary} probationary`}
                    // Only shown when it moved — an arrow reading "0" every day
                    // is a line the eye learns to skip.
                    trend={
                        headcountChange === 0
                            ? undefined
                            : {
                                  direction: headcountChange > 0 ? 'up' : 'down',
                                  label: `${headcountChange > 0 ? '+' : ''}${headcountChange} in the last 30 days`,
                              }
                    }
                />
                <StatCard
                    floating
                    href="/hr/timekeeping"
                    label="Present Today"
                    value={attendanceToday.present}
                    icon={UserCheck}
                    tone="success"
                    hint={`of ${attendanceToday.expected} scheduled`}
                />
                {/* Being on approved leave is not a fault, so this stays
                    informational rather than a warning. */}
                <StatCard
                    floating
                    href="/hr/leave"
                    label="On Leave Today"
                    value={leaveToday.count}
                    icon={CalendarDays}
                    tone="info"
                    hint={leaveToday.count > 0 ? leaveToday.summary : 'Nobody is away'}
                />
                <StatCard
                    floating
                    href="/hr/payroll"
                    label="Latest Payroll"
                    // The company's total net is HR's figure. An employee gets
                    // the tile with an em dash rather than a tile that is
                    // missing, so the grid keeps its four columns.
                    value={can?.viewCompanyFigures ? formatCurrency(payroll.total_net) : '—'}
                    icon={Wallet}
                    tone="primary"
                    hint={
                        can?.viewCompanyFigures
                            ? (payroll.period ?? 'No payroll run yet')
                            : 'Visible to HR'
                    }
                />
            </div>

            {/* Operational detail */}
            <div className="mb-5 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                <SplitStatCard
                    floating
                    href="/hr/timekeeping"
                    label="Today's Attendance"
                    icon={Clock}
                    tone="success"
                    stats={[
                        { label: 'Present', value: attendanceToday.present, tone: 'success' },
                        { label: 'Late', value: attendanceToday.late, tone: 'warning' },
                        { label: 'Absent', value: attendanceToday.absent, tone: 'destructive' },
                    ]}
                />

                <MeterCard
                    floating
                    href="/hr/timekeeping/reports"
                    label="Attendance Rate"
                    value={`${attendanceToday.rate}%`}
                    percent={attendanceToday.rate}
                    icon={UserCheck}
                    iconTone="success"
                    tone={gradeForPercent(attendanceToday.rate)}
                    hint={`${attendanceToday.present} of ${attendanceToday.expected} on duty`}
                />

                {/* The bar takes the band's own colour, so the meter and the
                    badge cannot disagree about how the score reads. */}
                <MeterCard
                    floating
                    href="/hr/performance"
                    label="Avg Performance"
                    value={statistics.average_rating?.toFixed(2) ?? '—'}
                    percent={((statistics.average_rating ?? 0) / 5) * 100}
                    icon={ClipboardCheck}
                    iconTone="primary"
                    tone={statistics.performance_band_variant ?? 'primary'}
                    badge={statistics.performance_band ?? undefined}
                    hint="Latest completed review cycle"
                />

                {/* Anything waiting on a decision is a warning, not a fault —
                    and each drops to grey at zero, so an empty queue is quiet. */}
                {/* The one tile that spans three modules. Leave is where most
                    of the queue sits and where an approver goes first, so it
                    is the destination — the other two are a click further on
                    rather than unreachable. */}
                <SplitStatCard
                    floating
                    href="/hr/leave"
                    label="Awaiting Approval"
                    icon={CalendarClock}
                    tone="warning"
                    stats={[
                        { label: 'Leave', value: approvals.leave, tone: 'warning' },
                        { label: 'Overtime', value: approvals.overtime, tone: 'warning' },
                        { label: 'Reviews', value: approvals.reviews, tone: 'warning' },
                    ]}
                />
            </div>

            {/* Charts */}
            <div className="mb-5 grid gap-5 lg:grid-cols-3">
                <Card floating className="lg:col-span-2">
                    <CardHeader
                        title="Headcount Trend"
                        description="Active employees at each month end."
                        action={
                            trendDelta(headcountTrend) === null ? undefined : (
                                <Badge
                                    variant={
                                        trendDelta(headcountTrend) >= 0
                                            ? 'success'
                                            : 'destructive'
                                    }
                                >
                                    {trendDelta(headcountTrend) >= 0 ? '+' : ''}
                                    {trendDelta(headcountTrend)} over 12 months
                                </Badge>
                            )
                        }
                    />
                    <CardBody>
                        <TrendChart data={headcountTrend} valueLabel="Active headcount" />
                    </CardBody>
                </Card>

                <Card floating>
                    <CardHeader
                        title="Employment Status"
                        description="Across all records."
                        action={
                            <div className="text-right">
                                <p className="text-xl font-semibold tabular-nums leading-none text-foreground">
                                    {statistics.active}
                                </p>
                                <p className="mt-1 text-[10px] uppercase tracking-wide text-muted-foreground">
                                    Active
                                </p>
                            </div>
                        }
                    />
                    <CardBody>
                        <StatusDonut data={statusMix} />
                    </CardBody>
                </Card>
            </div>

            {/* Headcount by department keeps its own card — it is a different
                question from the trend above (who, not when). */}
            <div
                className={cn(
                    'mb-5 grid gap-5',
                    companyCards === 0 ? 'lg:grid-cols-1' : 'lg:grid-cols-3',
                )}
            >
                {leaveSummary && (
                    <Card floating className="lg:col-span-1">
                        <CardHeader
                            title="Leave Requests"
                            description="Filed this month."
                            action={
                                <Link
                                    href="/hr/leave"
                                    className="text-xs font-medium text-primary hover:underline"
                                >
                                    View all
                                </Link>
                            }
                        />
                        <CardBody>
                            {/* Every tile carries `filed_from` as well as its
                                status, because the card counts what was filed
                                *this month*. Without it a tile reading 6 would
                                open a list of every pending request ever, and
                                a figure that cannot show its own rows is one
                                nobody can check. */}
                            <div className="flex gap-2">
                                <StatTile
                                    label="Pending"
                                    value={leaveSummary.pending}
                                    tone="warning"
                                    href={leaveFiled({ status: 'pending' })}
                                />
                                <StatTile
                                    label="Approved"
                                    value={leaveSummary.approved}
                                    tone="success"
                                    href={leaveFiled({ status: 'approved' })}
                                />
                                <StatTile
                                    label="Rejected"
                                    value={leaveSummary.rejected}
                                    tone="destructive"
                                    href={leaveFiled({ status: 'rejected' })}
                                />
                            </div>

                            <TilePreview
                                icon={CalendarDays}
                                tone="info"
                                href={
                                    leaveSummary.latest?.employee_id
                                        ? `/hr/leave?employee_id=${leaveSummary.latest.employee_id}`
                                        : undefined
                                }
                                title={leaveSummary.latest?.title}
                                subtitle={leaveSummary.latest?.subtitle}
                                badge={
                                    leaveSummary.latest && (
                                        <Badge status={leaveSummary.latest.status} />
                                    )
                                }
                                empty="No leave has been filed yet."
                            />
                        </CardBody>
                    </Card>
                )}

                {payrollSummary && (
                    <Card floating>
                        <CardHeader
                            title="Payroll"
                            description="Runs by stage."
                            action={
                                <Link
                                    href="/hr/payroll"
                                    className="text-xs font-medium text-primary hover:underline"
                                >
                                    View all
                                </Link>
                            }
                        />
                        <CardBody>
                            {/* `released` is not a run status — it is approved
                                or paid, the pair PayrollRun::REPORTABLE holds.
                                The list resolves the word rather than the URL
                                naming two statuses, so the tile and the screen
                                cannot come to mean different things. */}
                            <div className="flex gap-2">
                                <StatTile
                                    label="Draft"
                                    value={payrollSummary.draft}
                                    tone="muted"
                                    href="/hr/payroll?run_status=draft"
                                />
                                <StatTile
                                    label="For Approval"
                                    value={payrollSummary.for_approval}
                                    tone="warning"
                                    href="/hr/payroll?run_status=for_approval"
                                />
                                <StatTile
                                    label="Released"
                                    value={payrollSummary.released}
                                    tone="success"
                                    href="/hr/payroll?run_status=released"
                                />
                            </div>

                            <TilePreview
                                icon={Wallet}
                                tone="primary"
                                href={
                                    payrollSummary.latest
                                        ? `/hr/payroll/runs/${payrollSummary.latest.id}`
                                        : undefined
                                }
                                title={payrollSummary.latest?.title}
                                subtitle={payrollSummary.latest?.subtitle}
                                badge={
                                    payrollSummary.latest && (
                                        <Badge status={payrollSummary.latest.status} />
                                    )
                                }
                                empty="No payroll run yet."
                            />
                        </CardBody>
                    </Card>
                )}

                <Card floating>
                    <CardHeader
                        title="201 File Health"
                        description="What needs filing."
                        action={
                            <Link
                                href="/hr/onboarding"
                                className="text-xs font-medium text-primary hover:underline"
                            >
                                View all
                            </Link>
                        }
                    />
                    <CardBody>
                        {/* The three go to three different screens, because
                            they are three different questions. "Expiring" is
                            about documents and belongs to Credentials, whose
                            warning window is per document type — this tile
                            reads that same scanner rather than keeping a flat
                            window of its own, which is what used to make the
                            two disagree. */}
                        <div className="flex gap-2">
                            <StatTile
                                label="New Hires"
                                value={onboardingSummary.new_hires}
                                tone="info"
                                href={`/hr/employees?hired_within=${onboardingSummary.new_hire_days}`}
                            />
                            <StatTile
                                label="Expiring"
                                value={onboardingSummary.expiring}
                                tone="warning"
                                href="/hr/credentials?status=expiring"
                            />
                            {/* `status=active` as well, because that is what
                                the count was taken over — an archived record
                                with an empty file is not somebody's missing
                                paperwork. */}
                            <StatTile
                                label="No Documents"
                                value={onboardingSummary.without_documents}
                                tone="destructive"
                                href="/hr/employees?without_documents=1&status=active"
                            />
                        </div>

                        <TilePreview
                            icon={UserPlus}
                            tone="success"
                            href={
                                onboardingSummary.latest
                                    ? `/hr/employees/${onboardingSummary.latest.id}`
                                    : undefined
                            }
                            title={onboardingSummary.latest?.title}
                            subtitle={onboardingSummary.latest?.subtitle}
                            badge={
                                onboardingSummary.latest && (
                                    <Badge status={onboardingSummary.latest.status} />
                                )
                            }
                            empty="No employees on file yet."
                        />
                    </CardBody>
                </Card>
            </div>

            <div className="mb-5">
                <Card floating>
                    <CardHeader
                        title="Active Headcount by Department"
                        description="Employees with an active record."
                        action={
                            <Link
                                href="/hr/departments"
                                className="text-xs font-medium text-primary hover:underline"
                            >
                                View departments
                            </Link>
                        }
                    />
                    <CardBody>
                        <HeadcountChart data={headcountByDepartment} />
                    </CardBody>
                </Card>
            </div>

            {/* Recent activity */}
            <Card floating>
                <CardHeader
                    title="Recent Hires"
                    description="The six most recently hired."
                    action={
                        <Link
                            href="/hr/employees"
                            className="text-xs font-medium text-primary hover:underline"
                        >
                            View directory
                        </Link>
                    }
                />
                <CardBody>
                    {recentHires.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 py-8 text-center">
                            <span className="grid h-11 w-11 place-items-center rounded-full bg-secondary text-muted-foreground">
                                <UserPlus className="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p className="text-sm font-medium text-foreground">
                                No employees yet
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Add your first employee record to get started.
                            </p>
                        </div>
                    ) : (
                        <ul className="grid gap-1 sm:grid-cols-2 xl:grid-cols-3">
                            {recentHires.map((hire) => (
                                <li key={hire.id}>
                                    <Link
                                        href={`/hr/employees/${hire.id}`}
                                        className="flex items-center gap-3 rounded-lg p-2 transition-colors hover:bg-secondary/60"
                                    >
                                        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">
                                            {initials(hire.full_name)}
                                        </span>

                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium text-foreground">
                                                {hire.full_name}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {hire.position ?? 'No position assigned'}
                                            </span>
                                        </span>

                                        <Badge variant="muted">
                                            {formatDate(hire.date_hired)}
                                        </Badge>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardBody>
            </Card>
        </AppLayout>
    );
}

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

/**
 * Who the reader is, and the four screens that are theirs.
 *
 * Everything else on this dashboard is the company looking at itself — how
 * many people, whose leave is waiting, what payroll came to. None of it
 * answers the first question somebody actually has on landing here, which is
 * *where do I go*, and for a rank-and-file login none of those figures are
 * even theirs to act on.
 *
 * **Every part of it is a link, which is the point rather than a flourish.** A
 * card that states a department and cannot open it has told the reader
 * something they already knew about themselves.
 *
 * The client is deliberately the one thing here that is *not* linked:
 * `/hr/clients` is behind `manageOrganization`, so for the employees most
 * likely to be deployed to one it would be a link into a 403. Drawing that is
 * worse than drawing none — it says there is something behind it *and* that
 * the reader is not trusted with it.
 */
function ProfileCard({ profile }) {
    const { name, email, role, employee } = profile;

    /*
     * A login with no 201 file is a real case, not a defensive check: an
     * administrator need not be an employee at all, and a pure system account
     * has no record, no attendance, and no payslip. It gets the one link that
     * does exist for it rather than four that would 404.
     */
    const links = employee
        ? [
              { label: 'My 201 File', href: `/hr/employees/${employee.id}`, icon: IdCard },
              {
                  label: 'My Attendance',
                  href: `/hr/timekeeping/employee/${employee.id}`,
                  icon: Clock,
              },
              { label: 'My Leave', href: '/hr/leave', icon: CalendarDays },
              { label: 'My Payslips', href: '/hr/payroll/payslips', icon: Wallet },
          ]
        : [{ label: 'Account & Security', href: '/settings/security', icon: Shield }];

    const identity = employee ? `/hr/employees/${employee.id}` : '/settings/security';

    return (
        <Card floating className="mb-5">
            <CardBody className="space-y-4">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <Link
                        href={identity}
                        className="group flex min-w-0 flex-1 items-center gap-4 rounded-lg transition-colors"
                    >
                        {employee?.photo_url ? (
                            <img
                                src={employee.photo_url}
                                alt=""
                                className="h-14 w-14 shrink-0 rounded-full object-cover ring-2 ring-border"
                            />
                        ) : (
                            <span className="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-primary/10 text-base font-semibold text-primary">
                                {initials(name)}
                            </span>
                        )}

                        <span className="min-w-0">
                            <span className="block truncate text-base font-semibold text-foreground group-hover:text-primary">
                                {name}
                            </span>
                            <span className="mt-0.5 block truncate text-sm text-muted-foreground">
                                {employee
                                    ? [employee.position, employee.department]
                                          .filter(Boolean)
                                          .join(' · ') || 'No position assigned'
                                    : email}
                            </span>
                        </span>
                    </Link>

                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                        {employee?.employee_number && (
                            <Badge variant="primary">{employee.employee_number}</Badge>
                        )}
                        {employee?.employment_status ? (
                            <Badge status={employee.employment_status} />
                        ) : (
                            <Badge variant="muted">{titleCase(role)}</Badge>
                        )}
                    </div>
                </div>

                {employee && (
                    <>
                        <dl className="grid gap-4 border-t border-border pt-4 sm:grid-cols-3">
                            <ProfileFact
                                label="Department"
                                value={employee.department}
                                href="/hr/directory"
                                icon={Building2}
                            />
                            {/* Not a link — see the note on the component. */}
                            <ProfileFact
                                label={
                                    employee.employment_category === 'external'
                                        ? 'Deployed to'
                                        : 'Client'
                                }
                                value={employee.client ?? 'Internal staff'}
                            />
                            <ProfileFact label="Supervisor" value={employee.supervisor} />
                        </dl>

                        <dl className="grid gap-4 border-t border-border pt-4 sm:grid-cols-3">
                            {/*
                             * Absent from the payload entirely for a reader the
                             * policy refuses, so there is nothing here to hide
                             * with a class. Their own rate, on their own screen.
                             */}
                            {employee.compensation && (
                                <ProfileFact
                                    label="Basic Salary"
                                    value={`${formatCurrency(employee.compensation.basic_salary)} · ${titleCase(
                                        employee.compensation.pay_frequency,
                                    )}`}
                                    href="/hr/payroll/payslips"
                                    icon={Wallet}
                                />
                            )}

                            {/* Both halves of the month, because "18 days in"
                                and "2 days missed" are different questions and
                                the second is the one somebody acts on. */}
                            <ProfileFact
                                label={`Days In · ${employee.attendance.month}`}
                                value={`${employee.attendance.present} day(s)`}
                                href={`/hr/timekeeping/employee/${employee.id}?from=${employee.attendance.from}&to=${employee.attendance.to}`}
                                icon={Clock}
                            />
                            <ProfileFact
                                label="Absences This Month"
                                value={`${employee.attendance.absent} day(s)`}
                                href={`/hr/timekeeping/employee/${employee.id}?from=${employee.attendance.from}&to=${employee.attendance.to}`}
                                icon={CalendarClock}
                            />
                        </dl>
                    </>
                )}

                <div className="grid gap-2 border-t border-border pt-4 sm:grid-cols-2 lg:grid-cols-4">
                    {links.map(({ label, href, icon: Icon }) => (
                        <Link
                            key={href}
                            href={href}
                            className="flex items-center gap-2.5 rounded-lg border border-border px-3 py-2.5 text-sm text-foreground transition-colors hover:border-primary/30 hover:bg-secondary/60"
                        >
                            <Icon
                                className="h-4 w-4 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            <span className="min-w-0 flex-1 truncate">{label}</span>
                            <ChevronRight
                                className="h-4 w-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </Link>
                    ))}
                </div>
            </CardBody>
        </Card>
    );
}

/** One labelled fact, a link only when there is somewhere it may open. */
function ProfileFact({ label, value, href, icon: Icon }) {
    const body = (
        <>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 flex items-center gap-1.5 truncate text-sm text-foreground">
                {Icon && (
                    <Icon className="h-3.5 w-3.5 shrink-0 text-primary" aria-hidden="true" />
                )}
                <span className="truncate">{value || '—'}</span>
            </dd>
        </>
    );

    if (!href || !value) {
        return <div className="min-w-0">{body}</div>;
    }

    return (
        <Link href={href} className="group min-w-0 rounded-md transition-colors">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 flex items-center gap-1.5 truncate text-sm text-foreground group-hover:text-primary">
                {Icon && (
                    <Icon className="h-3.5 w-3.5 shrink-0 text-primary" aria-hidden="true" />
                )}
                <span className="truncate">{value}</span>
            </dd>
        </Link>
    );
}
