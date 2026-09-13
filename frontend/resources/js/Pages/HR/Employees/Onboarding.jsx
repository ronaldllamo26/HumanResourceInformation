import { Link, router } from '@inertiajs/react';
import { BadgeCheck, FileWarning, IdCard, OctagonAlert, Users } from 'lucide-react';
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

export default function Onboarding({ rows, summary, filters, departments }) {
    const applyFilter = (key, value) =>
        router.get(
            '/hr/onboarding',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const drillTo = (changes) => withFilters('/hr/onboarding', filters, changes, ['blocking']);

    // How much of the backlog actually stops somebody working, rather than
    // merely being untidy. That split is the reading.
    const blockingShare =
        summary.incomplete > 0 ? (summary.blocking / summary.incomplete) * 100 : 0;

    return (
        <AppLayout
            title="201 File Completeness"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Onboarding' },
            ]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {/* Grey at zero: an empty 201-file screen means every file is
                    complete, which is the outcome, not an absence of data. */}
                <StatCard
                    label="Incomplete Files"
                    value={summary.incomplete}
                    icon={Users}
                    tone={summary.incomplete > 0 ? 'warning' : 'muted'}
                    hint="missing at least one requirement"
                    href={drillTo({})}
                />

                {/* The hard half. A missing contract or licence is not untidy
                    paperwork — it is somebody who cannot lawfully be sent out. */}
                <MeterCard
                    label="Stops Deployment"
                    value={summary.blocking}
                    percent={blockingShare}
                    badge={summary.incomplete > 0 ? `${Math.round(blockingShare)}%` : undefined}
                    icon={OctagonAlert}
                    tone="destructive"
                    iconTone={summary.blocking > 0 ? 'destructive' : 'muted'}
                    hint={`of ${summary.incomplete} incomplete`}
                    href={drillTo({ blocking: '1' })}
                />

                <StatCard
                    label="Missing Documents"
                    value={summary.missing_documents}
                    icon={FileWarning}
                    tone={summary.missing_documents > 0 ? 'warning' : 'muted'}
                    hint="across every incomplete file"
                />

                {/* Non-blocking on purpose: a missing government number does
                    not stop somebody working, it stops the company filing for
                    them — which Compliance catches later, when it is dearer. */}
                <StatCard
                    label="Missing Gov’t Numbers"
                    value={summary.missing_numbers}
                    icon={IdCard}
                    tone={summary.missing_numbers > 0 ? 'info' : 'muted'}
                    hint="cannot be included in a filing"
                />
            </div>

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-end">
                    <Field label="Department" className="w-full sm:w-56">
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

                    <label className="flex items-center gap-2 pb-2 text-sm text-foreground">
                        <input
                            type="checkbox"
                            checked={Boolean(filters.blocking)}
                            onChange={(event) =>
                                applyFilter('blocking', event.target.checked ? 1 : '')
                            }
                            className="h-4 w-4 rounded border-border text-primary focus:ring-ring"
                        />
                        Only those that stop deployment
                    </label>

                    <p className="pb-2 text-xs text-muted-foreground lg:ml-auto">
                        Requirements are set per position in configuration.
                    </p>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Position</TH>
                            <TH>Hired</TH>
                            <TH>Missing</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={4}
                                icon={BadgeCheck}
                                title="Every 201 file is complete"
                                description="No employee is missing a required document or government number."
                            />
                        ) : (
                            rows.map((row) => (
                                <TR key={row.employee_id}>
                                    <TD>
                                        <Link
                                            href={`/hr/employees/${row.employee_id}`}
                                            className="flex items-center gap-2.5"
                                        >
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                {initials(row.employee_name)}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground hover:underline">
                                                    {row.employee_name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {row.employee_number}
                                                    {row.department
                                                        ? ` · ${row.department}`
                                                        : ''}
                                                </p>
                                            </div>
                                        </Link>
                                    </TD>

                                    <TD className="text-sm text-muted-foreground">
                                        {row.position ?? '—'}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {row.date_hired ? formatDate(row.date_hired) : '—'}
                                    </TD>

                                    <TD>
                                        <div className="flex flex-wrap gap-1.5">
                                            {row.missing.map((item) => (
                                                <Badge
                                                    key={item.key}
                                                    variant={
                                                        item.blocking ? 'destructive' : 'muted'
                                                    }
                                                >
                                                    {item.label}
                                                </Badge>
                                            ))}
                                        </div>
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
