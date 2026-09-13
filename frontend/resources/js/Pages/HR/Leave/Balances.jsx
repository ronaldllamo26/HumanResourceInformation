import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Sparkles, Users, Wallet } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Button,
    Card,
    Field,
    Input,
    Modal,
    Select,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
} from '@/Components/ui';
import { cn, initials } from '@/lib/utils';

export default function Balances({ year, years, types, rows, can }) {
    const [editing, setEditing] = useState(null); // { row, type, credits }
    const [accrueOpen, setAccrueOpen] = useState(false);

    const form = useForm({
        employee_id: '',
        leave_type_id: '',
        year,
        credits_earned: 0,
        credits_carried_over: 0,
    });

    const accrueForm = useForm({ year });

    const openEditor = (row, type) => {
        const credits = row.credits[type.id];

        form.setData({
            employee_id: row.employee_id,
            leave_type_id: type.id,
            year,
            credits_earned: credits.earned,
            credits_carried_over: credits.carried_over,
        });

        setEditing({ row, type, credits });
    };

    const submit = (event) => {
        event.preventDefault();

        form.post('/hr/leave/balances', {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        });
    };

    const submitAccrue = (event) => {
        event.preventDefault();

        accrueForm.post('/hr/leave/balances/accrue', {
            preserveScroll: true,
            onSuccess: () => setAccrueOpen(false),
        });
    };

    return (
        <AppLayout
            title="Leave & Absence"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Leave', href: '/hr/leave' },
                { label: 'Balances' },
            ]}
            actions={
                can.adjust && (
                    <Button size="sm" variant="outline" onClick={() => setAccrueOpen(true)}>
                        <Sparkles className="h-4 w-4" />
                        <span className="hidden sm:inline">Accrue Credits</span>
                    </Button>
                )
            }
        >
            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center">
                    <div className="sm:w-40">
                        <Select
                            value={year}
                            onChange={(event) =>
                                router.get(
                                    '/hr/leave/balances',
                                    { year: event.target.value },
                                    {
                                        preserveState: true,
                                        preserveScroll: true,
                                        replace: true,
                                    },
                                )
                            }
                            aria-label="Balance year"
                            options={years.map((value) => ({ value, label: value }))}
                        />
                    </div>

                    <p className="text-xs text-muted-foreground sm:ml-auto">
                        Each cell shows{' '}
                        <span className="font-medium text-foreground">available</span> (earned +
                        carried over − used).
                        {can.adjust && ' Click a cell to adjust.'}
                    </p>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            {types.map((type) => (
                                <TH key={type.id} className="text-right">
                                    <span title={type.name}>{type.code}</span>
                                </TH>
                            ))}
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={types.length + 1}
                                icon={Users}
                                title="No employees to show"
                                description="Leave credits appear once employees exist."
                            />
                        ) : (
                            rows.map((row) => (
                                <TR key={row.employee_id}>
                                    <TD>
                                        <div className="flex items-center gap-2.5">
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                {initials(row.full_name)}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {row.full_name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {row.employee_number}
                                                </p>
                                            </div>
                                        </div>
                                    </TD>

                                    {types.map((type) => {
                                        const credits = row.credits[type.id];
                                        const cell = (
                                            <span
                                                className={cn(
                                                    'tabular-nums',
                                                    credits.available <= 0
                                                        ? 'text-muted-foreground'
                                                        : 'font-medium text-foreground',
                                                )}
                                            >
                                                {credits.available}
                                            </span>
                                        );

                                        return (
                                            <TD key={type.id} className="text-right text-sm">
                                                {can.adjust ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => openEditor(row, type)}
                                                        title={`Earned ${credits.earned} · Carried over ${credits.carried_over} · Used ${credits.used}`}
                                                        className="rounded px-2 py-1 transition-colors hover:bg-secondary"
                                                    >
                                                        {cell}
                                                    </button>
                                                ) : (
                                                    <span
                                                        title={`Earned ${credits.earned} · Used ${credits.used}`}
                                                    >
                                                        {cell}
                                                    </span>
                                                )}
                                            </TD>
                                        );
                                    })}
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            {/* Adjust one employee's credits */}
            <Modal
                show={Boolean(editing)}
                onClose={() => setEditing(null)}
                title="Adjust Leave Credits"
                description={`${editing?.row.full_name} — ${editing?.type.name} (${year})`}
                maxWidth="md"
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="rounded-lg border border-border bg-secondary/40 p-3">
                        <p className="text-xs text-muted-foreground">
                            Used so far:{' '}
                            <span className="font-medium text-foreground">
                                {editing?.credits.used}
                            </span>{' '}
                            day(s). Used credits are moved by approvals, not here.
                        </p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Credits Earned"
                            required
                            error={form.errors.credits_earned}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    step="0.5"
                                    min="0"
                                    value={form.data.credits_earned}
                                    onChange={(event) =>
                                        form.setData('credits_earned', event.target.value)
                                    }
                                    error={form.errors.credits_earned}
                                />
                            )}
                        </Field>

                        <Field
                            label="Carried Over"
                            required
                            error={form.errors.credits_carried_over}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    step="0.5"
                                    min="0"
                                    value={form.data.credits_carried_over}
                                    onChange={(event) =>
                                        form.setData('credits_carried_over', event.target.value)
                                    }
                                    error={form.errors.credits_carried_over}
                                />
                            )}
                        </Field>
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setEditing(null)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            Save
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Accrual */}
            <Modal
                show={accrueOpen}
                onClose={() => setAccrueOpen(false)}
                title="Accrue Leave Credits"
                maxWidth="md"
            >
                <form onSubmit={submitAccrue} className="space-y-4">
                    <div className="flex gap-3">
                        <Wallet
                            className="mt-0.5 h-5 w-5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <p className="text-sm text-muted-foreground">
                            Credits are earned per completed month of service — a 15-day type
                            accrues 1.25 days a month — so someone hired in November earns two
                            months' worth, not a full year. Used credits are untouched, and
                            re-running recomputes rather than adds.
                        </p>
                    </div>

                    <Field label="Year" required error={accrueForm.errors.year}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={accrueForm.data.year}
                                onChange={(event) =>
                                    accrueForm.setData('year', event.target.value)
                                }
                                options={years.map((value) => ({ value, label: value }))}
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setAccrueOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={accrueForm.processing}>
                            Accrue
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
