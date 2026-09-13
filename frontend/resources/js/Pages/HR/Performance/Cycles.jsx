import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarClock, Lock, Pencil, Plus, Rocket } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    DateInput,
    Field,
    Input,
    Modal,
    Select,
    Table,
    TableEmpty,
    TBody,
    TD,
    Textarea,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { formatDate } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

const BLANK = {
    name: '',
    type: 'annual',
    period_start: '',
    period_end: '',
    review_due_date: '',
    description: '',
};

export default function Cycles({ cycles, types, can }) {
    const [editing, setEditing] = useState(null); // null | 'new' | cycle

    const form = useForm(BLANK);

    const open = (cycle) => {
        form.clearErrors();
        form.setData(
            cycle === 'new'
                ? BLANK
                : { ...BLANK, ...cycle, review_due_date: cycle.review_due_date ?? '' },
        );
        setEditing(cycle);
    };

    const submit = (event) => {
        event.preventDefault();

        const done = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setEditing(null);
            },
        };

        if (editing === 'new') {
            form.post('/hr/performance/cycles', done);
        } else {
            form.put(`/hr/performance/cycles/${editing.id}`, done);
        }
    };

    return (
        <AppLayout
            title="Performance Management"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Performance', href: '/hr/performance' },
                { label: 'Review Cycles' },
            ]}
        >
            <Card>
                <CardHeader
                    title="Review Cycles"
                    description="Rolling out a cycle builds every scorecard and creates the evaluations to be filled in."
                    action={
                        can.manage && (
                            <Button size="sm" onClick={() => open('new')}>
                                <Plus className="h-4 w-4" />
                                New Cycle
                            </Button>
                        )
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Cycle</TH>
                            <TH>Period</TH>
                            <TH>Due</TH>
                            <TH>Progress</TH>
                            <TH className="text-right">Avg Rating</TH>
                            <TH>Status</TH>
                            {can.manage && <TH className="text-right">Actions</TH>}
                        </TR>
                    </THead>

                    <TBody>
                        {cycles.length === 0 ? (
                            <TableEmpty
                                colSpan={can.manage ? 7 : 6}
                                icon={CalendarClock}
                                title="No review cycles"
                                description="Create a quarterly or annual cycle to begin evaluations."
                            />
                        ) : (
                            cycles.map((cycle) => (
                                <TR key={cycle.id}>
                                    <TD>
                                        <p className="text-sm font-medium text-foreground">
                                            {cycle.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {titleCase(cycle.type)}
                                        </p>
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {formatDate(cycle.period_start)} –{' '}
                                        {formatDate(cycle.period_end)}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {cycle.review_due_date
                                            ? formatDate(cycle.review_due_date)
                                            : '—'}
                                    </TD>

                                    <TD>
                                        <div className="flex items-center gap-2">
                                            <span className="h-2 w-24 overflow-hidden rounded-sm bg-muted">
                                                <span
                                                    className="block h-full rounded-r-[4px] bg-chart-1 transition-[width] duration-500"
                                                    style={{
                                                        width: `${cycle.progress.completion}%`,
                                                    }}
                                                />
                                            </span>
                                            <span className="text-xs tabular-nums text-muted-foreground">
                                                {cycle.progress.completion}%
                                            </span>
                                        </div>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {cycle.progress.submitted +
                                                cycle.progress.acknowledged}{' '}
                                            of {cycle.progress.total} done
                                        </p>
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-foreground">
                                        {cycle.progress.average_rating?.toFixed(2) ?? '—'}
                                    </TD>

                                    <TD>
                                        <Badge
                                            status={
                                                cycle.status === 'closed'
                                                    ? 'inactive'
                                                    : cycle.status === 'draft'
                                                      ? 'pending'
                                                      : 'active'
                                            }
                                        >
                                            {titleCase(cycle.status)}
                                        </Badge>
                                    </TD>

                                    {can.manage && (
                                        <TD>
                                            <div className="flex items-center justify-end gap-1">
                                                {cycle.status !== 'closed' && (
                                                    <>
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/hr/performance/cycles/${cycle.id}/rollout`,
                                                                    {},
                                                                    { preserveScroll: true },
                                                                )
                                                            }
                                                        >
                                                            <Rocket className="h-4 w-4" />
                                                            {cycle.status === 'draft'
                                                                ? 'Roll Out'
                                                                : 'Sync'}
                                                        </Button>

                                                        <button
                                                            type="button"
                                                            onClick={() => open(cycle)}
                                                            aria-label={`Edit ${cycle.name}`}
                                                            className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                        </button>

                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/hr/performance/cycles/${cycle.id}/close`,
                                                                    {},
                                                                    { preserveScroll: true },
                                                                )
                                                            }
                                                            aria-label={`Close ${cycle.name}`}
                                                            className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                                                        >
                                                            <Lock className="h-4 w-4" />
                                                        </button>
                                                    </>
                                                )}
                                            </div>
                                        </TD>
                                    )}
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            <Modal
                show={Boolean(editing)}
                onClose={() => setEditing(null)}
                title={editing === 'new' ? 'New Review Cycle' : 'Edit Review Cycle'}
                maxWidth="lg"
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field
                            label="Name"
                            required
                            className="sm:col-span-2"
                            error={form.errors.name}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    error={form.errors.name}
                                    placeholder="FY2026 Annual Review"
                                />
                            )}
                        </Field>

                        <Field label="Type" required error={form.errors.type}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={form.data.type}
                                    onChange={(event) =>
                                        form.setData('type', event.target.value)
                                    }
                                    options={types.map((type) => ({
                                        value: type,
                                        label: titleCase(type),
                                    }))}
                                />
                            )}
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Period Start" required error={form.errors.period_start}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={form.data.period_start}
                                    onChange={(event) =>
                                        form.setData('period_start', event.target.value)
                                    }
                                    error={form.errors.period_start}
                                />
                            )}
                        </Field>

                        <Field label="Period End" required error={form.errors.period_end}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={form.data.period_end}
                                    onChange={(event) =>
                                        form.setData('period_end', event.target.value)
                                    }
                                    error={form.errors.period_end}
                                />
                            )}
                        </Field>

                        <Field label="Reviews Due" error={form.errors.review_due_date}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={form.data.review_due_date}
                                    onChange={(event) =>
                                        form.setData('review_due_date', event.target.value)
                                    }
                                    error={form.errors.review_due_date}
                                />
                            )}
                        </Field>
                    </div>

                    <Field label="Description" error={form.errors.description}>
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={2}
                                value={form.data.description ?? ''}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setEditing(null)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            Save Cycle
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
