import { Link } from '@inertiajs/react';
import {
    Bell,
    ChevronLeft,
    Database,
    Palette,
    Settings as SettingsIcon,
    ScrollText,
    Shield,
    Plug,
    Users,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';

/**
 * Settings sections. `admin` marks the ones that reconfigure the company rather
 * than the signed-in person.
 *
 * The single description of what Settings *is*. It was mirrored by the
 * sidebar's `settings` children while Settings lived there as seven entries,
 * and the two had to be kept agreeing on both the labels and who may open each
 * one; the sidebar carries one entry now — `System Settings` under
 * Administration — so this is again the only copy, which is one fewer thing
 * that can drift. Still exported, because the server enforces the same `admin`
 * split with a 403 and the two must not disagree about which five those are.
 */
export const SETTINGS_SECTIONS = [
    {
        label: 'General',
        href: '/settings/general',
        icon: SettingsIcon,
        admin: true,
        blurb: 'Company details and the regional formats used on payslips.',
    },
    {
        label: 'Appearance',
        href: '/settings/appearance',
        icon: Palette,
        blurb: 'How the interface looks on this device.',
    },
    {
        label: 'Notifications',
        href: '/settings/notifications',
        icon: Bell,
        admin: true,
        blurb: 'Which events raise a notification, and how far ahead.',
    },
    {
        label: 'Users & Access',
        href: '/settings/users',
        icon: Users,
        admin: true,
        blurb: 'Login accounts and what each one may do.',
    },
    {
        label: 'Security',
        href: '/settings/security',
        icon: Shield,
        // No second factor to mention: both were removed on request, and a
        // blurb promising one sends somebody looking for a screen that is not
        // there.
        blurb: 'Your password, API tokens, and the privacy notice you accepted.',
    },
    {
        label: 'Audit Logs',
        href: '/settings/audit-logs',
        icon: ScrollText,
        // The one section that is neither everybody's nor the administrator's
        // alone, which is what `roles` exists for: `viewAuditLog` is
        // `isHrAdmin()`, so HR staff read the log too.
        roles: ['admin', 'hr_staff'],
        blurb: 'Who signed in, who changed a record, and who read one.',
    },
    {
        label: 'Data & Backup',
        href: '/settings/data',
        icon: Database,
        admin: true,
        blurb: 'What is stored, how to export it, and how long the audit trail is kept.',
    },
    {
        label: 'Integrations',
        href: '/settings/integrations',
        icon: Plug,
        admin: true,
        blurb: 'API tokens and the outside systems this HRIS talks to.',
    },
];

/**
 * The sections this user may actually open.
 *
 * The same role split the server enforces with a 403 — Appearance and Security
 * belong to every signed-in user, the other five reconfigure the company.
 * Drawing an entry that 403s would tell the reader there is something behind it
 * *and* that they are not trusted with it, which is the least useful pair of
 * facts a screen can offer.
 *
 * Exported because the index page and this layout both need it and must not
 * disagree: a section on the menu that the layout then refuses to list is a
 * door that vanishes once you walk through it.
 */
export function visibleSections(role) {
    return SETTINGS_SECTIONS.filter((section) => {
        // `roles` where `admin: true` cannot say it — Audit Logs is HR's as
        // well, because `viewAuditLog` is `isHrAdmin()`.
        if (section.roles) {
            return section.roles.includes(role);
        }

        return !section.admin || role === 'admin';
    });
}

/**
 * Shell for every settings page.
 *
 * **It renders no section list, and that is the point.** The sections have
 * been a 224px column beside the content, then a tab row above it, then both
 * at different widths — each attempt trying to make the sub-navigation live on
 * the same screen as the thing it navigates to. `/settings` is a menu now, so
 * that whole argument is settled by not having it: the list is the page you
 * came from, and repeating it inside every section is the same seven rows
 * drawn twice on two consecutive screens.
 *
 * The way back is the link below and the `System Settings` entry in the
 * sidebar, which stays lit from any `/settings/*` page via its `activePrefix`.
 * A back link rather than a restored list — one line is not a duplicate, and
 * without it a section is a dead end that costs a trip through the sidebar.
 */
export default function SettingsLayout({ title, description, children, actions }) {
    return (
        <AppLayout
            title="Settings"
            breadcrumbs={[{ label: 'Settings' }, { label: title }]}
            actions={actions}
        >
            <Link
                href="/settings"
                className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                <ChevronLeft className="h-4 w-4" aria-hidden="true" />
                Settings
            </Link>

            <div className="mb-5">
                <h2 className="text-lg font-semibold text-foreground">{title}</h2>
                {description && (
                    <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>
                )}
            </div>

            <div className="space-y-5">{children}</div>
        </AppLayout>
    );
}
