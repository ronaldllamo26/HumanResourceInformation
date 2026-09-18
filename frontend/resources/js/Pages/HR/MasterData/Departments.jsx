import { Link, useForm } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import {
    ArrowLeft,
    ArrowRight,
    ArrowRightLeft,
    Briefcase,
    Building2,
    Check,
    ChevronDown,
    ExternalLink,
    Network,
    Plus,
    Search,
    ShieldAlert,
    TriangleAlert,
    User,
    UserCheck,
    UserX,
    Users,
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
    MeterCard,
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
import { cn, formatCurrency, initials } from '@/lib/utils';

const BLANK_DEPT = { code: '', name: '', description: '', is_active: true };

const BLANK_POS = {
    department_id: '',
    code: '',
    title: '',
    salary_grade: '',
    min_salary: '',
    max_salary: '',
    is_active: true,
};

function SalaryBandBadge({ min, max }) {
    if (min === null || max === null) {
        return <span className="text-[11px] text-muted-foreground/80">No band</span>;
    }
    return (
        <span className="font-mono text-[11px] text-muted-foreground">
            {formatCurrency(min)} – {formatCurrency(max)}
        </span>
    );
}

export default function Departments({
    departments = [],
    filters = {},
    summary = {},
    departmentOptions = [],
    moveTargets = [],
}) {
    const [searchQuery, setSearchQuery] = useState(filters.search ?? '');
    const [selectedDeptId, setSelectedDeptId] = useState(null);
    const [expandedDeptIds, setExpandedDeptIds] = useState(
        () => new Set(departments.map((d) => d.id)),
    );

    // Modals
    const [creatingDept, setCreatingDept] = useState(false);
    const [creatingPos, setCreatingPos] = useState(false);
    const [moving, setMoving] = useState(null); // { employee, from, to }
    const [targetSearch, setTargetSearch] = useState('');

    const deptForm = useForm(BLANK_DEPT);
    const posForm = useForm(BLANK_POS);
    const moveForm = useForm({ position_id: '' });

    const openDeptModal = () => {
        deptForm.clearErrors();
        deptForm.setData(BLANK_DEPT);
        setCreatingDept(true);
    };

    const openPosModal = (prefillDeptId = '') => {
        posForm.clearErrors();
        posForm.setData({
            ...BLANK_POS,
            department_id: prefillDeptId ? String(prefillDeptId) : '',
        });
        setCreatingPos(true);
    };

    const submitDept = (e) => {
        e.preventDefault();
        deptForm.post('/hr/departments', {
            preserveScroll: true,
            onSuccess: () => {
                deptForm.reset();
                setCreatingDept(false);
            },
        });
    };

    const submitPos = (e) => {
        e.preventDefault();
        posForm.post('/hr/positions', {
            preserveScroll: true,
            onSuccess: () => {
                posForm.reset();
                setCreatingPos(false);
            },
        });
    };

    const proposeMove = (employee, fromPos) => {
        setMoving({ employee, from: fromPos, to: null });
    };

    const pickMoveTarget = (target) => {
        setMoving((curr) => ({ ...curr, to: target }));
    };

    const confirmMove = () => {
        if (!moving || !moving.to) return;
        moveForm.transform(() => ({ position_id: moving.to.value }));
        moveForm.patch(`/hr/employees/${moving.employee.id}/position`, {
            preserveScroll: true,
            onSuccess: () => {
                setMoving(null);
                setTargetSearch('');
            },
        });
    };

    const toggleDept = (deptId) => {
        setExpandedDeptIds((prev) => {
            const next = new Set(prev);
            if (next.has(deptId)) next.delete(deptId);
            else next.add(deptId);
            return next;
        });
    };

    const expandAll = () => {
        setExpandedDeptIds(new Set(departments.map((d) => d.id)));
    };

    const collapseAll = () => {
        setExpandedDeptIds(new Set());
    };

    // Filter departments based on search query
    const needle = searchQuery.trim().toLowerCase();
    const filteredDepartments = departments
        .map((dept) => {
            if (!needle) return dept;

            const deptMatches =
                dept.name.toLowerCase().includes(needle) ||
                dept.code.toLowerCase().includes(needle);

            const matchedPositions = (dept.positions ?? []).filter((pos) => {
                const posMatches =
                    pos.title.toLowerCase().includes(needle) ||
                    pos.code.toLowerCase().includes(needle);
                const employeeMatches = (pos.employees ?? []).some(
                    (emp) =>
                        emp.full_name.toLowerCase().includes(needle) ||
                        emp.employee_number.toLowerCase().includes(needle),
                );
                return posMatches || employeeMatches;
            });

            const matchedUnassigned = (dept.unassigned_employees ?? []).filter(
                (emp) =>
                    emp.full_name.toLowerCase().includes(needle) ||
                    emp.employee_number.toLowerCase().includes(needle),
            );

            if (deptMatches || matchedPositions.length > 0 || matchedUnassigned.length > 0) {
                return {
                    ...dept,
                    positions: deptMatches ? dept.positions : matchedPositions,
                    unassigned_employees: deptMatches
                        ? dept.unassigned_employees
                        : matchedUnassigned,
                };
            }
            return null;
        })
        .filter(Boolean);

    // Available destinations for move modal
    const destinations = moveTargets.filter((target) => {
        if (!moving || (moving.from && target.value === moving.from.id)) return false;
        if (!targetSearch.trim()) return true;
        const q = targetSearch.trim().toLowerCase();
        return [target.title, target.code, target.department]
            .filter(Boolean)
            .some((f) => f.toLowerCase().includes(q));
    });

    const activeRate = summary.total > 0 ? (summary.active / summary.total) * 100 : 0;
    const totalStaff =
        summary.total_employees ?? departments.reduce((acc, d) => acc + d.employees_count, 0);
    const totalPositions =
        summary.total_positions ?? departments.reduce((acc, d) => acc + d.positions_count, 0);

    const selectedDept = selectedDeptId
        ? filteredDepartments.find((d) => d.id === selectedDeptId) ||
          departments.find((d) => d.id === selectedDeptId)
        : null;
    const selectedPositions = selectedDept ? (selectedDept.positions ?? []) : [];
    const selectedUnassigned = selectedDept ? (selectedDept.unassigned_employees ?? []) : [];

    return (
        <AppLayout
            /*
                "Departments", matching the sidebar entry rather than the
                merged one it replaced. The nav row was `Organization Chart`
                covering both screens; splitting it into Departments and
                Positions left this page still titled after the entry that no
                longer exists, so the sidebar said one thing and the heading
                another about the same screen.
            */
            title="Departments"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Departments' },
            ]}
        >
            {/* SUMMARY STATS */}
            {/*
                `gap-5` and the dashboard's own breakpoints. These are the same
                `StatCard`s the dashboard draws, and they were sitting in a
                tighter grid that went to four columns straight from one — so
                on a tablet the row was four squeezed tiles where the dashboard
                shows two comfortable ones. Same component, different rhythm,
                which is the half of "matching" that class names decide rather
                than the component.
            */}
            <div className="mb-5 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Active Staff"
                    value={totalStaff}
                    icon={Users}
                    tone={totalStaff > 0 ? 'primary' : 'muted'}
                />

                <StatCard
                    label="Departments"
                    value={summary.total}
                    icon={Building2}
                    tone={summary.total > 0 ? 'info' : 'muted'}
                />

                <StatCard
                    label="Job Positions"
                    value={totalPositions}
                    icon={Briefcase}
                    tone={totalPositions > 0 ? 'success' : 'muted'}
                />

                <StatCard
                    label="Unstaffed Units"
                    value={summary.empty}
                    icon={UserX}
                    tone={summary.empty > 0 ? 'warning' : 'muted'}
                />
            </div>

            {/* ORGANIZATION CHART CONTENT */}
            <div className="space-y-6">
                {selectedDept ? (
                    /* VIEW: SPECIFIC DEPARTMENT POSITIONS */
                    <div className="space-y-6">
                        <div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setSelectedDeptId(null)}
                                className="h-9 gap-1.5 text-xs font-semibold"
                            >
                                <ArrowLeft className="h-3.5 w-3.5" />
                                <span>Back to All Departments</span>
                            </Button>
                        </div>

                        {/* Selected Department Banner */}
                        <Card>
                            <CardBody className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex items-start gap-3">
                                    <span className="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary">
                                        <Building2 className="h-6 w-6" />
                                    </span>
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <h2 className="text-lg font-bold text-foreground">
                                                {selectedDept.name}
                                            </h2>
                                            <Badge
                                                variant="outline"
                                                className="font-mono text-xs font-semibold text-primary"
                                            >
                                                {selectedDept.code}
                                            </Badge>
                                        </div>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {selectedDept.employees_count} active staff &bull;{' '}
                                            {selectedPositions.length} positions
                                        </p>
                                    </div>
                                </div>

                                <Button
                                    onClick={() => openPosModal(selectedDept.id)}
                                    className="h-9 gap-1.5 self-start text-xs font-semibold sm:self-auto"
                                >
                                    <Plus className="h-4 w-4" />
                                    <span>Add Position to {selectedDept.code}</span>
                                </Button>
                            </CardBody>
                        </Card>

                        {/* Positions Grid */}
                        {selectedPositions.length === 0 ? (
                            <Card>
                                <CardBody className="py-12 text-center">
                                    <Briefcase className="mx-auto h-8 w-8 text-muted-foreground/60" />
                                    <p className="mt-2 text-sm font-semibold text-foreground">
                                        No positions configured for {selectedDept.name}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Get started by adding the first job position to this
                                        department.
                                    </p>
                                    <Button
                                        onClick={() => openPosModal(selectedDept.id)}
                                        className="mt-4 h-8 gap-1.5 text-xs"
                                    >
                                        <Plus className="h-3.5 w-3.5" />
                                        <span>Add Position</span>
                                    </Button>
                                </CardBody>
                            </Card>
                        ) : (
                            /*
                                The position cards inside a department, brought
                                to the same house style as the department cards
                                above — otherwise clicking a department just
                                moved the mismatch one level in, which is worse
                                than leaving it on the outside where it was at
                                least consistent with itself.

                                Same three changes: the shared `Card floating`
                                instead of a hand-rolled div with `shadow-xs`,
                                `font-semibold` rather than `font-bold`, and
                                `gap-5` to match the grid above it.

                                Written as a plain block comment with no
                                surrounding braces, because this sits in an
                                expression slot — the else arm of a ternary —
                                where braces would be read as an object literal
                                rather than a JSX comment. The comment two
                                hundred lines up is written the same way for the
                                same reason; the braced form cost a build here.

                                And the version of this note that spelled both
                                forms out literally cost a second one: a block
                                comment cannot contain its own terminator, so
                                the example closed the comment early and the
                                rest of the sentence became code.
                            */
                            <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                                {selectedPositions.map((pos) => {
                                    const holders = pos.employees ?? [];
                                    return (
                                        <Card
                                            key={pos.id}
                                            floating
                                            className="flex flex-col p-4"
                                        >
                                            <div className="border-b border-border/60 pb-3">
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-center gap-1.5">
                                                            <Briefcase
                                                                className="h-4 w-4 shrink-0 text-primary"
                                                                aria-hidden="true"
                                                            />
                                                            <h3 className="truncate text-sm font-semibold text-foreground">
                                                                {pos.title}
                                                            </h3>
                                                        </div>
                                                    </div>
                                                    <Badge
                                                        variant={
                                                            holders.length > 0
                                                                ? 'secondary'
                                                                : 'muted'
                                                        }
                                                        className="font-mono text-xs"
                                                    >
                                                        {holders.length} Staff
                                                    </Badge>
                                                </div>
                                            </div>

                                            {/* Assigned Staff Members */}
                                            {holders.length > 0 && (
                                                <div className="mt-3 flex-1 space-y-2">
                                                    <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                                        Assigned Employees ({holders.length})
                                                    </p>
                                                    <div className="space-y-1.5">
                                                        {holders.map((emp) => (
                                                            <div
                                                                key={emp.id}
                                                                className="group flex items-center justify-between gap-2 rounded-lg border border-border/60 bg-background px-2.5 py-2 transition-colors hover:border-primary/50 hover:bg-secondary/40"
                                                            >
                                                                <Link
                                                                    href={`/hr/employees/${emp.id}`}
                                                                    className="flex min-w-0 flex-1 items-center gap-2"
                                                                    title={`View ${emp.full_name}'s profile`}
                                                                >
                                                                    {emp.photo_url ? (
                                                                        <img
                                                                            src={emp.photo_url}
                                                                            alt=""
                                                                            className="h-7 w-7 shrink-0 rounded-full object-cover"
                                                                        />
                                                                    ) : (
                                                                        <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                                            {initials(
                                                                                emp.full_name,
                                                                            )}
                                                                        </span>
                                                                    )}
                                                                    <div className="min-w-0 flex-1">
                                                                        <p className="truncate text-xs font-medium text-foreground transition-colors group-hover:text-primary">
                                                                            {emp.full_name}
                                                                        </p>
                                                                        <p className="truncate font-mono text-[10px] text-muted-foreground">
                                                                            {
                                                                                emp.employee_number
                                                                            }
                                                                        </p>
                                                                    </div>
                                                                </Link>

                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        proposeMove(emp, pos)
                                                                    }
                                                                    className="h-6 px-1.5 text-[11px] text-muted-foreground hover:text-primary"
                                                                    title="Reassign to another position"
                                                                >
                                                                    <ArrowRightLeft className="mr-1 h-3 w-3" />
                                                                    <span>Move</span>
                                                                </Button>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                        </Card>
                                    );
                                })}
                            </div>
                        )}

                        {/* Unassigned Staff */}
                        {selectedUnassigned.length > 0 && (
                            <div className="rounded-xl border border-amber-300 bg-amber-50/50 p-4 dark:border-amber-900/50 dark:bg-amber-950/20">
                                <p className="text-xs font-semibold text-amber-800 dark:text-amber-300">
                                    Unassigned Employees in {selectedDept.name} (
                                    {selectedUnassigned.length}):
                                </p>
                                <div className="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    {selectedUnassigned.map((emp) => (
                                        <div
                                            key={emp.id}
                                            className="flex items-center justify-between gap-2 rounded-lg border border-amber-200 bg-background p-2 dark:border-amber-900/40"
                                        >
                                            <Link
                                                href={`/hr/employees/${emp.id}`}
                                                className="truncate text-xs font-medium text-foreground hover:text-primary"
                                            >
                                                {emp.full_name} ({emp.employee_number})
                                            </Link>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    proposeMove(emp, {
                                                        id: null,
                                                        title: 'Unassigned',
                                                    })
                                                }
                                                className="h-6 px-2 text-[11px] text-primary"
                                            >
                                                Assign
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                ) : /* VIEW: ALL DEPARTMENTS CARDS */
                filteredDepartments.length === 0 ? (
                    <Card>
                        <CardBody className="py-12 text-center">
                            <p className="text-sm font-medium text-foreground">
                                No organization records match &ldquo;{searchQuery}&rdquo;
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Try clearing your search or adding a new department.
                            </p>
                        </CardBody>
                    </Card>
                ) : (
                    /*
                        Built from the shared `Card` rather than a hand-rolled
                        div, so the radius, border, surface and shadow are the
                        dashboard's **by construction** rather than by copying
                        class strings that then drift. `floating` is the same
                        flag every dashboard tile passes.

                        The type scale is the dashboard's too, taken from
                        `StatCard`: an 11px uppercase tracked label in muted
                        (there, the metric's name; here, the department code),
                        a `font-semibold` headline in the foreground colour,
                        and `text-xs` muted for the figures underneath. What
                        was here instead was a 5px-larger padding, a
                        `rounded-xl` icon tile two sizes up, `font-bold` where
                        the house uses semibold, and the code in a monospace
                        outline badge — six small departures that add up to a
                        screen that reads as though it came from somewhere
                        else.
                    */
                    <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                        {filteredDepartments.map((dept) => {
                            const positions = dept.positions ?? [];
                            const open = () => setSelectedDeptId(dept.id);

                            return (
                                <Card
                                    key={dept.id}
                                    floating
                                    onClick={open}
                                    /*
                                        `role`, `tabIndex` and the key handler
                                        are a fix rather than decoration: this
                                        was a `<div onClick>`, which no
                                        keyboard can reach and no screen reader
                                        announces as something you can press.
                                        `Card`'s own focus ring and hover lift
                                        are gated on `href`, and this card
                                        changes local state rather than
                                        navigating — so those classes are
                                        mirrored here from its `href &&
                                        floating` branch, deliberately the same
                                        values rather than near ones.
                                    */
                                    role="button"
                                    tabIndex={0}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' || event.key === ' ') {
                                            event.preventDefault();
                                            open();
                                        }
                                    }}
                                    className="group flex cursor-pointer flex-col justify-between p-4 transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-[0_18px_30px_-12px_hsl(var(--foreground)/0.18)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                                >
                                    <div>
                                        <div className="flex items-start justify-between gap-3">
                                            {/* The code, in the dashboard's
                                                label treatment — it names the
                                                thing rather than being a
                                                figure, which is what that
                                                11px uppercase line is for. */}
                                            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                                {dept.code}
                                            </p>
                                            {/* 9x9 and `rounded-lg`, the size
                                                and radius every StatCard icon
                                                tile uses, with `ICON_TONES.primary`'s
                                                own values — that map is not
                                                exported, so the pair is
                                                written out rather than
                                                guessed at. */}
                                            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                                                <Building2
                                                    className="h-[18px] w-[18px]"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                        </div>

                                        <h3 className="mt-2 text-base font-semibold leading-tight text-foreground">
                                            {dept.name}
                                        </h3>
                                    </div>

                                    <div className="mt-4 flex items-center justify-between gap-3">
                                        <p className="flex items-center gap-2.5 text-xs text-muted-foreground">
                                            <span className="flex items-center gap-1">
                                                <Users
                                                    className="h-3.5 w-3.5"
                                                    aria-hidden="true"
                                                />
                                                {dept.employees_count} staff
                                            </span>
                                            <span aria-hidden="true">&bull;</span>
                                            <span className="flex items-center gap-1">
                                                <Briefcase
                                                    className="h-3.5 w-3.5"
                                                    aria-hidden="true"
                                                />
                                                {positions.length} positions
                                            </span>
                                        </p>

                                        <span className="flex shrink-0 items-center text-xs font-semibold text-primary">
                                            Positions
                                            <ArrowRight
                                                className="ml-1 h-3.5 w-3.5"
                                                aria-hidden="true"
                                            />
                                        </span>
                                    </div>
                                </Card>
                            );
                        })}
                    </div>
                )}
            </div>

            {/* MODAL 1: NEW DEPARTMENT */}
            <Modal
                show={creatingDept}
                onClose={() => setCreatingDept(false)}
                title="New Department"
                description="Add a new functional unit to the organization structure."
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setCreatingDept(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submitDept} disabled={deptForm.processing}>
                            Create Department
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitDept} className="grid gap-4 sm:grid-cols-3">
                    <Field label="Code" required error={deptForm.errors.code}>
                        <Input
                            value={deptForm.data.code}
                            onChange={(e) =>
                                deptForm.setData('code', e.target.value.toUpperCase())
                            }
                            placeholder="OPS"
                            className="font-mono uppercase"
                        />
                    </Field>

                    <Field
                        label="Name"
                        required
                        error={deptForm.errors.name}
                        className="sm:col-span-2"
                    >
                        <Input
                            value={deptForm.data.name}
                            onChange={(e) => deptForm.setData('name', e.target.value)}
                            placeholder="Fleet Operations"
                        />
                    </Field>

                    <Field
                        label="Description"
                        error={deptForm.errors.description}
                        className="sm:col-span-3"
                    >
                        <Textarea
                            rows={2}
                            value={deptForm.data.description}
                            onChange={(e) => deptForm.setData('description', e.target.value)}
                            placeholder="Responsibilities of this department..."
                        />
                    </Field>

                    <label className="flex w-fit cursor-pointer items-center gap-2 sm:col-span-3">
                        <input
                            type="checkbox"
                            checked={deptForm.data.is_active}
                            onChange={(e) => deptForm.setData('is_active', e.target.checked)}
                            className="h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-ring focus:ring-offset-0"
                        />
                        <span className="text-sm text-muted-foreground">
                            Active — available for employee assignments
                        </span>
                    </label>
                </form>
            </Modal>

            {/* MODAL 2: NEW POSITION */}
            <Modal
                show={creatingPos}
                onClose={() => setCreatingPos(false)}
                title="New Position"
                description="Add a new job title under a department in the organization chart."
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setCreatingPos(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submitPos} disabled={posForm.processing}>
                            Create Position
                        </Button>
                    </>
                }
            >
                <form onSubmit={submitPos} className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Department"
                        required
                        error={posForm.errors.department_id}
                        className="sm:col-span-2"
                    >
                        <Select
                            value={posForm.data.department_id}
                            onChange={(e) => posForm.setData('department_id', e.target.value)}
                            options={[
                                { value: '', label: 'Select Department' },
                                ...departmentOptions,
                            ]}
                        />
                    </Field>

                    <Field label="Code" required error={posForm.errors.code}>
                        <Input
                            value={posForm.data.code}
                            onChange={(e) =>
                                posForm.setData('code', e.target.value.toUpperCase())
                            }
                            placeholder="OPS-DRV"
                            className="font-mono uppercase"
                        />
                    </Field>

                    <Field label="Title" required error={posForm.errors.title}>
                        <Input
                            value={posForm.data.title}
                            onChange={(e) => posForm.setData('title', e.target.value)}
                            placeholder="Professional Driver"
                        />
                    </Field>

                    <Field label="Salary Grade" error={posForm.errors.salary_grade}>
                        <Input
                            value={posForm.data.salary_grade}
                            onChange={(e) => posForm.setData('salary_grade', e.target.value)}
                            placeholder="12"
                        />
                    </Field>

                    <div className="grid grid-cols-2 gap-2">
                        <Field label="Min Salary" error={posForm.errors.min_salary}>
                            <Input
                                type="number"
                                step="0.01"
                                value={posForm.data.min_salary}
                                onChange={(e) => posForm.setData('min_salary', e.target.value)}
                                placeholder="18000"
                            />
                        </Field>
                        <Field label="Max Salary" error={posForm.errors.max_salary}>
                            <Input
                                type="number"
                                step="0.01"
                                value={posForm.data.max_salary}
                                onChange={(e) => posForm.setData('max_salary', e.target.value)}
                                placeholder="25000"
                            />
                        </Field>
                    </div>

                    <label className="flex w-fit cursor-pointer items-center gap-2 sm:col-span-2">
                        <input
                            type="checkbox"
                            checked={posForm.data.is_active}
                            onChange={(e) => posForm.setData('is_active', e.target.checked)}
                            className="h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-ring focus:ring-offset-0"
                        />
                        <span className="text-sm text-muted-foreground">
                            Active — available for employee assignments
                        </span>
                    </label>
                </form>
            </Modal>

            {/* MODAL 3: MOVE / REASSIGN EMPLOYEE */}
            <Modal
                show={Boolean(moving)}
                onClose={() => {
                    setMoving(null);
                    setTargetSearch('');
                }}
                title={moving ? `Move ${moving.employee?.full_name}` : 'Move Employee'}
                description={
                    moving?.from
                        ? `Currently holding: ${moving.from.title}. Select a target position to reassign.`
                        : 'Select a target position to assign this employee.'
                }
                footer={
                    <>
                        <Button
                            variant="secondary"
                            onClick={() => {
                                setMoving(null);
                                setTargetSearch('');
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={confirmMove}
                            disabled={!moving?.to || moveForm.processing}
                        >
                            Confirm Reassignment
                        </Button>
                    </>
                }
            >
                <div className="space-y-4">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={targetSearch}
                            onChange={(e) => setTargetSearch(e.target.value)}
                            placeholder="Filter positions by title, code, or department..."
                            className="pl-9 text-xs"
                        />
                    </div>

                    {moving?.to && (
                        <div className="flex items-center justify-between rounded-lg border border-primary/40 bg-primary/5 p-3 text-xs">
                            <div>
                                <p className="font-semibold text-foreground">
                                    Selected: {moving.to.title} ({moving.to.code})
                                </p>
                                <p className="text-muted-foreground">
                                    Department: {moving.to.department}
                                </p>
                            </div>
                            <Check className="h-4 w-4 text-primary" />
                        </div>
                    )}

                    <div className="max-h-60 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                        {destinations.length === 0 ? (
                            <p className="p-4 text-center text-xs text-muted-foreground">
                                No positions found matching your search.
                            </p>
                        ) : (
                            destinations.map((dest) => {
                                const isSelected = moving?.to?.value === dest.value;

                                return (
                                    <button
                                        key={dest.value}
                                        type="button"
                                        onClick={() => pickMoveTarget(dest)}
                                        className={cn(
                                            'flex w-full items-center justify-between p-3 text-left text-xs transition-colors',
                                            isSelected
                                                ? 'bg-primary/10 font-medium text-primary'
                                                : 'text-foreground hover:bg-secondary/50',
                                        )}
                                    >
                                        <div>
                                            <p className="font-semibold">{dest.title}</p>
                                            <p className="font-mono text-[10.5px] text-muted-foreground">
                                                {dest.code} &bull; {dest.department}
                                            </p>
                                        </div>
                                        {isSelected && (
                                            <Check className="h-4 w-4 text-primary" />
                                        )}
                                    </button>
                                );
                            })
                        )}
                    </div>
                </div>
            </Modal>
        </AppLayout>
    );
}
