import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { ChevronDown, LogOut, Menu, Settings } from 'lucide-react';
import PrimePowerLogo, { LogoMark } from '@/Components/layout/PrimePowerLogo';
import { NAV_GROUPS, isHrefActive, isItemActive, visibleGroups } from '@/config/navigation';
import { cn, initials } from '@/lib/utils';

/*
 * Which module the reader last had open.
 *
 * Remembered because **a redirect can land you where no module owns the
 * URL**, and without this the dropdown closes itself on arrival. That is the
 * bug the owner reported twice: clicking `Employee Information` navigates to
 * its first child, `/hr/my-profile`, which **302s to `/dashboard` for any
 * account with no 201 file** — every administrator, and the only account left
 * on a freshly emptied database. So the module opened, the visit bounced, the
 * sidebar remounted on a URL nothing owns, and the menu was closed before the
 * reader's finger left the button.
 *
 * The URL still wins where it says anything (below); this only answers when
 * it says nothing. Same storage as the collapsed rail, which is the existing
 * precedent for sidebar state living per-device rather than in the database.
 */
const EXPANDED_KEY = 'primepower-sidebar-module';

function rememberedModule() {
    try {
        return window.localStorage.getItem(EXPANDED_KEY) || null;
    } catch {
        // A private window or blocked site data throws rather than returning
        // null. The accordion still works for this visit; it is simply not
        // remembered, which is the right way for this to degrade.
        return null;
    }
}

function rememberModule(id) {
    try {
        if (id) {
            window.localStorage.setItem(EXPANDED_KEY, id);
        } else {
            window.localStorage.removeItem(EXPANDED_KEY);
        }
    } catch {
        // As above — nothing here is worth failing a render over.
    }
}

/**
 * Which module entry owns this URL, by id, or null.
 *
 * Pulled out of the effect so the state initialiser and the effect ask the
 * same question the same way — two copies of "which module am I inside" would
 * be two chances to disagree, and the disagreement would show as a dropdown
 * that opens on one navigation and not the next.
 */
function ownerOfUrl(groups, url) {
    return (
        groups
            .flatMap((group) => group.items)
            .find((item) => item.children?.some((child) => isHrefActive(child.href, url)))
            ?.id ?? null
    );
}

/**
 * What a nav entry's badge should read, or nothing.
 *
 * `badge` is a literal in the config; `badgeKey` names a shared Inertia prop
 * and is resolved here. Zero returns null rather than "0" on purpose — a badge
 * that is always lit stops being read within a week, which is the same reason
 * the topbar's credential indicator hides itself at zero.
 */
function badgeFor(entry, props) {
    const value = entry.badgeKey ? props[entry.badgeKey] : entry.badge;

    return value > 0 || (typeof value === 'string' && value) ? value : null;
}

export default function Sidebar({ collapsed, onToggleCollapsed, mobileOpen, onCloseMobile }) {
    const { props, url: currentUrl } = usePage();
    const user = props.auth?.user;
    const role = user?.role ?? 'employee';

    const groups = useMemo(() => visibleGroups(NAV_GROUPS, role), [role]);

    /*
     * The gear in the user card answers for itself.
     *
     * Settings is in NAV_GROUPS again, under Administration, so the nav entry
     * does light on a settings page — but this gear is a second door to the
     * same place and is not driven by `bestMatch()`. Computed here rather than
     * read off the entry so the two cannot disagree about the same URL, and a
     * control that never shows it is current is one people click twice.
     */
    const settingsActive = currentUrl.split('?')[0].startsWith('/settings');

    /*
     * Accordion: at most one module open at a time.
     *
     * **Seeded from the URL on the first render, not after it**, and that is
     * the fix for the dropdown appearing to vanish every time a module was
     * clicked. `AppLayout` is not a persistent Inertia layout, so this whole
     * component remounts on every navigation — and clicking a module parent
     * *navigates* (it lands on the module's first page). The sequence was:
     * remount, `useState(null)`, so the dropdown painted **closed**, then the
     * effect below ran after paint and opened it again over a 300ms
     * transition. Every click collapsed the module you had just opened and
     * slid it back out.
     *
     * Computing the owner in the initialiser means the first paint is already
     * correct and there is nothing to animate back from.
     */
    const [expandedModule, setExpandedModule] = useState(
        // The URL first, because it is the truth about where the reader is;
        // the remembered module only answers when the URL says nothing, which
        // is exactly the case a redirect leaves behind.
        () => ownerOfUrl(groups, currentUrl) ?? rememberedModule(),
    );

    /*
     * Still needed, and not a duplicate of the initialiser: the URL changes
     * without a remount on a partial reload, and a module reached from
     * anywhere other than its own parent button (a dashboard link, the
     * topbar, a redirect) has to open the right entry.
     */
    useEffect(() => {
        const owner = ownerOfUrl(groups, currentUrl);

        if (owner) {
            setExpandedModule(owner);
            rememberModule(owner);
        }
    }, [currentUrl, groups]);

    /**
     * A press on a module's own row.
     *
     * **It closes only the module you are already inside**, and that
     * condition is the whole rule. Toggling on "is it open" instead reads as
     * the menu fighting the reader: the accordion can be open because the
     * *remembered* module was restored — on the dashboard, say — and then the
     * very first press on that module closes it rather than going into it,
     * which is the complaint this is the second attempt at. Being inside a
     * module is the only state where collapsing it is something somebody
     * could mean, and it is still available there.
     */
    const handleParentClick = (item) => {
        const inside = ownerOfUrl(groups, currentUrl) === item.id;

        if (inside && expandedModule === item.id) {
            setExpandedModule(null);
            rememberModule(null);

            return;
        }

        setExpandedModule(item.id);
        // Written here rather than in an effect on `expandedModule`, so only a
        // deliberate press is remembered — an effect would also record the
        // accordion following the URL, and then a visit to one module would
        // decide what the *next* page opens with.
        rememberModule(item.id);

        // Opening a module lands the reader on its first page. `inside`
        // rather than the old "was it open": already in the module, this
        // would throw somebody on Positions back to My Profile for pressing
        // the heading above the list they were using.
        if (!inside) {
            const first = item.children?.[0];

            if (first?.href && !isHrefActive(first.href, currentUrl)) {
                router.visit(first.href);
            }
        }
    };

    return (
        <>
            {/* Mobile scrim */}
            <div
                className={cn(
                    // No fade either: a scrim easing in behind a drawer that
                    // now snaps open is the two halves of one gesture
                    // disagreeing about whether it is animated.
                    'fixed inset-0 z-40 bg-foreground/40 backdrop-blur-sm lg:hidden',
                    mobileOpen ? 'opacity-100' : 'pointer-events-none opacity-0',
                )}
                onClick={onCloseMobile}
                aria-hidden="true"
            />

            <aside
                className={cn(
                    /*
                     * No right border. The sidebar surface is already a shade
                     * off the page — that is what separates it, and a rule
                     * down the full height of the window on top of that reads
                     * as a line drawn *between* two panes rather than as the
                     * edge of one.
                     */
                    'fixed inset-y-0 left-0 z-50 flex flex-col bg-sidebar',
                    /*
                     * No transition. This carried `transition-all
                     * duration-300`, which slid the drawer in on a phone and
                     * — because `transition-all` covers every property —
                     * also animated the rail's width when it collapses on
                     * desktop. Both went with the dropdown's animation on the
                     * owner's instruction: the sidebar opens and closes, it
                     * does not perform doing so.
                     *
                     * `lg:translate-x-0` stays and is load-bearing: it is
                     * what keeps the sidebar on screen at desktop width,
                     * where the `-translate-x-full` below would otherwise
                     * push it off.
                     */
                    'lg:translate-x-0',
                    collapsed ? 'w-sidebar-collapsed' : 'w-sidebar',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full',
                )}
            >
                {/* Logo — and, when collapsed, the only toggle there is. */}
                <div
                    className={cn(
                        // No rule under the logo either: it sits on the same
                        // surface as the nav, and a full-width line says they
                        // are two panels when they are one.
                        'flex h-16 shrink-0 items-center',
                        collapsed ? 'justify-center px-2' : 'justify-between px-4',
                    )}
                >
                    {collapsed ? (
                        /* No separate button fits here collapsed: 64px of rail
                           less 8px of padding each side leaves 48px for the
                           40px mark (LOGO_SIZE in PrimePowerLogo.jsx), which is
                           4px of slack and not room for a second icon beside
                           it. So the mark *is* the control — click it to
                           expand — rather than growing the rail to fit both.
                           Not lg-gated like the button below: this is the
                           brand mark first and a toggle second, and it must
                           stay visible at every width the collapsed rail can
                           reach, mobile included. */
                        <button
                            type="button"
                            onClick={onToggleCollapsed}
                            title="Expand sidebar"
                            className="shrink-0 rounded-md p-1 transition-colors hover:bg-sidebar-accent/50"
                        >
                            <LogoMark />
                        </button>
                    ) : (
                        <>
                            <Link href="/dashboard" className="min-w-0">
                                <PrimePowerLogo collapsed={collapsed} />
                            </Link>

                            {/* Desktop only: collapsing is a rail concept, and
                                the mobile drawer has no rail state to collapse
                                into — it only ever opens full and closes via
                                the scrim. */}
                            <button
                                type="button"
                                onClick={onToggleCollapsed}
                                title="Collapse sidebar"
                                className="hidden shrink-0 rounded-md p-1.5 text-sidebar-muted transition-colors hover:bg-sidebar-accent/50 hover:text-sidebar-foreground lg:grid lg:place-items-center"
                            >
                                {/* A hamburger rather than a panel-with-arrow.
                                    The arrow icon names a direction the rail
                                    moves in, which is only legible once you
                                    already know what the control does; three
                                    lines is the one glyph everybody reads as
                                    "the menu" without being told. */}
                                <Menu className="h-4.5 w-4.5" aria-hidden="true" />
                            </button>
                        </>
                    )}
                </div>

                {/* Nav */}
                <nav className="scrollbar-thin flex-1 space-y-5 overflow-y-auto overflow-x-hidden px-3 py-4">
                    {groups.map((group, groupIndex) => (
                        <div key={group.label ?? `group-${groupIndex}`}>
                            {group.label && !collapsed && (
                                <p
                                    /*
                                     * The brand blue at full strength — the
                                     * same `--sidebar-primary` the active row
                                     * uses for its text and icon.
                                     *
                                     * It was the muted grey, then briefly the
                                     * blue at 75%, on the reasoning that a
                                     * label should sit behind the rows it
                                     * introduces. That reasoning holds for
                                     * body text and not for this: at 10px,
                                     * uppercase and letter-spaced, a colour
                                     * held back reads as *disabled* rather
                                     * than as secondary. These labels are the
                                     * only structural text in the sidebar —
                                     * they name the parts of the system — and
                                     * the size is already doing the work of
                                     * ranking them below the rows.
                                     */
                                    className="mb-1.5 px-2 text-[10px] font-semibold uppercase tracking-[0.08em] text-sidebar-primary"
                                >
                                    {group.label}
                                </p>
                            )}
                            {group.label && collapsed && (
                                <div
                                    className="mx-2 mb-2 h-px bg-sidebar-border"
                                    aria-hidden="true"
                                />
                            )}

                            <ul className="space-y-0.5">
                                {group.items.map((item) => {
                                    const Icon = item.icon;
                                    const hasChildren = Boolean(item.children?.length);
                                    const active = isItemActive(item, currentUrl);
                                    const isOpen = expandedModule === item.id && !collapsed;

                                    const rowClasses = cn(
                                        'group flex w-full items-center gap-2.5 rounded-lg py-2 text-[13px] font-medium',
                                        'transition-colors duration-150',
                                        collapsed ? 'justify-center px-0' : 'px-2.5',
                                        active
                                            ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                                            : 'text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
                                    );

                                    return (
                                        <li key={item.id} className="relative">
                                            {/*
                                             * A rule flush against the
                                             * sidebar's inner edge, marking the
                                             * open module.
                                             *
                                             * The pill alone says "this row is
                                             * highlighted"; a bar at the edge
                                             * says *where you are* — it survives
                                             * being read at a glance and out of
                                             * the corner of the eye, which a
                                             * tinted row of the same shape as
                                             * every other row does not.
                                             *
                                             * `-right-3` is the nav's own `px-3`
                                             * cancelled out, so it lands on the
                                             * content edge rather than floating
                                             * inside the padding. Not drawn
                                             * collapsed: there is no row left
                                             * for it to belong to.
                                             */}
                                            {active && !collapsed && (
                                                <span
                                                    className="pointer-events-none absolute -right-3 bottom-1.5 top-1.5 w-[3px] rounded-l-full bg-sidebar-primary"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {hasChildren ? (
                                                <button
                                                    type="button"
                                                    onClick={() => handleParentClick(item)}
                                                    className={rowClasses}
                                                    title={collapsed ? item.label : undefined}
                                                    aria-expanded={isOpen}
                                                >
                                                    <Icon
                                                        className={cn(
                                                            'h-4.5 w-4.5 shrink-0',
                                                            active && 'text-sidebar-primary',
                                                        )}
                                                        aria-hidden="true"
                                                    />
                                                    {!collapsed && (
                                                        <>
                                                            <span className="flex-1 truncate text-left leading-tight">
                                                                {item.label}
                                                            </span>
                                                            <ChevronDown
                                                                className={cn(
                                                                    // The arrow flips, it does not
                                                                    // animate flipping — the
                                                                    // rotation still says which
                                                                    // module is open.
                                                                    'h-4 w-4 shrink-0 text-sidebar-muted',
                                                                    isOpen && 'rotate-180',
                                                                )}
                                                                aria-hidden="true"
                                                            />
                                                        </>
                                                    )}
                                                </button>
                                            ) : (
                                                <Link
                                                    href={item.href}
                                                    onClick={onCloseMobile}
                                                    className={rowClasses}
                                                    title={collapsed ? item.label : undefined}
                                                    aria-current={active ? 'page' : undefined}
                                                >
                                                    <Icon
                                                        className={cn(
                                                            'h-4.5 w-4.5 shrink-0',
                                                            active && 'text-sidebar-primary',
                                                        )}
                                                        aria-hidden="true"
                                                    />
                                                    {!collapsed && (
                                                        <span className="flex-1 truncate">
                                                            {item.label}
                                                        </span>
                                                    )}
                                                </Link>
                                            )}

                                            {/*
                                                Dropdown of sub-pages, shown or
                                                not shown — no expand animation.

                                                It used to be the `grid-rows-[0fr]`
                                                → `[1fr]` trick with an opacity
                                                fade over 300ms. Removed on the
                                                owner's instruction, and it was
                                                the wrong mechanism here anyway:
                                                the sidebar remounts on every
                                                navigation, so the animation
                                                replayed on each one, and a
                                                transition that plays when
                                                nothing has actually opened
                                                reads as the menu losing its
                                                place. Rendered conditionally
                                                rather than hidden with a class,
                                                so a collapsed module's links are
                                                not in the tab order at all and
                                                the `tabIndex` juggling that used
                                                to be needed is gone with it.
                                            */}
                                            {hasChildren && isOpen && (
                                                <div>
                                                    <ul className="ml-[1.4rem] mt-0.5 space-y-0.5 border-l border-sidebar-border pl-2.5">
                                                        {item.children.map((child) => {
                                                            const ChildIcon = child.icon;
                                                            const childActive = isHrefActive(
                                                                child.href,
                                                                currentUrl,
                                                            );

                                                            return (
                                                                <li key={child.id}>
                                                                    {/* A heading for the children
                                                                        that follow, so a set with
                                                                        a different job from the
                                                                        rest of the dropdown says
                                                                        so — the four checks read
                                                                        as four more record
                                                                        screens without it. */}
                                                                    {child.divider && (
                                                                        <p className="mb-1 mt-2.5 px-2.5 text-[10px] font-semibold uppercase tracking-wider text-sidebar-foreground/40">
                                                                            {child.divider}
                                                                        </p>
                                                                    )}
                                                                    <Link
                                                                        href={child.href}
                                                                        onClick={onCloseMobile}
                                                                        aria-current={
                                                                            childActive
                                                                                ? 'page'
                                                                                : undefined
                                                                        }
                                                                        className={cn(
                                                                            'flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-[12.5px]',
                                                                            'transition-colors duration-150',
                                                                            childActive
                                                                                ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                                                                                : 'text-sidebar-foreground/60 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
                                                                        )}
                                                                    >
                                                                        <ChildIcon
                                                                            className="h-4 w-4 shrink-0"
                                                                            aria-hidden="true"
                                                                        />
                                                                        <span className="flex-1 truncate">
                                                                            {child.label}
                                                                        </span>
                                                                        {badgeFor(
                                                                            child,
                                                                            props,
                                                                        ) && (
                                                                            <span className="rounded-full bg-sidebar-primary/15 px-1.5 py-0.5 text-[10px] font-semibold text-sidebar-primary">
                                                                                {badgeFor(
                                                                                    child,
                                                                                    props,
                                                                                )}
                                                                            </span>
                                                                        )}
                                                                    </Link>
                                                                </li>
                                                            );
                                                        })}
                                                    </ul>
                                                </div>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    ))}
                </nav>

                {/* User card */}
                <div className="shrink-0 border-t border-sidebar-border p-3">
                    <div
                        className={cn(
                            'flex items-center gap-2.5',
                            collapsed && 'justify-center',
                        )}
                    >
                        {/* Collapsed, the avatar is the only thing left in this
                            block, so it carries the link — the gear beside it
                            would have nowhere to sit. */}
                        {collapsed ? (
                            <Link
                                href="/settings"
                                aria-label="Settings"
                                title={`${user?.name} — settings`}
                                className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-sidebar-primary text-xs font-semibold text-sidebar-primary-foreground transition-opacity hover:opacity-90"
                            >
                                {initials(user?.name)}
                            </Link>
                        ) : (
                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-sidebar-primary text-xs font-semibold text-sidebar-primary-foreground">
                                {initials(user?.name)}
                            </span>
                        )}

                        {!collapsed && (
                            <>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-[12.5px] font-semibold uppercase tracking-wide text-sidebar-foreground">
                                        {user?.name}
                                    </p>
                                    <p className="truncate text-[11px] text-sidebar-muted">
                                        {user?.username}
                                    </p>
                                </div>

                                {/* Settings lives here rather than up in the
                                    nav list: it configures the app and the
                                    account, which is what this block is about,
                                    and it is not a sixth module to file beside
                                    Payroll.

                                    Its own button rather than the whole row
                                    being a link — the row already holds the
                                    logout button, and an interactive control
                                    inside a link is neither valid nor
                                    predictable to click. */}
                                <Link
                                    href="/settings"
                                    aria-label="Settings"
                                    title="Settings"
                                    className={cn(
                                        'shrink-0 rounded-md p-1.5 transition-colors',
                                        settingsActive
                                            ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                                            : 'text-sidebar-muted hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                                    )}
                                >
                                    <Settings className="h-4 w-4" aria-hidden="true" />
                                </Link>

                                <Link
                                    href="/logout"
                                    method="post"
                                    as="button"
                                    aria-label="Log out"
                                    title="Log out"
                                    className="shrink-0 rounded-md p-1.5 text-sidebar-muted transition-colors hover:bg-destructive/10 hover:text-destructive"
                                >
                                    <LogOut className="h-4 w-4" aria-hidden="true" />
                                </Link>
                            </>
                        )}
                    </div>
                </div>
            </aside>
        </>
    );
}
