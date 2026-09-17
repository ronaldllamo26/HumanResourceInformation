import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    Briefcase,
    ChevronDown,
    ChevronRight,
    FileWarning,
    Handshake,
    Plus,
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
} from '@/Components/ui';
import { cn, formatCurrency, formatDate, initials } from '@/lib/utils';

const BLANK = {
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

/*
 * Where the people with nothing filed against them go.
 *
 * Named buckets rather than dropping them: somebody with no department is
 * still somebody who can be sent, and a picker that silently omitted them
 * would be a list nobody could reconcile against the roster.
 */
const NO_DEPARTMENT = 'No department';
const NO_POSITION = 'No position on file';

/**
 * Groups the roster by one field, keeping a bucket for the people who have
 * none of it, and counting how many of each group are free to send.
 *
 * The free count is the number the reader is actually after: "Operations has
 * twelve" is trivia until you know that eight of them are unplaced.
 */
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

/** One step down the org chart — a department, or a position inside one. */
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

            {/*
             * Two figures, because one does not answer the question. `free` is
             * what somebody opening this modal is looking for; the total is
             * the scale it has to be read against — three free out of four is
             * a different situation from three out of thirty.
             */}
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

/**
 * One half of the person list: the people who are free, or the people who are
 * already somewhere.
 *
 * The group carries the explanation once, in its heading, rather than every
 * row repeating it. A row then only has to say *which* client, which is the
 * part that differs between them.
 *
 * Absent rather than empty when the group has nobody: a heading over nothing
 * is a thing the reader has to look at twice to learn there is nothing there.
 */
function PickGroup({ label, hint, employees, busy, onPick }) {
    if (employees.length === 0) return null;

    return (
        <>
            <div className="sticky top-0 z-10 flex items-baseline gap-2 border-b border-border bg-secondary/60 px-4 py-1.5 backdrop-blur">
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

                        {/*
                         * The job first, then where they sit, then the number.
                         * A client asking for drivers is deciding on the
                         * *position* — the employee number is how you confirm
                         * you picked the right person, which is a later
                         * question and belongs later in the line.
                         *
                         * "No position on file" is said rather than left
                         * blank: it is a real gap somebody should fix before
                         * this person is sent anywhere, and a silent absence
                         * reads as a rendering fault.
                         */}
                        <span className="block truncate text-[11px] text-muted-foreground">
                            <span className={employee.position ? 'text-foreground' : 'italic'}>
                                {employee.position ?? 'No position on file'}
                            </span>
                            {employee.department && ` · ${employee.department}`}
                            <span className="font-mono"> · {employee.employee_number}</span>
                        </span>
                    </span>

                    {/*
                     * A badge rather than plain text, and `info` rather than a
                     * warning: being on somebody's site is a *state*, not a
                     * fault. Muted where there is nothing to name, because
                     * "not deployed" is the quiet answer of the two.
                     */}
                    <Badge variant={employee.client_name ? 'info' : 'muted'}>
                        {employee.client_name ?? 'Not deployed'}
                    </Badge>
                </button>
            ))}
        </>
    );
}

/**
 * A client, closed until somebody asks about it.
 *
 * The same shape the departments screen uses, and for the same reason: a table
 * of clients could be counted and never opened, so "what did we sign with
 * Metro Fleet, and who is on their site" had to be answered somewhere else.
 * Both halves are in here now — the terms, then the people.
 */
function ClientBlock({ client, open, onToggle, onDeploy, onRecall }) {
    const staff = client.employees ?? [];

    return (
        <Card className="mb-3">
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                className="flex w-full items-center gap-3 px-5 py-4 text-left transition-colors hover:bg-secondary/40"
            >
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                    <Handshake className="h-[18px] w-[18px]" aria-hidden="true" />
                </span>

                <div className="min-w-0 flex-1">
                    <h3 className="truncate text-sm font-semibold text-foreground">
                        {client.name}
                    </h3>
                    <p className="truncate text-[11px] text-muted-foreground">
                        <span className="font-mono">{client.code}</span>
                        {client.industry && ` · ${client.industry}`}
                    </p>
                </div>

                {/* A contract past its end date with people still on it is the
                    one finding worth carrying on the closed row: the
                    deployment is running past what was signed for. Reported,
                    never enforced — blocking payroll over a paperwork gap
                    would strand those employees unpaid. */}
                {client.contract_lapsed && (
                    <Badge variant="warning">
                        <FileWarning className="h-3 w-3" aria-hidden="true" />
                        Contract lapsed
                    </Badge>
                )}

                {!client.is_active && <Badge variant="muted">Inactive</Badge>}

                <Badge variant={client.active_employees_count > 0 ? 'info' : 'muted'}>
                    {client.active_employees_count}{' '}
                    {client.active_employees_count === 1 ? 'person' : 'people'}
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
                <>
                    <dl className="grid gap-4 border-t border-border px-5 py-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Fact label="Contact">{client.contact_person}</Fact>
                        <Fact label="Email">
                            {client.contact_email && (
                                <a
                                    href={`mailto:${client.contact_email}`}
                                    className="hover:text-primary"
                                >
                                    {client.contact_email}
                                </a>
                            )}
                        </Fact>
                        <Fact label="Phone">
                            {client.contact_number && (
                                <a
                                    href={`tel:${client.contact_number}`}
                                    className="font-mono tabular-nums hover:text-primary"
                                >
                                    {client.contact_number}
                                </a>
                            )}
                        </Fact>
                        <Fact label="Site">{client.address}</Fact>

                        {/* Not decoration: each region's RTWPB sets its own
                            wage floor, so this is the figure a deployed
                            employee's rate is measured against. */}
                        <Fact label="Wage region">
                            {client.wage_region && (
                                <>
                                    {client.wage_region_label ?? client.wage_region}
                                    {client.daily_minimum && (
                                        <span className="ml-1 text-xs text-muted-foreground">
                                            · {formatCurrency(client.daily_minimum)}/day floor
                                        </span>
                                    )}
                                </>
                            )}
                        </Fact>

                        <Fact label="Contract from">
                            {client.contract_start && formatDate(client.contract_start)}
                        </Fact>
                        <Fact label="Contract to">
                            {client.contract_end ? (
                                <span className={client.contract_lapsed ? 'text-warning' : ''}>
                                    {formatDate(client.contract_end)}
                                </span>
                            ) : (
                                <span className="text-muted-foreground">Open-ended</span>
                            )}
                        </Fact>

                        {/* Everyone ever filed here, against who is there now.
                            The gap is the client's history, and it is why a
                            client with staff is deactivated rather than
                            deleted — payslips keep the client they were
                            grouped under. */}
                        <Fact label="Filed here, all time">{client.employees_count}</Fact>
                    </dl>

                    {/* The action sits with the people it changes, not in the
                        page header: "deploy somebody" is a question about
                        *this* client, and a button at the top would have to
                        ask which one first. A deactivated client is not
                        offered — it is kept so payroll and attendance keep
                        what they were filed under, not so somebody new can be
                        sent there. */}
                    <div className="flex items-center gap-3 border-t border-border px-5 py-3">
                        <p className="flex-1 text-xs text-muted-foreground">
                            {staff.length === 0
                                ? 'Nobody is deployed here at the moment.'
                                : `${staff.length} deployed here now.`}
                        </p>

                        {client.is_active ? (
                            <Button size="sm" onClick={() => onDeploy(client)}>
                                <Plus className="h-4 w-4" aria-hidden="true" />
                                Deploy employee
                            </Button>
                        ) : (
                            <span className="text-xs text-muted-foreground">
                                Deactivated — no new deployments
                            </span>
                        )}
                    </div>

                    <div className="border-t border-border">
                        {staff.length === 0
                            ? null
                            : staff.map((employee) => (
                                  <div
                                      key={employee.id}
                                      className="flex items-center gap-3 border-b border-border px-4 py-2.5 last:border-0"
                                  >
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

                                      <span className="hidden shrink-0 text-xs text-muted-foreground sm:block">
                                          {employee.position ?? 'No position on file'}
                                      </span>

                                      {/* Bringing somebody back in-house is the
                                        reverse of deploying them, so it lives
                                        on the row rather than behind an edit
                                        screen. The word is "recall" and not
                                        "remove": the employee is not being
                                        taken off anything, they are coming
                                        back to internal staff. */}
                                      <Button
                                          size="sm"
                                          variant="ghost"
                                          className="shrink-0"
                                          onClick={() => onRecall(employee, client)}
                                      >
                                          Recall
                                      </Button>
                                  </div>
                              ))}
                    </div>
                </>
            )}
        </Card>
    );
}

export default function Clients({ clients, deployable = [], filters, summary, wageRegions }) {
    const [creating, setCreating] = useState(false);

    // The client being deployed to, and what is typed in its picker.
    const [deploying, setDeploying] = useState(null);
    const [pickSearch, setPickSearch] = useState('');

    /*
     * How far down the org chart the picker has been walked: department, then
     * position, then the people. Null at both levels is the top.
     *
     * The same shape the Org Directory groups by, and for the same reason —
     * "I need a driver out of Operations" is walking down the org chart, not
     * scanning a flat list of forty names for the word "driver".
     */
    const [pickDept, setPickDept] = useState(null);
    const [pickPosition, setPickPosition] = useState(null);

    const deployForm = useForm({ client_id: null });

    /*
     * Which clients are open. Closed to begin with, and more than one may be
     * open at once — this is a screen being read, and having one client close
     * itself because another was opened takes away what somebody was halfway
     * through. The same choice the departments and positions screens make.
     */
    const [expanded, setExpanded] = useState(() => new Set());

    const toggle = (id) =>
        setExpanded((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);

            return next;
        });

    // A search opens what it matched, or the answer arrives as a screen of
    // shut cards and reads as nothing found.
    const isOpen = (id) => Boolean(filters.search) || expanded.has(id);

    const form = useForm(BLANK);

    const open = () => {
        form.clearErrors();
        form.setData(BLANK);
        setCreating(true);
    };

    const submit = (event) => {
        event.preventDefault();

        form.post('/hr/clients', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setCreating(false);
            },
        });
    };

    const openDeploy = (client) => {
        setPickSearch('');
        setPickDept(null);
        setPickPosition(null);
        setDeploying(client);
    };

    /*
     * One PATCH per person, addressed as the employee — the same endpoint the
     * Positions screen moves somebody with, because in both cases it is the
     * *employee's* record that changes and the policy that guards it is
     * theirs.
     *
     * `transform()` returns undefined, so it is set and then submitted rather
     * than chained.
     */
    const deploy = (employee) => {
        deployForm.transform(() => ({ client_id: deploying.id }));

        deployForm.patch(`/hr/employees/${employee.id}/deployment`, {
            preserveScroll: true,
            onSuccess: () => setDeploying(null),
        });
    };

    /** The reverse: a null client is what makes somebody internal again. */
    const recall = (employee) => {
        deployForm.transform(() => ({ client_id: null }));

        deployForm.patch(`/hr/employees/${employee.id}/deployment`, {
            preserveScroll: true,
        });
    };

    /*
     * Everybody except the people already on this client's site — offering a
     * move to where somebody already is would be an action that does nothing.
     * The rest of the roster stays, deployed or not: moving a driver between
     * clients is the commoner act, and a list of only the undeployed answers
     * the rarer half of the question.
     */
    const candidates = deployable
        .filter((employee) => employee.client_id !== deploying?.id)
        .filter((employee) => {
            const needle = pickSearch.trim().toLowerCase();

            if (!needle) return true;

            /*
             * The position is searchable too, and that is the point of showing
             * it: a client asking for five drivers wants to type "driver", not
             * to read forty names looking for the word.
             */
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

    /*
     * A search escapes the hierarchy rather than filtering inside it.
     *
     * Somebody typing a name knows who they want; making them find the right
     * department first would be the drill-down charging rent. So a search
     * flattens to people wherever it is typed, and clearing it puts the reader
     * back where they were.
     */
    const searching = pickSearch.trim() !== '';

    /** The people at the level currently open. */
    const atLevel = searching
        ? candidates
        : candidates.filter(
              (employee) =>
                  (employee.department ?? NO_DEPARTMENT) === pickDept &&
                  (employee.position ?? NO_POSITION) === pickPosition,
          );

    /*
     * Split by whether they are on somebody's site already, because that is
     * the question being asked of this list and the two answers are different
     * acts.
     *
     * Sending an unplaced driver costs nothing. Sending a placed one *takes
     * them off another client's site* — the same click, a consequence the
     * other does not have.
     */
    const available = atLevel.filter((employee) => employee.client_id === null);
    const placed = atLevel.filter((employee) => employee.client_id !== null);

    // What the current step is offering: departments, then positions, then
    // the people. Only one of the three is ever non-empty.
    const departments =
        searching || pickDept ? [] : groupBy(candidates, 'department', NO_DEPARTMENT);

    const positions =
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

    const deployedRate = summary.total > 0 ? (summary.deployed / summary.total) * 100 : 0;
    const set = (field) => (event) => form.setData(field, event.target.value);

    return (
        <AppLayout
            title="Clients"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Clients' },
            ]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Clients"
                    value={summary.total}
                    icon={Briefcase}
                    tone={summary.total > 0 ? 'primary' : 'muted'}
                    hint={`${summary.active} still taking deployments`}
                />

                {/* A client with nobody on site is not a fault — it is one
                    that has not been staffed yet, or has been wound down. The
                    share says which kind of list this is. */}
                <MeterCard
                    label="With Deployments"
                    value={summary.deployed}
                    percent={deployedRate}
                    badge={summary.total > 0 ? `${Math.round(deployedRate)}%` : undefined}
                    icon={Users}
                    tone="success"
                    iconTone="success"
                    hint={`of ${summary.total} on the books`}
                />
                <StatCard
                    label="Deployed Staff"
                    value={clients.reduce(
                        (sum, client) => sum + client.active_employees_count,
                        0,
                    )}
                    icon={Users}
                    tone="info"
                    hint="active, across all clients"
                />
                {/* A contract past its end date with people still on it is the
                    finding worth surfacing — the deployment is running past
                    what was signed for. */}
                <StatCard
                    label="Lapsed Contracts"
                    value={summary.lapsed}
                    icon={FileWarning}
                    tone={summary.lapsed > 0 ? 'warning' : 'muted'}
                    hint="past end date, still staffed"
                />
            </div>

            <Card className="mb-5">
                <CardHeader
                    title="Clients"
                    action={
                        <Button onClick={open}>
                            <Plus className="h-4 w-4" />
                            New Client
                        </Button>
                    }
                />
            </Card>

            {clients.length === 0 ? (
                <Card>
                    <CardBody className="py-14 text-center">
                        <p className="text-sm font-medium text-foreground">
                            {filters.search
                                ? 'No client matches that search'
                                : 'No clients yet'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Clients are the companies deployed employees are filed against.
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
                        onDeploy={openDeploy}
                        onRecall={recall}
                    />
                ))
            )}

            <Modal
                show={deploying !== null}
                onClose={() => setDeploying(null)}
                title={deploying ? `Deploy to ${deploying.name}` : ''}
                /*
                   The whole roster's figures, not the open level's — this
                   line answers "is there anybody free at all", which is the
                   question before the drill-down starts. The per-level counts
                   are on the rows, where they narrow with the walk. */
                description={`${candidates.filter((employee) => employee.client_id === null).length} not deployed, ${candidates.filter((employee) => employee.client_id !== null).length} on another client's site. Walk down to a position, or search a name.`}
                maxWidth="lg"
            >
                <div className="space-y-3">
                    <SearchInput
                        value={pickSearch}
                        onChange={(event) => setPickSearch(event.target.value)}
                        placeholder="Search a name, position, or department"
                        aria-label="Search employees to deploy"
                    />

                    {/*
                     * The trail, and the way back up.
                     *
                     * Each crumb is the level it returns to, so going from a
                     * position back to "all departments" is one click rather
                     * than two. Hidden while searching, because a search is
                     * not a place in the hierarchy and a trail pointing at one
                     * would be a lie about where the reader is.
                     */}
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

                    {/*
                     * Rows rather than a dropdown: where somebody is *now* is
                     * the thing being decided against, and a select shows one
                     * truncated line at a time. The same choice the Positions
                     * screen makes for the same reason.
                     *
                     * Grouped, because the two groups are two different acts —
                     * see the split above. Ungrouped, both read as the same
                     * small grey line and the reader had to check each one.
                     */}
                    <div className="scrollbar-thin max-h-80 overflow-y-auto rounded-lg border border-border">
                        {candidates.length === 0 ? (
                            <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                                Nobody left to deploy here.
                            </p>
                        ) : (
                            <>
                                {departments.map((group) => (
                                    <DrillRow
                                        key={group.name}
                                        group={group}
                                        onOpen={setPickDept}
                                    />
                                ))}

                                {positions.map((group) => (
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
                                            onPick={deploy}
                                        />
                                        <PickGroup
                                            label="Deployed elsewhere"
                                            hint="sending them takes them off that site"
                                            employees={placed}
                                            busy={deployForm.processing}
                                            onPick={deploy}
                                        />

                                        {atLevel.length === 0 && (
                                            <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                                                {searching
                                                    ? 'Nobody matches that.'
                                                    : 'Everybody in this position is already here.'}
                                            </p>
                                        )}
                                    </>
                                )}
                            </>
                        )}
                    </div>

                    <p className="text-xs text-muted-foreground">
                        Deployment is a single client with no history, so a move regroups past
                        payslips under the new one.
                    </p>
                </div>
            </Modal>

            <Modal
                show={creating}
                onClose={() => setCreating(false)}
                title="New Client"
                description="Deployed employees are filed against a client; payroll and billing group by it."
                maxWidth="2xl"
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Code" required error={form.errors.code}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.code}
                                    onChange={set('code')}
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
                                    onChange={set('name')}
                                    error={form.errors.name}
                                />
                            )}
                        </Field>

                        <Field label="Industry" error={form.errors.industry}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.industry}
                                    onChange={set('industry')}
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
                                    onChange={set('wage_region')}
                                    placeholder="Select region"
                                    options={wageRegions.map((region) => ({
                                        value: region.value,
                                        label: region.label,
                                    }))}
                                />
                            )}
                        </Field>

                        <Field label="Contact Person" error={form.errors.contact_person}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.contact_person}
                                    onChange={set('contact_person')}
                                />
                            )}
                        </Field>

                        <Field label="Contact Number" error={form.errors.contact_number}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.contact_number}
                                    onChange={set('contact_number')}
                                />
                            )}
                        </Field>

                        <Field label="Contact Email" error={form.errors.contact_email}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="email"
                                    value={form.data.contact_email}
                                    onChange={set('contact_email')}
                                    error={form.errors.contact_email}
                                />
                            )}
                        </Field>

                        <Field label="Address" error={form.errors.address}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.address}
                                    onChange={set('address')}
                                />
                            )}
                        </Field>

                        <Field label="Contract Start" error={form.errors.contract_start}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={form.data.contract_start}
                                    onChange={set('contract_start')}
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
                                    onChange={set('contract_end')}
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
