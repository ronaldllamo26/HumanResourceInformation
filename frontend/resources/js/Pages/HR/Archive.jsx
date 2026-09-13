import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Archive as ArchiveIcon, Handshake, RotateCcw, Users } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    Modal,
    SearchInput,
    StatCard,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
} from '@/Components/ui';
import { formatDate } from '@/lib/utils';

export default function Archive({ rows, filters, summary, window: restoreWindow }) {
    const [pending, setPending] = useState(null);

    const confirmRestore = () => {
        const path =
            pending.kind === 'employee'
                ? `/hr/archive/employees/${pending.id}/restore`
                : `/hr/archive/clients/${pending.id}/restore`;

        router.post(path, {}, { preserveScroll: true, onFinish: () => setPending(null) });
    };

    const search = (value) =>
        router.get(
            '/hr/archive',
            { search: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <AppLayout
            title="Archive"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Archive' },
            ]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Archived Employees"
                    value={summary.employees}
                    icon={Users}
                    tone={summary.employees > 0 ? 'info' : 'muted'}
                    hint="201 files kept in full"
                />
                <StatCard
                    label="Archived Clients"
                    value={summary.clients}
                    icon={Handshake}
                    tone={summary.clients > 0 ? 'info' : 'muted'}
                    hint="payslips keep the client they were filed under"
                />
                <StatCard
                    label="Deleted Recently"
                    value={summary.within_window}
                    icon={ArchiveIcon}
                    tone={summary.within_window > 0 ? 'warning' : 'muted'}
                    hint={`Within the last ${restoreWindow} days`}
                />
            </div>

            <Card>
                <CardHeader
                    title="Deleted Records"
                    description="Nothing here was destroyed. Every row can be put back exactly as it was."
                    action={
                        <div className="w-full sm:w-64">
                            <SearchInput
                                defaultValue={filters.search ?? ''}
                                onChange={(event) => search(event.target.value)}
                                placeholder="Search name, number, or code"
                                aria-label="Search the archive"
                            />
                        </div>
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Record</TH>
                            <TH>Type</TH>
                            <TH>Filed Under</TH>
                            <TH>Deleted</TH>
                            <TH className="text-right" />
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={5}
                                icon={ArchiveIcon}
                                title={
                                    filters.search
                                        ? 'Nothing archived matches that search'
                                        : 'Nothing has been deleted'
                                }
                                description="Deleted employees and clients are kept here so a mis-click costs a click to undo."
                            />
                        ) : (
                            rows.map((row) => (
                                <TR key={`${row.kind}-${row.id}`}>
                                    <TD>
                                        <p className="font-medium text-foreground">
                                            {row.name}
                                        </p>
                                        <p className="font-mono text-xs text-muted-foreground">
                                            {row.reference}
                                        </p>
                                    </TD>

                                    <TD>
                                        <Badge variant="muted">
                                            {row.kind === 'employee' ? 'Employee' : 'Client'}
                                        </Badge>
                                    </TD>

                                    <TD className="text-sm">
                                        <p className="text-foreground">{row.detail}</p>
                                        {row.sub_detail && (
                                            <p className="text-xs text-muted-foreground">
                                                {row.sub_detail}
                                            </p>
                                        )}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm">
                                        <p className="text-foreground">
                                            {formatDate(row.deleted_at)}
                                        </p>
                                        {/* The window changes how a row reads, not
                                            whether it survives — nothing here
                                            expires. */}
                                        <p
                                            className={
                                                row.within_window
                                                    ? 'text-xs font-medium text-warning'
                                                    : 'text-xs text-muted-foreground'
                                            }
                                        >
                                            {row.days_ago === 0
                                                ? 'Today'
                                                : `${row.days_ago} day(s) ago`}
                                        </p>
                                    </TD>

                                    <TD className="text-right">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setPending(row)}
                                        >
                                            <RotateCcw className="h-4 w-4" />
                                            Restore
                                        </Button>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            <p className="mt-4 text-xs text-muted-foreground">
                Records are kept indefinitely, not for {restoreWindow} days — employment records
                must be held three years under the Labor Code and payroll records ten under the
                NIRC. The {restoreWindow}-day mark only highlights recent deletions.
            </p>

            <Modal
                show={pending !== null}
                onClose={() => setPending(null)}
                title="Restore this record?"
                description={
                    pending?.kind === 'employee'
                        ? `${pending?.name} goes back into the directory as active, and their login is re-enabled if they had one.`
                        : `${pending?.name} goes back onto the client list and can be assigned deployments again.`
                }
            >
                <div className="flex justify-end gap-2">
                    <Button variant="outline" onClick={() => setPending(null)}>
                        Cancel
                    </Button>
                    <Button onClick={confirmRestore}>
                        <RotateCcw className="h-4 w-4" />
                        Restore
                    </Button>
                </div>
            </Modal>
        </AppLayout>
    );
}
