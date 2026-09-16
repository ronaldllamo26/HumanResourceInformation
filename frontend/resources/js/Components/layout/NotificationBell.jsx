import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import {
    Bell,
    CalendarCheck,
    CalendarX,
    CalendarDays,
    Clock,
    FileClock,
    Inbox,
    Loader2,
    ShieldAlert,
} from 'lucide-react';
import { cn, formatDate } from '@/lib/utils';

const ICONS = {
    'leave-more': CalendarDays,
    overtime: Clock,
    corrections: FileClock,
    hires: Inbox,
    credentials: ShieldAlert,
};

const TONES = {
    warning: 'bg-warning/10 text-warning',
    info: 'bg-info/10 text-info',
    success: 'bg-success/10 text-success',
    destructive: 'bg-destructive/10 text-destructive',
};

function iconFor(item) {
    if (item.kind === 'leave') return CalendarDays;
    if (item.kind === 'decision') return item.tone === 'success' ? CalendarCheck : CalendarX;

    return ICONS[item.key] ?? Bell;
}

/**
 * The topbar bell, as a dropdown of what needs attention.
 *
 * The list is fetched each time the bell opens rather than carried on every
 * page, so it is always current and costs nothing until somebody looks. The
 * badge counts only what is waiting on this person to decide.
 */
export default function NotificationBell() {
    const { notificationCount = 0 } = usePage().props;
    const [open, setOpen] = useState(false);
    const [feed, setFeed] = useState(null);
    const [failed, setFailed] = useState(false);
    const container = useRef(null);

    useEffect(() => {
        if (!open) return undefined;

        let cancelled = false;
        setFailed(false);

        axios
            .get(route('notifications'))
            .then(({ data }) => !cancelled && setFeed(data))
            .catch(() => !cancelled && setFailed(true));

        const close = (event) => {
            if (event.key === 'Escape') setOpen(false);
        };
        const clickAway = (event) => {
            if (container.current && !container.current.contains(event.target)) setOpen(false);
        };

        document.addEventListener('keydown', close);
        document.addEventListener('mousedown', clickAway);

        return () => {
            cancelled = true;
            document.removeEventListener('keydown', close);
            document.removeEventListener('mousedown', clickAway);
        };
    }, [open]);

    const badge = feed?.count ?? notificationCount;

    return (
        <div ref={container} className="relative">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-haspopup="true"
                aria-expanded={open}
                aria-label={
                    badge > 0 ? `${badge} notification(s) need your attention` : 'Notifications'
                }
                className={cn(
                    'relative grid h-9 w-9 place-items-center rounded-md transition-colors',
                    open
                        ? 'bg-secondary text-foreground'
                        : 'text-muted-foreground hover:bg-secondary hover:text-foreground',
                )}
            >
                <Bell className="h-4.5 w-4.5" aria-hidden="true" />

                {badge > 0 && (
                    <span className="absolute -right-0.5 -top-0.5 grid min-w-4 place-items-center rounded-full bg-destructive px-1 text-[10px] font-semibold leading-4 text-destructive-foreground">
                        {badge > 9 ? '9+' : badge}
                    </span>
                )}
            </button>

            {open && (
                <div
                    role="menu"
                    className="absolute right-0 z-50 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-lg border border-border bg-popover text-popover-foreground shadow-lg"
                >
                    <div className="flex items-center justify-between border-b border-border px-4 py-3">
                        <p className="text-sm font-semibold text-foreground">Notifications</p>
                        {badge > 0 && (
                            <span className="rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive">
                                {badge} to act on
                            </span>
                        )}
                    </div>

                    <div className="max-h-[26rem] overflow-y-auto">
                        {failed ? (
                            <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                                Could not load notifications.
                            </p>
                        ) : feed === null ? (
                            <div className="flex items-center justify-center gap-2 px-4 py-8 text-sm text-muted-foreground">
                                <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                                Loading…
                            </div>
                        ) : feed.items.length === 0 ? (
                            <div className="px-4 py-10 text-center">
                                <Bell
                                    className="mx-auto h-6 w-6 text-muted-foreground/60"
                                    aria-hidden="true"
                                />
                                <p className="mt-2 text-sm font-medium text-foreground">
                                    You&apos;re all caught up
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Nothing needs your attention right now.
                                </p>
                            </div>
                        ) : (
                            <ul className="divide-y divide-border">
                                {feed.items.map((item) => {
                                    const Icon = iconFor(item);

                                    return (
                                        <li key={item.key}>
                                            <Link
                                                href={item.href}
                                                onClick={() => setOpen(false)}
                                                className="flex items-start gap-3 px-4 py-3 transition-colors hover:bg-secondary"
                                            >
                                                <span
                                                    className={cn(
                                                        'grid h-8 w-8 shrink-0 place-items-center rounded-full',
                                                        TONES[item.tone] ?? TONES.info,
                                                    )}
                                                >
                                                    <Icon
                                                        className="h-4 w-4"
                                                        aria-hidden="true"
                                                    />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-sm font-medium text-foreground">
                                                        {item.title}
                                                    </span>
                                                    <span className="block truncate text-xs text-muted-foreground">
                                                        {item.detail}
                                                    </span>
                                                    {item.at && (
                                                        <span className="mt-0.5 block text-[11px] text-muted-foreground/80">
                                                            {formatDate(item.at, {
                                                                hour: '2-digit',
                                                                minute: '2-digit',
                                                            })}
                                                        </span>
                                                    )}
                                                </span>
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
