import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    ArrowRightLeft,
    Briefcase,
    Building2,
    Check,
    ChevronDown,
    Plus,
    TriangleAlert,
    Users,
    X,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import OrgTabs from './Partials/OrgTabs';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    Field,
    Input,
    Modal,
    SearchInput,
    Select,
    MeterCard,
    StatCard,
} from '@/Components/ui';
import { cn, formatCurrency, initials } from '@/lib/utils';

const BLANK = {
    department_id: '',
    code: '',
    title: '',
    salary_grade: '',
    min_salary: '',
    max_salary: '',
    is_active: true,
};

/** The band, or an honest gap where one was never set. */
function Band({ min, max }) {
    if (min === null || max === null) {
        return <span className="text-xs text-muted-foreground">No band set</span>;
    }

    return (
        <span className="text-xs tabular-nums text-muted-foreground">
            {formatCurrency(min)} – {formatCurrency(max)}
        </span>
    );
}

/**
 * One person holding this title, and the control that moves them off it.
 *
 * The name, the number, and a photo — nothing else. This is a master-data
 * screen answering "who holds this title", which needs no salary, no
 * government number, and no 201 file; the same narrowing the org directory
 * makes, for the same reason.
 */
function HolderRow({ employee, onMove }) {
    return (
        <div className="flex items-center gap-3 border-b border-border px-4 py-2.5 last:border-0">
            {employee.photo_url ? (
                <img
                    src={employee.photo_url}
                    alt=""
                    className="h-9 w-9 shrink-0 rounded-full object-cover"
                />
            ) : (
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">
                    {initials(employee.full_name)}
                </span>
            )}

            <div className="min-w-0 flex-1">
                <Link
                    href={`/hr/employees/${employee.id}`}
                    className="block truncate text-sm font-medium text-foreground hover:text-primary"
                >
                    {employee.full_name}
                </Link>
                <p className="truncate font-mono text-[11px] text-muted-foreground">
                    {employee.employee_number}
                </p>
            </div>

            {/* A button, not a dropdown on every row.
                A select per person put a 240px control against every name and
                truncated the option inside it to "HR Officer · Human Resourc",
                so the list read as a column of half-sentences rather than as a
                list of people. The destination is a question asked once, about
                one person, and it is asked in a dialog where the titles have
                room to be read and compared. */}
            <Button
                variant="ghost"
                size="sm"
                className="shrink-0"
                onClick={() => onMove(employee)}
            >
                <ArrowRightLeft className="h-3.5 w-3.5" aria-hidden="true" />
                <span className="hidden sm:inline">Move</span>
            </Button>
        </div>
    );
}

/**
 * A position, closed until somebody asks who is in it.
 *
 * The screen opens on the titles — a shape a reader takes in at once — rather
 * than on a table whose headcount column can only be counted, never opened.
 * Expanding is the question being asked: "who is a Dispatcher?"
 *
 * A position with nobody in it is still drawn and still opens. An empty title
 * is a real state on an org chart, and hiding it would make the list quietly
 * shorter than the master data it is supposed to be showing.
 */
function PositionBlock({ position, open, onToggle, onMove }) {
    const holders = position.employees ?? [];

    return (
        <Card className="mb-3">
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                className="flex w-full items-center gap-3 px-5 py-4 text-left transition-colors hover:bg-secondary/40"
            >
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                    <Briefcase className="h-[18px] w-[18px]" aria-hidden="true" />
                </span>

                <div className="min-w-0 flex-1">
                    <h3 className="truncate text-sm font-semibold text-foreground">
                        {position.title}
                    </h3>
                    <p className="truncate text-[11px] text-muted-foreground">
                        <span className="font-mono">{position.code}</span>
                        {position.department && ` · ${position.department}`}
                        {position.salary_grade && ` · ${position.salary_grade}`}
                    </p>
                </div>

                <div className="hidden shrink-0 sm:block">
                    <Band min={position.min_salary} max={position.max_salary} />
                </div>

                {/* Deactivated is not deleted — the title stays listed so the
                    history filed under it keeps its name. Only ever drawn when
                    it is the exception, so the row is quiet when it is not. */}
                {!position.is_active && <Badge variant="muted">Inactive</Badge>}

                <Badge variant={position.employees_count > 0 ? 'info' : 'muted'}>
                    {position.employees_count}{' '}
                    {position.employees_count === 1 ? 'person' : 'people'}
                </Badge>

                <ChevronDown
                    className={cn(
                        'h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200',
                        open && 'rotate-180',
                    )}
                    aria-hidden="true"
                />
            </button>

            {open && (
                <div className="border-t border-border">
                    {holders.length === 0 ? (
                        <p className="px-5 py-4 text-xs text-muted-foreground">
                            Nobody holds this title yet.
                        </p>
                    ) : (
                        holders.map((employee) => (
                            <HolderRow key={employee.id} employee={employee} onMove={onMove} />
                        ))
                    )}
                </div>
            )}
        </Card>
    );
}

export default function Positions({
    positions,
    filters,
    departments,
    summary,
    moveTargets = [],
}) {
    const [creating, setCreating] = useState(false);

    /*
     * Which positions are open. Closed to begin with, and more than one may be
     * open at once — a master-data screen is being *read*, and having one
     * title close itself because somebody opened another takes away what they
     * were halfway through. The same choice the org directory makes.
     */
    const [expanded, setExpanded] = useState(() => new Set());

    /** The move being made: { employee, from, to } — `to` is null until picked. */
    const [moving, setMoving] = useState(null);

    /** Narrows the destination table inside the dialog, not the page. */
    const [targetSearch, setTargetSearch] = useState('');

    const form = useForm(BLANK);
    const moveForm = useForm({ position_id: '' });

    const toggle = (id) =>
        setExpanded((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);

            return next;
        });

    /*
     * A search opens what it matched. Without this, searching a closed list
     * returns the right answer and shows a screen of shut cards — the reader
     * would have to open each one to find out which of them the search meant.
     */
    const searching = Boolean(filters.search || filters.department_id);
    const isOpen = (id) => searching || expanded.has(id);

    const open = () => {
        form.clearErrors();
        form.setData(BLANK);
        setCreating(true);
    };

    const submit = (event) => {
        event.preventDefault();

        form.post('/hr/positions', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setCreating(false);
            },
        });
    };

    /*
     * Opens the dialog with nowhere chosen yet. The destination is picked from
     * a table inside it rather than from a control on the row: a move is an
     * undoable change to somebody's record, and the two things worth saying —
     * the department follows, the pay does not — have to be readable *before*
     * it happens rather than in a toast afterwards.
     */
    const proposeMove = (employee, fromPosition) =>
        setMoving({ employee, from: fromPosition, to: null });

    const pickTarget = (target) => setMoving((current) => ({ ...current, to: target }));

    /*
     * Everywhere they could go, minus where they already are. Filtered by the
     * dialog's own search rather than the page's, because these are different
     * questions: the page filter narrows what is being *read*, this one narrows
     * what is being *chosen from*, and reusing one for the other would move the
     * list behind the reader.
     */
    const destinations = moveTargets.filter((target) => {
        if (!moving || target.value === moving.from.id) return false;
        if (!targetSearch.trim()) return true;

        const needle = targetSearch.trim().toLowerCase();

        return [target.title, target.code, target.department]
            .filter(Boolean)
            .some((field) => field.toLowerCase().includes(needle));
    });

    const confirmMove = () => {
        /*
         * Set, then sent — never chained. `useForm`'s `transform()` returns
         * undefined in Inertia 2, so `.transform(...).patch(...)` throws on
         * the patch and the button just stops working, with the TypeError
         * going nowhere a user can see. Two statements cost nothing and
         * cannot do that.
         */
        moveForm.transform(() => ({ position_id: moving.to.value }));

        moveForm.patch(`/hr/employees/${moving.employee.id}/position`, {
            preserveScroll: true,
            onSuccess: closeMove,
        });
    };

    /** Closing clears the search too, so the next move starts on the whole list. */
    const closeMove = () => {
        setMoving(null);
        setTargetSearch('');
    };

    const activeRate = summary.total > 0 ? (summary.active / summary.total) * 100 : 0;

    const filter = (key, value) =>
        router.get(
            '/hr/positions',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <AppLayout
            title="Departments & Positions"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Departments & Positions' },
            ]}
        >
            <OrgTabs currentTab="positions" />

            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Positions"
                    value={summary.total}
                    icon={Briefcase}
                    tone={summary.total > 0 ? 'primary' : 'muted'}
                    hint="job titles on the org chart"
                />

                <MeterCard
                    label="Active"
                    value={summary.active}
                    percent={activeRate}
                    badge={summary.total > 0 ? `${Math.round(activeRate)}%` : undefined}
                    icon={Users}
                    tone="success"
                    iconTone="success"
                    hint={`of ${summary.total} — the rest are deactivated, not deleted`}
                />

                {/* Counted, not flagged as an error: a band is optional and
                    advisory. But a rate keyed against a bandless position has
                    nothing to be compared to, which is worth seeing. */}
                <StatCard
                    label="Without a Salary Band"
                    value={summary.without_band}
                    icon={TriangleAlert}
                    tone={summary.without_band > 0 ? 'info' : 'muted'}
                    hint="a rate keyed here has nothing to check against"
                />
            </div>

            <Card className="mb-5">
                <CardHeader
                    title="Positions"
                    action={
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Select
                                value={filters.department_id ?? ''}
                                onChange={(event) =>
                                    filter('department_id', event.target.value)
                                }
                                aria-label="Filter by department"
                                className="w-full sm:w-44"
                                options={[
                                    { value: '', label: 'All departments' },
                                    ...departments,
                                ]}
                            />
                            <Button onClick={open}>
                                <Plus className="h-4 w-4" />
                                New Position
                            </Button>
                        </div>
                    }
                />
                {filters.department_id && (
                    <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border bg-secondary/30 px-5 py-2.5 text-xs">
                        <div className="flex items-center gap-2">
                            <Building2 className="h-3.5 w-3.5 text-primary" />
                            <span className="text-muted-foreground">
                                Filtered by Department:{' '}
                                <strong className="text-foreground">
                                    {departments.find((d) => String(d.value) === String(filters.department_id))?.label ?? 'Selected Department'}
                                </strong>
                            </span>
                        </div>
                        <div className="flex items-center gap-3">
                            <Link
                                href="/hr/departments"
                                className="text-xs text-primary hover:underline"
                            >
                                &larr; Back to Departments
                            </Link>
                            <button
                                type="button"
                                onClick={() => filter('department_id', '')}
                                className="inline-flex items-center gap-1 rounded bg-secondary px-2 py-0.5 text-[11px] font-medium text-foreground hover:bg-secondary/80 transition-colors"
                            >
                                <X className="h-3 w-3" /> Clear filter
                            </button>
                        </div>
                    </div>
                )}
            </Card>

            {positions.length === 0 ? (
                <Card>
                    <CardBody className="py-14 text-center">
                        <p className="text-sm font-medium text-foreground">
                            {searching
                                ? 'No position matches those filters'
                                : 'No positions yet'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            A position gives an employee a job title and an optional salary
                            band.
                        </p>
                    </CardBody>
                </Card>
            ) : (
                positions.map((position) => (
                    <PositionBlock
                        key={position.id}
                        position={position}
                        open={isOpen(position.id)}
                        onToggle={() => toggle(position.id)}
                        onMove={(employee) => proposeMove(employee, position)}
                    />
                ))
            )}

            {/* --- Moving somebody between titles --- */}
            <Modal
                show={moving !== null}
                onClose={closeMove}
                title={moving ? `Move ${moving.employee.full_name}` : ''}
                description={
                    moving
                        ? `Currently ${moving.from.title}${moving.from.department ? ` in ${moving.from.department}` : ''}. Pick where they go.`
                        : undefined
                }
                maxWidth="2xl"
                footer={
                    <>
                        <Button variant="secondary" onClick={closeMove}>
                            Cancel
                        </Button>
                        {/* Nothing to confirm until somewhere is chosen, so the
                            button says so by being unavailable rather than by
                            failing when pressed. */}
                        <Button
                            onClick={confirmMove}
                            disabled={!moving?.to || moveForm.processing}
                        >
                            {moving?.to ? `Move to ${moving.to.title}` : 'Pick a position'}
                        </Button>
                    </>
                }
            >
                {moving && (
                    <div className="space-y-4">
                        <SearchInput
                            value={targetSearch}
                            onChange={(event) => setTargetSearch(event.target.value)}
                            placeholder="Search a title, code, or department"
                            aria-label="Search positions to move to"
                        />

                        {/* The destinations as rows rather than as options in a
                            select. A title, its department and its band are
                            three things worth comparing across a list, and a
                            dropdown shows one truncated line at a time. */}
                        <div className="max-h-[19rem] overflow-y-auto rounded-lg border border-border">
                            {destinations.length === 0 ? (
                                <p className="px-4 py-8 text-center text-xs text-muted-foreground">
                                    No other position matches that.
                                </p>
                            ) : (
                                destinations.map((target) => {
                                    const chosen = moving.to?.value === target.value;

                                    return (
                                        <button
                                            key={target.value}
                                            type="button"
                                            onClick={() => pickTarget(target)}
                                            aria-pressed={chosen}
                                            className={cn(
                                                'flex w-full items-center gap-3 border-b border-border px-4 py-2.5 text-left transition-colors last:border-0',
                                                chosen
                                                    ? 'bg-primary/10'
                                                    : 'hover:bg-secondary/60',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'grid h-8 w-8 shrink-0 place-items-center rounded-lg',
                                                    chosen
                                                        ? 'bg-primary text-primary-foreground'
                                                        : 'bg-primary/10 text-primary',
                                                )}
                                            >
                                                {chosen ? (
                                                    <Check
                                                        className="h-4 w-4"
                                                        aria-hidden="true"
                                                    />
                                                ) : (
                                                    <Briefcase
                                                        className="h-4 w-4"
                                                        aria-hidden="true"
                                                    />
                                                )}
                                            </span>

                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium text-foreground">
                                                    {target.title}
                                                </span>
                                                <span className="block truncate text-[11px] text-muted-foreground">
                                                    <span className="font-mono">
                                                        {target.code}
                                                    </span>
                                                    {target.department &&
                                                        ` · ${target.department}`}
                                                </span>
                                            </span>

                                            <span className="hidden shrink-0 sm:block">
                                                <Band
                                                    min={target.min_salary}
                                                    max={target.max_salary}
                                                />
                                            </span>

                                            <Badge variant="muted">
                                                {target.employees_count}
                                            </Badge>
                                        </button>
                                    );
                                })
                            )}
                        </div>

                        {/* What the move costs, drawn only once there is a move
                            to describe. */}
                        {moving.to && (
                            <ul className="space-y-1.5 rounded-lg bg-secondary/50 p-3 text-xs text-muted-foreground">
                                {/* A position belongs to a department, so a
                                    record filed under one while holding the
                                    other's title is not a state anybody chose. */}
                                {moving.to.department_id !== moving.from.department_id && (
                                    <li>
                                        Their department changes from{' '}
                                        <span className="font-medium text-foreground">
                                            {moving.from.department ?? 'none'}
                                        </span>{' '}
                                        to{' '}
                                        <span className="font-medium text-foreground">
                                            {moving.to.department ?? 'none'}
                                        </span>
                                        , because the position belongs to it.
                                    </li>
                                )}
                                <li>
                                    <span className="font-medium text-foreground">
                                        Pay does not change.
                                    </span>{' '}
                                    A rate is a decision with a date and a reason behind it —
                                    record it on Salaries &amp; Adjustments if a raise goes with
                                    this move.
                                </li>
                                <li>
                                    Attendance, leave, and payslips already filed keep the title
                                    they were filed under.
                                </li>
                            </ul>
                        )}
                    </div>
                )}
            </Modal>

            <Modal
                show={creating}
                onClose={() => setCreating(false)}
                title="New position"
                description="The salary band is advisory — HR pays outside it deliberately often enough that enforcing it would be wrong."
                maxWidth="2xl"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setCreating(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={form.processing}>
                            Create
                        </Button>
                    </>
                }
            >
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="Department" required error={form.errors.department_id}>
                        <Select
                            value={form.data.department_id}
                            onChange={(event) =>
                                form.setData('department_id', event.target.value)
                            }
                            options={[
                                { value: '', label: 'Select a department' },
                                ...departments,
                            ]}
                        />
                    </Field>

                    <Field label="Code" required error={form.errors.code}>
                        <Input
                            value={form.data.code}
                            onChange={(event) => form.setData('code', event.target.value)}
                            placeholder="OPS-DRV"
                        />
                    </Field>

                    <Field label="Title" required error={form.errors.title}>
                        <Input
                            value={form.data.title}
                            onChange={(event) => form.setData('title', event.target.value)}
                            placeholder="Professional Driver"
                        />
                    </Field>

                    <Field label="Salary grade" error={form.errors.salary_grade}>
                        <Input
                            value={form.data.salary_grade}
                            onChange={(event) =>
                                form.setData('salary_grade', event.target.value)
                            }
                            placeholder="SG-8"
                        />
                    </Field>

                    <Field label="Minimum salary" error={form.errors.min_salary}>
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            value={form.data.min_salary}
                            onChange={(event) => form.setData('min_salary', event.target.value)}
                        />
                    </Field>

                    <Field label="Maximum salary" error={form.errors.max_salary}>
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            value={form.data.max_salary}
                            onChange={(event) => form.setData('max_salary', event.target.value)}
                        />
                    </Field>

                    <label className="flex w-fit cursor-pointer items-center gap-2 sm:col-span-2">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(event) =>
                                form.setData('is_active', event.target.checked)
                            }
                            className="h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-ring focus:ring-offset-0"
                        />
                        <span className="text-sm text-muted-foreground">
                            Active — available when filing an employee
                        </span>
                    </label>
                </form>
            </Modal>
        </AppLayout>
    );
}
