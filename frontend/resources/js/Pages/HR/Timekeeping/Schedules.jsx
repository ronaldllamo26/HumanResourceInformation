import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarRange, Clock, Moon, Plus } from 'lucide-react';
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
import { cn, formatDate, initials } from '@/lib/utils';

/** ISO weekday numbers — 1 is Monday, matching Carbon's dayOfWeekIso. */
const WEEKDAYS = [
    { value: 1, short: 'Mon' },
    { value: 2, short: 'Tue' },
    { value: 3, short: 'Wed' },
    { value: 4, short: 'Thu' },
    { value: 5, short: 'Fri' },
    { value: 6, short: 'Sat' },
    { value: 7, short: 'Sun' },
];

const BLANK_SHIFT = {
    name: '',
    start_time: '08:00',
    end_time: '17:00',
    break_minutes: 60,
    grace_period_minutes: 15,
    is_night_shift: false,
    is_active: true,
};

export default function Schedules({ shifts, schedules, employees, can }) {
    const [shiftCreating, setShiftCreating] = useState(false);
    const [scheduleOpen, setScheduleOpen] = useState(false);

    const shiftForm = useForm(BLANK_SHIFT);
    const scheduleForm = useForm({
        employee_id: '',
        shift_id: '',
        effective_from: '',
        effective_to: '',
        days_of_week: [1, 2, 3, 4, 5],
    });

    const openShift = () => {
        shiftForm.setData(BLANK_SHIFT);
        setShiftCreating(true);
    };

    const submitShift = (event) => {
        event.preventDefault();

        shiftForm.post('/hr/timekeeping/shifts', {
            preserveScroll: true,
            onSuccess: () => {
                shiftForm.reset();
                setShiftCreating(false);
            },
        });
    };

    const submitSchedule = (event) => {
        event.preventDefault();

        scheduleForm.post('/hr/timekeeping/schedules', {
            preserveScroll: true,
            onSuccess: () => {
                scheduleForm.reset();
                setScheduleOpen(false);
            },
        });
    };

    const toggleDay = (day) => {
        const current = scheduleForm.data.days_of_week;

        scheduleForm.setData(
            'days_of_week',
            current.includes(day) ? current.filter((d) => d !== day) : [...current, day].sort(),
        );
    };

    return (
        <AppLayout
            title="Timekeeping & Attendance"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Timekeeping', href: '/hr/timekeeping' },
                { label: 'Shifts & Schedules' },
            ]}
        >
            {/* Shifts */}
            <Card className="mb-5">
                <CardHeader
                    title="Shifts"
                    description="Start and end times drive every late, undertime, and overtime figure."
                    action={
                        can.manage && (
                            <Button size="sm" onClick={openShift}>
                                <Plus className="h-4 w-4" />
                                New Shift
                            </Button>
                        )
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Shift</TH>
                            <TH>Hours</TH>
                            <TH className="text-right">Break</TH>
                            <TH className="text-right">Grace</TH>
                            <TH className="text-right">Assigned</TH>
                            <TH>Status</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {shifts.length === 0 ? (
                            <TableEmpty
                                colSpan={6}
                                icon={Clock}
                                title="No shifts defined"
                                description="Create a shift before assigning schedules."
                            />
                        ) : (
                            shifts.map((shift) => (
                                <TR key={shift.id}>
                                    <TD>
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-medium text-foreground">
                                                {shift.name}
                                            </span>
                                            {shift.is_night_shift && (
                                                <Moon
                                                    className="h-3.5 w-3.5 text-primary"
                                                    aria-label="Night shift"
                                                />
                                            )}
                                        </div>
                                        {shift.crosses_midnight && (
                                            <p className="text-xs text-muted-foreground">
                                                Ends the next day
                                            </p>
                                        )}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm tabular-nums text-foreground">
                                        {shift.start_time} – {shift.end_time}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {shift.break_minutes}m
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {shift.grace_period_minutes}m
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {shift.assigned_count}
                                    </TD>

                                    <TD>
                                        <Badge
                                            status={shift.is_active ? 'active' : 'inactive'}
                                        />
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            {/* Employee schedules */}
            <Card>
                <CardHeader
                    title="Employee Schedules"
                    description="Which shift an employee works, and on which days. Days off become rest days."
                    action={
                        can.manage && (
                            <Button size="sm" onClick={() => setScheduleOpen(true)}>
                                <Plus className="h-4 w-4" />
                                Assign
                            </Button>
                        )
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Shift</TH>
                            <TH>Working Days</TH>
                            <TH>Effective</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {schedules.data.length === 0 ? (
                            <TableEmpty
                                colSpan={4}
                                icon={CalendarRange}
                                title="No schedules assigned"
                                description="Without a schedule, attendance cannot derive rest days or lateness."
                            />
                        ) : (
                            schedules.data.map((schedule) => (
                                <TR key={schedule.id}>
                                    <TD>
                                        <div className="flex items-center gap-2.5">
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                {initials(schedule.employee.full_name)}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {schedule.employee.full_name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {schedule.employee.employee_number}
                                                </p>
                                            </div>
                                        </div>
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-foreground">
                                        {schedule.shift.name}
                                        <span className="block text-xs tabular-nums text-muted-foreground">
                                            {schedule.shift.start_time} –{' '}
                                            {schedule.shift.end_time}
                                        </span>
                                    </TD>

                                    <TD>
                                        <div className="flex gap-1">
                                            {WEEKDAYS.map((day) => (
                                                <span
                                                    key={day.value}
                                                    title={day.short}
                                                    className={cn(
                                                        'grid h-6 w-6 place-items-center rounded text-[10px] font-medium',
                                                        schedule.days_of_week?.includes(
                                                            day.value,
                                                        )
                                                            ? 'bg-primary/15 text-primary'
                                                            : 'bg-secondary text-muted-foreground/50',
                                                    )}
                                                >
                                                    {day.short[0]}
                                                </span>
                                            ))}
                                        </div>
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {formatDate(schedule.effective_from)}
                                        {' → '}
                                        {schedule.effective_to
                                            ? formatDate(schedule.effective_to)
                                            : 'ongoing'}
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>

                <Pagination links={schedules.meta.links ?? []} meta={schedules.meta} />
            </Card>

            {/* Shift form */}
            <Modal
                show={shiftCreating}
                onClose={() => setShiftCreating(false)}
                title="New Shift"
                description="An end time at or before the start means the shift runs past midnight."
                maxWidth="lg"
            >
                <form onSubmit={submitShift} className="space-y-4">
                    <Field label="Name" required error={shiftForm.errors.name}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={shiftForm.data.name}
                                onChange={(event) =>
                                    shiftForm.setData('name', event.target.value)
                                }
                                error={shiftForm.errors.name}
                                placeholder="e.g. Early Dispatch"
                            />
                        )}
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Start Time" required error={shiftForm.errors.start_time}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="time"
                                    value={shiftForm.data.start_time}
                                    onChange={(event) =>
                                        shiftForm.setData('start_time', event.target.value)
                                    }
                                    error={shiftForm.errors.start_time}
                                />
                            )}
                        </Field>

                        <Field label="End Time" required error={shiftForm.errors.end_time}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="time"
                                    value={shiftForm.data.end_time}
                                    onChange={(event) =>
                                        shiftForm.setData('end_time', event.target.value)
                                    }
                                    error={shiftForm.errors.end_time}
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
                                    value={shiftForm.data.break_minutes}
                                    onChange={(event) =>
                                        shiftForm.setData('break_minutes', event.target.value)
                                    }
                                    error={shiftForm.errors.break_minutes}
                                />
                            )}
                        </Field>

                        <Field
                            label="Grace Period (minutes)"
                            required
                            hint="Arrivals inside this are not late."
                            error={shiftForm.errors.grace_period_minutes}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    min="0"
                                    value={shiftForm.data.grace_period_minutes}
                                    onChange={(event) =>
                                        shiftForm.setData(
                                            'grace_period_minutes',
                                            event.target.value,
                                        )
                                    }
                                    error={shiftForm.errors.grace_period_minutes}
                                />
                            )}
                        </Field>
                    </div>

                    <div className="flex flex-wrap gap-5">
                        <label className="flex items-center gap-2.5">
                            <input
                                type="checkbox"
                                checked={shiftForm.data.is_night_shift}
                                onChange={(event) =>
                                    shiftForm.setData('is_night_shift', event.target.checked)
                                }
                                className="h-4 w-4 rounded border-input text-primary focus:ring-ring/30"
                            />
                            <span className="text-sm text-foreground">Night shift</span>
                        </label>

                        <label className="flex items-center gap-2.5">
                            <input
                                type="checkbox"
                                checked={shiftForm.data.is_active}
                                onChange={(event) =>
                                    shiftForm.setData('is_active', event.target.checked)
                                }
                                className="h-4 w-4 rounded border-input text-primary focus:ring-ring/30"
                            />
                            <span className="text-sm text-foreground">Active</span>
                        </label>
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setShiftCreating(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={shiftForm.processing}>
                            Save Shift
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Assign schedule */}
            <Modal
                show={scheduleOpen}
                onClose={() => setScheduleOpen(false)}
                title="Assign Schedule"
                description="Days left unselected become rest days for this employee."
                maxWidth="lg"
            >
                <form onSubmit={submitSchedule} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Employee"
                            required
                            error={scheduleForm.errors.employee_id}
                        >
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={scheduleForm.data.employee_id}
                                    onChange={(event) =>
                                        scheduleForm.setData('employee_id', event.target.value)
                                    }
                                    placeholder="Select employee"
                                    error={scheduleForm.errors.employee_id}
                                    options={employees.map((employee) => ({
                                        value: employee.id,
                                        label: employee.full_name,
                                    }))}
                                />
                            )}
                        </Field>

                        <Field label="Shift" required error={scheduleForm.errors.shift_id}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={scheduleForm.data.shift_id}
                                    onChange={(event) =>
                                        scheduleForm.setData('shift_id', event.target.value)
                                    }
                                    placeholder="Select shift"
                                    error={scheduleForm.errors.shift_id}
                                    options={shifts
                                        .filter((shift) => shift.is_active)
                                        .map((shift) => ({
                                            value: shift.id,
                                            label: `${shift.name} (${shift.start_time}–${shift.end_time})`,
                                        }))}
                                />
                            )}
                        </Field>

                        <Field
                            label="Effective From"
                            required
                            error={scheduleForm.errors.effective_from}
                        >
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={scheduleForm.data.effective_from}
                                    onChange={(event) =>
                                        scheduleForm.setData(
                                            'effective_from',
                                            event.target.value,
                                        )
                                    }
                                    error={scheduleForm.errors.effective_from}
                                />
                            )}
                        </Field>

                        <Field
                            label="Effective To"
                            hint="Leave blank for ongoing."
                            error={scheduleForm.errors.effective_to}
                        >
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={scheduleForm.data.effective_to}
                                    onChange={(event) =>
                                        scheduleForm.setData('effective_to', event.target.value)
                                    }
                                    error={scheduleForm.errors.effective_to}
                                />
                            )}
                        </Field>
                    </div>

                    <Field
                        label="Working Days"
                        required
                        error={scheduleForm.errors.days_of_week}
                    >
                        <div className="flex flex-wrap gap-2">
                            {WEEKDAYS.map((day) => {
                                const selected = scheduleForm.data.days_of_week.includes(
                                    day.value,
                                );

                                return (
                                    <button
                                        key={day.value}
                                        type="button"
                                        onClick={() => toggleDay(day.value)}
                                        aria-pressed={selected}
                                        className={cn(
                                            'rounded-md border px-3 py-1.5 text-xs font-medium transition-colors',
                                            selected
                                                ? 'border-primary bg-primary/10 text-primary'
                                                : 'border-border text-muted-foreground hover:bg-secondary',
                                        )}
                                    >
                                        {day.short}
                                    </button>
                                );
                            })}
                        </div>
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setScheduleOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={scheduleForm.processing}>
                            Assign
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
