import {
    BadgeCheck,
    Banknote,
    Bell,
    Briefcase,
    Building2,
    CalendarDays,
    CalendarCheck,
    CalendarRange,
    ClipboardList,
    Clock,
    FilePenLine,
    Database,
    DoorOpen,
    FileSpreadsheet,
    FileText,
    FileWarning,
    HandCoins,
    Handshake,
    IdCard,
    Inbox,
    LayoutDashboard,
    ListChecks,
    Lock,
    Network,
    Palette,
    Plug,
    Receipt,
    ScanLine,
    ScrollText,
    Settings,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Target,
    Timer,
    TrendingUp,
    User,
    Users,
    Wallet,
    Wallet2,
} from 'lucide-react';

/**
 * Sidebar navigation for Core Transaction 2 — HRIS.
 *
 * Each group carries an optional uppercase label; each item may carry
 * `children` (rendered as a dropdown), a `badge`, and `roles` (omit = visible to
 * every authenticated role).
 */
export const NAV_GROUPS = [
    {
        label: null,
        items: [
            {
                id: 'dashboard',
                label: 'Dashboard',
                icon: LayoutDashboard,
                href: '/dashboard',
            },
        ],
    },
    {
        label: 'Employee Management',
        items: [
            {
                id: 'employee-info',
                label: 'Employee Information',
                icon: IdCard,
                children: [
                    {
                        /*
                         * The signed-in user's own 201 file and personal records.
                         */
                        id: 'employee-my-profile',
                        label: 'My Profile',
                        icon: User,
                        href: '/hr/my-profile',
                    },
                    {
                        /*
                         * The way into the workforce, so it sits above the
                         * directory rather than under it.
                         *
                         * PrimePower does not hire into this system directly:
                         * Core 1 recruits, sends the hire over the API, and
                         * somebody here approves or declines it. That makes
                         * this a work queue rather than a record screen, which
                         * is why it is the only nav entry carrying a count.
                         */
                        id: 'employee-endorsements',
                        label: 'New Hires',
                        icon: Inbox,
                        href: '/hr/endorsements',
                        roles: ['admin', 'hr_staff'],
                        // Filled from the shared prop of this name. Hidden at
                        // zero — an always-lit badge stops being read, the
                        // same rule the credential indicator follows.
                        badgeKey: 'pendingEndorsements',
                    },
                    {
                        id: 'employee-list',
                        label: 'Employee Directory',
                        icon: Users,
                        href: '/hr/employees',
                    },
                    {
                        id: 'employee-departments-positions',
                        label: 'Organization Chart',
                        icon: Network,
                        href: '/hr/departments',
                        roles: ['admin', 'hr_staff'],
                        activePrefixes: ['/hr/departments', '/hr/positions', '/hr/directory'],
                    },
                    /*
                     * Clients, back where Positions leaves off.
                     *
                     * This had its own group for a while, `Client Management`,
                     * on the reasoning that a client is not a person — it is
                     * who the workforce is sent to — and deserved to sit beside
                     * `Employee Management` rather than inside it. That still
                     * holds; it moved back anyway. One more heading in a
                     * five-item sidebar is a cost the screen itself pays for
                     * every visitor, and this is the shorter path to the same
                     * screen for the two roles who open it at all.
                     */
                    {
                        id: 'clients',
                        label: 'Clients',
                        icon: Handshake,
                        href: '/hr/clients',
                        roles: ['admin', 'hr_staff'],
                    },

                    /*
                     * The four screens that *judge* the 201 file rather than
                     * hold it — and this is the third arrangement they have
                     * had, asked for by the owner.
                     *
                     * They were loose in this list once, then pulled out into
                     * a sibling entry called `Checks & Readiness`, because
                     * loose they read as four more record screens — which is
                     * the wrong claim about all four: none of them stores
                     * anything, and each answers a question the file itself
                     * does not. What that argument was really against was the
                     * *lack of a label*, not the nesting: these are reached
                     * from a person's record, and a reader looking for "is
                     * this driver's licence lapsing" opens Employee
                     * Information first.
                     *
                     * So they are children again, with `divider` carrying the
                     * heading the sibling entry used to be. The objection is
                     * answered rather than overruled — the four still say what
                     * they are, and they no longer cost a second top-level row
                     * to say it.
                     *
                     * Named for what they do, not how: they spent a while
                     * under "AI & Analytics" and not one of them calls a model
                     * — they are config-driven rule engines over dates,
                     * regexes and string comparisons, and `RecordIntegrityChecker`
                     * says so in its own docblock. "Compliance" was the
                     * obvious alternative and is taken: here it means SSS, BIR
                     * and PhilHealth remittance, under Payroll.
                     */
                    {
                        /*
                         * Unified monitor for deployability, 201 file completeness,
                         * and credential/licence validity across the workforce.
                         */
                        id: 'employee-deployment',
                        label: 'Checks & Readiness',
                        icon: BadgeCheck,
                        href: '/hr/deployment',
                    },
                ],
            },
        ],
    },


    {
        label: 'Time & Attendance',
        items: [
            {
                id: 'timekeeping',
                label: 'Timekeeping',
                icon: Clock,
                children: [
                    {
                        id: 'timekeeping-records',
                        label: 'Daily Time Records',
                        icon: ClipboardList,
                        href: '/hr/timekeeping',
                    },
                    {
                        id: 'timekeeping-shifts',
                        label: 'Shifts & Rest Days',
                        icon: CalendarRange,
                        href: '/hr/timekeeping/shifts',
                        roles: ['admin', 'hr_staff'],
                    },
                    {
                        id: 'timekeeping-holidays',
                        label: 'Holiday Calendar',
                        icon: CalendarCheck,
                        href: '/hr/timekeeping/holidays',
                    },
                    {
                        id: 'timekeeping-overtime',
                        label: 'Overtime Requests',
                        icon: Timer,
                        href: '/hr/timekeeping/overtime',
                    },
                    {
                        id: 'timekeeping-corrections',
                        label: 'Time Corrections',
                        icon: FilePenLine,
                        href: '/hr/timekeeping/corrections',
                    },
                    {
                        id: 'timekeeping-cutoffs',
                        label: 'Cutoff Closing',
                        icon: Lock,
                        href: '/hr/timekeeping/cutoffs',
                        roles: ['admin', 'hr_staff'],
                    },
                    {
                        id: 'timekeeping-client-timesheets',
                        label: 'Client Timesheets',
                        icon: Handshake,
                        href: '/hr/timekeeping/client-timesheets',
                        roles: ['admin', 'hr_staff'],
                    },
                ],
            },
            {
                id: 'leave',
                label: 'Leave & Absence',
                icon: CalendarDays,
                children: [
                    {
                        id: 'leave-requests',
                        label: 'Requests',
                        icon: ClipboardList,
                        href: '/hr/leave',
                    },
                    {
                        id: 'leave-calendar',
                        label: 'Calendar',
                        icon: CalendarDays,
                        href: '/hr/leave/calendar',
                    },
                    {
                        id: 'leave-balances',
                        label: 'Balances',
                        icon: Wallet2,
                        href: '/hr/leave/balances',
                    },
                    {
                        id: 'leave-types',
                        label: 'Leave Types',
                        icon: FileText,
                        href: '/hr/leave/types',
                    },
                ],
            },
        ],
    },
    {
        label: 'Payroll & Performance',
        items: [
            {
                id: 'payroll',
                label: 'Payroll & Compensation',
                icon: Wallet,
                children: [
                    {
                        id: 'payroll-runs',
                        label: 'Payroll Runs',
                        icon: Receipt,
                        href: '/hr/payroll',
                        roles: ['admin', 'hr_staff'],
                    },
                    {
                        id: 'payroll-payslips',
                        label: 'Payslips',
                        icon: FileText,
                        href: '/hr/payroll/payslips',
                    },
                    {
                        id: 'payroll-salaries',
                        label: 'Salaries & Adjustments',
                        icon: Banknote,
                        href: '/hr/payroll/salaries',
                        roles: ['admin', 'hr_staff'],
                    },
                    {
                        id: 'payroll-compensation',
                        label: 'Allowances & Loans',
                        icon: HandCoins,
                        href: '/hr/payroll/compensation',
                        roles: ['admin', 'hr_staff'],
                    },
                    {
                        id: 'payroll-separations',
                        label: 'Separation & Final Pay',
                        icon: DoorOpen,
                        href: '/hr/payroll/separations',
                        roles: ['admin', 'hr_staff'],
                    },
                    {
                        id: 'payroll-compliance',
                        label: 'Compliance',
                        icon: ShieldCheck,
                        href: '/hr/payroll/compliance',
                        roles: ['admin', 'hr_staff'],
                    },
                ],
            },
            {
                id: 'performance',
                label: 'Performance Management',
                icon: TrendingUp,
                children: [
                    {
                        id: 'perf-reviews',
                        label: 'Evaluations',
                        icon: BadgeCheck,
                        href: '/hr/performance',
                    },
                    {
                        id: 'perf-cycles',
                        label: 'Review Cycles',
                        icon: CalendarRange,
                        href: '/hr/performance/cycles',
                    },
                    {
                        id: 'perf-kpis',
                        label: 'KPI Library',
                        icon: Target,
                        href: '/hr/performance/kpis',
                    },
                ],
            },
        ],
    },
    /*
     * The group that is about the AI, and holds only what actually is.
     *
     * It briefly held six screens on the reasoning that what unites them is
     * reading across modules rather than maintaining one. True of all six, and
     * the wrong name for it: five were config-driven rule engines — dates,
     * regexes, string comparisons — and a group labelled "AI" whose members
     * call no model is a claim the first click disproves. Worse, the one
     * feature that *is* AI was not in it. They are back under the modules
     * whose records they read; the criterion was fine, the label was not.
     *
     * What is left is the screen that measures the scanner, which belongs to
     * the AI in the only way a screen can: it is where the accuracy figures
     * come from. The scanner itself has no entry because it is not a screen —
     * it fills a form on the 201-file upload and runs the batch filer, both
     * reached from the Employees list.
     */
    {
        label: 'AI & Analytics',
        items: [
            {
                id: 'reports',
                label: 'Reports',
                icon: FileSpreadsheet,
                href: '/hr/reports',
                roles: ['admin', 'hr_staff'],
            },
            {
                id: 'analytics-workforce',
                label: 'Workforce Analytics',
                icon: TrendingUp,
                href: '/hr/analytics/workforce',
                roles: ['admin', 'hr_staff'],
            },
            {
                id: 'ai-document-batch',
                label: 'AI Batch Scanner',
                icon: ScanLine,
                href: '/hr/employees/documents/batch',
                roles: ['admin', 'hr_staff'],
            },
            {
                id: 'analytics-scan-accuracy',
                label: 'Scanner Accuracy',
                icon: Target,
                href: '/hr/scan-accuracy',
                roles: ['admin', 'hr_staff'],
            },
        ],
    },
    /*
     * Configuring the app, kept apart from doing the company's work.
     *
     * Settings has been in three places now, and the group label is what makes
     * this one different from the second. It was a 224px column beside the
     * page (deleted — it cost width the forms had none of), then a sidebar
     * entry with seven `children` (deleted — a dropdown of seven settings
     * screens sits level with Payroll's seven, which files "change my
     * password" as a peer of a payroll run), then two gears and no sidebar
     * entry at all.
     *
     * This is one entry under a label that says what it is. The objection to
     * the second answer was never the sidebar, it was the ranking — and
     * "ADMINISTRATION" is the thing that ranks it. The seven sections stay
     * where they already live: `SettingsLayout` renders them as a row on the
     * page, so the sidebar carries one line rather than seven.
     *
     * No `roles`. `/settings` redirects to the first section the person may
     * open, and Appearance and Security belong to every signed-in user — a
     * role filter here would hide the door to somebody's own password.
     *
     * `activePrefix` is what lights it from `/settings/security` and the rest:
     * the sub-navigation is not `children`, so there is no child href for
     * `bestMatch()` to find.
     */
    {
        label: 'Administration',
        items: [
            {
                /*
                 * Was reachable only as a Settings section, which filed "who
                 * may open a 201 file" behind the same door as the company's
                 * date format. It is the access-control screen *and* the
                 * access review, so it belongs under the heading that says
                 * administration. It stays a Settings section too — the same
                 * two-doors arrangement the topbar gear and this group
                 * already have for Settings itself.
                 */
                id: 'users',
                label: 'Users & Access',
                icon: Users,
                href: '/settings/users',
                roles: ['admin'],
            },
            {
                /*
                 * The audit trail, out of the bottom of Settings → Security
                 * and onto its own screen. Carries two roles where Users &
                 * Access carries one: `viewAuditLog` is `isHrAdmin()`, and
                 * reading the log is not the same act as handing out access.
                 */
                id: 'audit-logs',
                label: 'Audit Logs',
                icon: ScrollText,
                href: '/settings/audit-logs',
                roles: ['admin', 'hr_staff'],
            },
            {
                id: 'system-settings',
                label: 'System Settings',
                icon: Settings,
                href: '/settings',
                activePrefix: '/settings',
            },
        ],
    },
];

/*
 * Settings is deliberately not in this list.
 *
 * It sat here as a "System" group of seven children, alongside the five
 * modules — which put "change my password" and "back up the database" at the
 * same level as Payroll. Settings is not a sixth module: it configures the
 * app and the account rather than doing the company's work, and it is reached
 * from where an account is reached, which is the user card at the foot of this
 * sidebar and the top right of the topbar.
 *
 * Nothing about the routes or permissions moved with it, and `ALL_HREFS` is
 * derived from the groups above — so `bestMatch()` simply finds nothing on a
 * settings page, which is correct: there is no sidebar entry for it to light.
 *
 * `SettingsLayout` carries the seven sections now, as a row of tabs across the
 * top. That is not the old left-hand column coming back: the column cost the
 * forms 224px of width at exactly the size where they needed it, and a tab row
 * costs height, which these pages have.
 */

/** Strips the query string and hash, leaving a comparable path. */
function pathOf(url) {
    return url.split('?')[0].split('#')[0];
}

/**
 * Every navigable href, longest first.
 *
 * Matching by longest prefix is what lets `/hr/employees/create` win over
 * `/hr/employees`, while `/hr/employees/42` still resolves to the directory.
 */
const ALL_HREFS = NAV_GROUPS.flatMap((group) =>
    group.items.flatMap((item) => [
        ...(item.href ? [item.href] : []),
        ...(item.children ?? []).map((child) => child.href),
        ...(item.activePrefixes ?? []),
    ]),
).sort((a, b) => b.length - a.length);

/** The single nav href that best describes the current URL. */
export function bestMatch(currentUrl) {
    const path = pathOf(currentUrl);

    return ALL_HREFS.find((href) => path === href || path.startsWith(`${href}/`)) ?? null;
}

/** True when this href is the best match for the current URL. */
export function isHrefActive(href, currentUrl) {
    if (!href) return false;

    return bestMatch(currentUrl) === href;
}

/** True when any of the item's children owns the current URL. */
export function isItemActive(item, currentUrl) {
    if (item.href && isHrefActive(item.href, currentUrl)) return true;

    // For an item whose sub-navigation lives outside the sidebar entirely
    // (Settings: SettingsLayout renders its own section list once you're
    // in) rather than as `children` here — the entry still has to read as
    // current from any page under it, not just the one it happens to link
    // to.
    /*
     * ...but only while no other entry owns the URL outright. `/settings` is
     * System Settings' prefix and `/settings/users` is Users & Access's own
     * href, so without this both rows light up on that page — and two current
     * rows tell the reader neither.
     */
    if (item.activePrefix && pathOf(currentUrl).startsWith(item.activePrefix)) {
        const owner = bestMatch(currentUrl);

        return owner === null || owner === item.href;
    }

    if (item.activePrefixes && item.activePrefixes.some((prefix) => pathOf(currentUrl).startsWith(prefix))) {
        const owner = bestMatch(currentUrl);

        return owner === null || owner === item.href || item.activePrefixes.includes(owner);
    }

    return (item.children ?? []).some((child) => isHrefActive(child.href, currentUrl));
}

/**
 * A `divider` belongs to the run of children after it, not to the one child
 * that happens to carry it: when role filtering removes that child, the heading
 * moves to the next survivor rather than disappearing with it. A set of screens
 * that silently loses its label for one role is the bug this guards against.
 */
export function visibleGroups(groups, role) {
    const allowed = (entry) => !entry.roles || entry.roles.includes(role);

    return groups
        .map((group) => ({
            ...group,
            items: group.items
                .filter(allowed)
                .map((item) => {
                    if (!item.children) return item;

                    // The dropped children are read before they go, so a
                    // heading on one of them survives onto the next.
                    let pending = null;
                    const kept = [];

                    for (const child of item.children) {
                        if (!allowed(child)) {
                            pending = pending ?? child.divider ?? null;
                            continue;
                        }

                        kept.push(
                            pending && !child.divider ? { ...child, divider: pending } : child,
                        );
                        pending = null;
                    }

                    return { ...item, children: kept };
                })
                // A parent whose children are all hidden has nothing to show.
                .filter((item) => item.href || (item.children?.length ?? 0) > 0),
        }))
        .filter((group) => group.items.length > 0);
}
