import { router } from '@inertiajs/react';
import { History as HistoryIcon } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Card,
    Pagination,
    Select,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
} from '@/Components/ui';
import { formatDate, initials } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '').replace(/\b\w/g, (character) => character.toUpperCase());

export default function History({ audits, filters, events }) {
    const applyFilter = (key, value) => {
        router.get(
            '/hr/timekeeping/history',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const rows = audits.data ?? [];

    return (
        <AppLayout
            title="Timekeeping & Attendance"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Timekeeping', href: '/hr/timekeeping' },
                { label: 'History' },
            ]}
        >
            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center">
                    <Select
                        value={filters.event ?? ''}
                        onChange={(event) => applyFilter('event', event.target.value)}
                        placeholder="All events"
                        className="w-full sm:w-48"
                        aria-label="Filter by event"
                        options={events.map((event) => ({
                            value: event,
                            label: titleCase(event),
                        }))}
                    />

                    <p className="text-xs text-muted-foreground sm:ml-auto">
                        Every create, edit, and deletion of a DTR record, newest first.
                    </p>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Event</TH>
                            <TH>Employee</TH>
                            <TH>DTR Date</TH>
                            <TH>What changed</TH>
                            <TH>By</TH>
                            <TH>When</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={6}
                                icon={HistoryIcon}
                                title="No edits recorded"
                                description="Every DTR create, correction, and deletion will appear here."
                            />
                        ) : (
                            rows.map((audit) => (
                                <TR key={audit.id}>
                                    <TD>
                                        <Badge
                                            variant={
                                                audit.event === 'deleted'
                                                    ? 'destructive'
                                                    : audit.event === 'created'
                                                      ? 'success'
                                                      : 'primary'
                                            }
                                        >
                                            {audit.event}
                                        </Badge>
                                    </TD>

                                    <TD>
                                        {audit.employee ? (
                                            <div className="flex items-center gap-2.5">
                                                <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary/10 text-[9px] font-semibold text-primary">
                                                    {initials(audit.employee.full_name)}
                                                </span>
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm text-foreground">
                                                        {audit.employee.full_name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {audit.employee.employee_number}
                                                    </p>
                                                </div>
                                            </div>
                                        ) : (
                                            <span className="text-sm text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {audit.log_date ? formatDate(audit.log_date) : '—'}
                                    </TD>

                                    <TD className="max-w-sm">
                                        <ul className="space-y-0.5">
                                            {audit.changes.map((line, index) => (
                                                <li
                                                    key={index}
                                                    className="truncate text-xs text-muted-foreground"
                                                    title={line}
                                                >
                                                    {line}
                                                </li>
                                            ))}
                                        </ul>
                                    </TD>

                                    <TD className="text-sm text-muted-foreground">
                                        {audit.user}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {formatDate(audit.created_at, {
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        })}
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>

                <Pagination links={audits.meta.links ?? []} meta={audits.meta} />
            </Card>
        </AppLayout>
    );
}
