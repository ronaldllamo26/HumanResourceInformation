import { router } from '@inertiajs/react';
import { ClipboardList, Star } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    Pagination,
    Select,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
} from '@/Components/ui';
import { initials } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

export default function Index({
    reviews,
    filters,
    statuses,
    reviewerTypes,
    cycles,
    pendingForMe,
}) {
    const applyFilter = (key, value) => {
        router.get(
            '/hr/performance',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const rows = reviews.data ?? [];

    return (
        <AppLayout
            title="Performance Management"
            breadcrumbs={[{ label: 'Human Resource' }, { label: 'Performance Management' }]}
        >
            {pendingForMe > 0 && (
                <div className="mb-5 flex items-start gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-3">
                    <ClipboardList
                        className="mt-0.5 h-5 w-5 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <p className="text-sm text-foreground">
                        You have{' '}
                        <span className="font-medium">
                            {pendingForMe} evaluation{pendingForMe === 1 ? '' : 's'}
                        </span>{' '}
                        still to complete.
                    </p>
                </div>
            )}

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:flex-wrap">
                    <Select
                        value={filters.review_cycle_id ?? ''}
                        onChange={(event) => applyFilter('review_cycle_id', event.target.value)}
                        placeholder="All cycles"
                        className="w-full sm:w-56"
                        aria-label="Filter by cycle"
                        options={cycles.map((cycle) => ({
                            value: cycle.id,
                            label: cycle.name,
                        }))}
                    />

                    <Select
                        value={filters.status ?? ''}
                        onChange={(event) => applyFilter('status', event.target.value)}
                        placeholder="All statuses"
                        className="w-full sm:w-44"
                        aria-label="Filter by status"
                        options={statuses.map((status) => ({
                            value: status,
                            label: titleCase(status),
                        }))}
                    />

                    <Select
                        value={filters.reviewer_type ?? ''}
                        onChange={(event) => applyFilter('reviewer_type', event.target.value)}
                        placeholder="All perspectives"
                        className="w-full sm:w-44"
                        aria-label="Filter by reviewer type"
                        options={reviewerTypes.map((type) => ({
                            value: type,
                            label: titleCase(type),
                        }))}
                    />
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Cycle</TH>
                            <TH>Perspective</TH>
                            <TH>Reviewer</TH>
                            <TH className="text-right">Rating</TH>
                            <TH>Status</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={7}
                                icon={Star}
                                title="No evaluations"
                                description="Roll out a review cycle to create evaluations."
                            />
                        ) : (
                            rows.map((review) => (
                                <TR key={review.id}>
                                    <TD>
                                        <div className="flex items-center gap-2.5">
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                {initials(review.employee)}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {review.employee}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {review.employee_number}
                                                </p>
                                            </div>
                                        </div>
                                    </TD>

                                    <TD className="text-sm text-muted-foreground">
                                        {review.cycle}
                                    </TD>

                                    <TD>
                                        <Badge
                                            variant={
                                                review.reviewer_type === 'supervisor'
                                                    ? 'primary'
                                                    : 'muted'
                                            }
                                        >
                                            {titleCase(review.reviewer_type)}
                                        </Badge>
                                    </TD>

                                    <TD className="text-sm text-muted-foreground">
                                        {review.reviewer}
                                        {review.is_mine_to_write && (
                                            <span className="ml-1 text-xs text-primary">
                                                (you)
                                            </span>
                                        )}
                                    </TD>

                                    <TD className="text-right">
                                        {review.overall_rating ? (
                                            <div>
                                                <span className="text-sm font-medium tabular-nums text-foreground">
                                                    {review.overall_rating.toFixed(2)}
                                                </span>
                                                {review.band && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {review.band.label}
                                                    </p>
                                                )}
                                            </div>
                                        ) : (
                                            <span className="text-sm text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </TD>

                                    <TD>
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
                                    </TD>

                                    <TD>
                                        <div className="flex justify-end">
                                            <Button
                                                size="sm"
                                                variant={
                                                    review.can.update ? 'primary' : 'ghost'
                                                }
                                                href={`/hr/performance/reviews/${review.id}`}
                                            >
                                                {review.can.update
                                                    ? 'Evaluate'
                                                    : review.can.acknowledge
                                                      ? 'Acknowledge'
                                                      : 'View'}
                                            </Button>
                                        </div>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>

                <Pagination links={reviews.meta.links ?? []} meta={reviews.meta} />
            </Card>
        </AppLayout>
    );
}
