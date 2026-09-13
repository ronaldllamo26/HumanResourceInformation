import { Link } from '@inertiajs/react';
import { ArrowRight, CircleCheck, OctagonAlert, TriangleAlert } from 'lucide-react';
import { Card } from '@/Components/ui';

/**
 * What Timekeeping says about the period this run was computed from.
 *
 * A payroll run reads attendance without judging it, so a forgotten time-out
 * understates hours and a pending overtime request pays nothing — both quietly.
 * This says so before the run is submitted, and links to the screen that fixes
 * it rather than only naming the problem.
 */
export default function ReadinessPanel({ readiness }) {
    if (!readiness) return null;

    if (readiness.ready) {
        return (
            <Card className="mb-5">
                <div className="flex items-start gap-3 p-4">
                    <CircleCheck className="mt-0.5 h-5 w-5 shrink-0 text-success" />
                    <div>
                        <p className="text-sm font-medium text-foreground">
                            Time records are complete
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            No missing time-outs, undecided overtime, or employees without a DTR
                            in this period.
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
                    Check the time records before paying this run
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {readiness.blockers > 0 && (
                        <>
                            {readiness.blockers} issue(s) would make the pay wrong
                            {readiness.warnings > 0 && ', '}
                        </>
                    )}
                    {readiness.warnings > 0 && `${readiness.warnings} worth a second look`}. The
                    run still computes — nothing here stops it.
                </p>
            </div>

            <ul className="divide-y divide-border">
                {readiness.checks.map((check) => {
                    const isBlocker = check.severity === 'blocker';
                    const Icon = isBlocker ? OctagonAlert : TriangleAlert;

                    return (
                        <li key={check.key} className="flex items-start gap-3 p-4">
                            <Icon
                                className={`mt-0.5 h-5 w-5 shrink-0 ${
                                    isBlocker ? 'text-destructive' : 'text-warning'
                                }`}
                            />

                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium text-foreground">
                                    {check.title}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {check.detail}
                                </p>

                                {check.employees.length > 0 && (
                                    <p className="mt-1.5 text-xs text-muted-foreground">
                                        {check.employees.join(', ')}
                                    </p>
                                )}
                            </div>

                            <Link
                                href={check.action_href}
                                className="flex shrink-0 items-center gap-1 whitespace-nowrap text-xs font-medium text-primary hover:underline"
                            >
                                {check.action_label}
                                <ArrowRight className="h-3.5 w-3.5" />
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </Card>
    );
}
