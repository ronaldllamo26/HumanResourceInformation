import { Link, router } from '@inertiajs/react';
import { CircleCheck, CircleSlash, ShieldCheck, TriangleAlert } from 'lucide-react';
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
import { withFilters } from '@/lib/utils';

/**
 * Blocked is not a stronger warning — it is a different kind of statement. A
 * driver whose licence has lapsed may not lawfully drive, so the row says so
 * in the one colour the system reserves for "this would be wrong".
 */
const STATUS = {
    ready: { label: 'Ready', variant: 'success', icon: CircleCheck },
    warning: { label: 'Needs attention', variant: 'warning', icon: TriangleAlert },
    blocked: { label: 'Cannot deploy', variant: 'destructive', icon: CircleSlash },
};

export default function Deployment({ rows, filters, clients, summary }) {
    const apply = (key, value) =>
        router.get(
            '/hr/deployment',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    // Clicking a figure opens the people it counted, keeping the client and
    // search already applied.
    const drillTo = (changes) => withFilters('/hr/deployment', filters, changes, ['status']);

    const readyRate = summary.total > 0 ? (summary.ready / summary.total) * 100 : 0;

    return (
        <AppLayout
            title="Deployment Readiness"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Deployment Readiness' },
            ]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {/* Ready as a share of the bench — "31 ready" answers nothing
                    until you know whether the bench is 33 or 300. */}
                <MeterCard
                    label="Ready to Deploy"
                    value={summary.ready}
                    percent={readyRate}
                    badge={`${Math.round(readyRate)}%`}
                    icon={CircleCheck}
                    tone={readyRate >= 80 ? 'success' : 'warning'}
                    iconTone="success"
                    hint={`of ${summary.total} on the books`}
                    href={drillTo({ status: 'ready' })}
                />

                <StatCard
                    label="Needs Attention"
                    value={summary.warning}
                    icon={TriangleAlert}
                    tone={summary.warning > 0 ? 'warning' : 'muted'}
                    hint="deployable, but something is due"
                    href={drillTo({ status: 'warning' })}
                />

                {/* The one place this system says "this would be wrong" rather
                    than "someone should look" — a driver whose licence has
                    lapsed may not lawfully drive. */}
                <StatCard
                    label="Cannot Deploy"
                    value={summary.blocked}
                    icon={CircleSlash}
                    tone={summary.blocked > 0 ? 'destructive' : 'muted'}
                    hint="lapsed credential or incomplete file"
                    href={drillTo({ status: 'blocked' })}
                />

                {/* Not a finding — the other side of the question. Readiness
                    only matters against somewhere to send people. */}
                <StatCard
                    label="Clients"
                    value={clients.length}
                    icon={ShieldCheck}
                    tone={clients.length > 0 ? 'primary' : 'muted'}
                    hint="available for deployment"
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
                            <TH>Why</TH>
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
                                const status = STATUS[row.status] ?? STATUS.warning;

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
                                                    Credentials current, 201 file complete.
                                                </span>
                                            ) : (
                                                <ul className="space-y-1">
                                                    {row.reasons.map((reason, index) => (
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
                                                    ))}
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

            <p className="mt-4 text-xs text-muted-foreground">
                A lapsed licence or an incomplete 201 file blocks deployment because dispatching
                anyway is the company&rsquo;s liability, not a matter of tidiness. Anything
                inside its renewal window is reported and left to HR to decide.
            </p>
        </AppLayout>
    );
}
