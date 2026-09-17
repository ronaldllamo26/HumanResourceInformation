import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { ArrowLeft, Check, History, Send, TriangleAlert } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    Field,
    Modal,
    Textarea,
} from '@/Components/ui';
import { cn, formatDate, initials } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

export default function Review({ review, scorecard, weightTotal, weightsBalance, scale, can }) {
    const [acknowledgeOpen, setAcknowledgeOpen] = useState(false);

    const form = useForm({
        ratings: scorecard.map((line) => ({
            kpi_id: line.kpi_id,
            rating: line.rating ?? '',
            actual_value: line.actual_value ?? '',
            comments: line.comments ?? '',
        })),
        strengths: review.strengths ?? '',
        areas_for_improvement: review.areas_for_improvement ?? '',
        goals: review.goals ?? '',
        reviewer_comments: review.reviewer_comments ?? '',
    });

    const acknowledgeForm = useForm({ employee_comments: '' });

    /** Live preview of the weighted score as the reviewer works. */
    const liveScore = useMemo(() => {
        const rated = form.data.ratings
            .map((entry, index) => ({
                rating: parseFloat(entry.rating),
                weight: scorecard[index]?.weight ?? 0,
            }))
            .filter((entry) => !Number.isNaN(entry.rating));

        if (rated.length === 0) return null;

        const weight = rated.reduce((sum, entry) => sum + entry.weight, 0);

        if (weight <= 0) {
            return rated.reduce((sum, entry) => sum + entry.rating, 0) / rated.length;
        }

        return rated.reduce((sum, entry) => sum + entry.rating * entry.weight, 0) / weight;
    }, [form.data.ratings, scorecard]);

    const setRating = (index, key, value) => {
        const next = [...form.data.ratings];
        next[index] = { ...next[index], [key]: value };
        form.setData('ratings', next);
    };

    const submitDraft = (event) => {
        event.preventDefault();
        form.put(`/hr/performance/reviews/${review.id}`, { preserveScroll: true });
    };

    const options = Object.entries(scale.descriptors)
        .map(([value, meta]) => ({ value: Number(value), ...meta }))
        .sort((a, b) => b.value - a.value);

    return (
        <AppLayout
            title={`Evaluation — ${review.employee.full_name}`}
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Performance', href: '/hr/performance' },
                { label: review.employee.full_name },
            ]}
            actions={
                <div className="flex items-center gap-1.5">
                    <Button
                        size="sm"
                        variant="outline"
                        href={`/hr/performance/employees/${review.employee.id}/history`}
                    >
                        <History className="h-4 w-4" />
                        <span className="hidden sm:inline">History</span>
                    </Button>

                    {can.submit && (
                        <Button
                            size="sm"
                            onClick={() =>
                                router.post(
                                    `/hr/performance/reviews/${review.id}/submit`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Send className="h-4 w-4" />
                            <span className="hidden sm:inline">Submit</span>
                        </Button>
                    )}

                    {can.acknowledge && (
                        <Button size="sm" onClick={() => setAcknowledgeOpen(true)}>
                            <Check className="h-4 w-4" />
                            <span className="hidden sm:inline">Acknowledge</span>
                        </Button>
                    )}
                </div>
            }
        >
            <div className="mb-4">
                <Button href="/hr/performance" variant="ghost" size="sm">
                    <ArrowLeft className="h-4 w-4" />
                    Back to evaluations
                </Button>
            </div>

            {/* Header */}
            <Card className="mb-5">
                <CardBody className="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <span className="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-primary/10 text-lg font-semibold text-primary">
                        {initials(review.employee.full_name)}
                    </span>

                    <div className="min-w-0 flex-1">
                        <h2 className="truncate text-base font-semibold text-foreground">
                            {review.employee.full_name}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {review.employee.position ?? 'No position'} ·{' '}
                            {review.employee.employee_number}
                        </p>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <Badge variant="primary">
                                {titleCase(review.reviewer_type)} review
                            </Badge>
                            <Badge
                                status={
                                    review.status === 'acknowledged'
                                        ? 'approved'
                                        : review.status === 'submitted'
                                          ? 'pending'
                                          : 'inactive'
                                }
                            >
                                {titleCase(review.status)}
                            </Badge>
                            <span className="text-xs text-muted-foreground">
                                {review.cycle.name} · {formatDate(review.cycle.period_start)} –{' '}
                                {formatDate(review.cycle.period_end)}
                            </span>
                        </div>
                    </div>

                    <div className="shrink-0 text-right">
                        <p className="text-xs text-muted-foreground">
                            {can.update ? 'Live score' : 'Overall rating'}
                        </p>
                        <p className="text-2xl font-semibold tabular-nums text-foreground">
                            {(can.update ? liveScore : review.overall_rating)?.toFixed(2) ??
                                '—'}
                        </p>
                        {!can.update && review.band && (
                            <p className="text-xs text-muted-foreground">{review.band.label}</p>
                        )}
                    </div>
                </CardBody>
            </Card>

            {!weightsBalance && (
                <div className="mb-5 flex items-start gap-3 rounded-lg border border-warning/30 bg-warning/10 px-4 py-3">
                    <TriangleAlert
                        className="mt-0.5 h-5 w-5 shrink-0 text-warning"
                        aria-hidden="true"
                    />
                    <p className="text-sm text-foreground">
                        This scorecard&apos;s weights total{' '}
                        <span className="font-medium">{weightTotal}</span>, not 100. The score
                        is still computed proportionally, but HR should rebalance the KPI
                        weights.
                    </p>
                </div>
            )}

            <form onSubmit={submitDraft} className="space-y-5">
                {/* KPI scorecard */}
                <Card>
                    <CardHeader title="Scorecard" />

                    <CardBody className="space-y-5">
                        {scorecard.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No KPIs are assigned for this cycle. HR needs to roll out the
                                cycle or add KPIs to the library.
                            </p>
                        )}

                        {scorecard.map((line, index) => (
                            <div
                                key={line.kpi_id}
                                className="rounded-lg border border-border p-4"
                            >
                                <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium text-foreground">
                                            {line.title}
                                        </p>
                                        {line.description && (
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {line.description}
                                            </p>
                                        )}
                                        <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                            {line.category && (
                                                <Badge variant="muted">{line.category}</Badge>
                                            )}
                                            <span className="text-xs text-muted-foreground">
                                                Weight {line.weight}%
                                            </span>
                                            {line.target_value !== null && (
                                                <span className="text-xs text-muted-foreground">
                                                    Target {line.target_value}{' '}
                                                    {line.measurement_unit}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {/* Rating scale */}
                                <div className="flex flex-wrap gap-2">
                                    {options.map((option) => {
                                        const selected =
                                            Number(form.data.ratings[index]?.rating) ===
                                            option.value;

                                        return (
                                            <button
                                                key={option.value}
                                                type="button"
                                                disabled={!can.update}
                                                onClick={() =>
                                                    setRating(index, 'rating', option.value)
                                                }
                                                aria-pressed={selected}
                                                title={option.description}
                                                className={cn(
                                                    'rounded-md border px-3 py-2 text-left text-xs transition-colors',
                                                    'disabled:cursor-not-allowed disabled:opacity-70',
                                                    selected
                                                        ? 'border-primary bg-primary/10 text-primary'
                                                        : 'border-border text-muted-foreground hover:bg-secondary',
                                                )}
                                            >
                                                <span className="block font-semibold tabular-nums">
                                                    {option.value}
                                                </span>
                                                <span className="block">{option.label}</span>
                                            </button>
                                        );
                                    })}
                                </div>

                                <div className="mt-3">
                                    <Textarea
                                        rows={2}
                                        disabled={!can.update}
                                        value={form.data.ratings[index]?.comments ?? ''}
                                        onChange={(event) =>
                                            setRating(index, 'comments', event.target.value)
                                        }
                                        placeholder="Evidence or context for this rating…"
                                    />
                                </div>
                            </div>
                        ))}
                    </CardBody>
                </Card>

                {/* Narrative */}
                <Card>
                    <CardHeader title="Narrative" />
                    <CardBody className="grid gap-4 sm:grid-cols-2">
                        {[
                            ['strengths', 'Strengths'],
                            ['areas_for_improvement', 'Areas for Improvement'],
                            ['goals', 'Goals for Next Cycle'],
                            ['reviewer_comments', 'Reviewer Comments'],
                        ].map(([field, label]) => (
                            <Field key={field} label={label} error={form.errors[field]}>
                                {({ id }) => (
                                    <Textarea
                                        id={id}
                                        rows={3}
                                        disabled={!can.update}
                                        value={form.data[field]}
                                        onChange={(event) =>
                                            form.setData(field, event.target.value)
                                        }
                                    />
                                )}
                            </Field>
                        ))}
                    </CardBody>
                </Card>

                {review.employee_comments && (
                    <Card>
                        <CardHeader title="Employee Acknowledgement" />
                        <CardBody>
                            <p className="text-sm text-foreground">
                                {review.employee_comments}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Acknowledged {formatDate(review.acknowledged_at)}
                            </p>
                        </CardBody>
                    </Card>
                )}

                {can.update && (
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" href="/hr/performance">
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            Save Draft
                        </Button>
                    </div>
                )}
            </form>

            {/* Acknowledgement */}
            <Modal
                show={acknowledgeOpen}
                onClose={() => setAcknowledgeOpen(false)}
                title="Acknowledge this review"
                description="Confirms you have read it. You may add your own comments."
                maxWidth="md"
            >
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        acknowledgeForm.post(
                            `/hr/performance/reviews/${review.id}/acknowledge`,
                            {
                                preserveScroll: true,
                                onSuccess: () => setAcknowledgeOpen(false),
                            },
                        );
                    }}
                    className="space-y-4"
                >
                    <Field
                        label="Your Comments"
                        error={acknowledgeForm.errors.employee_comments}
                    >
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={3}
                                value={acknowledgeForm.data.employee_comments}
                                onChange={(event) =>
                                    acknowledgeForm.setData(
                                        'employee_comments',
                                        event.target.value,
                                    )
                                }
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={() => setAcknowledgeOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={acknowledgeForm.processing}>
                            Acknowledge
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
