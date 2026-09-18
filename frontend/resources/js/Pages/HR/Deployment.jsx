import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import {
    BadgeCheck,
    CalendarClock,
    CircleCheck,
    CircleSlash,
    FileWarning,
    IdCard,
    OctagonAlert,
    ShieldAlert,
    ShieldCheck,
    ShieldX,
    TriangleAlert,
    Users,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Card,
    SearchInput,
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
import { cn, formatDate, initials, withFilters } from '@/lib/utils';

const DEPLOYMENT_STATUS = {
    ready: { label: 'Ready', variant: 'success', icon: CircleCheck },
    warning: { label: 'Needs attention', variant: 'warning', icon: TriangleAlert },
    blocked: { label: 'Cannot deploy', variant: 'destructive', icon: CircleSlash },
};

function countdown(days) {
    if (days < 0) return `${Math.abs(days)} day(s) overdue`;
    if (days === 0) return 'Today';
    return `in ${days} day(s)`;
}

export default function Deployment({
    rows = [],
    filters = {},
    clients = [],
    summary = {},
    credentials = [],
    credentialsSummary = {},
    credentialTypes = [],
    onboardingRows = [],
    onboardingSummary = {},
    departments = [],
    activeTab: initialTab = 'deployment',
}) {
    const [currentTab, setCurrentTab] = useState(filters.tab || initialTab || 'deployment');

    const apply = (key, value) => {
        router.get(
            '/hr/deployment',
            { ...filters, tab: currentTab, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const switchTab = (tab) => {
        setCurrentTab(tab);
        router.get(
            '/hr/deployment',
            { ...filters, tab },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    // Drill helper
    const drillTo = (changes) =>
        withFilters('/hr/deployment', { ...filters, tab: currentTab }, changes, ['status']);

    const readyRate =
        (summary?.total || 0) > 0 ? ((summary.ready || 0) / summary.total) * 100 : 0;
    const blockingOnboardingRate =
        (onboardingSummary?.incomplete || 0) > 0
            ? ((onboardingSummary.blocking || 0) / onboardingSummary.incomplete) * 100
            : 0;
    const lapsedCredentialsRate =
        (credentialsSummary?.total || 0) > 0
            ? ((credentialsSummary.expired || 0) / credentialsSummary.total) * 100
            : 0;

    const tabs = [
        {
            id: 'deployment',
            label: 'Deployment Readiness',
            icon: BadgeCheck,
            count: `${summary.ready || 0} ready`,
            tone: 'success',
        },
        {
            id: 'onboarding',
            label: '201 File Status',
            icon: FileWarning,
            count: `${onboardingSummary.incomplete || 0} incomplete`,
            tone: (onboardingSummary.blocking || 0) > 0 ? 'destructive' : 'warning',
        },
        {
            id: 'credentials',
            label: 'Credentials & Licenses',
            icon: ShieldAlert,
            count: `${credentialsSummary.total || 0} to monitor`,
            tone: (credentialsSummary.expired || 0) > 0 ? 'destructive' : 'warning',
        },
    ];

    return (
        <AppLayout
            title="Checks & Readiness"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Checks & Readiness' },
            ]}
        >
            {/* Header & Unified Navigation Tabs */}
            <div className="mb-6 space-y-4">
                <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="text-xl font-semibold tracking-tight text-foreground">
                            Checks &amp; Readiness Hub
                        </h2>
                    </div>
                </div>

                {/* Tabs bar */}
                <div className="flex flex-wrap gap-2 border-b border-border pb-1">
                    {tabs.map((tab) => {
                        const Icon = tab.icon;
                        const isActive = currentTab === tab.id;

                        return (
                            <button
                                key={tab.id}
                                type="button"
                                onClick={() => switchTab(tab.id)}
                                className={cn(
                                    'flex items-center gap-2 rounded-t-lg border-b-2 px-4 py-2.5 text-sm font-medium transition-colors',
                                    isActive
                                        ? 'shadow-xs border-primary bg-primary/5 text-primary'
                                        : 'border-transparent text-muted-foreground hover:border-border hover:bg-muted/40 hover:text-foreground',
                                )}
                            >
                                <Icon
                                    className={cn(
                                        'h-4 w-4 shrink-0',
                                        isActive ? 'text-primary' : 'text-muted-foreground',
                                    )}
                                />
                                <span>{tab.label}</span>
                                {tab.count && (
                                    <span
                                        className={cn(
                                            'rounded-full px-2 py-0.5 text-[10px] font-semibold tabular-nums',
                                            isActive
                                                ? 'bg-primary/15 text-primary'
                                                : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {tab.count}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>
            </div>

            {/* TAB 1: DEPLOYMENT READINESS */}
            {currentTab === 'deployment' && (
                <div className="space-y-5">
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <MeterCard
                            label="Ready to Deploy"
                            value={summary.ready || 0}
                            percent={readyRate}
                            badge={`${Math.round(readyRate)}%`}
                            icon={CircleCheck}
                            tone={readyRate >= 80 ? 'success' : 'warning'}
                            iconTone="success"
                            href={drillTo({ status: 'ready' })}
                        />

                        <StatCard
                            label="Needs Attention"
                            value={summary.warning || 0}
                            icon={TriangleAlert}
                            tone={(summary.warning || 0) > 0 ? 'warning' : 'muted'}
                            href={drillTo({ status: 'warning' })}
                        />

                        <StatCard
                            label="Cannot Deploy"
                            value={summary.blocked || 0}
                            icon={CircleSlash}
                            tone={(summary.blocked || 0) > 0 ? 'destructive' : 'muted'}
                            href={drillTo({ status: 'blocked' })}
                        />

                        <StatCard
                            label="Active Clients"
                            value={clients.length}
                            icon={ShieldCheck}
                            tone={clients.length > 0 ? 'primary' : 'muted'}
                            href="/hr/clients"
                        />
                    </div>

                    <Card>
                        <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-center">
                            <div className="lg:w-72">
                                <SearchInput
                                    defaultValue={filters.search ?? ''}
                                    onChange={(event) => apply('search', event.target.value)}
                                    placeholder="Search name or employee number…"
                                    aria-label="Search employees"
                                />
                            </div>

                            <div className="flex flex-1 flex-wrap gap-2 lg:justify-end">
                                <Select
                                    value={filters.status ?? ''}
                                    onChange={(event) => apply('status', event.target.value)}
                                    placeholder="All readiness"
                                    className="w-full sm:w-48"
                                    aria-label="Filter by readiness"
                                    options={[
                                        { value: 'blocked', label: 'Cannot deploy' },
                                        { value: 'warning', label: 'Needs attention' },
                                        { value: 'ready', label: 'Ready' },
                                    ]}
                                />

                                <Select
                                    value={filters.client_id ?? ''}
                                    onChange={(event) => apply('client_id', event.target.value)}
                                    placeholder="All assignments"
                                    className="w-full sm:w-52"
                                    aria-label="Filter by client"
                                    options={clients.map((client) => ({
                                        value: client.id,
                                        label: client.name,
                                    }))}
                                />
                            </div>
                        </div>

                        <Table>
                            <THead>
                                <TR>
                                    <TH>Employee</TH>
                                    <TH>Assignment</TH>
                                    <TH>Readiness</TH>
                                    <TH>Status Reason &amp; Impact</TH>
                                </TR>
                            </THead>

                            <TBody>
                                {rows.length === 0 ? (
                                    <TableEmpty
                                        colSpan={4}
                                        icon={CircleCheck}
                                        title="Nobody matches these filters"
                                        description="Try widening the readiness or assignment filter."
                                    />
                                ) : (
                                    rows.map((row) => {
                                        const status =
                                            DEPLOYMENT_STATUS[row.status] ??
                                            DEPLOYMENT_STATUS.warning;

                                        return (
                                            <TR key={row.employee_id}>
                                                <TD>
                                                    <Link
                                                        href={`/hr/employees/${row.employee_id}`}
                                                        className="font-medium text-foreground hover:text-primary hover:underline"
                                                    >
                                                        {row.employee_name}
                                                    </Link>
                                                    <p className="font-mono text-xs text-muted-foreground">
                                                        {row.employee_number}
                                                    </p>
                                                </TD>

                                                <TD className="text-sm">
                                                    <p className="text-foreground">
                                                        {row.client ?? row.department ?? '—'}
                                                    </p>
                                                    {row.position && (
                                                        <p className="text-xs text-muted-foreground">
                                                            {row.position}
                                                        </p>
                                                    )}
                                                </TD>

                                                <TD className="whitespace-nowrap">
                                                    <Badge variant={status.variant}>
                                                        {status.label}
                                                    </Badge>
                                                </TD>

                                                <TD>
                                                    {row.reasons.length === 0 ? (
                                                        <span className="text-sm text-muted-foreground">
                                                            Credentials current, 201 file
                                                            complete.
                                                        </span>
                                                    ) : (
                                                        <ul className="space-y-1">
                                                            {row.reasons.map(
                                                                (reason, index) => (
                                                                    <li
                                                                        key={index}
                                                                        className={
                                                                            reason.blocking
                                                                                ? 'text-xs font-medium text-destructive'
                                                                                : 'text-xs text-muted-foreground'
                                                                        }
                                                                    >
                                                                        {reason.detail}
                                                                    </li>
                                                                ),
                                                            )}
                                                        </ul>
                                                    )}
                                                </TD>
                                            </TR>
                                        );
                                    })
                                )}
                            </TBody>
                        </Table>
                    </Card>
                </div>
            )}

            {/* TAB 2: 201 FILE STATUS (ONBOARDING) */}
            {currentTab === 'onboarding' && (
                <div className="space-y-5">
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <StatCard
                            label="Incomplete Files"
                            value={onboardingSummary.incomplete || 0}
                            icon={Users}
                            tone={(onboardingSummary.incomplete || 0) > 0 ? 'warning' : 'muted'}
                        />

                        <MeterCard
                            label="Stops Deployment"
                            value={onboardingSummary.blocking || 0}
                            percent={blockingOnboardingRate}
                            badge={
                                (onboardingSummary.incomplete || 0) > 0
                                    ? `${Math.round(blockingOnboardingRate)}%`
                                    : undefined
                            }
                            icon={OctagonAlert}
                            tone="destructive"
                            iconTone={
                                (onboardingSummary.blocking || 0) > 0 ? 'destructive' : 'muted'
                            }
                            href={drillTo({ blocking: filters.blocking ? undefined : '1' })}
                        />

                        <StatCard
                            label="Missing Documents"
                            value={onboardingSummary.missing_documents || 0}
                            icon={FileWarning}
                            tone={
                                (onboardingSummary.missing_documents || 0) > 0
                                    ? 'warning'
                                    : 'muted'
                            }
                        />

                        <StatCard
                            label="Missing Gov’t Numbers"
                            value={onboardingSummary.missing_numbers || 0}
                            icon={IdCard}
                            tone={
                                (onboardingSummary.missing_numbers || 0) > 0 ? 'info' : 'muted'
                            }
                        />
                    </div>

                    <Card>
                        <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-center">
                            <div className="lg:w-72">
                                <SearchInput
                                    defaultValue={filters.search ?? ''}
                                    onChange={(event) => apply('search', event.target.value)}
                                    placeholder="Search employee name or number…"
                                    aria-label="Search employees"
                                />
                            </div>

                            <div className="flex flex-1 flex-wrap items-center gap-2 lg:justify-end">
                                <Select
                                    value={filters.department_id ?? ''}
                                    onChange={(event) =>
                                        apply('department_id', event.target.value)
                                    }
                                    placeholder="All departments"
                                    className="w-full sm:w-56"
                                    aria-label="Filter by department"
                                    options={departments.map((dept) => ({
                                        value: dept.id,
                                        label: dept.name,
                                    }))}
                                />

                                <button
                                    type="button"
                                    onClick={() =>
                                        apply('blocking', filters.blocking ? undefined : '1')
                                    }
                                    className={cn(
                                        'rounded-md border px-3 py-1.5 text-xs font-medium transition-colors',
                                        filters.blocking
                                            ? 'border-destructive/30 bg-destructive/10 font-semibold text-destructive'
                                            : 'border-border text-muted-foreground hover:bg-muted',
                                    )}
                                >
                                    {filters.blocking
                                        ? 'Showing Blocking Only'
                                        : 'Filter Blocking'}
                                </button>
                            </div>
                        </div>

                        <Table>
                            <THead>
                                <TR>
                                    <TH>Employee</TH>
                                    <TH>Department / Position</TH>
                                    <TH>Missing Requirements</TH>
                                    <TH>Deployment Impact</TH>
                                </TR>
                            </THead>

                            <TBody>
                                {onboardingRows.length === 0 ? (
                                    <TableEmpty
                                        colSpan={4}
                                        icon={CircleCheck}
                                        title="All 201 files are complete"
                                        description="No missing mandatory documents or government identification numbers found."
                                    />
                                ) : (
                                    onboardingRows.map((item) => (
                                        <TR key={item.employee_id}>
                                            <TD>
                                                <div className="flex items-center gap-2.5">
                                                    <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                        {initials(item.employee_name)}
                                                    </span>
                                                    <div>
                                                        <Link
                                                            href={`/hr/employees/${item.employee_id}`}
                                                            className="font-medium text-foreground hover:text-primary hover:underline"
                                                        >
                                                            {item.employee_name}
                                                        </Link>
                                                        <p className="font-mono text-xs text-muted-foreground">
                                                            {item.employee_number}
                                                        </p>
                                                    </div>
                                                </div>
                                            </TD>

                                            <TD className="text-sm">
                                                <p className="text-foreground">
                                                    {item.department || '—'}
                                                </p>
                                                {item.position && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {item.position}
                                                    </p>
                                                )}
                                            </TD>

                                            <TD>
                                                <div className="flex flex-wrap gap-1.5">
                                                    {item.missing.map((req, idx) => (
                                                        <span
                                                            key={idx}
                                                            className={cn(
                                                                'rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                                                req.blocking
                                                                    ? 'border-destructive/20 bg-destructive/10 text-destructive'
                                                                    : req.kind === 'document'
                                                                      ? 'border-warning/20 bg-warning/10 text-warning'
                                                                      : 'border-border bg-muted text-muted-foreground',
                                                            )}
                                                            title={
                                                                req.blocking
                                                                    ? 'Mandatory for deployment'
                                                                    : 'Record keeping'
                                                            }
                                                        >
                                                            {req.label}
                                                        </span>
                                                    ))}
                                                </div>
                                            </TD>

                                            <TD className="whitespace-nowrap">
                                                {item.blocking > 0 ? (
                                                    <Badge variant="destructive">
                                                        Stops Deployment ({item.blocking})
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="muted">File Notice</Badge>
                                                )}
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </Card>
                </div>
            )}

            {/* TAB 3: CREDENTIALS & LICENSES */}
            {currentTab === 'credentials' && (
                <div className="space-y-5">
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <StatCard
                            label="Needs Attention"
                            value={credentialsSummary.total || 0}
                            icon={CalendarClock}
                            tone={(credentialsSummary.total || 0) > 0 ? 'warning' : 'muted'}
                        />

                        <MeterCard
                            label="Already Expired"
                            value={credentialsSummary.expired || 0}
                            percent={lapsedCredentialsRate}
                            badge={
                                (credentialsSummary.total || 0) > 0
                                    ? `${Math.round(lapsedCredentialsRate)}%`
                                    : undefined
                            }
                            icon={ShieldX}
                            tone="destructive"
                            iconTone={
                                (credentialsSummary.expired || 0) > 0 ? 'destructive' : 'muted'
                            }
                            href={drillTo({ cred_status: 'expired' })}
                        />

                        <StatCard
                            label="Stops Work / Deployment"
                            value={credentialsSummary.blocking || 0}
                            icon={OctagonAlert}
                            tone={
                                (credentialsSummary.blocking || 0) > 0 ? 'destructive' : 'muted'
                            }
                            href={drillTo({ blocking: filters.blocking ? undefined : '1' })}
                        />

                        <StatCard
                            label="Expiring Soon"
                            value={credentialsSummary.expiring || 0}
                            icon={CalendarClock}
                            tone={(credentialsSummary.expiring || 0) > 0 ? 'warning' : 'muted'}
                            href={drillTo({ cred_status: 'expiring' })}
                        />
                    </div>

                    <Card>
                        <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-center">
                            <div className="lg:w-72">
                                <SearchInput
                                    defaultValue={filters.search ?? ''}
                                    onChange={(event) => apply('search', event.target.value)}
                                    placeholder="Search employee or credential…"
                                    aria-label="Search credentials"
                                />
                            </div>

                            <div className="flex flex-1 flex-wrap items-center gap-2 lg:justify-end">
                                <Select
                                    value={filters.cred_status ?? ''}
                                    onChange={(event) =>
                                        apply('cred_status', event.target.value)
                                    }
                                    placeholder="All expiry statuses"
                                    className="w-full sm:w-44"
                                    aria-label="Filter by status"
                                    options={[
                                        { value: 'expired', label: 'Expired' },
                                        { value: 'expiring', label: 'Expiring soon' },
                                    ]}
                                />

                                <Select
                                    value={filters.cred_type ?? ''}
                                    onChange={(event) => apply('cred_type', event.target.value)}
                                    placeholder="All document types"
                                    className="w-full sm:w-52"
                                    aria-label="Filter by document type"
                                    options={credentialTypes}
                                />

                                <Select
                                    value={filters.department_id ?? ''}
                                    onChange={(event) =>
                                        apply('department_id', event.target.value)
                                    }
                                    placeholder="All departments"
                                    className="w-full sm:w-52"
                                    aria-label="Filter by department"
                                    options={departments.map((dept) => ({
                                        value: dept.id,
                                        label: dept.name,
                                    }))}
                                />
                            </div>
                        </div>

                        <Table>
                            <THead>
                                <TR>
                                    <TH>Employee</TH>
                                    <TH>Credential / Licence</TH>
                                    <TH>Expiry Date</TH>
                                    <TH>Remaining Time</TH>
                                    <TH>Impact</TH>
                                </TR>
                            </THead>

                            <TBody>
                                {credentials.length === 0 ? (
                                    <TableEmpty
                                        colSpan={5}
                                        icon={CircleCheck}
                                        title="All credentials are up to date"
                                        description="No expired or expiring certifications found matching your filters."
                                    />
                                ) : (
                                    credentials.map((cred) => (
                                        <TR key={cred.id}>
                                            <TD>
                                                <div className="flex items-center gap-2.5">
                                                    <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                        {initials(cred.employee_name)}
                                                    </span>
                                                    <div>
                                                        <Link
                                                            href={`/hr/employees/${cred.employee_id}`}
                                                            className="font-medium text-foreground hover:text-primary hover:underline"
                                                        >
                                                            {cred.employee_name}
                                                        </Link>
                                                        <p className="font-mono text-xs text-muted-foreground">
                                                            {cred.employee_number}
                                                        </p>
                                                    </div>
                                                </div>
                                            </TD>

                                            <TD className="text-sm">
                                                <p className="font-medium text-foreground">
                                                    {cred.type_label}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {cred.department || '—'}
                                                </p>
                                            </TD>

                                            <TD className="text-sm">
                                                {formatDate(cred.expiry_date)}
                                            </TD>

                                            <TD className="whitespace-nowrap">
                                                <Badge
                                                    variant={
                                                        cred.status === 'expired'
                                                            ? 'destructive'
                                                            : 'warning'
                                                    }
                                                >
                                                    {countdown(cred.days_remaining)}
                                                </Badge>
                                            </TD>

                                            <TD className="whitespace-nowrap">
                                                {cred.blocking ? (
                                                    <Badge variant="destructive">
                                                        Stops Work
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="muted">Informational</Badge>
                                                )}
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </Card>
                </div>
            )}
        </AppLayout>
    );
}
