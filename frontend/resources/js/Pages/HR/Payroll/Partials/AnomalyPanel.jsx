import { CircleCheck, OctagonAlert, TriangleAlert } from 'lucide-react';
import { Card } from '@/Components/ui';

/**
 * What the computed money looks like, checked for the patterns payroll fraud
 * and payroll mistakes leave behind: two salaries into one bank account,
 * somebody paid after leaving, payslips that do not add up.
 *
 * Shown to whoever submits and approves the run, while it can still be sent
 * back. Like the readiness panel it warns and never blocks.
 */
export default function AnomalyPanel({ anomalies }) {
    if (!anomalies) return null;

    if (anomalies.clean) {
        return (
            <Card className="mb-5">
                <div className="flex items-start gap-3 p-4">
                    <CircleCheck className="mt-0.5 h-5 w-5 shrink-0 text-success" />
                    <div>
                        <p className="text-sm font-medium text-foreground">
                            No payroll anomalies found
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            No shared bank accounts, nobody paid after leaving, and every
                            payslip adds up.
                        </p>
                    </div>
                </div>
            </Card>
        );
    }

    return (
        <Card className="mb-5">
            <div className="border-b border-border p-4">
                <p className="text-sm font-medium text-foreground">
                    Review these before approving
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {anomalies.critical > 0 && (
                        <>
                            {anomalies.critical} could mean somebody is paid who should not be
                            {anomalies.warnings > 0 && ', '}
                        </>
                    )}
                    {anomalies.warnings > 0 && `${anomalies.warnings} worth confirming`}.
                    Nothing here stops the run — the approver decides.
                </p>
            </div>

            <ul className="divide-y divide-border">
                {anomalies.findings.map((finding) => {
                    const isCritical = finding.severity === 'critical';
                    const Icon = isCritical ? OctagonAlert : TriangleAlert;

                    return (
                        <li key={finding.key} className="flex items-start gap-3 p-4">
                            <Icon
                                className={`mt-0.5 h-5 w-5 shrink-0 ${
                                    isCritical ? 'text-destructive' : 'text-warning'
                                }`}
                            />
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium text-foreground">
                                    {finding.title}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {finding.detail}
                                </p>
                                {finding.employees.length > 0 && (
                                    <p className="mt-1.5 text-xs text-muted-foreground">
                                        {finding.employees.join(', ')}
                                    </p>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ul>
        </Card>
    );
}
