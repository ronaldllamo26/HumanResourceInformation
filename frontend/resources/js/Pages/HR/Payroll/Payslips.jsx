import { Receipt } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    Pagination,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
} from '@/Components/ui';
import { formatCurrency, formatDate } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

export default function Payslips({ payslips, isHr }) {
    const rows = payslips.data ?? [];

    return (
        <AppLayout
            title={isHr ? 'Payroll & Compensation' : 'My Payslips'}
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Payroll', href: isHr ? '/hr/payroll' : undefined },
                { label: 'Payslips' },
            ]}
        >
            <Card>
                <Table>
                    <THead>
                        <TR>
                            <TH>Payslip</TH>
                            {isHr && <TH>Employee</TH>}
                            <TH>Period</TH>
                            <TH>Pay Date</TH>
                            <TH className="text-right">Gross</TH>
                            <TH className="text-right">Deductions</TH>
                            <TH className="text-right">Net Pay</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={isHr ? 8 : 7}
                                icon={Receipt}
                                title="No payslips yet"
                                description={
                                    isHr
                                        ? 'Compute a payroll run to generate payslips.'
                                        : 'Your payslips appear here once payroll is approved.'
                                }
                            />
                        ) : (
                            rows.map((payslip) => (
                                <TR key={payslip.id}>
                                    <TD className="text-sm font-medium text-foreground">
                                        {payslip.payslip_number}
                                    </TD>

                                    {isHr && (
                                        <TD className="text-sm text-foreground">
                                            {payslip.employee}
                                        </TD>
                                    )}

                                    <TD className="text-sm text-muted-foreground">
                                        {payslip.period}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {formatDate(payslip.pay_date)}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-foreground">
                                        {formatCurrency(payslip.gross_pay)}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {formatCurrency(payslip.deductions_total)}
                                    </TD>

                                    <TD className="text-right text-sm font-medium tabular-nums text-foreground">
                                        {formatCurrency(payslip.net_pay)}
                                    </TD>

                                    <TD>
                                        <div className="flex items-center justify-end gap-2">
                                            <Badge status={payslip.status}>
                                                {titleCase(payslip.status)}
                                            </Badge>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                href={`/hr/payroll/payslips/${payslip.id}`}
                                            >
                                                View
                                            </Button>
                                        </div>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>

                <Pagination links={payslips.meta.links ?? []} meta={payslips.meta} />
            </Card>
        </AppLayout>
    );
}
