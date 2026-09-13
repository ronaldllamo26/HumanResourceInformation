import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { visibleSections } from '@/Layouts/SettingsLayout';

/**
 * The menu Settings opens onto.
 *
 * `/settings` used to redirect straight into a section — General for an admin,
 * Appearance for everybody else — so the door and the room were the same click
 * and there was never a moment where the parts of Settings were laid out
 * together. That was right while Settings was reached only from a gear, where
 * the person clicking had already decided they wanted *something* in here. It
 * is wrong now that `System Settings` is a sidebar entry: a nav entry that
 * silently lands you on the company's regional formats has answered a question
 * nobody asked, and the seven sections are the actual subject.
 *
 * So the list is the destination, and a section is what you choose next. This
 * is the only place it is drawn: `SettingsLayout` renders no list of its own,
 * because a menu repeated inside every room it opens is the same seven rows on
 * two consecutive screens.
 *
 * The role filter is `visibleSections()`, which lives beside the sections
 * themselves rather than being restated here. The server still enforces the
 * same split with a 403; this decides what is drawn, never what is allowed.
 */
export default function SettingsIndex() {
    const role = usePage().props.auth?.user?.role;
    const sections = visibleSections(role);

    return (
        <AppLayout title="Settings" breadcrumbs={[{ label: 'Settings' }]}>
            {/*
             * Centred, and held to a readable measure rather than run to the
             * page's full width. These are seven menu rows: stretched across
             * 1600px a row puts its chevron a hand's width from its label, and
             * left-aligned on a wide screen it sits in a corner of an
             * otherwise empty page. A menu is the whole content here, so it
             * belongs in the middle of it.
             */}
            <div className="mx-auto w-full max-w-2xl">
                <div className="mb-5">
                    <h2 className="text-lg font-semibold text-foreground">Settings</h2>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Configure the system and your own account.
                    </p>
                </div>

                <div className="overflow-hidden rounded-xl border border-border bg-card">
                    {sections.map(({ label, href, icon: Icon, blurb }, index) => (
                        <Link
                            key={href}
                            href={href}
                            className={[
                                'flex items-center gap-3.5 px-4 py-3.5 transition-colors hover:bg-secondary/60',
                                // A rule between rows, never above the first or
                                // below the last — the card's own border is
                                // already doing that job there.
                                index > 0 ? 'border-t border-border' : '',
                            ].join(' ')}
                        >
                            <span
                                className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-secondary text-primary"
                                aria-hidden="true"
                            >
                                <Icon className="h-4.5 w-4.5" />
                            </span>

                            <span className="min-w-0 flex-1">
                                <span className="block text-sm font-medium text-foreground">
                                    {label}
                                </span>
                                {blurb && (
                                    <span className="mt-0.5 block text-xs text-muted-foreground">
                                        {blurb}
                                    </span>
                                )}
                            </span>

                            <ChevronRight
                                className="h-4 w-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </Link>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
