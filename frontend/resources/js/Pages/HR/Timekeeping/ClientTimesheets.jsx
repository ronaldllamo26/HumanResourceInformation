import { router } from '@inertiajs/react';
import { useState } from 'react';
import { FileSpreadsheet, Handshake } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Button,
    Card,
    CardHeader,
    Select,
    Table,
    TableEmpty,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { formatDate } from '@/lib/utils';
import { StateBadge, TIMEKEEPING_CRUMBS } from './Partials/shared';

export default function ClientTimesheets({ periods, period, clients }) {
    const [preparing, setPreparing] = useState(null);

    const prepare = (client) => {
        if (
            client.timesheet &&
            !window.confirm(
                `Prepare ${client.name}'s timesheet again from the current records? What was sent before is replaced.`,
            )
        ) {
            return;
        }

        setPreparing(client.id);
        router.post(
            '/hr/timekeeping/client-timesheets',
            { client_id: client.id, payroll_period_id: period.id },
            { onFinish: () => setPreparing(null) },
        );
    };

    return (
        <AppLayout
            title="Client Timesheets"
            breadcrumbs={[...TIMEKEEPING_CRUMBS, { label: 'Client Timesheets' }]}
        >
            <Card>
                <CardHeader
                    title={period ? `Timesheets · ${period.name}` : 'Client Timesheets'}
                    description="For each client, the attendance of the staff deployed there — prepared from the time records, sent to the client, and confirmed or disputed by them."
                    action={
                        periods.length > 0 && (
                            <Select
                                value={period?.id ?? ''}
                                onChange={(event) =>
                                    router.get(
                                        '/hr/timekeeping/client-timesheets',
                                        { period: event.target.value },
                                        {
                                            preserveState: true,
                                            replace: true,
                                        },
                                    )
                                }
                                aria-label="Payroll period"
                                className="w-full sm:w-72"
                                options={periods}
                            />
                        )
                    }
                />
                <Table>
                    <THead>
                        <TR>
                            <TH>Client</TH>
                            <TH className="text-right">Deployed</TH>
                            <TH>Timesheet</TH>
                            <TH>Sent</TH>
                            <TH>Answered</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {!period || clients.length === 0 ? (
                            <TableEmpty
                                colSpan={6}
                                icon={Handshake}
                                title={
                                    period
                                        ? 'Nobody is deployed to a client'
                                        : 'No payroll periods yet'
                                }
                                description="Clients with staff deployed to them are listed here once there is a payroll period."
                            />
                        ) : (
                            clients.map((client) => (
                                <TR key={client.id}>
                                    <TD>
                                        <p className="text-sm font-medium text-foreground">
                                            {client.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {client.code}
                                            {client.contact_person
                                                ? ` · ${client.contact_person}`
                                                : ''}
                                        </p>
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {client.deployed}
                                    </TD>
                                    <TD>
                                        {client.timesheet ? (
                                            <StateBadge status={client.timesheet.status} />
                                        ) : (
                                            <span className="text-xs text-muted-foreground">
                                                Not prepared
                                            </span>
                                        )}
                                    </TD>
                                    <TD className="text-sm text-muted-foreground">
                                        {client.timesheet?.sent_at
                                            ? formatDate(client.timesheet.sent_at)
                                            : '—'}
                                    </TD>
                                    <TD className="text-sm text-muted-foreground">
                                        {client.timesheet?.confirmed_at
                                            ? formatDate(client.timesheet.confirmed_at)
                                            : '—'}
                                    </TD>
                                    <TD className="text-right">
                                        <div className="flex justify-end gap-1">
                                            {client.timesheet && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    href={`/hr/timekeeping/client-timesheets/${client.timesheet.id}`}
                                                >
                                                    Open
                                                </Button>
                                            )}
                                            {client.deployed > 0 &&
                                                client.timesheet?.status !== 'confirmed' && (
                                                    <Button
                                                        size="sm"
                                                        loading={preparing === client.id}
                                                        onClick={() => prepare(client)}
                                                    >
                                                        <FileSpreadsheet className="h-4 w-4" />
                                                        {client.timesheet
                                                            ? 'Prepare again'
                                                            : 'Prepare'}
                                                    </Button>
                                                )}
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
