import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { ChevronDown, LogOut, Menu, Settings } from 'lucide-react';
import PrimePowerLogo, { LogoMark } from '@/Components/layout/PrimePowerLogo';
import { NAV_GROUPS, isHrefActive, isItemActive, visibleGroups } from '@/config/navigation';
import { cn, initials } from '@/lib/utils';

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

    // Accordion: at most one module open at a time.
    const [expandedModule, setExpandedModule] = useState(null);

    // Keep the accordion in sync with whichever module owns the current URL.
    useEffect(() => {
        const owner = groups
            .flatMap((group) => group.items)
            .find((item) =>
                item.children?.some((child) => isHrefActive(child.href, currentUrl)),
            );

        if (owner) setExpandedModule(owner.id);
    }, [currentUrl, groups]);

    const handleParentClick = (item) => {
        setExpandedModule((prev) => (prev === item.id ? null : item.id));
    };

    return (
        <>
            {/* Mobile scrim */}
            <div
                className={cn(
                    'fixed inset-0 z-40 bg-foreground/40 backdrop-blur-sm transition-opacity lg:hidden',
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
                    'transition-all duration-300 lg:translate-x-0',
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
                                                                    'h-4 w-4 shrink-0 text-sidebar-muted transition-transform duration-200',
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

                                            {/* Dropdown of sub-pages */}
                                            {hasChildren && (
                                                <div
                                                    className={cn(
                                                        'grid transition-all duration-300',
                                                        isOpen
                                                            ? 'grid-rows-[1fr] opacity-100'
                                                            : 'grid-rows-[0fr] opacity-0',
                                                    )}
                                                >
                                                    <ul className="ml-[1.4rem] mt-0.5 space-y-0.5 overflow-hidden border-l border-sidebar-border pl-2.5">
                                                        {item.children.map((child) => {
                                                            const ChildIcon = child.icon;
                                                            const childActive = isHrefActive(
                                                                child.href,
                                                                currentUrl,
                                                            );

                                                            return (
                                                                <li key={child.id}>
                                                                    <Link
                                                                        href={child.href}
                                                                        onClick={onCloseMobile}
                                                                        tabIndex={
                                                                            isOpen ? 0 : -1
                                                                        }
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
                                        {user?.email}
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
