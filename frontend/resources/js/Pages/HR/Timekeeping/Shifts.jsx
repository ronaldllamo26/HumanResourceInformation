import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarRange, Clock, Moon, Plus, Trash2, UserPlus } from 'lucide-react';
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
    Pagination,
    Select,
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

const BLANK_SHIFT = {
    code: '',
    name: '',
    start_time: '08:00',
    end_time: '17:00',
    break_minutes: 60,
    grace_minutes: 10,
    is_active: true,
};

const BLANK_ASSIGNMENT = {
    employee_id: '',
    shift_id: '',
    rest_days: [6, 7],
    effective_from: '',
    effective_to: '',
};

export default function Shifts({ shifts, assignments, filters, employees, weekdays, can }) {
    const { auth } = usePage().props;
    const canCreateShift =
        can?.createShift ??
        (auth?.user?.role === 'admin' || auth?.user?.role === 'super_admin');
    const [shiftModal, setShiftModal] = useState(null);
    const [assigning, setAssigning] = useState(false);
    const shiftForm = useForm(BLANK_SHIFT);
    const assignForm = useForm(BLANK_ASSIGNMENT);

    const openShift = (shift = null) => {
        shiftForm.clearErrors();
        shiftForm.setData(shift ? { ...BLANK_SHIFT, ...shift } : BLANK_SHIFT);
        setShiftModal(shift ?? 'new');
    };

    const saveShift = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setShiftModal(null) };

        if (shiftModal === 'new') {
            shiftForm.post('/hr/timekeeping/shifts', options);
        } else {
            shiftForm.put(`/hr/timekeeping/shifts/${shiftModal.id}`, options);
        }
    };

    const removeShift = (shift) => {
        if (
            window.confirm(`Remove shift ${shift.code}? A shift in use is deactivated instead.`)
        ) {
            router.delete(`/hr/timekeeping/shifts/${shift.id}`, { preserveScroll: true });
        }
    };

    const openAssign = () => {
        assignForm.clearErrors();
        assignForm.setData({
            ...BLANK_ASSIGNMENT,
            shift_id: shifts.find((shift) => shift.is_active)?.id ?? '',
            effective_from: new Date().toISOString().slice(0, 10),
        });
        setAssigning(true);
    };

    const toggleRestDay = (day) => {
        const current = assignForm.data.rest_days;
        assignForm.setData(
            'rest_days',
            current.includes(day)
                ? current.filter((value) => value !== day)
                : [...current, day],
        );
    };

    const saveAssignment = (event) => {
        event.preventDefault();
        assignForm.post('/hr/timekeeping/schedules', {
            preserveScroll: true,
            onSuccess: () => setAssigning(false),
        });
    };

    const removeAssignment = (assignment) => {
        if (
            window.confirm(
                `Remove ${assignment.employee}'s schedule from ${formatDate(assignment.effective_from)}?`,
            )
        ) {
            router.delete(`/hr/timekeeping/schedules/${assignment.id}`, {
                preserveScroll: true,
            });
        }
    };

    const rows = assignments.data ?? [];

    return (
        <AppLayout
            title="Shifts & Rest Days"
            breadcrumbs={[...TIMEKEEPING_CRUMBS, { label: 'Shifts & Rest Days' }]}
        >
            <Card className="mb-5">
                <CardHeader
                    title="Shifts"
                    action={
                        canCreateShift ? (
                            <Button onClick={() => openShift()}>
                                <Plus className="h-4 w-4" />
                                New Shift
                            </Button>
                        ) : null
                    }
                />
                <Table>
                    <THead>
                        <TR>
                            <TH>Code</TH>
                            <TH>Name</TH>
                            <TH>Hours</TH>
                            <TH className="text-right">Break</TH>
                            <TH className="text-right">Grace</TH>
                            <TH className="text-right">People</TH>
                            <TH>Status</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {shifts.length === 0 ? (
                            <TableEmpty
                                colSpan={7}
                                icon={Clock}
                                title="No shifts yet"
                                description="Without a shift, lateness and undertime cannot be computed."
                            />
                        ) : (
                            shifts.map((shift) => (
                                <TR key={shift.id}>
                                    <TD className="font-medium text-foreground">
                                        {shift.code}
                                    </TD>
                                    <TD className="text-sm">{shift.name}</TD>
                                    <TD className="whitespace-nowrap text-sm tabular-nums">
                                        {shift.hours}
                                        {shift.overnight && (
                                            <Moon
                                                className="ml-1.5 inline h-3.5 w-3.5 text-muted-foreground"
                                                aria-label="Ends the next morning"
                                            />
                                        )}
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {shift.break_minutes}m
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {shift.grace_minutes}m
                                    </TD>
                                    <TD className="text-right text-sm tabular-nums">
                                        {shift.assignments_count}
                                    </TD>
                                    <TD>
                                        <Badge variant={shift.is_active ? 'success' : 'muted'}>
                                            {shift.is_active ? 'Active' : 'Inactive'}
                                        </Badge>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            <Card>
                <CardHeader
                    title="Employee schedules"
                    action={
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.get(
                                        '/hr/timekeeping/shifts',
                                        filters.history ? {} : { history: 1 },
                                        { preserveScroll: true, preserveState: true },
                                    )
                                }
                            >
                                {filters.history ? 'Current only' : 'Include past'}
                            </Button>
                            <Button onClick={openAssign}>
                                <UserPlus className="h-4 w-4" />
                                Assign Schedule
                            </Button>
                        </div>
                    }
                />
                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Shift</TH>
                            <TH>Rest days</TH>
                            <TH>Effective</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={5}
                                icon={CalendarRange}
                                title="No schedules"
                                description="Employees with no schedule rest on Saturday and Sunday, with no shift to be late against."
                            />
                        ) : (
                            rows.map((assignment) => (
                                <TR key={assignment.id}>
                                    <TD>
                                        <p className="text-sm text-foreground">
                                            {assignment.employee}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {assignment.employee_number}
                                        </p>
                                    </TD>
                                    <TD className="whitespace-nowrap text-sm">
                                        {assignment.shift}
                                    </TD>
                                    <TD>
                                        <div className="flex flex-wrap gap-1">
                                            {assignment.rest_days.length === 0 ? (
                                                <span className="text-xs text-muted-foreground">
                                                    None
                                                </span>
                                            ) : (
                                                assignment.rest_days.map((day) => (
                                                    <Badge key={day} variant="muted">
                                                        {day}
                                                    </Badge>
                                                ))
                                            )}
                                        </div>
                                    </TD>
                                    <TD className="whitespace-nowrap text-sm">
                                        {formatDate(assignment.effective_from)} –{' '}
                                        {assignment.effective_to
                                            ? formatDate(assignment.effective_to)
                                            : 'onwards'}
                                    </TD>
                                    <TD className="text-right">
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            onClick={() => removeAssignment(assignment)}
                                            aria-label="Remove schedule"
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
                <Pagination links={assignments.links ?? []} meta={assignments} />
            </Card>

            <Modal
                show={shiftModal !== null}
                onClose={() => setShiftModal(null)}
                title={shiftModal === 'new' ? 'New Shift' : 'Edit Shift'}
                description="An end time earlier than the start is a night shift ending the next morning."
            >
                <form onSubmit={saveShift} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Code" required error={shiftForm.errors.code}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={shiftForm.data.code}
                                    onChange={(event) =>
                                        shiftForm.setData('code', event.target.value)
                                    }
                                    placeholder="DAY"
                                    required
                                />
                            )}
                        </Field>
                        <Field
                            label="Name"
                            required
                            error={shiftForm.errors.name}
                            className="sm:col-span-2"
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={shiftForm.data.name}
                                    onChange={(event) =>
                                        shiftForm.setData('name', event.target.value)
                                    }
                                    placeholder="Day Shift"
                                    required
                                />
                            )}
                        </Field>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Starts" required error={shiftForm.errors.start_time}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="time"
                                    value={shiftForm.data.start_time}
                                    onChange={(event) =>
                                        shiftForm.setData('start_time', event.target.value)
                                    }
                                    required
                                />
                            )}
                        </Field>
                        <Field label="Ends" required error={shiftForm.errors.end_time}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="time"
                                    value={shiftForm.data.end_time}
                                    onChange={(event) =>
                                        shiftForm.setData('end_time', event.target.value)
                                    }
                                    required
                                />
                            )}
                        </Field>
                        <Field
                            label="Break (minutes)"
                            required
                            error={shiftForm.errors.break_minutes}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    min="0"
                                    max="180"
                                    value={shiftForm.data.break_minutes}
                                    onChange={(event) =>
                                        shiftForm.setData('break_minutes', event.target.value)
                                    }
                                    required
                                />
                            )}
                        </Field>
                        <Field
                            label="Grace (minutes)"
                            required
                            error={shiftForm.errors.grace_minutes}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    min="0"
                                    max="60"
                                    value={shiftForm.data.grace_minutes}
                                    onChange={(event) =>
                                        shiftForm.setData('grace_minutes', event.target.value)
                                    }
                                    required
                                />
                            )}
                        </Field>
                    </div>
                    {shiftModal !== 'new' && (
                        <label className="flex items-center gap-2 text-sm text-foreground">
                            <input
                                type="checkbox"
                                checked={Boolean(shiftForm.data.is_active)}
                                onChange={(event) =>
                                    shiftForm.setData('is_active', event.target.checked)
                                }
                                className="h-4 w-4 rounded border-border text-primary focus:ring-ring"
                            />
                            Active — offered for new schedules
                        </label>
                    )}
                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setShiftModal(null)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" loading={shiftForm.processing}>
                            Save Shift
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal
                show={assigning}
                onClose={() => setAssigning(false)}
                title="Assign Schedule"
                description="Days already recorded keep the figures they were computed with."
            >
                <form onSubmit={saveAssignment} className="space-y-4">
                    <Field label="Employee" required error={assignForm.errors.employee_id}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={assignForm.data.employee_id}
                                onChange={(event) =>
                                    assignForm.setData('employee_id', event.target.value)
                                }
                                placeholder="Choose an employee"
                                options={employees}
                                required
                            />
                        )}
                    </Field>
                    <Field label="Shift" required error={assignForm.errors.shift_id}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={assignForm.data.shift_id}
                                onChange={(event) =>
                                    assignForm.setData('shift_id', event.target.value)
                                }
                                placeholder="Choose a shift"
                                options={shifts
                                    .filter((shift) => shift.is_active)
                                    .map((shift) => ({
                                        value: shift.id,
                                        label: `${shift.code} · ${shift.name} (${shift.hours})`,
                                    }))}
                                required
                            />
                        )}
                    </Field>
                    <Field label="Rest days" error={assignForm.errors.rest_days}>
                        {() => (
                            <div className="flex flex-wrap gap-2">
                                {weekdays.map((day) => {
                                    const on = assignForm.data.rest_days.includes(day.value);

                                    return (
                                        <Button
                                            key={day.value}
                                            type="button"
                                            size="sm"
                                            variant={on ? 'primary' : 'outline'}
                                            onClick={() => toggleRestDay(day.value)}
                                            aria-pressed={on}
                                        >
                                            {day.label}
                                        </Button>
                                    );
                                })}
                            </div>
                        )}
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Effective from"
                            required
                            error={assignForm.errors.effective_from}
                        >
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={assignForm.data.effective_from}
                                    onChange={(event) =>
                                        assignForm.setData('effective_from', event.target.value)
                                    }
                                    required
                                />
                            )}
                        </Field>
                        <Field
                            label="Until"
                            hint="Blank = until changed"
                            error={assignForm.errors.effective_to}
                        >
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={assignForm.data.effective_to}
                                    onChange={(event) =>
                                        assignForm.setData('effective_to', event.target.value)
                                    }
                                />
                            )}
                        </Field>
                    </div>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setAssigning(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" loading={assignForm.processing}>
                            Save Schedule
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
