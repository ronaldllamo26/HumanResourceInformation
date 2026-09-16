import { Badge } from '@/Components/ui';

/** How each day's status reads: a worked day is green, a day to chase is red. */
const DAY_VARIANTS = {
    present: 'success',
    late: 'warning',
    undertime: 'warning',
    absent: 'destructive',
    incomplete: 'destructive',
    rest_day: 'muted',
    holiday: 'primary',
    on_leave: 'primary',
};

export function DayStatus({ status }) {
    return <Badge variant={DAY_VARIANTS[status] ?? 'default'} status={status} />;
}

/** 95 → "1h 35m"; zero is a dash so a clean day does not read as a wall of zeros. */
export function minutes(value) {
    const total = Number(value) || 0;

    if (total === 0) return '—';

    const hours = Math.floor(total / 60);
    const rest = total % 60;

    if (hours === 0) return `${rest}m`;

    return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
}

export const TIMEKEEPING_CRUMBS = [
    { label: 'Time & Attendance' },
    { label: 'Timekeeping', href: '/hr/timekeeping' },
];

export const DECISION_BADGES = {
    pending: 'warning',
    approved: 'success',
    rejected: 'destructive',
    cancelled: 'muted',
    draft: 'muted',
    sent: 'primary',
    confirmed: 'success',
    disputed: 'destructive',
    open: 'warning',
    closed: 'success',
};

export function StateBadge({ status }) {
    return <Badge variant={DECISION_BADGES[status] ?? 'default'} status={status} />;
}
