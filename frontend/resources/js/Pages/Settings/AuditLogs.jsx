import { router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Eye,
    FileDown,
    KeyRound,
    ListChecks,
    ScrollText,
    ShieldCheck,
    TriangleAlert,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    DateInput,
    Field,
    Pagination,
    SearchInput,
    Select,
    StatCard,
    Table,
    TableEmpty,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { withFilters } from '@/lib/utils';

/**
 * The audit trail, on its own screen.
 *
 * It used to be 50 unpaginated rows under the password form on Settings →
 * Security, which answered "what happened in the last hour" and nothing else.
 * "Who opened this employee's file in August" needs a range, a person and
 * pages, so that is what this is.
 */

/** Sign-ins, reads and changes are read for different reasons, so they are coloured apart. */
const EVENT_VARIANTS = {
    login: 'success',
    logout: 'muted',
    login_failed: 'destructive',
    lockout: 'destructive',
    created: 'primary',
    updated: 'warning',
    deleted: 'destructive',
    accessed: 'info',
    exported: 'info',
    imported: 'info',
};

const titleCase = (value) =>
    String(value ?? '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());

const stamp = (iso) => (iso ? new Date(iso).toLocaleString() : '—');

export default function AuditLogs({ entries, filters, summary, options, retentionDays }) {
    const [verifying, setVerifying] = useState(false);

    const apply = (changes) =>
        router.get(
            withFilters('/settings/audit-logs', filters, { ...changes, page: undefined }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const verify = () => {
        setVerifying(true);
        router.post(
            '/settings/audit-logs/verify',
            {},
            { preserveScroll: true, onFinish: () => setVerifying(false) },
        );
    };

    const rows = entries.data ?? [];

    return (
        <AppLayout
            title="Audit Logs"
            breadcrumbs={[{ label: 'Administration' }, { label: 'Audit Logs' }]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Entries in range"
                    value={summary.total}
                    icon={ScrollText}
                    tone={summary.total > 0 ? 'primary' : 'muted'}
                    hint="every source, not just the filter"
                />
                <StatCard
                    label="Sign-ins"
                    value={summary.sign_ins}
                    icon={KeyRound}
                    tone={summary.sign_ins > 0 ? 'success' : 'muted'}
                    href={withFilters('/settings/audit-logs', filters, {
                        group: 'auth',
                        event: 'login',
                    })}
                />
                <StatCard
                    label="Failed sign-ins"
                    value={summary.failed}
                    icon={TriangleAlert}
                    tone={summary.failed > 0 ? 'destructive' : 'muted'}
                    hint="wrong password, or a guessed username"
                    href={withFilters('/settings/audit-logs', filters, {
                        group: 'auth',
                        event: 'login_failed',
                    })}
                />
                <StatCard
                    label="Reads & exports"
                    value={summary.reads}
                    icon={Eye}
                    tone={summary.reads > 0 ? 'info' : 'muted'}
                    hint="201 files opened, files downloaded"
                    href={withFilters('/settings/audit-logs', filters, {
                        group: 'reads',
                        event: undefined,
                    })}
                />
            </div>

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:flex-wrap lg:items-end">
                    <Field label="Show" className="w-full lg:w-48">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.group}
                                onChange={(event) =>
                                    apply({ group: event.target.value, event: undefined })
                                }
                                options={options.groups}
                            />
                        )}
                    </Field>
                    <Field label="From" className="w-full lg:w-40">
                        {({ id }) => (
                            <DateInput
                                id={id}
                                value={filters.from}
                                onChange={(event) => apply({ from: event.target.value })}
                            />
                        )}
                    </Field>
                    <Field label="To" className="w-full lg:w-40">
                        {({ id }) => (
                            <DateInput
                                id={id}
                                value={filters.to}
                                onChange={(event) => apply({ to: event.target.value })}
                            />
                        )}
                    </Field>
                    <Field label="Event" className="w-full lg:w-48">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.event ?? ''}
                                onChange={(event) => apply({ event: event.target.value })}
                                placeholder="Any event"
                                options={options.events}
                            />
                        )}
                    </Field>
                    <Field label="By" className="w-full lg:w-48">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.user ?? ''}
                                onChange={(event) => apply({ user: event.target.value })}
                                placeholder="Anyone"
                                options={options.users}
                            />
                        )}
                    </Field>
                    <Field label="Address or record" className="w-full lg:w-52">
                        {({ id }) => (
                            <SearchInput
                                id={id}
                                defaultValue={filters.search ?? ''}
                                placeholder="127.0.0.1 or Employee"
                                onKeyDown={(event) =>
                                    event.key === 'Enter' &&
                                    apply({ search: event.target.value })
                                }
                            />
                        )}
                    </Field>

                    <div className="flex flex-wrap gap-2 lg:ml-auto">
                        <Button
                            variant="outline"
                            href={withFilters('/settings/audit-logs/export', filters)}
                            external
                        >
                            <FileDown className="h-4 w-4" />
                            CSV
                        </Button>
                        <Button variant="outline" onClick={verify} loading={verifying}>
                            <ShieldCheck className="h-4 w-4" />
                            Verify integrity
                        </Button>
                    </div>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>When</TH>
                            <TH>Event</TH>
                            <TH>By</TH>
                            <TH>Record</TH>
                            <TH>Detail</TH>
                            <TH>Address</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={6}
                                icon={ListChecks}
                                title="Nothing in this range"
                                description="Widen the dates, or switch Show to Everything — sign-ins and reads are filtered out of Record changes."
                            />
                        ) : (
                            rows.map((entry) => (
                                <TR key={entry.id}>
                                    <TD className="whitespace-nowrap text-xs text-muted-foreground">
                                        {stamp(entry.created_at)}
                                    </TD>
                                    <TD>
                                        <Badge
                                            variant={EVENT_VARIANTS[entry.event] ?? 'default'}
                                        >
                                            {titleCase(entry.event)}
                                        </Badge>
                                    </TD>
                                    <TD className="text-sm text-foreground">{entry.user}</TD>
                                    <TD className="text-sm text-muted-foreground">
                                        {entry.subject ?? '—'}
                                        {entry.subject_id ? ` #${entry.subject_id}` : ''}
                                    </TD>
                                    <TD className="max-w-72 text-xs text-muted-foreground">
                                        {entry.attempted_login && (
                                            <p className="text-foreground">
                                                tried {entry.attempted_login}
                                            </p>
                                        )}
                                        {entry.changed.filter(Boolean).length > 0 && (
                                            <p className="truncate">
                                                {entry.changed.filter(Boolean).join(', ')}
                                            </p>
                                        )}
                                    </TD>
                                    <TD className="whitespace-nowrap text-xs text-muted-foreground">
                                        {entry.ip_address ?? '—'}
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>

                <Pagination links={entries.links ?? []} meta={entries} />
            </Card>
        </AppLayout>
    );
}
