import { Link } from '@inertiajs/react';
import { CheckCircle2, CircleAlert, ShieldCheck, TriangleAlert } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Badge, Card, CardBody, CardHeader } from '@/Components/ui';
import { cn } from '@/lib/utils';

/**
 * What each finding means, and how hard it argues.
 *
 * `error` is a contradiction — two records cannot both be right. `warning` is
 * a record that is probably just untidy. Nothing here blocks anything; it is
 * a work queue, the same shape as Credentials and 201 File Status.
 */
const KINDS = {
    duplicate_number: {
        icon: CircleAlert,
        label: 'Shared number',
        note: 'Two people cannot hold one government number. At remittance time it puts one employee’s contributions under the other’s name.',
    },
    name_mismatch: {
        icon: CircleAlert,
        label: 'Name mismatch',
        note: 'A filed document named somebody the 201 file does not.',
    },
    date_conflict: {
        icon: CircleAlert,
        label: 'Impossible date',
        note: 'A document dated before the employee was born is a misread year, or somebody else’s paper.',
    },
    number_format: {
        icon: TriangleAlert,
        label: 'Unusual format',
        note: 'Reported, not doubted — an older card, or a keying slip.',
    },
    duplicate_document: {
        icon: TriangleAlert,
        label: 'Two current copies',
        note: 'Of a document an employee holds one of at a time.',
    },
};

function Tile({ label, value, tone }) {
    return (
        <div className="rounded-lg border border-border bg-card px-4 py-3">
            <p className="text-xs text-muted-foreground">{label}</p>
            {/* Zero drops to grey by itself — "0 errors" in red reads as a
                problem when it is the opposite. */}
            <p
                className={cn(
                    'mt-0.5 text-2xl font-semibold tabular-nums',
                    value > 0 ? tone : 'text-muted-foreground',
                )}
            >
                {value}
            </p>
        </div>
    );
}

export default function RecordChecks({ rows = [], summary, checks }) {
    return (
        <AppLayout
            title="Record Checks"
            breadcrumbs={[{ label: 'AI & Analytics' }, { label: 'Record Checks' }]}
        >
            <Card className="mb-6">
                <CardHeader
                    title="Where the records disagree with each other"
                    description="201 File Status asks what is missing and Credentials asks what is lapsing. Neither can see a number keyed against two people, or a document naming somebody else. Every check here is a comparison — there is no model behind this screen."
                />
                <CardBody>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Tile
                            label="Employees checked"
                            value={summary.employees}
                            tone="text-foreground"
                        />
                        <Tile
                            label="With something to fix"
                            value={summary.affected}
                            tone="text-warning"
                        />
                        <Tile
                            label="Contradictions"
                            value={summary.errors}
                            tone="text-destructive"
                        />
                        <Tile
                            label="Findings in total"
                            value={summary.findings}
                            tone="text-warning"
                        />
                    </div>
                </CardBody>
            </Card>

            {rows.length === 0 ? (
                /* An empty screen still has to say what was looked for.
                   "Nothing found" and "nothing is checked" look identical
                   otherwise, and only one of them is good news. */
                <Card>
                    <CardBody className="py-10 text-center">
                        <ShieldCheck
                            className="mx-auto h-10 w-10 text-success"
                            aria-hidden="true"
                        />
                        <p className="mt-3 text-sm font-medium text-foreground">
                            Every record agrees with itself and with the others.
                        </p>
                        <p className="mx-auto mt-1 max-w-lg text-sm text-muted-foreground">
                            All {summary.employees} employees were checked against the rules
                            below. Nothing found is the good outcome — it is not the same as
                            nothing being looked for.
                        </p>

                        <div className="mx-auto mt-6 max-w-2xl space-y-4 text-left">
                            <div>
                                <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    Government numbers, per agency
                                </p>
                                <ul className="space-y-1">
                                    {checks.formats.map((rule) => (
                                        <li
                                            key={rule.label}
                                            className="text-xs text-muted-foreground"
                                        >
                                            <span className="text-foreground">
                                                {rule.label}
                                            </span>{' '}
                                            — {rule.shape}
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            <div>
                                <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    And also
                                </p>
                                <ul className="space-y-1 text-xs text-muted-foreground">
                                    <li>
                                        The same number on two employees — checked across every
                                        record, not only the ones you can see.
                                    </li>
                                    <li>
                                        Two current copies of a {checks.singleCopy.join(', ')}.
                                    </li>
                                    <li>A document dated before the employee was born.</li>
                                    <li>
                                        A scanned document that named somebody other than the
                                        employee it was filed under.
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </CardBody>
                </Card>
            ) : (
                <div className="space-y-4">
                    {rows.map((row) => (
                        <Card key={row.employee_id}>
                            <CardBody>
                                <div className="mb-3 flex flex-wrap items-center gap-2">
                                    <Link
                                        href={`/hr/employees/${row.employee_id}`}
                                        className="text-sm font-medium text-foreground hover:underline"
                                    >
                                        {row.employee_name}
                                    </Link>
                                    <span className="font-mono text-xs text-muted-foreground">
                                        {row.employee_number}
                                    </span>
                                    {row.position && (
                                        <span className="text-xs text-muted-foreground">
                                            {row.position}
                                        </span>
                                    )}
                                    <Badge
                                        variant={
                                            row.severity === 'error' ? 'destructive' : 'warning'
                                        }
                                        className="ml-auto"
                                    >
                                        {row.findings.length}{' '}
                                        {row.findings.length === 1 ? 'finding' : 'findings'}
                                    </Badge>
                                </div>

                                <ul className="space-y-2">
                                    {row.findings.map((finding, index) => {
                                        const kind = KINDS[finding.type] ?? KINDS.number_format;
                                        const Icon = kind.icon;
                                        const bad = finding.severity === 'error';

                                        return (
                                            <li
                                                key={index}
                                                className={cn(
                                                    'rounded-md border p-2.5',
                                                    bad
                                                        ? 'border-destructive/30 bg-destructive/5'
                                                        : 'border-warning/30 bg-warning/5',
                                                )}
                                            >
                                                <p
                                                    className={cn(
                                                        'flex items-start gap-1.5 text-xs font-medium',
                                                        bad
                                                            ? 'text-destructive'
                                                            : 'text-warning',
                                                    )}
                                                >
                                                    <Icon
                                                        className="mt-0.5 h-3.5 w-3.5 shrink-0"
                                                        aria-hidden="true"
                                                    />
                                                    {finding.summary}
                                                </p>
                                                <p className="mt-1 pl-5 text-xs text-foreground">
                                                    {finding.detail}
                                                </p>
                                                {/* Why it matters, not only what
                                                    it is — a finding nobody can
                                                    act on is noise. */}
                                                <p className="mt-0.5 pl-5 text-xs text-muted-foreground">
                                                    {kind.note}
                                                </p>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </CardBody>
                        </Card>
                    ))}
                </div>
            )}

            {rows.length > 0 && (
                <p className="mt-4 flex items-start gap-1.5 text-xs text-muted-foreground">
                    <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    Nothing here blocks anything. It is a work queue — the records still
                    function, they just disagree.
                </p>
            )}
        </AppLayout>
    );
}
