import {
    BadgeCheck,
    Banknote,
    Bell,
    Briefcase,
    Building2,
    CalendarDays,
    CalendarRange,
    ClipboardList,
    Clock,
    Database,
    DoorOpen,
    FileText,
    FileWarning,
    Gauge,
    HandCoins,
    Handshake,
    History,
    IdCard,
    Inbox,
    LayoutDashboard,
    ListChecks,
    Palette,
    Plug,
    Receipt,
    ScanLine,
    Settings,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Target,
    TrendingUp,
    TriangleAlert,
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
                        /*
                         * Who works here, arranged the way the company is —
                         * a colleague's screen rather than HR's record. It
                         * carries no `roles`, and that is deliberate: the
                         * fields narrow to a name, a job, a posting, and a
                         * work contact, which is what makes it safe for
                         * everybody. See EmployeePolicy::viewDirectory.
                         */
                        id: 'employee-directory',
                        label: 'Departments',
                        icon: Building2,
                        href: '/hr/directory',
                    },
                    {
                        id: 'employee-list',
                        label: 'Employee Directory',
                        icon: Users,
                        href: '/hr/employees',
                    },
                    /*
                     * The master-data Departments screen has left this list.
                     *
                     * Two entries called "Departments" would have been two
                     * answers to one word, and the one people actually open is
                     * the screen above — which walks the org chart and holds
                     * the people. Editing a department is a rarer act than
                     * reading one, and it did not earn a permanent line here.
                     *
                     * The route is untouched: `/hr/departments` still works,
                     * `/settings/organization` still redirects to it, and
                     * Positions still files against what it maintains. Only
                     * the nav entry is gone, so restoring it is one object.
                     *
                     * `ALL_HREFS` is derived from this list, so `bestMatch()`
                     * now finds nothing on `/hr/departments` — correct, in the
                     * same way it finds nothing on a settings page: there is
                     * no entry for it to light.
                     */
                    {
                        id: 'employee-positions',
                        label: 'Positions',
                        icon: Briefcase,
                        href: '/hr/positions',
                        roles: ['admin', 'hr_staff'],
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
                ],
            },
            {
                /*
                 * The four screens that *judge* the 201 file rather than hold
                 * it, under one entry beside the module they read.
                 *
                 * Loose in Employee Information they read as four more record
                 * screens, which is the wrong claim about all four: none of
                 * them stores anything, and each answers a question the file
                 * itself does not — what is lapsing (`CredentialExpiryScanner`),
                 * what was never filed (`OnboardingChecker`), where the records
                 * disagree (`RecordIntegrityChecker`), and whether the person
                 * can be sent to a client tomorrow (`DeploymentReadinessChecker`,
                 * which composes the first two).
                 *
                 * A sibling entry rather than a nesting, because the sidebar
                 * renders exactly two levels — group and children — and this is
                 * the same shape Timekeeping and Leave already take inside Time
                 * & Attendance.
                 *
                 * Named for what they do, not for how they do it. They spent a
                 * while under "AI & Analytics" and not one of them calls a
                 * model — they are config-driven rule engines over dates,
                 * regexes and string comparisons, and Record Checks says so in
                 * its own docblock, deliberately. "Compliance" was the obvious
                 * alternative and is taken: in this system it means SSS, BIR
                 * and PhilHealth remittance, under Payroll.
                 */
                id: 'employee-checks',
                label: 'Checks & Readiness',
                icon: ShieldCheck,
                children: [
                    {
                        id: 'employee-credentials',
                        label: 'Credentials',
                        icon: ShieldAlert,
                        href: '/hr/credentials',
                    },
                    {
                        id: 'employee-onboarding',
                        label: '201 File Status',
                        icon: FileWarning,
                        href: '/hr/onboarding',
                    },
                    {
                        /*
                         * Where the records disagree with each other. Open to
                         * the same roles as the directory it reads — a
                         * supervisor sees the findings on their own reports.
                         */
                        id: 'employee-record-checks',
                        label: 'Record Checks',
                        icon: ListChecks,
                        href: '/hr/record-checks',
                    },
                    {
                        /*
                         * Last, because it is the verdict the three above feed:
                         * it re-uses the credential and onboarding scanners
                         * rather than re-deriving either, so the four screens
                         * cannot disagree about the same driver.
                         */
                        id: 'employee-deployment',
                        label: 'Deployment Readiness',
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
                label: 'Timekeeping & Attendance',
                icon: Clock,
                children: [
                    /*
                     * "Records", not "Daily Records": the screen is one row
                     * per employee for a cutoff now, and the days sit under
                     * the person rather than being the list. It absorbed the
                     * Reports entry that used to sit further down, which
                     * showed the same figures with no way into them.
                     */
                    {
                        id: 'tk-daily',
                        label: 'Records',
                        icon: ListChecks,
                        href: '/hr/timekeeping',
                    },
                    /*
                     * Beside Records, because the pair is enter-then-read:
                     * a cutoff is encoded here and counted there, off the
                     * same table.
                     */
                    {
                        id: 'tk-period',
                        label: 'Period DTR',
                        icon: ClipboardList,
                        href: '/hr/timekeeping/period',
                        roles: ['admin', 'hr_staff'],
                    },
                    {
                        id: 'tk-overtime',
                        label: 'Overtime',
                        icon: Clock,
                        href: '/hr/timekeeping/overtime',
                    },
                    /*
                     * An employee cannot edit a time record and never should
                     * be able to, so a discrepancy on their DTR is raised
                     * here and decided before it changes anything. No `roles`:
                     * the people who file these are the people the queue is
                     * for.
                     */
                    {
                        id: 'tk-adjustments',
                        label: 'DTR Corrections',
                        icon: FileWarning,
                        href: '/hr/timekeeping/adjustments',
                    },
                    {
                        id: 'tk-schedules',
                        label: 'Shifts & Schedules',
                        icon: CalendarRange,
                        href: '/hr/timekeeping/schedules',
                    },
                    {
                        id: 'tk-holidays',
                        label: 'Holidays',
                        icon: CalendarDays,
                        href: '/hr/timekeeping/holidays',
                    },
                    {
                        /*
                         * The automated DTR checker, back beside the records
                         * it reads. `AttendanceExceptionScanner` is a
                         * config-driven rule engine over `attendance_logs`
                         * plus the leave cross-check — no model, and its
                         * thresholds live in `config/timekeeping.php`.
                         *
                         * "Exceptions" rather than "Attendance Exceptions":
                         * the longer name was earned by sitting in a group
                         * that mixed modules, where the word alone said
                         * nothing about which records. Inside Timekeeping its
                         * siblings are Records, Overtime and Holidays, and the
                         * subject is not in question.
                         */
                        id: 'tk-exceptions',
                        label: 'Exceptions',
                        icon: TriangleAlert,
                        href: '/hr/timekeeping/exceptions',
                    },
                    {
                        id: 'tk-history',
                        label: 'History',
                        icon: History,
                        href: '/hr/timekeeping/history',
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
                id: 'analytics-workforce',
                label: 'Workforce Analytics',
                icon: TrendingUp,
                href: '/hr/analytics/workforce',
                roles: ['admin', 'hr_staff'],
            },
            {
                id: 'analytics-attendance',
                label: 'Attendance & Cost Insights',
                icon: Gauge,
                href: '/hr/analytics/attendance',
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
    if (item.activePrefix && pathOf(currentUrl).startsWith(item.activePrefix)) {
        return true;
    }

    return (item.children ?? []).some((child) => isHrefActive(child.href, currentUrl));
}

export function visibleGroups(groups, role) {
    const allowed = (entry) => !entry.roles || entry.roles.includes(role);

    return groups
        .map((group) => ({
            ...group,
            items: group.items
                .filter(allowed)
                .map((item) => ({
                    ...item,
                    children: item.children?.filter(allowed),
                }))
                // A parent whose children are all hidden has nothing to show.
                .filter((item) => item.href || (item.children?.length ?? 0) > 0),
        }))
        .filter((group) => group.items.length > 0);
}
