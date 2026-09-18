import { router } from '@inertiajs/react';
import { useState } from 'react';
import { LogOut, Monitor, ShieldAlert, Users } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    Modal,
    StatCard,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
    TableEmpty,
} from '@/Components/ui';

const ROLE_LABELS = {
    super_admin: 'Super Administrator',
    admin: 'Administrator',
    hr_staff: 'HR Staff',
    supervisor: 'Supervisor',
    employee: 'Employee',
};

/**
 * Reads a `last_activity` unix timestamp as "how long ago".
 *
 * Relative rather than absolute on purpose: during an incident the question
 * is "is this live right now", and "4:12pm" makes the reader do the
 * subtraction against a clock they have to find first.
 */
function sinceLabel(timestamp) {
    const seconds = Math.max(0, Math.floor(Date.now() / 1000) - timestamp);

    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;

    return `${Math.floor(seconds / 86400)}d ago`;
}

/** The browser and platform out of a user-agent string, or nothing. */
function deviceLabel(userAgent) {
    if (!userAgent) return 'Unknown device';

    const browser = /Edg\//.test(userAgent)
        ? 'Edge'
        : /OPR\//.test(userAgent)
          ? 'Opera'
          : /Chrome\//.test(userAgent)
            ? 'Chrome'
            : /Safari\//.test(userAgent)
              ? 'Safari'
              : /Firefox\//.test(userAgent)
                ? 'Firefox'
                : 'Browser';

    const platform = /Windows/.test(userAgent)
        ? 'Windows'
        : /Macintosh|Mac OS/.test(userAgent)
          ? 'macOS'
          : /Android/.test(userAgent)
            ? 'Android'
            : /iPhone|iPad/.test(userAgent)
              ? 'iOS'
              : /Linux/.test(userAgent)
                ? 'Linux'
                : '';

    return platform ? `${browser} · ${platform}` : browser;
}

export default function Sessions({ sessions = [], summary = {} }) {
    const [confirmingAll, setConfirmingAll] = useState(false);

    const endOne = (id) =>
        router.delete('/settings/sessions/one', {
            data: { session: id },
            preserveScroll: true,
        });

    const endAll = () => {
        router.delete('/settings/sessions/all', { preserveScroll: true });
        setConfirmingAll(false);
    };

    return (
        <AppLayout
            title="Active Sessions"
            breadcrumbs={[
                { label: 'Settings', href: '/settings' },
                { label: 'Active Sessions' },
            ]}
        >
            <div className="space-y-6">
                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard
                        label="Open sessions"
                        value={summary.sessions ?? 0}
                        icon={Monitor}
                        tone={summary.sessions ? 'info' : 'muted'}
                    />
                    <StatCard
                        label="Signed-in accounts"
                        value={summary.accounts ?? 0}
                        icon={Users}
                        tone={summary.accounts ? 'info' : 'muted'}
                    />
                    <StatCard
                        label="Idle sign-out"
                        value={`${summary.idle_minutes ?? 0} min`}
                        icon={ShieldAlert}
                        tone="muted"
                    />
                </div>

                <Card>
                    <CardHeader
                        title="Signed in now"
                        action={
                            <Button
                                variant="destructive"
                                onClick={() => setConfirmingAll(true)}
                                disabled={sessions.filter((s) => !s.is_current).length === 0}
                            >
                                <LogOut className="h-4 w-4" />
                                <span className="hidden sm:inline">Sign out everyone</span>
                            </Button>
                        }
                    />
                    <CardBody className="p-0">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Account</TH>
                                    <TH>Role</TH>
                                    <TH>Address</TH>
                                    <TH>Device</TH>
                                    <TH>Last activity</TH>
                                    <TH className="text-right">Action</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {sessions.length === 0 && (
                                    <TableEmpty
                                        colSpan={6}
                                        title="Nobody is signed in"
                                        description="Sessions appear here as people sign in."
                                    />
                                )}

                                {sessions.map((session) => (
                                    <TR key={session.id}>
                                        <TD>
                                            <div className="font-medium text-foreground">
                                                {session.name}
                                                {session.is_current && (
                                                    <Badge variant="info" className="ml-2">
                                                        You
                                                    </Badge>
                                                )}
                                            </div>
                                            {session.username && (
                                                <div className="text-xs text-muted-foreground">
                                                    {session.username}
                                                </div>
                                            )}
                                        </TD>
                                        <TD className="text-muted-foreground">
                                            {ROLE_LABELS[session.role] ?? '—'}
                                        </TD>
                                        <TD className="font-mono text-xs text-muted-foreground">
                                            {session.ip_address ?? '—'}
                                        </TD>
                                        <TD className="text-muted-foreground">
                                            {deviceLabel(session.user_agent)}
                                        </TD>
                                        <TD className="text-muted-foreground">
                                            {sinceLabel(session.last_activity)}
                                        </TD>
                                        <TD className="text-right">
                                            {/*
                                             * No button on your own row: ending
                                             * it here would sign the reader out
                                             * mid-incident, and "Sign out" in
                                             * the topbar is the honest door for
                                             * that.
                                             */}
                                            {!session.is_current && (
                                                <Button
                                                    variant="ghost"
                                                    onClick={() => endOne(session.id)}
                                                >
                                                    End
                                                </Button>
                                            )}
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </CardBody>
                </Card>
            </div>

            <Modal
                show={confirmingAll}
                onClose={() => setConfirmingAll(false)}
                title="Sign out everyone?"
                description="Every session but your own ends immediately and those people land on the login screen. API tokens on biometric devices are not affected."
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setConfirmingAll(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={endAll}>
                            Sign out everyone
                        </Button>
                    </>
                }
            />
        </AppLayout>
    );
}
