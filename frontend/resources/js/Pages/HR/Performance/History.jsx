import { ArrowLeft, TrendingUp } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Badge, Button, Card, CardBody, CardHeader } from '@/Components/ui';
import { cn, formatDate, initials } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

const SCALE_MAX = 5;

/**
 * Composite score per review cycle.
 *
 * One measure across cycles, so every bar wears the same hue — colour here
 * would encode nothing. Scores are direct-labelled and the axis is fixed to the
 * full 1–5 scale, so a 4.2 never looks like a perfect score.
 */
function TrendChart({ history }) {
    const scored = history.filter((entry) => entry.composite !== null);

    if (scored.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No completed reviews yet — a cycle needs submitted evaluations before it scores.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            {scored.map((entry) => (
                <div
                    key={entry.cycle_id}
                    className="group grid grid-cols-[minmax(0,10rem)_1fr_auto] items-center gap-3"
                    title={`${entry.cycle}: ${entry.composite} of ${SCALE_MAX} — ${entry.band?.label}`}
                >
                    <span className="truncate text-xs text-muted-foreground">
                        {entry.cycle}
                    </span>

                    <span className="h-2.5 w-full overflow-hidden rounded-sm bg-muted">
                        <span
                            className="block h-full rounded-r-[4px] bg-chart-1 transition-[width] duration-500"
                            style={{ width: `${(entry.composite / SCALE_MAX) * 100}%` }}
                        />
                    </span>

                    <span className="w-10 text-right text-xs font-medium tabular-nums text-foreground">
                        {entry.composite.toFixed(2)}
                    </span>
                </div>
            ))}

            <p className="pt-1 text-xs text-muted-foreground">Scored out of {SCALE_MAX}.</p>
        </div>
    );
}

export default function History({ employee, history, weights }) {
    const latest = [...history].reverse().find((entry) => entry.composite !== null);

    return (
        <AppLayout
            title={`Performance — ${employee.full_name}`}
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Performance', href: '/hr/performance' },
                { label: employee.full_name },
            ]}
        >
            <div className="mb-4">
                <Button href="/hr/performance" variant="ghost" size="sm">
                    <ArrowLeft className="h-4 w-4" />
                    Back to evaluations
                </Button>
            </div>

            <Card className="mb-5">
                <CardBody className="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <span className="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-primary/10 text-lg font-semibold text-primary">
                        {initials(employee.full_name)}
                    </span>

                    <div className="min-w-0 flex-1">
                        <h2 className="truncate text-base font-semibold text-foreground">
                            {employee.full_name}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {employee.position ?? 'No position'}
                            {employee.department ? ` · ${employee.department}` : ''} ·{' '}
                            {employee.employee_number}
                        </p>
                    </div>

                    <div className="shrink-0 text-right">
                        <p className="text-xs text-muted-foreground">Latest score</p>
                        <p className="text-2xl font-semibold tabular-nums text-foreground">
                            {latest?.composite?.toFixed(2) ?? '—'}
                        </p>
                        {latest?.band && (
                            <Badge variant={latest.band.variant}>{latest.band.label}</Badge>
                        )}
                    </div>
                </CardBody>
            </Card>

            <div className="grid gap-5 lg:grid-cols-2">
                <Card>
                    <CardHeader
                        title="Performance Trend"
                        description="Composite score for each completed cycle."
                    />
                    <CardBody>
                        <TrendChart history={history} />
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader
                        title="360 Breakdown"
                        description="How each perspective scored, and how much it counts."
                    />
                    <CardBody>
                        {history.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 py-8 text-center">
                                <span className="grid h-11 w-11 place-items-center rounded-full bg-secondary text-muted-foreground">
                                    <TrendingUp className="h-5 w-5" aria-hidden="true" />
                                </span>
                                <p className="text-sm font-medium text-foreground">
                                    No review history
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Roll out a cycle to start building a record.
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-5">
                                {history.map((entry) => (
                                    <div key={entry.cycle_id}>
                                        <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                                            <p className="text-sm font-medium text-foreground">
                                                {entry.cycle}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                to {formatDate(entry.period_end)} ·{' '}
                                                {entry.reviews} review(s)
                                            </p>
                                        </div>

                                        <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                            {Object.entries(entry.perspectives).map(
                                                ([type, score]) => (
                                                    <div
                                                        key={type}
                                                        className={cn(
                                                            'rounded-md border border-border px-2.5 py-2',
                                                            score === null && 'opacity-50',
                                                        )}
                                                    >
                                                        <dt className="text-[10px] uppercase tracking-wide text-muted-foreground">
                                                            {titleCase(type)} ·{' '}
                                                            {Math.round(weights[type] * 100)}%
                                                        </dt>
                                                        <dd className="mt-0.5 text-sm font-medium tabular-nums text-foreground">
                                                            {score?.toFixed(2) ?? '—'}
                                                        </dd>
                                                    </div>
                                                ),
                                            )}
                                        </dl>
                                    </div>
                                ))}

                                <p className="border-t border-border pt-3 text-xs text-muted-foreground">
                                    Perspectives that did not review are re-normalised rather
                                    than counted as zero, so a missing peer review never drags
                                    the score down.
                                </p>
                            </div>
                        )}
                    </CardBody>
                </Card>
            </div>
        </AppLayout>
    );
}
