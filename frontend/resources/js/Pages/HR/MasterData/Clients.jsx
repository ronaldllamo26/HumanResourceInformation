import { Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    Briefcase,
    Calendar,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    CircleSlash,
    DollarSign,
    ExternalLink,
    FileText,
    FileWarning,
    Handshake,
    MapPin,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    ShieldAlert,
    ShieldCheck,
    UserCheck,
    UserPlus,
    Users,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    DateInput,
    Field,
    Input,
    Modal,
    SearchInput,
    Select,
    MeterCard,
    StatCard,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { cn, formatCurrency, formatDate, initials } from '@/lib/utils';

const BLANK_CLIENT = {
    code: '',
    name: '',
    industry: '',
    wage_region: '',
    contact_person: '',
    contact_email: '',
    contact_number: '',
    address: '',
    contract_start: '',
    contract_end: '',
    is_active: true,
};

const BLANK_DEPLOYMENT = {
    client_id: '',
    position_id: '',
    employment_status: 'contractual',
    contract_start: '',
    contract_end: '',
    basic_salary: '',
    wage_region: '',
    notes: '',
};

/** One labelled fact inside an opened client. Absent rather than blank. */
function Fact({ label, children }) {
    if (children === null || children === undefined || children === '') return null;

    return (
        <div className="min-w-0">
            <dt className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                {label}
            </dt>
            <dd className="mt-0.5 truncate text-sm text-foreground">{children}</dd>
        </div>
    );
}

const NO_DEPARTMENT = 'No department';
const NO_POSITION = 'No position on file';

function groupBy(employees, field, fallback) {
    const groups = new Map();

    employees.forEach((employee) => {
        const key = employee[field] ?? fallback;

        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(employee);
    });

    return [...groups.entries()]
        .map(([name, members]) => ({
            name,
            members,
            free: members.filter((member) => member.client_id === null).length,
        }))
        .sort((a, b) => a.name.localeCompare(b.name));
}

function DrillRow({ group, onOpen }) {
    return (
        <button
            type="button"
            onClick={() => onOpen(group.name)}
            className="flex w-full items-center gap-3 border-b border-border px-4 py-3 text-left transition-colors last:border-0 hover:bg-secondary/50"
        >
            <span className="min-w-0 flex-1 truncate text-sm font-medium text-foreground">
                {group.name}
            </span>
            <span className="shrink-0 text-xs text-muted-foreground">
                <span className={group.free > 0 ? 'font-medium text-foreground' : ''}>
                    {group.free} free
                </span>
                {' · '}
                {group.members.length} total
            </span>
            <ChevronRight
                className="h-4 w-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
        </button>
    );
}

function PickGroup({ label, hint, employees, busy, onPick }) {
    if (employees.length === 0) return null;

    return (
        <>
            <div className="sticky top-0 z-10 flex items-baseline gap-2 border-b border-border bg-secondary/80 px-4 py-1.5 backdrop-blur">
                <span className="text-[11px] font-semibold uppercase tracking-wide text-foreground">
                    {label}
                </span>
                <span className="text-[11px] text-muted-foreground">
                    {employees.length} · {hint}
                </span>
            </div>

            {employees.map((employee) => (
                <button
                    key={employee.id}
                    type="button"
                    disabled={busy}
                    onClick={() => onPick(employee)}
                    className="flex w-full items-center gap-3 border-b border-border px-4 py-2.5 text-left transition-colors last:border-0 hover:bg-secondary/50 disabled:opacity-50"
                >
                    <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                        {initials(employee.full_name)}
                    </span>

                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium text-foreground">
                            {employee.full_name}
                        </span>
                        <span className="block truncate text-[11px] text-muted-foreground">
                            <span className={employee.position ? 'text-foreground' : 'italic'}>
                                {employee.position ?? 'No position on file'}
                            </span>
                            {employee.department && ` · ${employee.department}`}
                            <span className="font-mono"> · {employee.employee_number}</span>
                        </span>
                    </span>

                    <Badge variant={employee.client_name ? 'info' : 'muted'}>
                        {employee.client_name ?? 'Not deployed'}
                    </Badge>
                </button>
            ))}
        </>
    );
}

/**
 * Enhanced Client Card with Contract Details, Regional Floor, and Deployed Roster.
 */
function ClientBlock({
    client,
    open,
    onToggle,
    onOpenDeploy,
    onOpenEditDeployment,
    onRecall,
    wageRegions,
}) {
    const staff = client.employees ?? [];

    const regionInfo = useMemo(() => {
        return (
            wageRegions.find((r) => r.value === client.wage_region) ?? {
                label: client.wage_region,
                daily_minimum: client.daily_minimum,
            }
        );
    }, [wageRegions, client.wage_region]);

    return (
        <Card className="mb-4 overflow-hidden transition-all duration-200">
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                className="flex w-full items-center gap-3 px-5 py-4 text-left transition-colors hover:bg-secondary/40"
            >
                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                    <Handshake className="h-5 w-5" aria-hidden="true" />
                </span>

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="truncate text-base font-semibold text-foreground">
                            {client.name}
                        </h3>
                        <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs text-muted-foreground">
                            {client.code}
                        </span>
                    </div>
                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                        {client.industry && <span>{client.industry} · </span>}
                        {client.address && <span>{client.address} · </span>}
                        {client.wage_region_label && (
                            <span className="font-medium text-primary">
                                {client.wage_region_label}
                                {client.daily_minimum &&
                                    ` (Floor: ${formatCurrency(client.daily_minimum)}/day)`}
                            </span>
                        )}
                    </p>
                </div>

                <div className="hidden items-center gap-2 sm:flex">
                    {client.contract_lapsed && (
                        <Badge variant="warning">
                            <FileWarning className="mr-1 h-3 w-3" aria-hidden="true" />
                            Contract lapsed
                        </Badge>
                    )}

                    {!client.is_active && <Badge variant="muted">Inactive</Badge>}

                    <Badge
                        variant={client.active_employees_count > 0 ? 'info' : 'muted'}
                        className="font-medium"
                    >
                        {client.active_employees_count}{' '}
                        {client.active_employees_count === 1 ? 'Person' : 'People'} Deployed
                    </Badge>
                </div>

                <ChevronDown
                    className={cn(
                        'h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200',
                        open && 'rotate-180',
                    )}
                    aria-hidden="true"
                />
            </button>

            {open && (
                <div className="border-t border-border bg-card/60">
                    {/* Client Master Details Bar */}
                    <dl className="grid gap-4 border-b border-border/80 bg-muted/20 px-5 py-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Fact label="Contact Person">{client.contact_person}</Fact>
                        <Fact label="Contact Email">
                            {client.contact_email && (
                                <a
                                    href={`mailto:${client.contact_email}`}
                                    className="text-primary hover:underline"
                                >
                                    {client.contact_email}
                                </a>
                            )}
                        </Fact>
                        <Fact label="Contact Phone">
                            {client.contact_number && (
                                <a
                                    href={`tel:${client.contact_number}`}
                                    className="font-mono tabular-nums text-primary hover:underline"
                                >
                                    {client.contact_number}
                                </a>
                            )}
                        </Fact>
                        <Fact label="Site Location">{client.address}</Fact>

                        <Fact label="Wage Region & Floor">
                            {client.wage_region ? (
                                <>
                                    <span className="font-medium">
                                        {client.wage_region_label ?? client.wage_region}
                                    </span>
                                    {client.daily_minimum && (
                                        <span className="ml-1 font-semibold text-emerald-600 dark:text-emerald-400">
                                            · {formatCurrency(client.daily_minimum)}/day min
                                        </span>
                                    )}
                                </>
                            ) : (
                                <span className="text-muted-foreground">Standard Region</span>
                            )}
                        </Fact>

                        <Fact label="Client Master Agreement">
                            {client.contract_start ? (
                                <>
                                    {formatDate(client.contract_start)}
                                    {client.contract_end
                                        ? ` to ${formatDate(client.contract_end)}`
                                        : ' (Open-ended)'}
                                </>
                            ) : (
                                <span className="text-muted-foreground">Not set</span>
                            )}
                        </Fact>

                        <Fact label="Contract Status">
                            {client.contract_lapsed ? (
                                <span className="font-semibold text-warning">
                                    Lapsed (Expired)
                                </span>
                            ) : client.contract_end ? (
                                <span className="font-medium text-emerald-600 dark:text-emerald-400">
                                    Active until {formatDate(client.contract_end)}
                                </span>
                            ) : (
                                <span className="text-muted-foreground">Continuous</span>
                            )}
                        </Fact>

                        <Fact label="Total Historical Deployments">
                            {client.employees_count} staff recorded
                        </Fact>
                    </dl>

                    {/* Action Hub for Client: Data Onboarding & Profiling */}
                    <div className="flex flex-col gap-3 border-b border-border bg-card px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                Data Onboarding &amp; Deployed Workforce
                            </h4>
                        </div>

                        {client.is_active ? (
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    href={`/hr/employees/create?client_id=${client.id}`}
                                    title="Direktang mag-register at mag-profile ng bagong empleyado para sa client na ito"
                                >
                                    <UserPlus className="mr-1.5 h-3.5 w-3.5 text-primary" />
                                    Onboard New Staff
                                </Button>

                                <Button
                                    size="sm"
                                    onClick={() => onOpenDeploy(client)}
                                    title="Mag-deploy ng existing employee na may kumpletong contract at salary rate"
                                >
                                    <Briefcase className="mr-1.5 h-3.5 w-3.5" />
                                    Deploy Staff to Client
                                </Button>
                            </div>
                        ) : (
                            <span className="text-xs italic text-muted-foreground">
                                Deactivated Client — hindi maaaring mag-assign ng bagong
                                deployment.
                            </span>
                        )}
                    </div>

                    {/* Deployed Workforce Roster */}
                    {staff.length === 0 ? (
                        <div className="px-5 py-10 text-center">
                            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-secondary/80 text-muted-foreground">
                                <Users className="h-6 w-6" aria-hidden="true" />
                            </div>
                            <h4 className="mt-3 text-sm font-semibold text-foreground">
                                Walang Naka-deploy na Empleyado
                            </h4>
                            {client.is_active && (
                                <div className="mt-4 flex flex-wrap justify-center gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        href={`/hr/employees/create?client_id=${client.id}`}
                                    >
                                        <UserPlus className="mr-1.5 h-3.5 w-3.5" />
                                        Onboard New Staff
                                    </Button>
                                    <Button size="sm" onClick={() => onOpenDeploy(client)}>
                                        <Briefcase className="mr-1.5 h-3.5 w-3.5" />
                                        Deploy Existing Staff
                                    </Button>
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <THead>
                                    <TR className="bg-muted/40 text-[11px]">
                                        <TH>Employee Profile</TH>
                                        <TH>Assigned Role / Dept</TH>
                                        <TH>Contract Duration &amp; Status</TH>
                                        <TH>Salary Rate &amp; Compliance</TH>
                                        <TH>Wage Region</TH>
                                        <TH className="text-right">Actions</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {staff.map((employee) => (
                                        <TR key={employee.id} className="hover:bg-muted/20">
                                            {/* Profile */}
                                            <TD>
                                                <div className="flex items-center gap-2.5">
                                                    {employee.photo_url ? (
                                                        <img
                                                            src={employee.photo_url}
                                                            alt=""
                                                            className="h-9 w-9 shrink-0 rounded-full border border-border object-cover"
                                                        />
                                                    ) : (
                                                        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                                                            {initials(employee.full_name)}
                                                        </span>
                                                    )}
                                                    <div className="min-w-0">
                                                        <Link
                                                            href={`/hr/employees/${employee.id}`}
                                                            className="block truncate text-sm font-medium text-foreground hover:text-primary hover:underline"
                                                        >
                                                            {employee.full_name}
                                                        </Link>
                                                        <span className="font-mono text-[11px] text-muted-foreground">
                                                            {employee.employee_number}
                                                        </span>
                                                    </div>
                                                </div>
                                            </TD>

                                            {/* Role & Dept */}
                                            <TD>
                                                <div className="min-w-0">
                                                    <p className="truncate text-xs font-medium text-foreground">
                                                        {employee.position ??
                                                            'No position assigned'}
                                                    </p>
                                                    <p className="truncate text-[11px] text-muted-foreground">
                                                        {employee.department ??
                                                            'External Manpower'}
                                                    </p>
                                                </div>
                                            </TD>

                                            {/* Contract */}
                                            <TD>
                                                <div className="space-y-1">
                                                    <div className="flex items-center gap-1.5 text-xs text-foreground">
                                                        <Calendar className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                                        <span>
                                                            {employee.contract_start
                                                                ? formatDate(
                                                                      employee.contract_start,
                                                                  )
                                                                : 'Started'}
                                                            {employee.contract_end ? (
                                                                <>
                                                                    {' – '}
                                                                    <span
                                                                        className={
                                                                            employee.contract_lapsed
                                                                                ? 'font-bold text-destructive'
                                                                                : ''
                                                                        }
                                                                    >
                                                                        {formatDate(
                                                                            employee.contract_end,
                                                                        )}
                                                                    </span>
                                                                </>
                                                            ) : (
                                                                <span className="text-muted-foreground">
                                                                    {' · '}Continuous
                                                                </span>
                                                            )}
                                                        </span>
                                                    </div>

                                                    <div>
                                                        {employee.contract_lapsed ? (
                                                            <Badge
                                                                variant="destructive"
                                                                className="text-[10px]"
                                                            >
                                                                Contract Lapsed
                                                            </Badge>
                                                        ) : employee.contract_expiring_soon ? (
                                                            <Badge
                                                                variant="warning"
                                                                className="text-[10px]"
                                                            >
                                                                Expiring Soon
                                                            </Badge>
                                                        ) : (
                                                            <Badge
                                                                variant="success"
                                                                className="text-[10px]"
                                                            >
                                                                {employee.employment_status
                                                                    ? ucwords(
                                                                          employee.employment_status,
                                                                      )
                                                                    : 'Active Contract'}
                                                            </Badge>
                                                        )}
                                                    </div>
                                                </div>
                                            </TD>

                                            {/* Salary Rate */}
                                            <TD>
                                                <div>
                                                    <p className="font-mono text-xs font-semibold text-foreground">
                                                        {employee.basic_salary > 0
                                                            ? formatCurrency(
                                                                  employee.basic_salary,
                                                              )
                                                            : '₱0.00'}{' '}
                                                        <span className="font-sans text-[10px] font-normal text-muted-foreground">
                                                            / mo
                                                        </span>
                                                    </p>
                                                    {employee.daily_equivalent > 0 && (
                                                        <p className="font-mono text-[11px] text-muted-foreground">
                                                            ≈{' '}
                                                            {formatCurrency(
                                                                employee.daily_equivalent,
                                                            )}
                                                            /day
                                                        </p>
                                                    )}
                                                    {employee.daily_minimum_floor && (
                                                        <span
                                                            className={cn(
                                                                'mt-0.5 inline-flex items-center gap-1 text-[10px] font-medium',
                                                                employee.is_wage_compliant
                                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                                    : 'font-semibold text-amber-600 dark:text-amber-400',
                                                            )}
                                                        >
                                                            {employee.is_wage_compliant ? (
                                                                <CheckCircle2 className="h-3 w-3" />
                                                            ) : (
                                                                <ShieldAlert className="h-3 w-3" />
                                                            )}
                                                            {employee.is_wage_compliant
                                                                ? 'Compliant with wage floor'
                                                                : `Below ${formatCurrency(employee.daily_minimum_floor)}/day`}
                                                        </span>
                                                    )}
                                                </div>
                                            </TD>

                                            {/* Region */}
                                            <TD>
                                                <span className="inline-flex items-center gap-1 rounded bg-secondary px-2 py-0.5 text-xs text-foreground">
                                                    <MapPin className="h-3 w-3 text-muted-foreground" />
                                                    {employee.wage_region_label ??
                                                        employee.wage_region ??
                                                        'Default'}
                                                </span>
                                            </TD>

                                            {/* Actions */}
                                            <TD className="text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            onOpenEditDeployment(
                                                                client,
                                                                employee,
                                                            )
                                                        }
                                                        title="I-edit ang contract dates, salary rate, o posisyon"
                                                        aria-label="Edit deployment"
                                                    >
                                                        <Pencil className="h-3.5 w-3.5 text-primary" />
                                                        <span className="ml-1 hidden text-xs lg:inline">
                                                            Contract &amp; Rate
                                                        </span>
                                                    </Button>

                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="text-muted-foreground hover:text-destructive"
                                                        onClick={() =>
                                                            onRecall(employee, client)
                                                        }
                                                        title="Ibalik sa internal staff (in-house)"
                                                        aria-label="Recall employee"
                                                    >
                                                        <RotateCcw className="h-3.5 w-3.5" />
                                                        <span className="ml-1 hidden text-xs lg:inline">
                                                            Recall
                                                        </span>
                                                    </Button>
                                                </div>
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </div>
                    )}
                </div>
            )}
        </Card>
    );
}

function ucwords(str) {
    return String(str || '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

export default function Clients({
    clients = [],
    deployable = [],
    filters = {},
    summary = {},
    wageRegions = [],
    positions = [],
    departments = [],
    employmentStatuses = [],
}) {
    const [creating, setCreating] = useState(false);

    // Deployment & Profiling Modal state
    const [deployModal, setDeployModal] = useState({
        open: false,
        client: null,
        employee: null,
        isEdit: false,
    });

    const [selectedCandidate, setSelectedCandidate] = useState(null);
    const [pickSearch, setPickSearch] = useState('');
    const [pickDept, setPickDept] = useState(null);
    const [pickPosition, setPickPosition] = useState(null);

    const form = useForm(BLANK_CLIENT);
    const deployForm = useForm(BLANK_DEPLOYMENT);

    const [expanded, setExpanded] = useState(() => new Set());

    const toggle = (id) =>
        setExpanded((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);

            return next;
        });

    const isOpen = (id) => Boolean(filters.search) || expanded.has(id);

    const openCreate = () => {
        form.clearErrors();
        form.setData(BLANK_CLIENT);
        setCreating(true);
    };

    const submitCreate = (event) => {
        event.preventDefault();
        form.post('/hr/clients', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setCreating(false);
            },
        });
    };

    // Open deployment modal for new candidate
    const openDeploy = (client) => {
        setPickSearch('');
        setPickDept(null);
        setPickPosition(null);
        setSelectedCandidate(null);
        deployForm.clearErrors();
        deployForm.setData({
            ...BLANK_DEPLOYMENT,
            client_id: client.id,
            wage_region: client.wage_region || '',
            contract_start: new Date().toISOString().slice(0, 10),
        });
        setDeployModal({
            open: true,
            client,
            employee: null,
            isEdit: false,
        });
    };

    // Open deployment modal to edit existing contract/rate
    const openEditDeployment = (client, employee) => {
        deployForm.clearErrors();
        deployForm.setData({
            client_id: client.id,
            position_id: employee.position_id ? String(employee.position_id) : '',
            employment_status: employee.employment_status || 'contractual',
            contract_start: employee.contract_start || '',
            contract_end: employee.contract_end || '',
            basic_salary: employee.basic_salary ? String(employee.basic_salary) : '',
            wage_region: employee.wage_region || client.wage_region || '',
            notes: '',
        });
        setSelectedCandidate(employee);
        setDeployModal({
            open: true,
            client,
            employee,
            isEdit: true,
        });
    };

    const selectCandidate = (employee) => {
        setSelectedCandidate(employee);
        deployForm.setData((prev) => ({
            ...prev,
            client_id: deployModal.client.id,
            position_id: employee.position_id ? String(employee.position_id) : prev.position_id,
            employment_status: employee.employment_status || 'contractual',
            basic_salary: employee.basic_salary
                ? String(employee.basic_salary)
                : prev.basic_salary,
            wage_region: employee.wage_region || deployModal.client.wage_region || '',
            contract_start: employee.contract_start || new Date().toISOString().slice(0, 10),
            contract_end: employee.contract_end || '',
        }));
    };

    const submitDeployment = (event) => {
        event.preventDefault();

        const employeeId = selectedCandidate?.id || deployModal.employee?.id;
        if (!employeeId) return;

        deployForm.patch(`/hr/employees/${employeeId}/deployment`, {
            preserveScroll: true,
            onSuccess: () => {
                setDeployModal({ open: false, client: null, employee: null, isEdit: false });
                setSelectedCandidate(null);
            },
        });
    };

    const recall = (employee, client) => {
        if (
            !window.confirm(
                `Ibalik si ${employee.full_name} sa internal staff mula sa ${client.name}?`,
            )
        ) {
            return;
        }

        deployForm.transform(() => ({ client_id: null }));
        deployForm.patch(`/hr/employees/${employee.id}/deployment`, {
            preserveScroll: true,
        });
    };

    // Candidates filtering
    const candidates = useMemo(() => {
        return deployable
            .filter((employee) => employee.client_id !== deployModal.client?.id)
            .filter((employee) => {
                const needle = pickSearch.trim().toLowerCase();
                if (!needle) return true;

                return [
                    employee.full_name,
                    employee.employee_number,
                    employee.position,
                    employee.department,
                ].some((field) =>
                    String(field ?? '')
                        .toLowerCase()
                        .includes(needle),
                );
            });
    }, [deployable, deployModal.client, pickSearch]);

    const searching = pickSearch.trim() !== '';

    const atLevel = searching
        ? candidates
        : candidates.filter(
              (employee) =>
                  (employee.department ?? NO_DEPARTMENT) === pickDept &&
                  (employee.position ?? NO_POSITION) === pickPosition,
          );

    const available = atLevel.filter((employee) => employee.client_id === null);
    const placed = atLevel.filter((employee) => employee.client_id !== null);

    const candidateDepartments =
        searching || pickDept ? [] : groupBy(candidates, 'department', NO_DEPARTMENT);

    const candidatePositions =
        searching || !pickDept || pickPosition
            ? []
            : groupBy(
                  candidates.filter(
                      (employee) => (employee.department ?? NO_DEPARTMENT) === pickDept,
                  ),
                  'position',
                  NO_POSITION,
              );

    const showingPeople = searching || Boolean(pickPosition);

    // Dynamic wage calculation in deployment form
    const currentWageRegion = useMemo(() => {
        const key = deployForm.data.wage_region || deployModal.client?.wage_region;
        return wageRegions.find((r) => r.value === key) ?? null;
    }, [deployForm.data.wage_region, deployModal.client, wageRegions]);

    const dailyCalculated = useMemo(() => {
        const salary = parseFloat(deployForm.data.basic_salary);
        return !isNaN(salary) && salary > 0 ? Math.round((salary / 26) * 100) / 100 : 0;
    }, [deployForm.data.basic_salary]);

    const isSalaryCompliant = useMemo(() => {
        if (!currentWageRegion?.daily_minimum || dailyCalculated === 0) return true;
        return dailyCalculated >= currentWageRegion.daily_minimum;
    }, [dailyCalculated, currentWageRegion]);

    const deployedRate = summary.total > 0 ? (summary.deployed / summary.total) * 100 : 0;
    const setClientField = (field) => (event) => form.setData(field, event.target.value);
    const setDeployField = (field) => (event) => deployForm.setData(field, event.target.value);

    return (
        <AppLayout
            title="Clients"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Clients' },
            ]}
        >
            {/* Top Stat Cards */}
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Clients"
                    value={summary.total}
                    icon={Briefcase}
                    tone={summary.total > 0 ? 'primary' : 'muted'}
                />

                <MeterCard
                    label="With Deployments"
                    value={summary.deployed}
                    percent={deployedRate}
                    badge={summary.total > 0 ? `${Math.round(deployedRate)}%` : undefined}
                    icon={Users}
                    tone="success"
                    iconTone="success"
                />

                <StatCard
                    label="Deployed Staff"
                    value={clients.reduce(
                        (sum, client) => sum + (client.active_employees_count || 0),
                        0,
                    )}
                    icon={Users}
                    tone="info"
                />

                <StatCard
                    label="Lapsed Contracts"
                    value={summary.lapsed}
                    icon={FileWarning}
                    tone={summary.lapsed > 0 ? 'warning' : 'muted'}
                />
            </div>

            {/* Header Action Card */}
            <Card className="mb-5">
                <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="text-base font-semibold text-foreground">
                            Client Organizations &amp; Manpower Deployments
                        </h2>
                    </div>

                    <Button onClick={openCreate}>
                        <Plus className="mr-1.5 h-4 w-4" />
                        New Client
                    </Button>
                </div>
            </Card>

            {/* Clients List */}
            {clients.length === 0 ? (
                <Card>
                    <CardBody className="py-14 text-center">
                        <p className="text-sm font-medium text-foreground">
                            {filters.search
                                ? 'No client matches that search'
                                : 'Walang rehistradong kliyente sa ngayon.'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Magdagdag ng kliyente upang simulan ang pag-onboard at pag-deploy ng
                            mga kawani.
                        </p>
                    </CardBody>
                </Card>
            ) : (
                clients.map((client) => (
                    <ClientBlock
                        key={client.id}
                        client={client}
                        open={isOpen(client.id)}
                        onToggle={() => toggle(client.id)}
                        onOpenDeploy={openDeploy}
                        onOpenEditDeployment={openEditDeployment}
                        onRecall={recall}
                        wageRegions={wageRegions}
                    />
                ))
            )}

            {/* Comprehensive Deployment & Profiling Modal */}
            <Modal
                show={deployModal.open}
                onClose={() => {
                    setDeployModal({
                        open: false,
                        client: null,
                        employee: null,
                        isEdit: false,
                    });
                    setSelectedCandidate(null);
                }}
                title={
                    deployModal.isEdit
                        ? `I-edit ang Kontrata at Deployment · ${selectedCandidate?.full_name}`
                        : `Data Onboarding & Deployment Profiling · ${deployModal.client?.name}`
                }
                maxWidth="3xl"
            >
                <div className="space-y-4">
                    {/* Step 1: Candidate Selection (Only when deploying new) */}
                    {!deployModal.isEdit && !selectedCandidate && (
                        <div className="space-y-3">
                            <div className="rounded-lg border border-primary/20 bg-primary/5 p-3 text-xs text-foreground">
                                <span className="font-semibold text-primary">
                                    Hakbang 1: Piliin ang Empleyadong I-dedeploy
                                </span>
                                <p className="mt-0.5 text-muted-foreground">
                                    Pumili ng kawani mula sa listahan. Mag-search ng pangalan o
                                    pumili ayon sa departamento.
                                </p>
                            </div>

                            <SearchInput
                                value={pickSearch}
                                onChange={(event) => setPickSearch(event.target.value)}
                                placeholder="Mag-search ng pangalan, posisyon, o employee number..."
                                aria-label="Search employees to deploy"
                            />

                            {!searching && (
                                <nav className="flex flex-wrap items-center gap-1 text-xs">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setPickDept(null);
                                            setPickPosition(null);
                                        }}
                                        disabled={!pickDept}
                                        className="rounded px-1.5 py-0.5 font-medium text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground disabled:pointer-events-none disabled:text-foreground"
                                    >
                                        All departments
                                    </button>

                                    {pickDept && (
                                        <>
                                            <ChevronRight
                                                className="h-3 w-3 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setPickPosition(null)}
                                                disabled={!pickPosition}
                                                className="rounded px-1.5 py-0.5 font-medium text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground disabled:pointer-events-none disabled:text-foreground"
                                            >
                                                {pickDept}
                                            </button>
                                        </>
                                    )}

                                    {pickPosition && (
                                        <>
                                            <ChevronRight
                                                className="h-3 w-3 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                            <span className="px-1.5 py-0.5 font-medium text-foreground">
                                                {pickPosition}
                                            </span>
                                        </>
                                    )}
                                </nav>
                            )}

                            <div className="scrollbar-thin max-h-72 overflow-y-auto rounded-lg border border-border">
                                {candidates.length === 0 ? (
                                    <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                                        Walang available na empleyado para i-deploy.
                                    </p>
                                ) : (
                                    <>
                                        {candidateDepartments.map((group) => (
                                            <DrillRow
                                                key={group.name}
                                                group={group}
                                                onOpen={setPickDept}
                                            />
                                        ))}

                                        {candidatePositions.map((group) => (
                                            <DrillRow
                                                key={group.name}
                                                group={group}
                                                onOpen={setPickPosition}
                                            />
                                        ))}

                                        {showingPeople && (
                                            <>
                                                <PickGroup
                                                    label="Not deployed"
                                                    hint="free to send"
                                                    employees={available}
                                                    busy={deployForm.processing}
                                                    onPick={selectCandidate}
                                                />
                                                <PickGroup
                                                    label="Deployed elsewhere"
                                                    hint="malilipat mula sa kasalukuyang site"
                                                    employees={placed}
                                                    busy={deployForm.processing}
                                                    onPick={selectCandidate}
                                                />

                                                {atLevel.length === 0 && (
                                                    <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                                                        {searching
                                                            ? 'Walang tumugma sa search na iyon.'
                                                            : 'Lahat sa posisyong ito ay naka-deploy na.'}
                                                    </p>
                                                )}
                                            </>
                                        )}
                                    </>
                                )}
                            </div>
                        </div>
                    )}

                    {/* Step 2: Contract, Salary Rate & Deployment Details Form */}
                    {selectedCandidate && (
                        <form onSubmit={submitDeployment} className="space-y-4">
                            {/* Selected Employee Summary Banner */}
                            <div className="flex items-center justify-between rounded-lg border border-border bg-secondary/40 p-3">
                                <div className="flex items-center gap-3">
                                    <span className="grid h-10 w-10 place-items-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                                        {initials(selectedCandidate.full_name)}
                                    </span>
                                    <div>
                                        <h4 className="text-sm font-semibold text-foreground">
                                            {selectedCandidate.full_name}
                                        </h4>
                                        <p className="font-mono text-xs text-muted-foreground">
                                            {selectedCandidate.employee_number}
                                            {selectedCandidate.position &&
                                                ` · ${selectedCandidate.position}`}
                                            {selectedCandidate.department &&
                                                ` · ${selectedCandidate.department}`}
                                        </p>
                                    </div>
                                </div>

                                {!deployModal.isEdit && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setSelectedCandidate(null)}
                                    >
                                        Palitan
                                    </Button>
                                )}
                            </div>

                            {/* Section: Deployment Details */}
                            <div className="space-y-3 rounded-lg border border-border bg-card p-3.5">
                                <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-primary">
                                    <MapPin className="h-3.5 w-3.5" />
                                    Deployment &amp; Regional Assignment
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label className="mb-1 block text-xs font-medium text-muted-foreground">
                                            Target Client
                                        </label>
                                        <div className="rounded-md border border-border bg-muted/30 px-3 py-2 text-xs font-medium text-foreground">
                                            {deployModal.client?.name} (
                                            {deployModal.client?.code})
                                        </div>
                                    </div>

                                    <Field
                                        label="Deployment Role / Position"
                                        error={deployForm.errors.position_id}
                                    >
                                        {({ id }) => (
                                            <Select
                                                id={id}
                                                value={deployForm.data.position_id}
                                                onChange={setDeployField('position_id')}
                                                placeholder="Select deployment position"
                                                options={positions.map((pos) => ({
                                                    value: String(pos.id),
                                                    label: pos.title,
                                                }))}
                                            />
                                        )}
                                    </Field>

                                    <Field
                                        label="Assigned Wage Region"
                                        error={deployForm.errors.wage_region}
                                        hint="Kung sa ibang site/rehiyon naiba sa default ng kliyente."
                                    >
                                        {({ id }) => (
                                            <Select
                                                id={id}
                                                value={deployForm.data.wage_region}
                                                onChange={setDeployField('wage_region')}
                                                placeholder={`Use client's (${deployModal.client?.wage_region || 'Standard'})`}
                                                options={wageRegions.map((region) => ({
                                                    value: region.value,
                                                    label: `${region.label} (Floor: ${formatCurrency(region.daily_minimum)}/day)`,
                                                }))}
                                            />
                                        )}
                                    </Field>

                                    <Field
                                        label="Employment / Contract Type"
                                        required
                                        error={deployForm.errors.employment_status}
                                    >
                                        {({ id }) => (
                                            <Select
                                                id={id}
                                                value={deployForm.data.employment_status}
                                                onChange={setDeployField('employment_status')}
                                                options={employmentStatuses}
                                            />
                                        )}
                                    </Field>
                                </div>
                            </div>

                            {/* Section: Contract Details (Kontrata) */}
                            <div className="space-y-3 rounded-lg border border-border bg-card p-3.5">
                                <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-primary">
                                    <FileText className="h-3.5 w-3.5" />
                                    Contract Details (Kontrata)
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field
                                        label="Contract Start Date (Simula)"
                                        required
                                        error={deployForm.errors.contract_start}
                                    >
                                        {({ id }) => (
                                            <DateInput
                                                id={id}
                                                value={deployForm.data.contract_start}
                                                onChange={setDeployField('contract_start')}
                                                error={deployForm.errors.contract_start}
                                            />
                                        )}
                                    </Field>

                                    <Field
                                        label="Contract End Date (Katapusan)"
                                        error={deployForm.errors.contract_end}
                                        hint="Iwanang blangko kung open-ended / continuous agreement."
                                    >
                                        {({ id }) => (
                                            <DateInput
                                                id={id}
                                                value={deployForm.data.contract_end}
                                                onChange={setDeployField('contract_end')}
                                                error={deployForm.errors.contract_end}
                                            />
                                        )}
                                    </Field>
                                </div>
                            </div>

                            {/* Section: Salary Rate & Regional Floor Compliance */}
                            <div className="space-y-3 rounded-lg border border-border bg-card p-3.5">
                                <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-primary">
                                    <DollarSign className="h-3.5 w-3.5" />
                                    Salary Rate &amp; Wage Compliance (Pasahod)
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field
                                        label="Monthly Basic Salary (Rate)"
                                        required
                                        error={deployForm.errors.basic_salary}
                                        hint="Buwanang sahod na nakasaad sa kontrata."
                                    >
                                        {({ id }) => (
                                            <Input
                                                id={id}
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={deployForm.data.basic_salary}
                                                onChange={setDeployField('basic_salary')}
                                                placeholder="hal. 25000"
                                                error={deployForm.errors.basic_salary}
                                            />
                                        )}
                                    </Field>

                                    {/* Live Wage Floor Calculation Preview */}
                                    <div className="flex flex-col justify-center rounded-md border border-border/70 bg-secondary/30 p-2.5">
                                        <div className="text-[11px] font-medium text-muted-foreground">
                                            Daily Equivalent (26 days):
                                        </div>
                                        <div className="font-mono text-sm font-semibold text-foreground">
                                            {formatCurrency(dailyCalculated)} / araw
                                        </div>

                                        {currentWageRegion && (
                                            <div className="mt-1 flex items-center gap-1.5 text-xs">
                                                {isSalaryCompliant ? (
                                                    <span className="flex items-center gap-1 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                                                        <CheckCircle2 className="h-3 w-3" />
                                                        Compliant sa Regional Floor (
                                                        {formatCurrency(
                                                            currentWageRegion.daily_minimum,
                                                        )}
                                                        )
                                                    </span>
                                                ) : (
                                                    <span className="flex items-center gap-1 text-[11px] font-semibold text-amber-600 dark:text-amber-400">
                                                        <ShieldAlert className="h-3 w-3" />
                                                        Mababa sa Floor (
                                                        {formatCurrency(
                                                            currentWageRegion.daily_minimum,
                                                        )}
                                                        /day)
                                                    </span>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                </div>

                                <Field
                                    label="Deployment Notes / Specific Project Assignment"
                                    error={deployForm.errors.notes}
                                >
                                    {({ id }) => (
                                        <Input
                                            id={id}
                                            value={deployForm.data.notes}
                                            onChange={setDeployField('notes')}
                                            placeholder="hal. Deployed to Warehouse Project Phase 2, Shift Schedule A"
                                        />
                                    )}
                                </Field>
                            </div>

                            {/* Modal Actions */}
                            <div className="flex justify-end gap-2 border-t border-border pt-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        setDeployModal({
                                            open: false,
                                            client: null,
                                            employee: null,
                                            isEdit: false,
                                        });
                                        setSelectedCandidate(null);
                                    }}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={deployForm.processing}>
                                    <CheckCircle2 className="mr-1.5 h-4 w-4" />
                                    {deployModal.isEdit
                                        ? 'Update Deployment & Contract'
                                        : 'Confirm & Deploy to Client'}
                                </Button>
                            </div>
                        </form>
                    )}
                </div>
            </Modal>

            {/* Create Client Modal */}
            <Modal
                show={creating}
                onClose={() => setCreating(false)}
                title="New Client"
                description="Deployed employees are filed against a client; payroll and billing group by it."
                maxWidth="2xl"
            >
                <form onSubmit={submitCreate} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Code" required error={form.errors.code}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.code}
                                    onChange={setClientField('code')}
                                    error={form.errors.code}
                                    placeholder="MTL"
                                />
                            )}
                        </Field>

                        <Field label="Name" required error={form.errors.name}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.name}
                                    onChange={setClientField('name')}
                                    error={form.errors.name}
                                />
                            )}
                        </Field>

                        <Field label="Industry" error={form.errors.industry}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.industry}
                                    onChange={setClientField('industry')}
                                />
                            )}
                        </Field>

                        <Field
                            label="Wage Region"
                            error={form.errors.wage_region}
                            hint="The regional wage floor deployed staff are measured against."
                        >
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={form.data.wage_region}
                                    onChange={setClientField('wage_region')}
                                    placeholder="Select region"
                                    options={wageRegions.map((region) => ({
                                        value: region.value,
                                        label: `${region.label} (Floor: ${formatCurrency(region.daily_minimum)}/day)`,
                                    }))}
                                />
                            )}
                        </Field>

                        <Field label="Contact Person" error={form.errors.contact_person}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.contact_person}
                                    onChange={setClientField('contact_person')}
                                />
                            )}
                        </Field>

                        <Field label="Contact Number" error={form.errors.contact_number}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.contact_number}
                                    onChange={setClientField('contact_number')}
                                />
                            )}
                        </Field>

                        <Field label="Contact Email" error={form.errors.contact_email}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="email"
                                    value={form.data.contact_email}
                                    onChange={setClientField('contact_email')}
                                    error={form.errors.contact_email}
                                />
                            )}
                        </Field>

                        <Field label="Address" error={form.errors.address}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.address}
                                    onChange={setClientField('address')}
                                />
                            )}
                        </Field>

                        <Field label="Contract Start" error={form.errors.contract_start}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={form.data.contract_start}
                                    onChange={setClientField('contract_start')}
                                />
                            )}
                        </Field>

                        <Field
                            label="Contract End"
                            error={form.errors.contract_end}
                            hint="Leave blank for an open-ended agreement."
                        >
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={form.data.contract_end}
                                    onChange={setClientField('contract_end')}
                                    error={form.errors.contract_end}
                                />
                            )}
                        </Field>
                    </div>

                    <label className="flex items-center gap-2.5 text-sm text-foreground">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(event) =>
                                form.setData('is_active', event.target.checked)
                            }
                            className="h-4 w-4 rounded border-border text-primary focus:ring-ring"
                        />
                        Active — available when assigning deployments
                    </label>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setCreating(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Create Client
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
