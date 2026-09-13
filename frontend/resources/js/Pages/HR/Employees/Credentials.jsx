import { Link, router } from '@inertiajs/react';
import { BadgeCheck, CalendarClock, ShieldAlert, ShieldX } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Card,
    Field,
    Select,
    MeterCard,
    StatCard,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
} from '@/Components/ui';
import { formatDate, initials, withFilters } from '@/lib/utils';

/** "in 12 days" reads faster than a date the reader has to subtract from today. */
function countdown(days) {
    if (days < 0) return `${Math.abs(days)} day(s) overdue`;
    if (days === 0) return 'Today';

    return `in ${days} day(s)`;
}

export default function Credentials({
    credentials,
    summary,
    filters,
    departments,
    types,
    statuses,
}) {
    const applyFilter = (key, value) => {
        router.get(
            '/hr/credentials',
            {
                ...filters,
                // The status dropdown drops the tile's  filter: the
                // two cross, and leaving one behind ands them together.
                ...(key === 'status' ? { blocking: undefined } : {}),
                [key]: value || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    /*
     * Clicking a figure opens the rows it counted. `status` and `blocking`
     * cross rather than nest — a blocking document can be either expired or
     * expiring — so one is cleared before the other is set.
     */
    const drillTo = (changes) =>
        withFilters('/hr/credentials', filters, changes, ['status', 'blocking']);

    // How much of the backlog has already lapsed rather than merely
    // approaching. The share is the reading: 4 expired out of 5 is a different
    // morning from 4 out of 60.
    const lapsedShare = summary.total > 0 ? (summary.expired / summary.total) * 100 : 0;

    return (
        <AppLayout
            title="Credential Expiry"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Credentials' },
            ]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {/* The whole backlog. Grey at zero, because an empty
                    credentials screen is the good outcome — everything on file
                    is current. */}
                <StatCard
                    label="Needs Attention"
                    value={summary.total}
                    icon={CalendarClock}
                    tone={summary.total > 0 ? 'warning' : 'muted'}
                    hint="lapsed or inside its renewal window"
                    href={drillTo({})}
                />

                {/* Already lapsed, as a share of the backlog — the split
                    between "too late" and "still time" is the whole reading. */}
                <MeterCard
                    label="Already Expired"
                    value={summary.expired}
                    percent={lapsedShare}
                    badge={summary.total > 0 ? `${Math.round(lapsedShare)}%` : undefined}
                    icon={ShieldX}
                    tone="destructive"
                    iconTone={summary.expired > 0 ? 'destructive' : 'muted'}
                    hint={`of ${summary.total} needing attention`}
                    href={drillTo({ status: 'expired' })}
                />

                <StatCard
                    label="Expiring Soon"
                    value={summary.expiring}
                    icon={ShieldAlert}
                    tone={summary.expiring > 0 ? 'warning' : 'muted'}
                    hint="still time to renew"
                    href={drillTo({ status: 'expiring' })}
                />

                {/* The hardest flag on the screen: a lapsed licence is not
                    untidy paperwork, it is a driver who may not lawfully be
                    dispatched. Destructive, not warning. */}
                <StatCard
                    label="Stops Work"
                    value={summary.blocking}
                    icon={ShieldX}
                    tone={summary.blocking > 0 ? 'destructive' : 'muted'}
                    hint="licence or medical — cannot legally work"
                    href={drillTo({ blocking: '1' })}
                />
            </div>

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:flex-wrap lg:items-end">
                    <Field label="Status" className="w-full sm:w-44">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.status ?? ''}
                                onChange={(event) => applyFilter('status', event.target.value)}
                                placeholder="All"
                                options={statuses}
                            />
                        )}
                    </Field>

                    <Field label="Document Type" className="w-full sm:w-52">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.type ?? ''}
                                onChange={(event) => applyFilter('type', event.target.value)}
                                placeholder="All types"
                                options={types}
                            />
                        )}
                    </Field>

                    <Field label="Department" className="w-full sm:w-52">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.department_id ?? ''}
                                onChange={(event) =>
                                    applyFilter('department_id', event.target.value)
                                }
                                placeholder="All departments"
                                options={departments.map((department) => ({
                                    value: department.id,
                                    label: department.name,
                                }))}
                            />
                        )}
                    </Field>

                    <p className="pb-2 text-xs text-muted-foreground lg:ml-auto">
                        Renewal windows are set per document type in configuration.
                    </p>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Document</TH>
                            <TH>Expires</TH>
                            <TH>Countdown</TH>
                            <TH>Status</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {credentials.length === 0 ? (
                            <TableEmpty
                                colSpan={5}
                                icon={BadgeCheck}
                                title="Everything is in date"
                                description="No licence, medical, or clearance is inside its renewal window."
                            />
                        ) : (
                            credentials.map((item) => (
                                <TR key={item.id}>
                                    <TD>
                                        <Link
                                            href={`/hr/employees/${item.employee_id}`}
                                            className="flex items-center gap-2.5"
                                        >
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                {initials(item.employee_name)}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground hover:underline">
                                                    {item.employee_name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {item.employee_number}
                                                    {item.department
                                                        ? ` · ${item.department}`
                                                        : ''}
                                                </p>
                                            </div>
                                        </Link>
                                    </TD>

                                    <TD>
                                        <p className="text-sm text-foreground">{item.title}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {item.type_label}
                                            {item.blocking && ' · required to work'}
                                        </p>
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {formatDate(item.expires_at)}
                                    </TD>

                                    <TD
                                        className={`whitespace-nowrap text-sm ${
                                            item.days_remaining < 0
                                                ? 'font-medium text-destructive'
                                                : 'text-muted-foreground'
                                        }`}
                                    >
                                        {countdown(item.days_remaining)}
                                    </TD>

                                    <TD>
                                        <Badge
                                            variant={
                                                item.status === 'expired'
                                                    ? 'destructive'
                                                    : 'warning'
                                            }
                                        >
                                            {item.status === 'expired'
                                                ? 'Expired'
                                                : 'Expiring soon'}
                                        </Badge>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>
        </AppLayout>
    );
}
