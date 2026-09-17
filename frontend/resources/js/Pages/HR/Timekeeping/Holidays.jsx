import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarDays, Pencil, Plus, Trash2, TriangleAlert } from 'lucide-react';
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
    StatCard,
    Table,
    TableEmpty,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { formatDate } from '@/lib/utils';
import { TIMEKEEPING_CRUMBS } from './Partials/shared';

const BLANK = { name: '', date: '', type: 'regular' };

const TYPES = [
    { value: 'regular', label: 'Regular holiday' },
    { value: 'special', label: 'Special non-working day' },
];

export default function Holidays({ holidays, filters, years, summary, nextYear, can }) {
    const [editing, setEditing] = useState(null);
    const form = useForm(BLANK);

    const open = (holiday = null) => {
        form.clearErrors();
        form.setData(
            holiday
                ? { name: holiday.name, date: holiday.date, type: holiday.type }
                : { ...BLANK, date: `${filters.year}-01-01` },
        );
        setEditing(holiday ?? 'new');
    };

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (editing === 'new') {
            form.post('/hr/timekeeping/holidays', options);
        } else {
            form.put(`/hr/timekeeping/holidays/${editing.id}`, options);
        }
    };

    const remove = (holiday) => {
        if (window.confirm(`Remove ${holiday.name} (${formatDate(holiday.date)})?`)) {
            router.delete(`/hr/timekeeping/holidays/${holiday.id}`, { preserveScroll: true });
        }
    };

    const changeYear = (year) =>
        router.get(
            '/hr/timekeeping/holidays',
            { year },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <AppLayout
            title="Holiday Calendar"
            breadcrumbs={[...TIMEKEEPING_CRUMBS, { label: 'Holiday Calendar' }]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label={`Holidays in ${filters.year}`}
                    value={summary.total}
                    icon={CalendarDays}
                    tone={summary.total > 0 ? 'primary' : 'warning'}
                    hint="read by attendance, leave and payroll"
                />
                <StatCard
                    label="Regular"
                    value={summary.regular}
                    icon={CalendarDays}
                    tone={summary.regular > 0 ? 'info' : 'muted'}
                    hint="200% when worked"
                />
                <StatCard
                    label="Special non-working"
                    value={summary.special}
                    icon={CalendarDays}
                    tone={summary.special > 0 ? 'info' : 'muted'}
                    hint="130% when worked"
                />
            </div>

            {nextYear.count === 0 && can.manage && (
                <Card className="mb-5">
                    <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start">
                        <TriangleAlert className="mt-0.5 h-5 w-5 shrink-0 text-warning" />
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium text-foreground">
                                No holidays recorded for {nextYear.year} yet
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Leave spanning {nextYear.year} would be charged for holidays as
                                if they were working days, and nobody working one would get the
                                premium.
                            </p>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => changeYear(nextYear.year)}
                        >
                            Set up {nextYear.year}
                        </Button>
                    </div>
                </Card>
            )}

            <Card>
                <CardHeader
                    title={`${filters.year} Holiday Calendar`}
                    action={
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Select
                                value={String(filters.year)}
                                onChange={(event) => changeYear(event.target.value)}
                                aria-label="Year"
                                className="w-full sm:w-32"
                                options={years.map((year) => ({
                                    value: String(year),
                                    label: String(year),
                                }))}
                            />
                            {can.manage && (
                                <Button onClick={() => open()}>
                                    <Plus className="h-4 w-4" />
                                    Add Holiday
                                </Button>
                            )}
                        </div>
                    }
                />
                <Table>
                    <THead>
                        <TR>
                            <TH>Date</TH>
                            <TH>Holiday</TH>
                            <TH>Type</TH>
                            {can.manage && <TH className="text-right">Actions</TH>}
                        </TR>
                    </THead>
                    <TBody>
                        {holidays.length === 0 ? (
                            <TableEmpty
                                colSpan={can.manage ? 4 : 3}
                                icon={CalendarDays}
                                title={`No holidays recorded for ${filters.year}`}
                                description="Every day this year is treated as an ordinary working day until holidays are added."
                            />
                        ) : (
                            holidays.map((holiday) => (
                                <TR key={holiday.id}>
                                    <TD className="whitespace-nowrap">
                                        <p className="text-sm font-medium text-foreground">
                                            {formatDate(holiday.date)}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {holiday.day}
                                        </p>
                                    </TD>
                                    <TD className="text-sm text-foreground">{holiday.name}</TD>
                                    <TD>
                                        <Badge
                                            variant={
                                                holiday.type === 'regular' ? 'primary' : 'muted'
                                            }
                                        >
                                            {holiday.type === 'regular' ? 'Regular' : 'Special'}
                                        </Badge>
                                        <span className="ml-2 text-xs text-muted-foreground">
                                            ×{holiday.multiplier}
                                        </span>
                                    </TD>
                                    {can.manage && (
                                        <TD className="text-right">
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    onClick={() => open(holiday)}
                                                    aria-label="Edit holiday"
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    onClick={() => remove(holiday)}
                                                    aria-label="Remove holiday"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
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
                show={editing !== null}
                onClose={() => setEditing(null)}
                title={editing === 'new' ? 'Add Holiday' : 'Edit Holiday'}
            >
                <form onSubmit={submit} className="space-y-4">
                    <Field label="Name" required error={form.errors.name}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                placeholder="e.g. Eid'l Fitr"
                                required
                            />
                        )}
                    </Field>
                    <Field label="Date" required error={form.errors.date}>
                        {({ id }) => (
                            <DateInput
                                id={id}
                                value={form.data.date}
                                onChange={(event) => form.setData('date', event.target.value)}
                                required
                            />
                        )}
                    </Field>
                    <Field label="Type" required error={form.errors.type}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={form.data.type}
                                onChange={(event) => form.setData('type', event.target.value)}
                                options={TYPES}
                            />
                        )}
                    </Field>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setEditing(null)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            Save Holiday
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
