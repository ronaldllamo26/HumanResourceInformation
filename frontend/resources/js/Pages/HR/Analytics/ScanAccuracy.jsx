import { router } from '@inertiajs/react';
import { CheckCircle2, CircleAlert, MinusCircle, Timer, TriangleAlert } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Card,
    CardBody,
    CardHeader,
    DateInput,
    Table,
    TableEmpty,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { cn, formatDate } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

/**
 * A rate, coloured by where it sits on the scale.
 *
 * Uses the `--grade-*` ramp rather than success/warning/destructive, because
 * an accuracy figure is a *position on a scale* — which is exactly what that
 * ramp is for — and not one of three states.
 */
function Rate({ value }) {
    if (value === null || value === undefined) {
        return <span className="text-muted-foreground">—</span>;
    }

    const grade = value >= 90 ? 1 : value >= 75 ? 2 : value >= 60 ? 3 : value >= 40 ? 5 : 6;

    return <Badge variant={`grade-${grade}`}>{value}%</Badge>;
}

function Tile({ label, value, hint, tone = 'text-foreground', icon: Icon }) {
    return (
        <div className="rounded-lg border border-border bg-card px-4 py-3">
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                {Icon && <Icon className="h-3.5 w-3.5" aria-hidden="true" />}
                {label}
            </p>
            <p className={cn('mt-0.5 text-2xl font-semibold tabular-nums', tone)}>{value}</p>
            {hint && <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

const OUTCOMES = {
    clean: { icon: CheckCircle2, tone: 'text-success', label: 'Filed as read' },
    corrected: { icon: TriangleAlert, tone: 'text-warning', label: 'Corrected' },
    abandoned: { icon: CircleAlert, tone: 'text-muted-foreground', label: 'Abandoned' },
};

export default function ScanAccuracy({
    report,
    filters,
    driver,
    comparedFields = [],
    employees,
}) {
    const { totals, fields, types, sources, recent } = report;

    const setRange = (key, value) =>
        router.get('/hr/scan-accuracy', { ...filters, [key]: value }, { preserveState: true });

    return (
        <AppLayout
            title="Scanner Accuracy"
            breadcrumbs={[{ label: 'AI & Analytics' }, { label: 'Scanner Accuracy' }]}
        >
            <Card className="mb-6">
                <CardHeader
                    title="How the scanner is actually doing"
                    description={`Measured from what HR did with each proposal, on the ${driver} driver. The model reports a confidence of its own on every scan; it is not used here, because asked across six documents it answered "high" six times — including on the readings that were wrong.`}
                    action={
                        <div className="flex flex-wrap items-center gap-2">
                            <DateInput
                                value={filters.from}
                                onChange={(event) => setRange('from', event.target.value)}
                                aria-label="From"
                            />
                            <span className="text-xs text-muted-foreground">to</span>
                            <DateInput
                                value={filters.to}
                                onChange={(event) => setRange('to', event.target.value)}
                                aria-label="To"
                            />
                        </div>
                    }
                />
                <CardBody>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Tile
                            label="Scans"
                            value={totals.scans}
                            hint={`${totals.filed} filed, ${totals.abandoned} abandoned`}
                        />
                        {/* The headline. "How often does this just work" is the
                            question a reader has; a per-field average is not
                            an answer to it. */}
                        <Tile
                            label="Filed with nothing corrected"
                            value={totals.clean_rate === null ? '—' : `${totals.clean_rate}%`}
                            hint={`${totals.clean} of ${totals.filed}`}
                            tone="text-chart-1"
                        />
                        <Tile
                            label="Individual fields kept"
                            value={totals.field_rate === null ? '—' : `${totals.field_rate}%`}
                            hint={`${totals.fields_kept} of ${totals.fields_offered} offered`}
                        />
                        <Tile
                            label="Median scan"
                            value={
                                totals.median_ms === null
                                    ? '—'
                                    : `${(totals.median_ms / 1000).toFixed(1)}s`
                            }
                            hint="Median, not mean — a cold model skews the average"
                            icon={Timer}
                        />
                    </div>

                    {totals.scans === 0 && (
                        <p className="mt-4 flex items-start gap-1.5 text-sm text-muted-foreground">
                            <MinusCircle
                                className="mt-0.5 h-4 w-4 shrink-0"
                                aria-hidden="true"
                            />
                            No scans in this range yet. Every document read on an
                            employee&rsquo;s 201 file is measured here, across {employees}{' '}
                            employees.
                        </p>
                    )}
                </CardBody>
            </Card>

            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader
                        title="By field"
                        description={`Worst first. Only ${comparedFields.map(titleCase).join(', ')} are counted — the number and the name are checks rather than stored values, so there is nothing to compare them against.`}
                    />
                    <CardBody>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Field</TH>
                                    <TH className="text-right">Offered</TH>
                                    <TH className="text-right">Corrected</TH>
                                    <TH className="text-right">Kept</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {fields.map((row) => (
                                    <TR key={row.field}>
                                        <TD>{titleCase(row.field)}</TD>
                                        <TD className="text-right tabular-nums">
                                            {row.offered}
                                        </TD>
                                        <TD className="text-right tabular-nums">
                                            {row.corrected}
                                        </TD>
                                        <TD className="text-right">
                                            <Rate value={row.rate} />
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader
                        title="By how the type was decided"
                        description="The five sources are ordered by an argument — a number a person filed beats a printed heading beats an inference beats the model's guess. This is where that ordering is checked against outcomes instead of asserted."
                    />
                    <CardBody>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Source</TH>
                                    <TH className="text-right">Scans</TH>
                                    <TH className="text-right">Type kept</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {sources.length === 0 ? (
                                    <TableEmpty
                                        colSpan={3}
                                        title="Nothing measured yet"
                                        description="Scan a few documents and the ordering can be checked."
                                    />
                                ) : (
                                    sources.map((row) => (
                                        <TR key={row.source}>
                                            <TD>{titleCase(row.source)}</TD>
                                            <TD className="text-right tabular-nums">
                                                {row.scans}
                                            </TD>
                                            <TD className="text-right">
                                                <Rate value={row.rate} />
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardBody>
                </Card>
            </div>

            <Card className="mt-6">
                <CardHeader
                    title="By document type"
                    description="Counted on the type the document was finally filed as — the answer a person settled on, not the one proposed."
                />
                <CardBody>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Type</TH>
                                <TH className="text-right">Scans</TH>
                                <TH>Most corrected field</TH>
                                <TH className="text-right">Filed as read</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {types.length === 0 ? (
                                <TableEmpty
                                    colSpan={4}
                                    title="Nothing filed yet"
                                    description="A scan appears here once its document is saved."
                                />
                            ) : (
                                types.map((row) => (
                                    <TR key={row.type}>
                                        <TD>{titleCase(row.type)}</TD>
                                        <TD className="text-right tabular-nums">{row.scans}</TD>
                                        <TD className="text-muted-foreground">
                                            {row.worst_field ? titleCase(row.worst_field) : '—'}
                                        </TD>
                                        <TD className="text-right">
                                            <Rate value={row.rate} />
                                        </TD>
                                    </TR>
                                ))
                            )}
                        </TBody>
                    </Table>
                </CardBody>
            </Card>

            <Card className="mt-6">
                <CardHeader
                    title="Recent scans"
                    description="Abandoned scans are counted too — a reading poor enough to start over is a failure, and leaving them out would flatter every figure above."
                />
                <CardBody>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Employee</TH>
                                <TH>Proposed</TH>
                                <TH>Filed as</TH>
                                <TH>Corrected</TH>
                                <TH className="text-right">Took</TH>
                                <TH>Scanned</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {recent.length === 0 ? (
                                <TableEmpty
                                    colSpan={6}
                                    title="No scans in this range"
                                    description="Widen the dates, or read a document on an employee's 201 file."
                                />
                            ) : (
                                recent.map((row) => {
                                    const outcome = OUTCOMES[row.outcome];
                                    const Icon = outcome.icon;

                                    return (
                                        <TR key={row.id}>
                                            <TD>
                                                <span className="flex items-center gap-1.5">
                                                    <Icon
                                                        className={cn(
                                                            'h-3.5 w-3.5',
                                                            outcome.tone,
                                                        )}
                                                        aria-hidden="true"
                                                    />
                                                    {row.employee ?? '—'}
                                                </span>
                                            </TD>
                                            <TD className="text-muted-foreground">
                                                {titleCase(row.proposed_type) || '—'}
                                                {row.type_source && (
                                                    <span className="block text-xs">
                                                        via {titleCase(row.type_source)}
                                                    </span>
                                                )}
                                            </TD>
                                            <TD>
                                                {row.saved_type ? (
                                                    titleCase(row.saved_type)
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        {outcome.label}
                                                    </span>
                                                )}
                                            </TD>
                                            <TD>
                                                {row.corrected.length === 0 ? (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                ) : (
                                                    <span className="flex flex-wrap gap-1">
                                                        {row.corrected.map((field) => (
                                                            <Badge
                                                                key={field}
                                                                variant="warning"
                                                            >
                                                                {titleCase(field)}
                                                            </Badge>
                                                        ))}
                                                    </span>
                                                )}
                                            </TD>
                                            <TD className="text-right tabular-nums text-muted-foreground">
                                                {row.duration_ms
                                                    ? `${(row.duration_ms / 1000).toFixed(1)}s`
                                                    : '—'}
                                            </TD>
                                            <TD className="text-muted-foreground">
                                                {formatDate(row.scanned_at)}
                                            </TD>
                                        </TR>
                                    );
                                })
                            )}
                        </TBody>
                    </Table>
                </CardBody>
            </Card>
        </AppLayout>
    );
}
