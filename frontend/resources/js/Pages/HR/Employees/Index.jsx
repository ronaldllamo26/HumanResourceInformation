import { Link, router, WhenVisible } from '@inertiajs/react';
import { useState } from 'react';
import {
    CalendarClock,
    Inbox,
    Loader2,
    ScanLine,
    UserCheck,
    UserPlus,
    Users,
    UserX,
    Upload,
    X,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Card,
    Button,
    MeterCard,
    StatCard,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
} from '@/Components/ui';
import { formatDate, initials, withFilters } from '@/lib/utils';

const titleCase = (value) =>
    String(value)
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

const CATEGORY_LABELS = {
    internal: 'Internal staff',
    external: 'External (deployed)',
};

const RECORD_STATUS_LABELS = {
    active: 'Active',
    on_leave: 'On leave',
    inactive: 'Inactive',
};

/**
 * Every narrowing currently applied, as something a person can read and undo.
 *
 * The five dropdowns are gone from this screen, but the filters they set have
 * not: the dashboard tiles link here, this screen's own tiles drill into
 * themselves, and the topbar quick search lands here with `?search=`. So the
 * narrowing arrives with no control on the page unless something draws it —
 * and a list narrowed by an invisible filter is a list nobody can explain.
 *
 * Chips are the better half of that trade anyway. Five selects reading "All
 * staff / All clients / All departments…" spend a row saying *nothing is
 * filtered*; a chip only exists when there is something to say, and it says
 * the value rather than the axis.
 *
 * Ids are resolved to names here, because "Department 3" is not a thing
 * anybody recognises.
 */
function activeFilters(filters, { departments = [], clients = [] }) {
    const chips = [];
    const named = (list, id, key = 'name') =>
        list.find((row) => String(row.id) === String(id))?.[key];

    if (filters.search) chips.push({ key: 'search', label: `Matching “${filters.search}”` });

    if (filters.employment_category) {
        chips.push({
            key: 'employment_category',
            label: CATEGORY_LABELS[filters.employment_category] ?? filters.employment_category,
        });
    }

    if (filters.client_id) {
        chips.push({
            key: 'client_id',
            label: named(clients, filters.client_id) ?? 'One client',
        });
    }

    if (filters.department_id) {
        chips.push({
            key: 'department_id',
            label: named(departments, filters.department_id) ?? 'One department',
        });
    }

    if (filters.employment_status) {
        // The dashboard donut groups two statuses into one slice, so this can
        // be a comma list that no single dropdown option could ever have said.
        chips.push({
            key: 'employment_status',
            label: String(filters.employment_status).split(',').map(titleCase).join(' or '),
        });
    }

    if (filters.status) {
        chips.push({
            key: 'status',
            label: RECORD_STATUS_LABELS[filters.status] ?? titleCase(filters.status),
        });
    }

    // Set by dashboard tiles, and never by anything on this screen.
    if (filters.hired_within) {
        chips.push({
            key: 'hired_within',
            label: `Hired in the last ${filters.hired_within} days`,
        });
    }

    if (filters.without_documents) {
        chips.push({ key: 'without_documents', label: 'No documents on file' });
    }

    return chips;
}

export default function Index({
    employees,
    statistics,
    departments,
    clients,
    filters,
    sort,
    can,
    pendingEndorsements = 0,
}) {
    const applyFilter = (key, value) => {
        router.get(
            '/hr/employees',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const applySort = (key) => {
        const direction = sort.key === key && sort.direction === 'asc' ? 'desc' : 'asc';

        router.get(
            '/hr/employees',
            { ...filters, sort: key, direction },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const rows = employees.data ?? [];
    const meta = employees.meta ?? {};
    const hasMore = (meta.current_page ?? 1) < (meta.last_page ?? 1);
    const chips = activeFilters(filters, { departments, clients });

    /*
     * Clicking a figure opens the rows it counted, keeping whatever the
     * screen is already narrowed to. `status` and `employment_status` are
     * cleared together: they are two dropdowns over the same list, and a tile
     * that set one while leaving the other behind would return the people who
     * are both — usually nobody.
     *
     * The two dashboard-set keys are cleared with them, for the same reason:
     * arriving on "no documents on file" and then clicking "Total Employees"
     * has to give the whole list back, not the whole list still narrowed to
     * the people with an empty 201 file.
     */
    const drillTo = (changes) =>
        withFilters('/hr/employees', filters, changes, [
            'status',
            'employment_status',
            'hired_within',
            'without_documents',
        ]);

    // 38 of 41 is the reading; 38 on its own is a number whose scale the
    // reader has to go and find.
    const activeRate = statistics.total > 0 ? (statistics.active / statistics.total) * 100 : 0;

    return (
        <AppLayout
            title="Employee Information"
            breadcrumbs={[{ label: 'Human Resource' }, { label: 'Employee Information' }]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {/* The whole list. Clicking it clears the narrowing filters
                    rather than adding one — it is the way back out. */}
                <StatCard
                    label="Total Employees"
                    value={statistics.total}
                    icon={Users}
                    tone="primary"
                    href={drillTo({})}
                />

                {/* A share, because "38 active" says nothing until you know
                    whether the roster is 41 or 400. */}
                <MeterCard
                    label="Active"
                    value={statistics.active}
                    percent={activeRate}
                    badge={`${Math.round(activeRate)}%`}
                    icon={UserCheck}
                    tone={activeRate >= 90 ? 'success' : 'warning'}
                    iconTone="success"
                    href={drillTo({ status: 'active' })}
                />

                {/* Info, not warning — an approved absence is not a fault.
                    Grey at zero, like every other tile in the system. */}
                <StatCard
                    label="On Leave"
                    value={statistics.on_leave}
                    icon={CalendarClock}
                    tone={statistics.on_leave > 0 ? 'info' : 'muted'}
                    href={drillTo({ status: 'on_leave' })}
                />

                {/* Not a problem, a clock: every one of these is a
                    regularisation date somebody has to act on. */}
                <StatCard
                    label="Probationary"
                    value={statistics.probationary}
                    icon={UserX}
                    tone={statistics.probationary > 0 ? 'warning' : 'muted'}
                    href={drillTo({ employment_status: 'probationary' })}
                />
            </div>

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-center">
                    <div className="flex flex-1 flex-wrap items-center gap-2">
                        {/* Whatever the list is narrowed to, named and
                            removable. Nothing at all when nothing is applied,
                            which is the row's usual state — five dropdowns all
                            reading "All …" spent a whole row announcing that
                            no filter was on. */}
                        {chips.length > 0 && (
                            <>
                                <span className="text-xs text-muted-foreground">Showing</span>

                                {chips.map((chip) => (
                                    <button
                                        key={chip.key}
                                        type="button"
                                        onClick={() => applyFilter(chip.key, '')}
                                        title={`Remove this filter`}
                                        className="flex h-8 shrink-0 items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-3 text-xs font-medium text-primary transition-colors hover:bg-primary/20"
                                    >
                                        {chip.label}
                                        <X className="h-3.5 w-3.5" aria-hidden="true" />
                                    </button>
                                ))}

                                {/* One press back to the whole list. With
                                    several chips on, clearing them one at a
                                    time is four presses to undo one click from
                                    the dashboard. */}
                                {chips.length > 1 && (
                                    <Link
                                        href="/hr/employees"
                                        className="text-xs font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                                    >
                                        Clear all
                                    </Link>
                                )}
                            </>
                        )}

                        {/* The actions end the row. `ml-auto` rather than the
                            container justifying to the end: the chips have to
                            start at the left edge, or they read as part of the
                            button group rather than as a description of the
                            list. */}
                        <div className="ml-auto flex flex-wrap items-center gap-2">
                            {/* Two ways in, beside each other: a spreadsheet of a
                            workforce that already exists, and a stack of scans
                            for the files they arrive with. Splitting them
                            across the screen would make the bulk paths look
                            like separate features rather than the same act at
                            scale.

                            New Hires is the usual way in: Core 1 recruits and
                            the hire is approved on the endorsements screen.
                            Add Employee is the direct way, for hires that do
                            not come through recruitment; its form requires a
                            reason, which is recorded in the audit log in place
                            of the endorsement. Import stays because digitising
                            a workforce that already works here is not hiring. */}
                            {can.create && (
                                <Button variant="outline" href="/hr/employees/import">
                                    <Upload className="h-4 w-4" />
                                    Import
                                </Button>
                            )}

                            {can.fileDocuments && (
                                <Button variant="outline" href="/hr/employees/documents/batch">
                                    <ScanLine className="h-4 w-4" />
                                    File Scans
                                </Button>
                            )}

                            {can.create && (
                                <Button variant="outline" href="/hr/employees/create">
                                    <UserPlus className="h-4 w-4" />
                                    Add Employee
                                </Button>
                            )}

                            {can.create && (
                                <Button href="/hr/endorsements">
                                    <Inbox className="h-4 w-4" />
                                    New Hires
                                    {pendingEndorsements > 0 && (
                                        <span className="ml-0.5 grid min-w-5 place-items-center rounded-full bg-primary-foreground/20 px-1.5 text-[11px] font-semibold leading-5">
                                            {pendingEndorsements}
                                        </span>
                                    )}
                                </Button>
                            )}
                        </div>
                    </div>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH sortKey="employee_number" sort={sort} onSort={applySort}>
                                Employee
                            </TH>
                            <TH>Department</TH>
                            <TH>Position</TH>
                            <TH sortKey="employment_status" sort={sort} onSort={applySort}>
                                Employment
                            </TH>
                            <TH sortKey="date_hired" sort={sort} onSort={applySort}>
                                Date Hired
                            </TH>
                            <TH sortKey="status" sort={sort} onSort={applySort}>
                                Status
                            </TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={6}
                                title="No employees found"
                                description="Try adjusting your search or filters, or add a new employee record."
                            />
                        ) : (
                            <>
                                {rows.map((employee) => (
                                    <TR key={employee.id}>
                                        <TD>
                                            <Link
                                                href={`/hr/employees/${employee.id}`}
                                                className="group flex items-center gap-3"
                                            >
                                                {employee.photo_url ? (
                                                    <img
                                                        src={employee.photo_url}
                                                        alt=""
                                                        className="h-9 w-9 shrink-0 rounded-full object-cover"
                                                    />
                                                ) : (
                                                    <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                                                        {initials(employee.full_name)}
                                                    </span>
                                                )}

                                                <span className="min-w-0">
                                                    <span className="block truncate text-sm font-medium text-foreground group-hover:text-primary">
                                                        {employee.full_name}
                                                    </span>
                                                    <span className="block truncate text-xs text-muted-foreground">
                                                        {employee.employee_number}
                                                    </span>
                                                </span>
                                            </Link>
                                        </TD>

                                        {/* One column, one meaning.
                                            It used to hold two: the department
                                            for internal staff and the client
                                            for deployed staff, whichever
                                            existed. That made a column nobody
                                            could read down — "Fleet
                                            Maintenance" and "Pacific Coast
                                            Beverages" are not the same kind of
                                            fact, and sorting or scanning it
                                            answered neither question.

                                            The department is the one every
                                            employee has, so it is the one this
                                            column holds. Who a deployed person
                                            is billed to is on their own record
                                            and on the client screen, which is
                                            where that question is asked. */}
                                        <TD className="truncate text-sm text-muted-foreground">
                                            {employee.department?.name ?? '—'}
                                        </TD>

                                        <TD className="text-sm text-muted-foreground">
                                            {employee.position?.title ?? '—'}
                                        </TD>

                                        <TD>
                                            <Badge status={employee.employment_status} />
                                        </TD>

                                        <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                            {formatDate(employee.date_hired)}
                                        </TD>

                                        <TD>
                                            <Badge status={employee.status} />
                                        </TD>
                                    </TR>
                                ))}

                                {/* Scrolling this row into view fetches the next
                                    page and appends it above — infinite scroll
                                    instead of numbered pages. It disappears once
                                    the last page has loaded. */}
                                {hasMore && (
                                    <WhenVisible
                                        as="tr"
                                        always
                                        data="employees"
                                        params={{
                                            data: { page: (meta.current_page ?? 1) + 1 },
                                            // Otherwise each scroll-triggered
                                            // fetch pushes ?page=2, ?page=3…
                                            // onto the URL and browser history —
                                            // one Back press per page loaded,
                                            // and a refresh mid-scroll would
                                            // render only that lone page instead
                                            // of everything loaded so far.
                                            preserveUrl: true,
                                        }}
                                    >
                                        {({ fetching }) => (
                                            <TD colSpan={6} className="py-4 text-center">
                                                {fetching && (
                                                    <span className="inline-flex items-center gap-2 text-xs text-muted-foreground">
                                                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                                        Loading more…
                                                    </span>
                                                )}
                                            </TD>
                                        )}
                                    </WhenVisible>
                                )}
                            </>
                        )}
                    </TBody>
                </Table>

                {!hasMore && rows.length > 0 && (
                    <p className="border-t border-border px-4 py-3 text-center text-xs text-muted-foreground">
                        {rows.length} of {meta.total} employee(s)
                    </p>
                )}
            </Card>
        </AppLayout>
    );
}
