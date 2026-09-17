import { Link, useForm } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import {
    ArrowRight,
    ArrowRightLeft,
    Briefcase,
    Building2,
    Check,
    ChevronDown,
    ChevronRight,
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
    // Initial view mode from query param ?view=tree|departments|positions
    const [viewMode, setViewMode] = useState(() => {
        if (typeof window !== 'undefined') {
            const param = new URLSearchParams(window.location.search).get('view');
            if (param && ['tree', 'departments', 'positions'].includes(param)) {
                return param;
            }
        }
        return 'tree';
    });

    const [searchQuery, setSearchQuery] = useState(filters.search ?? '');
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
    const totalStaff = summary.total_employees ?? departments.reduce((acc, d) => acc + d.employees_count, 0);
    const totalPositions = summary.total_positions ?? departments.reduce((acc, d) => acc + d.positions_count, 0);

    return (
        <AppLayout
            title="Organization Chart"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Organization Chart' },
            ]}
        >
            <OrgTabs
                currentTab={viewMode}
                viewMode={viewMode}
                onViewModeChange={(mode) => setViewMode(mode)}
            />

            {/* SUMMARY STATS */}
            <div className="mb-5 grid gap-4 sm:grid-cols-4">
                <StatCard
                    label="Active Staff"
                    value={totalStaff}
                    icon={Users}
                    tone={totalStaff > 0 ? 'primary' : 'muted'}
                    hint="across all departments"
                />

                <StatCard
                    label="Departments"
                    value={summary.total}
                    icon={Building2}
                    tone={summary.total > 0 ? 'info' : 'muted'}
                    hint={`${summary.active} active units`}
                />

                <StatCard
                    label="Job Positions"
                    value={totalPositions}
                    icon={Briefcase}
                    tone={totalPositions > 0 ? 'success' : 'muted'}
                    hint="registered titles"
                />

                <StatCard
                    label="Unstaffed Units"
                    value={summary.empty}
                    icon={UserX}
                    tone={summary.empty > 0 ? 'warning' : 'muted'}
                    hint="departments without staff"
                />
            </div>

            {/* UNIFIED CONTROL TOOLBAR */}
            <Card className="mb-6">
                <CardBody className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex flex-1 items-center gap-2">
                        <div className="relative w-full max-w-sm">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                placeholder="Search employees, positions, departments..."
                                className="pl-9 text-xs"
                            />
                            {searchQuery && (
                                <button
                                    type="button"
                                    onClick={() => setSearchQuery('')}
                                    className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:bg-secondary"
                                >
                                    <X className="h-3.5 w-3.5" />
                                </button>
                            )}
                        </div>

                        <div className="hidden items-center gap-1.5 md:flex">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={expandAll}
                                className="h-8 text-xs"
                            >
                                Expand All
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={collapseAll}
                                className="h-8 text-xs"
                            >
                                Collapse All
                            </Button>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            variant="secondary"
                            onClick={() => openPosModal()}
                            className="h-9 gap-1.5 text-xs font-semibold"
                        >
                            <Plus className="h-4 w-4" />
                            <span>New Position</span>
                        </Button>
                        <Button
                            onClick={openDeptModal}
                            className="h-9 gap-1.5 text-xs font-semibold"
                        >
                            <Plus className="h-4 w-4" />
                            <span>New Department</span>
                        </Button>
                    </div>
                </CardBody>
            </Card>

            {/* VIEW MODE 1: VISUAL HIERARCHY TREE */}
            {viewMode === 'tree' && (
                <div className="space-y-8">
                    {/* Top Root Node: Company */}
                    <div className="flex flex-col items-center text-center">
                        <div className="relative z-10 w-full max-w-md rounded-2xl border-2 border-primary/40 bg-card p-5 shadow-md">
                            <div className="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-primary text-primary-foreground shadow-sm">
                                <Building2 className="h-6 w-6" />
                            </div>
                            <h2 className="mt-3 text-base font-extrabold tracking-tight text-foreground">
                                PRIMEPOWER MANPOWER SERVICES
                            </h2>
                            <p className="text-xs font-medium text-muted-foreground">
                                Enterprise Organization Chart & Directory
                            </p>
                            <div className="mt-3 flex flex-wrap items-center justify-center gap-2 border-t border-border pt-3">
                                <Badge variant="secondary" className="font-mono text-xs">
                                    {summary.total} Departments
                                </Badge>
                                <Badge variant="secondary" className="font-mono text-xs">
                                    {totalPositions} Positions
                                </Badge>
                                <Badge variant="primary" className="font-mono text-xs">
                                    {totalStaff} Active Staff
                                </Badge>
                            </div>
                        </div>

                        {/* Trunk line connecting to departments */}
                        <div className="h-8 w-0.5 bg-primary/30" />
                    </div>

                    {/* Department Nodes Grid / Branches */}
                    {filteredDepartments.length === 0 ? (
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
                        <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                            {filteredDepartments.map((dept) => {
                                const isExpanded = expandedDeptIds.has(dept.id);
                                const positions = dept.positions ?? [];
                                const unassigned = dept.unassigned_employees ?? [];

                                return (
                                    <div
                                        key={dept.id}
                                        className="flex flex-col rounded-xl border border-border bg-card shadow-xs transition-shadow hover:shadow-md"
                                    >
                                        {/* Department Node Header */}
                                        <div className="flex items-start justify-between border-b border-border p-4">
                                            <div className="flex items-start gap-3">
                                                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                                                    <Building2 className="h-5 w-5" />
                                                </span>
                                                <div>
                                                    <div className="flex items-center gap-2">
                                                        <h3 className="text-sm font-bold text-foreground">
                                                            {dept.name}
                                                        </h3>
                                                        <span className="font-mono text-xs font-semibold text-primary">
                                                            [{dept.code}]
                                                        </span>
                                                    </div>
                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                        {dept.employees_count} staff &bull; {positions.length} positions
                                                    </p>
                                                </div>
                                            </div>

                                            <div className="flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    onClick={() => openPosModal(dept.id)}
                                                    title={`Add position to ${dept.name}`}
                                                    className="grid h-8 w-8 place-items-center rounded-md border border-border bg-background text-muted-foreground hover:bg-secondary hover:text-primary transition-colors"
                                                >
                                                    <Plus className="h-4 w-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => toggleDept(dept.id)}
                                                    className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-secondary hover:text-foreground transition-colors"
                                                >
                                                    <ChevronDown
                                                        className={cn(
                                                            'h-4 w-4 transition-transform duration-200',
                                                            isExpanded && 'rotate-180',
                                                        )}
                                                    />
                                                </button>
                                            </div>
                                        </div>

                                        {/* Department Positions & Employees Branch */}
                                        {isExpanded && (
                                            <div className="flex-1 space-y-3 bg-secondary/15 p-4">
                                                {positions.length === 0 ? (
                                                    <div className="rounded-lg border border-dashed border-border/70 p-4 text-center">
                                                        <p className="text-xs text-muted-foreground">
                                                            No positions configured yet.
                                                        </p>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => openPosModal(dept.id)}
                                                            className="mt-1.5 h-7 text-xs text-primary"
                                                        >
                                                            <Plus className="mr-1 h-3.5 w-3.5" /> Add Position
                                                        </Button>
                                                    </div>
                                                ) : (
                                                    positions.map((pos) => {
                                                        const holders = pos.employees ?? [];

                                                        return (
                                                            <div
                                                                key={pos.id}
                                                                className="rounded-lg border border-border bg-background p-3 shadow-xs"
                                                            >
                                                                {/* Position Node */}
                                                                <div className="flex items-start justify-between gap-2 border-b border-border/50 pb-2">
                                                                    <div className="min-w-0 flex-1">
                                                                        <div className="flex items-center gap-1.5">
                                                                            <Briefcase className="h-3.5 w-3.5 shrink-0 text-primary" />
                                                                            <span className="truncate text-xs font-bold text-foreground">
                                                                                {pos.title}
                                                                            </span>
                                                                        </div>
                                                                        <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                                            <span className="font-mono text-[10px] text-muted-foreground">
                                                                                {pos.code}
                                                                            </span>
                                                                            {pos.salary_grade && (
                                                                                <Badge variant="outline" className="px-1.5 py-0 text-[10px]">
                                                                                    SG-{pos.salary_grade}
                                                                                </Badge>
                                                                            )}
                                                                            <SalaryBandBadge min={pos.min_salary} max={pos.max_salary} />
                                                                        </div>
                                                                    </div>
                                                                    <Badge variant={holders.length > 0 ? 'secondary' : 'muted'} className="text-[11px]">
                                                                        {holders.length}
                                                                    </Badge>
                                                                </div>

                                                                {/* Assigned Staff */}
                                                                <div className="mt-2 space-y-1.5">
                                                                    {holders.length === 0 ? (
                                                                        <p className="text-[11px] italic text-muted-foreground/80 py-0.5">
                                                                            Position is currently unstaffed.
                                                                        </p>
                                                                    ) : (
                                                                        holders.map((emp) => (
                                                                            <div
                                                                                key={emp.id}
                                                                                className="group flex items-center justify-between gap-2 rounded-md border border-border/60 bg-card px-2.5 py-1.5 transition-colors hover:border-primary/50 hover:bg-secondary/40"
                                                                            >
                                                                                <Link
                                                                                    href={`/hr/employees/${emp.id}`}
                                                                                    className="flex min-w-0 flex-1 items-center gap-2"
                                                                                    title={`View ${emp.full_name}'s employee directory record`}
                                                                                >
                                                                                    {emp.photo_url ? (
                                                                                        <img
                                                                                            src={emp.photo_url}
                                                                                            alt=""
                                                                                            className="h-7 w-7 shrink-0 rounded-full object-cover"
                                                                                        />
                                                                                    ) : (
                                                                                        <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                                                            {initials(emp.full_name)}
                                                                                        </span>
                                                                                    )}
                                                                                    <div className="min-w-0 flex-1">
                                                                                        <p className="truncate text-xs font-medium text-foreground group-hover:text-primary transition-colors">
                                                                                            {emp.full_name}
                                                                                        </p>
                                                                                        <p className="truncate font-mono text-[10px] text-muted-foreground">
                                                                                            {emp.employee_number}
                                                                                        </p>
                                                                                    </div>
                                                                                </Link>

                                                                                <Button
                                                                                    variant="ghost"
                                                                                    size="sm"
                                                                                    onClick={() => proposeMove(emp, pos)}
                                                                                    className="h-6 px-1.5 text-[11px] text-muted-foreground hover:text-primary"
                                                                                    title="Reassign to another position"
                                                                                >
                                                                                    <ArrowRightLeft className="h-3 w-3 mr-1" />
                                                                                    <span>Move</span>
                                                                                </Button>
                                                                            </div>
                                                                        ))
                                                                    )}
                                                                </div>
                                                            </div>
                                                        );
                                                    })
                                                )}

                                                {/* Unassigned Staff */}
                                                {unassigned.length > 0 && (
                                                    <div className="rounded-lg border border-amber-300 bg-amber-50/50 p-2.5 dark:border-amber-900/50 dark:bg-amber-950/20">
                                                        <p className="mb-1.5 text-[11px] font-semibold text-amber-800 dark:text-amber-300">
                                                            Unassigned position ({unassigned.length}):
                                                        </p>
                                                        <div className="space-y-1">
                                                            {unassigned.map((emp) => (
                                                                <div
                                                                    key={emp.id}
                                                                    className="flex items-center justify-between gap-2 rounded bg-background p-1.5"
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
                                                                        onClick={() => proposeMove(emp, { id: null, title: 'Unassigned' })}
                                                                        className="h-5 px-1 text-[10px] text-primary"
                                                                    >
                                                                        Assign
                                                                    </Button>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            )}

            {/* VIEW MODE 2: DEPARTMENTS & STAFF BREAKDOWN */}
            {viewMode === 'departments' && (
                <Card>
                    <CardHeader
                        title="Departments & Teams"
                        description="Expand any department row to view full job position details and assigned staff."
                        action={
                            <Button onClick={openDeptModal}>
                                <Plus className="h-4 w-4" />
                                New Department
                            </Button>
                        }
                    />

                    <Table>
                        <THead>
                            <TR>
                                <TH className="w-10"></TH>
                                <TH>Code</TH>
                                <TH>Department</TH>
                                <TH className="text-right">Positions</TH>
                                <TH className="text-right">Staff</TH>
                                <TH>Status</TH>
                                <TH className="text-right">Actions</TH>
                            </TR>
                        </THead>

                        <TBody>
                            {filteredDepartments.length === 0 ? (
                                <TableEmpty
                                    colSpan={7}
                                    icon={Building2}
                                    title="No departments found"
                                    description="Try searching for a different name or create a new department."
                                />
                            ) : (
                                filteredDepartments.map((department) => {
                                    const isExpanded = expandedDeptIds.has(department.id);
                                    const positions = department.positions ?? [];

                                    return (
                                        <Fragment key={department.id}>
                                            <TR
                                                className={cn(
                                                    'cursor-pointer hover:bg-secondary/40 transition-colors',
                                                    isExpanded && 'bg-secondary/20',
                                                )}
                                                onClick={() => toggleDept(department.id)}
                                            >
                                                <TD className="w-10 text-center">
                                                    <button
                                                        type="button"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            toggleDept(department.id);
                                                        }}
                                                        className="grid h-7 w-7 place-items-center rounded text-muted-foreground hover:bg-secondary"
                                                    >
                                                        <ChevronDown
                                                            className={cn(
                                                                'h-4 w-4 transition-transform duration-200',
                                                                isExpanded && 'rotate-180',
                                                            )}
                                                        />
                                                    </button>
                                                </TD>
                                                <TD>
                                                    <span className="font-mono text-xs font-bold text-primary">
                                                        {department.code}
                                                    </span>
                                                </TD>
                                                <TD>
                                                    <p className="font-semibold text-foreground">
                                                        {department.name}
                                                    </p>
                                                    {department.description && (
                                                        <p className="max-w-md truncate text-xs text-muted-foreground">
                                                            {department.description}
                                                        </p>
                                                    )}
                                                </TD>
                                                <TD className="text-right font-mono font-medium">
                                                    {department.positions_count}
                                                </TD>
                                                <TD className="text-right font-mono font-medium">
                                                    {department.employees_count}
                                                </TD>
                                                <TD>
                                                    <Badge variant={department.is_active ? 'success' : 'muted'}>
                                                        {department.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                </TD>
                                                <TD className="text-right">
                                                    <div
                                                        className="flex items-center justify-end gap-2"
                                                        onClick={(e) => e.stopPropagation()}
                                                    >
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => openPosModal(department.id)}
                                                            className="h-7 gap-1 text-xs"
                                                        >
                                                            <Plus className="h-3 w-3" />
                                                            <span>Add Position</span>
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => toggleDept(department.id)}
                                                            className="h-7 text-xs"
                                                        >
                                                            {isExpanded ? 'Collapse' : 'Explore'}
                                                        </Button>
                                                    </div>
                                                </TD>
                                            </TR>

                                            {/* Expanded Department Detail */}
                                            {isExpanded && (
                                                <TR className="bg-secondary/15 hover:bg-secondary/15">
                                                    <TD colSpan={7} className="p-4 sm:p-5">
                                                        <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
                                                            <div className="mb-4 flex items-center justify-between border-b border-border pb-2.5">
                                                                <h4 className="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                                                                    Positions in {department.name} ({positions.length})
                                                                </h4>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() => openPosModal(department.id)}
                                                                    className="h-7 gap-1 text-xs text-primary"
                                                                >
                                                                    <Plus className="h-3.5 w-3.5" />
                                                                    <span>New Position</span>
                                                                </Button>
                                                            </div>

                                                            {positions.length === 0 ? (
                                                                <p className="text-xs italic text-muted-foreground py-2">
                                                                    No positions created for this department yet.
                                                                </p>
                                                            ) : (
                                                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                                                    {positions.map((pos) => (
                                                                        <div
                                                                            key={pos.id}
                                                                            className="rounded-lg border border-border bg-background p-3"
                                                                        >
                                                                            <div className="flex items-start justify-between gap-1">
                                                                                <div>
                                                                                    <p className="font-bold text-xs text-foreground">
                                                                                        {pos.title}
                                                                                    </p>
                                                                                    <p className="font-mono text-[10px] text-muted-foreground">
                                                                                        {pos.code} {pos.salary_grade && `· SG-${pos.salary_grade}`}
                                                                                    </p>
                                                                                </div>
                                                                                <Badge variant="outline" className="text-[10px]">
                                                                                    {pos.employees?.length ?? 0} staff
                                                                                </Badge>
                                                                            </div>

                                                                            <div className="mt-2.5 space-y-1">
                                                                                {(pos.employees ?? []).map((emp) => (
                                                                                    <div
                                                                                        key={emp.id}
                                                                                        className="flex items-center justify-between gap-2 rounded bg-secondary/30 p-1.5"
                                                                                    >
                                                                                        <Link
                                                                                            href={`/hr/employees/${emp.id}`}
                                                                                            className="truncate text-xs font-medium text-foreground hover:text-primary transition-colors"
                                                                                        >
                                                                                            {emp.full_name}
                                                                                        </Link>
                                                                                        <Button
                                                                                            variant="ghost"
                                                                                            size="sm"
                                                                                            onClick={() => proposeMove(emp, pos)}
                                                                                            className="h-5 px-1.5 text-[10px] text-muted-foreground hover:text-primary"
                                                                                        >
                                                                                            Move
                                                                                        </Button>
                                                                                    </div>
                                                                                ))}
                                                                            </div>
                                                                        </div>
                                                                    ))}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </TD>
                                                </TR>
                                            )}
                                        </Fragment>
                                    );
                                })
                            )}
                        </TBody>
                    </Table>
                </Card>
            )}

            {/* VIEW MODE 3: POSITIONS & BANDS REGISTRY */}
            {viewMode === 'positions' && (
                <Card>
                    <CardHeader
                        title="Position Registry & Salary Bands"
                        description="All job titles across departments, salary bands, and assigned holders."
                        action={
                            <Button onClick={() => openPosModal()}>
                                <Plus className="h-4 w-4" />
                                New Position
                            </Button>
                        }
                    />

                    <div className="p-4 space-y-3">
                        {filteredDepartments.map((dept) => {
                            const positions = dept.positions ?? [];
                            if (positions.length === 0) return null;

                            return (
                                <div key={dept.id} className="rounded-xl border border-border bg-card p-4">
                                    <div className="mb-3 flex items-center justify-between border-b border-border pb-2">
                                        <div className="flex items-center gap-2">
                                            <Building2 className="h-4 w-4 text-primary" />
                                            <h3 className="font-bold text-sm text-foreground">{dept.name}</h3>
                                            <Badge variant="outline" className="font-mono text-[10px]">
                                                {dept.code}
                                            </Badge>
                                        </div>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => openPosModal(dept.id)}
                                            className="h-6 text-xs text-primary"
                                        >
                                            <Plus className="mr-1 h-3 w-3" /> Add Position
                                        </Button>
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                        {positions.map((pos) => {
                                            const holders = pos.employees ?? [];

                                            return (
                                                <div
                                                    key={pos.id}
                                                    className="rounded-lg border border-border bg-background p-3 shadow-xs"
                                                >
                                                    <div className="flex items-start justify-between gap-2">
                                                        <div>
                                                            <h4 className="text-xs font-bold text-foreground">
                                                                {pos.title}
                                                            </h4>
                                                            <p className="font-mono text-[10px] text-muted-foreground">
                                                                {pos.code} {pos.salary_grade && `· SG-${pos.salary_grade}`}
                                                            </p>
                                                        </div>
                                                        <Badge variant={holders.length > 0 ? 'secondary' : 'muted'} className="text-[10px]">
                                                            {holders.length} staff
                                                        </Badge>
                                                    </div>

                                                    <div className="mt-2 text-[11px] text-muted-foreground">
                                                        Salary Band: <SalaryBandBadge min={pos.min_salary} max={pos.max_salary} />
                                                    </div>

                                                    <div className="mt-2.5 border-t border-border/50 pt-2 space-y-1">
                                                        {holders.length === 0 ? (
                                                            <p className="text-[10px] italic text-muted-foreground">
                                                                No staff assigned.
                                                            </p>
                                                        ) : (
                                                            holders.map((emp) => (
                                                                <div
                                                                    key={emp.id}
                                                                    className="flex items-center justify-between gap-1 text-xs"
                                                                >
                                                                    <Link
                                                                        href={`/hr/employees/${emp.id}`}
                                                                        className="truncate font-medium text-foreground hover:text-primary transition-colors"
                                                                    >
                                                                        {emp.full_name}
                                                                    </Link>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => proposeMove(emp, pos)}
                                                                        className="text-[10px] font-semibold text-primary hover:underline"
                                                                    >
                                                                        Move
                                                                    </button>
                                                                </div>
                                                            ))
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </Card>
            )}

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
                            onChange={(e) => deptForm.setData('code', e.target.value.toUpperCase())}
                            placeholder="OPS"
                            className="uppercase font-mono"
                        />
                    </Field>

                    <Field label="Name" required error={deptForm.errors.name} className="sm:col-span-2">
                        <Input
                            value={deptForm.data.name}
                            onChange={(e) => deptForm.setData('name', e.target.value)}
                            placeholder="Fleet Operations"
                        />
                    </Field>

                    <Field label="Description" error={deptForm.errors.description} className="sm:col-span-3">
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
                    <Field label="Department" required error={posForm.errors.department_id} className="sm:col-span-2">
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
                            onChange={(e) => posForm.setData('code', e.target.value.toUpperCase())}
                            placeholder="OPS-DRV"
                            className="uppercase font-mono"
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

                    <div className="max-h-60 overflow-y-auto rounded-lg border border-border divide-y divide-border">
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
                                            'w-full flex items-center justify-between p-3 text-left text-xs transition-colors',
                                            isSelected
                                                ? 'bg-primary/10 text-primary font-medium'
                                                : 'hover:bg-secondary/50 text-foreground',
                                        )}
                                    >
                                        <div>
                                            <p className="font-semibold">{dest.title}</p>
                                            <p className="text-muted-foreground font-mono text-[10.5px]">
                                                {dest.code} &bull; {dest.department}
                                            </p>
                                        </div>
                                        {isSelected && <Check className="h-4 w-4 text-primary" />}
                                    </button>
                                );
                            })
                        )}
                    </div>

                    <p className="text-[11px] text-muted-foreground italic">
                        Note: Reassignment updates the employee&rsquo;s official position and department. Base pay remains unchanged until adjusted in Compensation.
                    </p>
                </div>
            </Modal>
        </AppLayout>
    );
}
