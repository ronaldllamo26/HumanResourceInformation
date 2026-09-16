import { Link } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Button,
    Card,
    CardHeader,
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

export default function Cutoffs({ periods }) {
    return (
        <AppLayout
            title="Cutoff Closing"
            breadcrumbs={[...TIMEKEEPING_CRUMBS, { label: 'Cutoff Closing' }]}
        >
            <Card>
                <CardHeader
                    title="Payroll periods"
                    description="Close a period once its time records are checked. Closed days cannot be edited, imported, corrected or have overtime decided — so payroll pays from figures that no longer move."
                />
                <Table>
                    <THead>
                        <TR>
                            <TH>Period</TH>
                            <TH>Dates</TH>
                            <TH>Status</TH>
                            <TH className="text-right">Still to settle</TH>
                            <TH>Closed by</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {periods.length === 0 ? (
                            <TableEmpty
                                colSpan={6}
                                icon={Lock}
                                title="No payroll periods yet"
                                description="Periods are created under Payroll; each one gets a cutoff here."
                            />
                        ) : (
                            periods.map((period) => (
                                <TR key={period.id}>
                                    <TD className="text-sm font-medium text-foreground">
                                        <Link
                                            href={`/hr/timekeeping/cutoffs/${period.id}`}
                                            className="hover:underline"
                                        >
                                            {period.name}
                                        </Link>
                                    </TD>
                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {formatDate(period.start_date)} –{' '}
                                        {formatDate(period.end_date)}
                                    </TD>
                                    <TD>
                                        <StateBadge status={period.status} />
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {period.outstanding === null ? (
                                            '—'
                                        ) : (
                                            <span
                                                className={
                                                    period.outstanding > 0
                                                        ? 'text-warning'
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                {period.outstanding}
                                            </span>
                                        )}
                                    </TD>
                                    <TD className="text-sm text-muted-foreground">
                                        {period.closed_by
                                            ? `${period.closed_by} · ${formatDate(period.closed_at)}`
                                            : '—'}
                                    </TD>
                                    <TD className="text-right">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            href={`/hr/timekeeping/cutoffs/${period.id}`}
                                        >
                                            Review
                                        </Button>
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
