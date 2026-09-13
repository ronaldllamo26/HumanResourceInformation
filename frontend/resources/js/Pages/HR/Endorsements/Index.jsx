import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle2, Inbox, Radio, UserCheck, XCircle } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    Field,
    Modal,
    Pagination,
    Select,
    StatCard,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
    Textarea,
} from '@/Components/ui';
import { formatDate } from '@/lib/utils';

const STATUS_FILTERS = [
    { value: 'pending', label: 'Awaiting decision' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Declined' },
    { value: 'all', label: 'All' },
];

/**
 * Hires proposed by Core 1, waiting on a decision here.
 *
 * Core 1 recruits; this system employs. Nobody reaches the payroll without
 * somebody on this screen saying yes, which is why there is no "add" button
 * anywhere on it — an endorsement arrives over the API or it does not arrive.
 */
export default function Index({ endorsements, filters, statistics }) {
    const applyStatus = (status) => {
        router.get(
            '/hr/endorsements',
            { search: filters.search || undefined, status },
            { preserveState: true, replace: true },
        );
    };

    /*
     * Which row is being declined, held as the row itself rather than an id so
     * the dialog can name the person. A decline needs a reason typed into it,
     * so it cannot be a button that simply fires: Core 1 reads the outcome
     * back, and a recruiter told only "rejected" sends the same candidate
     * again.
     */
    const [declining, setDeclining] = useState(null);
    const form = useForm({ decision_note: '' });

    const submitDecline = (event) => {
        event.preventDefault();

        form.post(`/hr/endorsements/${declining.id}/reject`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setDeclining(null);
            },
        });
    };

    const rows = endorsements.data ?? [];

    // Nothing to decide on the Approved or Declined views, so the column would
    // be an empty strip on every row. Drawn only when some row can use it.
    const showActions = rows.some((row) => row.can_decide);

    return (
        <AppLayout title="Endorsements from Core 1">
            <div className="mb-5 grid gap-5 sm:grid-cols-3">
                <StatCard
                    label="Awaiting decision"
                    value={statistics.pending}
                    icon={Inbox}
                    /* Zero drops to grey by itself: an empty queue is the good
                       outcome, and an amber "0 waiting" reads as a problem. */
                    tone={statistics.pending > 0 ? 'warning' : 'muted'}
                    href="/hr/endorsements?status=pending"
                    floating
                />
                <StatCard
                    label="Approved"
                    value={statistics.approved}
                    icon={CheckCircle2}
                    tone={statistics.approved > 0 ? 'success' : 'muted'}
                    href="/hr/endorsements?status=approved"
                    floating
                />
                <StatCard
                    label="Declined"
                    value={statistics.rejected}
                    icon={XCircle}
                    tone={statistics.rejected > 0 ? 'muted' : 'muted'}
                    href="/hr/endorsements?status=rejected"
                    floating
                />
            </div>

            <Card floating>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-center">
                    <div className="w-full lg:max-w-[13rem]">
                        <Select
                            value={filters.status}
                            onChange={(event) => applyStatus(event.target.value)}
                            options={STATUS_FILTERS}
                        />
                    </div>

                    {/* No create action, and that is the screen's whole point:
                        an endorsement is written by Core 1 over the API, never
                        typed here. A button would make this a second way to
                        hire and quietly undo the handover. */}
                    <p className="flex items-center gap-1.5 text-xs text-muted-foreground lg:ml-auto">
                        <Radio className="h-3.5 w-3.5" aria-hidden="true" />
                        Received from Core 1 over the API
                    </p>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Candidate</TH>
                            <TH>Reference</TH>
                            <TH>Hired for</TH>
                            <TH>Start date</TH>
                            <TH>Received</TH>
                            <TH>Status</TH>
                            {showActions && <TH className="text-right">Decision</TH>}
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 && (
                            <TableEmpty
                                colSpan={showActions ? 7 : 6}
                                icon={Inbox}
                                title={
                                    filters.status === 'pending'
                                        ? 'Nothing waiting'
                                        : 'No endorsements match this filter'
                                }
                                description={
                                    filters.status === 'pending'
                                        ? 'New hires appear here when Core 1 sends them over.'
                                        : undefined
                                }
                            />
                        )}

                        {rows.map((row) => (
                            <TR key={row.id}>
                                <TD>
                                    <Link
                                        href={`/hr/endorsements/${row.id}`}
                                        className="font-medium text-foreground hover:text-primary"
                                    >
                                        {row.full_name}
                                    </Link>
                                    {row.email && (
                                        <p className="text-xs text-muted-foreground">
                                            {row.email}
                                        </p>
                                    )}
                                </TD>

                                <TD>
                                    <span className="font-mono text-xs text-muted-foreground">
                                        {row.reference}
                                    </span>
                                </TD>

                                <TD>
                                    <p className="text-sm text-foreground">
                                        {row.position_title ?? '—'}
                                    </p>
                                    {row.client_name && (
                                        <p className="text-xs text-muted-foreground">
                                            {row.client_name}
                                        </p>
                                    )}
                                </TD>

                                <TD>{row.date_hired ? formatDate(row.date_hired) : '—'}</TD>

                                <TD>{row.submitted_at ? formatDate(row.submitted_at) : '—'}</TD>

                                <TD>
                                    <Badge status={row.status}>{row.status}</Badge>

                                    {row.employee_number && (
                                        <Link
                                            href={`/hr/employees/${row.employee_id}`}
                                            className="mt-1 block font-mono text-xs text-muted-foreground hover:text-primary"
                                        >
                                            {row.employee_number}
                                        </Link>
                                    )}

                                    {row.status === 'rejected' && row.decided_by && (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            by {row.decided_by}
                                        </p>
                                    )}
                                </TD>

                                {showActions && (
                                    <TD>
                                        {row.can_decide ? (
                                            <div className="flex items-center justify-end gap-1.5">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => setDeclining(row)}
                                                >
                                                    <XCircle className="h-3.5 w-3.5" />
                                                    Decline
                                                </Button>

                                                {/* A link, not a submit, and
                                                    that is the rule rather
                                                    than a shortcut: approving
                                                    opens the employee form.
                                                    Core 1 cannot know the
                                                    salary, the pay frequency,
                                                    the category, or which
                                                    client is billed, and none
                                                    of those may be defaulted
                                                    silently on somebody about
                                                    to be put on a payroll. */}
                                                <Button
                                                    size="sm"
                                                    href={`/hr/employees/create?endorsement=${row.id}`}
                                                >
                                                    <UserCheck className="h-3.5 w-3.5" />
                                                    Approve
                                                </Button>
                                            </div>
                                        ) : (
                                            /* Decided rows keep the column's
                                               width so the table does not step
                                               in and out on a mixed view. */
                                            <span className="sr-only">Already decided</span>
                                        )}
                                    </TD>
                                )}
                            </TR>
                        ))}
                    </TBody>
                </Table>

                <Pagination links={endorsements.meta?.links ?? []} meta={endorsements.meta} />
            </Card>

            {/* The same dialog the review screen uses, for the same reason: a
                decline is an answer Core 1 reads back, and one given without a
                reason fills the queue with the same unstated argument. */}
            <Modal
                show={declining !== null}
                onClose={() => setDeclining(null)}
                title={declining ? `Decline ${declining.full_name}?` : ''}
                description="Core 1 reads this reason back, so say what would have to change."
            >
                <form onSubmit={submitDecline} className="space-y-4">
                    <Field label="Reason" required error={form.errors.decision_note}>
                        <Textarea
                            value={form.data.decision_note}
                            onChange={(event) =>
                                form.setData('decision_note', event.target.value)
                            }
                            error={form.errors.decision_note}
                            rows={4}
                            placeholder="e.g. No LTO licence on file — cannot be deployed as a driver."
                        />
                    </Field>

                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDeclining(null)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" variant="destructive" disabled={form.processing}>
                            Decline endorsement
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
