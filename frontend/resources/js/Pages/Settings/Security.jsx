import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { History, KeyRound, ShieldCheck, TriangleAlert } from 'lucide-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    Field,
    Input,
    Modal,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
} from '@/Components/ui';
import { formatDate } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

const AUDIT_FILTERS = [
    { id: 'changes', label: 'Record changes' },
    { id: 'auth', label: 'Sign-ins' },
    { id: 'all', label: 'All' },
];

/** A failed sign-in is a finding; a successful one is a note. */
const eventVariant = (entry) => {
    if (entry.event === 'login_failed' || entry.event === 'lockout') return 'destructive';
    if (entry.event === 'deleted') return 'destructive';
    if (entry.event === 'created') return 'success';
    if (entry.is_auth) return 'muted';
    return 'primary';
};

export default function Security({
    account,
    tokens,
    auditLog,
    auditFilter,
    canViewAudit,
    canRename = false,
    mustChangePassword = false,
    privacy = null,
}) {
    const [deleteOpen, setDeleteOpen] = useState(false);

    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const deleteForm = useForm({ password: '' });

    const submitPassword = (event) => {
        event.preventDefault();

        passwordForm.put('/settings/security/password', {
            preserveScroll: true,
            onSuccess: () => passwordForm.reset(),
        });
    };

    return (
        <SettingsLayout title="Security" description="Your password and API tokens.">
            {/* The user did not ask for this screen — RequirePasswordChange
                sent them here. Without saying so, the redirect reads as the
                system losing their click. */}
            {mustChangePassword && (
                <div className="rounded-lg border border-warning/30 bg-warning/5 p-4">
                    <p className="flex items-start gap-2 text-sm font-medium text-warning">
                        <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        Choose your own password before continuing.
                    </p>
                    <p className="mt-1.5 pl-6 text-sm text-muted-foreground">
                        This account is still on the password it was set up with, which someone
                        else has seen. The rest of the system opens once you have replaced it
                        below.
                    </p>
                </div>
            )}

            <Card>
                <CardHeader
                    title="Password"
                    description="Changing your password does not sign you out of this browser."
                />
                <CardBody>
                    {/* Stated here because a username is easy to forget, and
                        this is the screen somebody opens to sort out a login. */}
                    <p className="mb-4 text-sm text-muted-foreground">
                        You sign in as{' '}
                        <span className="font-mono font-medium text-foreground">
                            {account.username}
                        </span>
                        .
                    </p>

                    <form onSubmit={submitPassword} className="space-y-4">
                        <Field
                            label="Current Password"
                            required
                            className="max-w-md"
                            error={passwordForm.errors.current_password}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="password"
                                    autoComplete="current-password"
                                    value={passwordForm.data.current_password}
                                    onChange={(event) =>
                                        passwordForm.setData(
                                            'current_password',
                                            event.target.value,
                                        )
                                    }
                                    error={passwordForm.errors.current_password}
                                />
                            )}
                        </Field>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="New Password"
                                required
                                error={passwordForm.errors.password}
                            >
                                {({ id }) => (
                                    <Input
                                        id={id}
                                        type="password"
                                        autoComplete="new-password"
                                        value={passwordForm.data.password}
                                        onChange={(event) =>
                                            passwordForm.setData('password', event.target.value)
                                        }
                                        error={passwordForm.errors.password}
                                    />
                                )}
                            </Field>

                            <Field label="Confirm New Password" required>
                                {({ id }) => (
                                    <Input
                                        id={id}
                                        type="password"
                                        autoComplete="new-password"
                                        value={passwordForm.data.password_confirmation}
                                        onChange={(event) =>
                                            passwordForm.setData(
                                                'password_confirmation',
                                                event.target.value,
                                            )
                                        }
                                    />
                                )}
                            </Field>
                        </div>

                        <div className="flex justify-end">
                            <Button type="submit" loading={passwordForm.processing}>
                                Update Password
                            </Button>
                        </div>
                    </form>
                </CardBody>
            </Card>

            {privacy && (
                <Card>
                    <CardHeader
                        title="Privacy Notice"
                        description="What is collected about you, why, and who can see it (RA 10173)."
                        action={
                            <Link href={route('privacy.notice')}>
                                <Button variant="outline">
                                    <ShieldCheck className="h-4 w-4" />
                                    Read notice
                                </Button>
                            </Link>
                        }
                    />
                    <CardBody>
                        <p className="text-sm text-muted-foreground">
                            {privacy.acknowledged_at ? (
                                <>
                                    You acknowledged version {privacy.version} on{' '}
                                    <span className="font-medium text-foreground">
                                        {formatDate(privacy.acknowledged_at)}
                                    </span>
                                    .
                                </>
                            ) : (
                                <>You have not acknowledged the current version yet.</>
                            )}
                        </p>
                    </CardBody>
                </Card>
            )}

            <Card>
                <CardHeader
                    title="API Tokens"
                    description="Tokens issued to your account for the REST API."
                    action={
                        tokens.length > 0 && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        '/settings/security/tokens/revoke-all',
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Revoke All
                            </Button>
                        )
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Token</TH>
                            <TH>Last Used</TH>
                            <TH>Created</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {tokens.length === 0 ? (
                            <TableEmpty
                                colSpan={4}
                                icon={KeyRound}
                                title="No API tokens"
                                description="Create one under Integrations to use the REST API."
                            />
                        ) : (
                            tokens.map((token) => (
                                <TR key={token.id}>
                                    <TD className="text-sm font-medium text-foreground">
                                        {token.name}
                                    </TD>
                                    <TD className="text-sm text-muted-foreground">
                                        {token.last_used_at
                                            ? formatDate(token.last_used_at)
                                            : 'Never'}
                                    </TD>
                                    <TD className="text-sm text-muted-foreground">
                                        {formatDate(token.created_at)}
                                    </TD>
                                    <TD>
                                        <div className="flex justify-end">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.delete(
                                                        `/settings/security/tokens/${token.id}`,
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Revoke
                                            </Button>
                                        </div>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            {canViewAudit && (
                <Card>
                    <CardHeader
                        title="Audit Log"
                        description="The 50 most recent entries across every module."
                        action={
                            <div className="flex flex-wrap gap-1">
                                {AUDIT_FILTERS.map((option) => (
                                    <Button
                                        key={option.id}
                                        size="sm"
                                        variant={
                                            auditFilter === option.id ? 'primary' : 'outline'
                                        }
                                        onClick={() =>
                                            router.get(
                                                '/settings/security',
                                                { audit: option.id },
                                                { preserveScroll: true, preserveState: true },
                                            )
                                        }
                                    >
                                        {option.label}
                                    </Button>
                                ))}
                                {/* Every entry is signed when written; this checks
                                    none has been edited since. */}
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            route('settings.security.audit.verify'),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <ShieldCheck className="h-4 w-4" />
                                    Verify integrity
                                </Button>
                            </div>
                        }
                    />

                    <Table>
                        <THead>
                            <TR>
                                <TH>Event</TH>
                                <TH>Record</TH>
                                <TH>Detail</TH>
                                <TH>User</TH>
                                <TH>When</TH>
                            </TR>
                        </THead>

                        <TBody>
                            {auditLog.length === 0 ? (
                                <TableEmpty
                                    colSpan={5}
                                    icon={History}
                                    title="No audit entries"
                                />
                            ) : (
                                auditLog.map((entry) => (
                                    <TR key={entry.id}>
                                        <TD>
                                            <Badge variant={eventVariant(entry)}>
                                                {titleCase(entry.event)}
                                            </Badge>
                                        </TD>
                                        <TD className="text-sm text-foreground">
                                            {/* An attempt on an address that is
                                                not ours has no record to name. */}
                                            {entry.subject_id
                                                ? `${entry.subject} #${entry.subject_id}`
                                                : '—'}
                                        </TD>
                                        <TD className="max-w-xs">
                                            <p className="truncate text-xs text-muted-foreground">
                                                {entry.is_auth
                                                    ? (entry.attempted_login ?? '—')
                                                    : entry.changed.length > 0
                                                      ? entry.changed.map(titleCase).join(', ')
                                                      : '—'}
                                            </p>
                                        </TD>
                                        <TD className="text-sm text-muted-foreground">
                                            {/* Nobody is signed in during a
                                                failed attempt — show where it
                                                came from instead of "System". */}
                                            {entry.is_auth && entry.user === 'System'
                                                ? (entry.ip_address ?? '—')
                                                : entry.user}
                                        </TD>
                                        <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                            {formatDate(entry.created_at, {
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })}
                                        </TD>
                                    </TR>
                                ))
                            )}
                        </TBody>
                    </Table>
                </Card>
            )}

            <Card className="border-destructive/30">
                <CardHeader
                    title="Delete Account"
                    description="Permanently removes your login. This cannot be undone."
                />
                <CardBody>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="flex items-start gap-2 text-sm text-muted-foreground">
                            <TriangleAlert
                                className="mt-0.5 h-4 w-4 shrink-0 text-destructive"
                                aria-hidden="true"
                            />
                            Employee records stay for audit and payroll history — only the login
                            is removed.
                        </p>
                        <Button variant="destructive" onClick={() => setDeleteOpen(true)}>
                            Delete Account
                        </Button>
                    </div>
                </CardBody>
            </Card>

            <Modal
                show={deleteOpen}
                onClose={() => setDeleteOpen(false)}
                title="Delete your account?"
                maxWidth="md"
            >
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        deleteForm.delete('/settings/security/account');
                    }}
                    className="space-y-4"
                >
                    <p className="text-sm text-muted-foreground">
                        Enter your password to confirm. You will be signed out immediately.
                    </p>

                    <Field label="Password" required error={deleteForm.errors.password}>
                        {({ id }) => (
                            <Input
                                id={id}
                                type="password"
                                autoComplete="current-password"
                                value={deleteForm.data.password}
                                onChange={(event) =>
                                    deleteForm.setData('password', event.target.value)
                                }
                                error={deleteForm.errors.password}
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={() => setDeleteOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            loading={deleteForm.processing}
                        >
                            Delete Account
                        </Button>
                    </div>
                </form>
            </Modal>
        </SettingsLayout>
    );
}
