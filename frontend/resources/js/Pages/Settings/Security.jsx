import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    ArrowRight,
    CheckCircle2,
    Clock,
    FileText,
    History,
    KeyRound,
    Mail,
    MessageSquare,
    Plus,
    Send,
    ShieldAlert,
    ShieldCheck,
    ShieldOff,
    TriangleAlert,
    User,
    XCircle,
} from 'lucide-react';
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

export default function Security({
    account = {},
    tokens = [],
    canViewAudit = false,
    canRename = false,
    mustChangePassword = false,
    privacy = null,
    otp = {},
    is_super_admin = false,
    changeRequests = [],
}) {
    const isOtpActive = Boolean(otp?.otp_enabled && otp?.otp_email);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [enableModalOpen, setEnableModalOpen] = useState(false);
    const [changeEmailModalOpen, setChangeEmailModalOpen] = useState(false);
    const [requestModalOpen, setRequestModalOpen] = useState(false);
    const [sendingTest, setSendingTest] = useState(false);

    const requestForm = useForm({
        requested_username: '',
        requested_email: '',
        staff_notes: '',
    });

    const hasPendingRequest = changeRequests.some((r) => r.status === 'pending');

    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const deleteForm = useForm({ password: '' });

    const enableForm = useForm({
        otp_email: otp?.otp_email ?? '',
        password: '',
        otp_enabled: true,
    });

    const changeEmailForm = useForm({
        otp_email: otp?.otp_email ?? '',
        password: '',
        otp_enabled: true,
    });

    const submitPassword = (event) => {
        event.preventDefault();

        passwordForm.put('/settings/security/password', {
            preserveScroll: true,
            onSuccess: () => passwordForm.reset(),
        });
    };

    const handleEnableOtp = (e) => {
        e.preventDefault();
        enableForm.put('/settings/security/otp', {
            preserveScroll: true,
            onSuccess: () => {
                setEnableModalOpen(false);
                enableForm.reset('password');
            },
        });
    };

    const handleChangeEmail = (e) => {
        e.preventDefault();
        changeEmailForm.put('/settings/security/otp', {
            preserveScroll: true,
            onSuccess: () => {
                setChangeEmailModalOpen(false);
                changeEmailForm.reset('password');
            },
        });
    };

    const sendTestOtp = () => {
        setSendingTest(true);
        router.post(
            '/settings/security/otp/test',
            {},
            {
                preserveScroll: true,
                onFinish: () => setSendingTest(false),
            },
        );
    };

    const handleRequestChange = (e) => {
        e.preventDefault();
        requestForm.post(route('settings.security.changeRequest'), {
            preserveScroll: true,
            onSuccess: () => {
                setRequestModalOpen(false);
                requestForm.reset();
            },
        });
    };

    return (
        <SettingsLayout title="Security">
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
                <CardHeader title="Password" />
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

            {/* Multi-Factor Authentication (Email OTP) */}
            <Card>
                <CardHeader
                    title="Two-Factor Authentication (Email OTP)"
                    badge={
                        isOtpActive ? (
                            <Badge variant={otp?.otp_verified ? 'success' : 'warning'}>
                                {otp?.otp_verified
                                    ? 'Enabled & Verified'
                                    : 'Enabled (Pending Verification)'}
                            </Badge>
                        ) : (
                            <Badge variant="warning">Setup Required</Badge>
                        )
                    }
                />
                <CardBody className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        Protect your account with a two-factor sign-in code. Signing in requires
                        a 6-digit one-time code sent directly to your Gmail inbox, valid for{' '}
                        {otp?.ttl_minutes ?? 2} minutes. Multi-Factor Authentication is
                        mandatory for all roles (Administrator, Supervisor, HR Staff, and
                        Employee) and cannot be disabled.
                    </p>

                    {isOtpActive ? (
                        <div className="flex flex-col justify-between gap-4 rounded-lg border border-border bg-muted/20 p-4 sm:flex-row sm:items-center">
                            <div className="space-y-1">
                                <div className="flex items-center gap-2">
                                    <Mail className="h-4 w-4 text-primary" />
                                    <span className="font-mono text-sm font-semibold text-foreground">
                                        {otp.otp_email}
                                    </span>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Sign-in codes are sent to this address on every login.
                                </p>
                            </div>

                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={sendTestOtp}
                                    disabled={sendingTest}
                                >
                                    <Send className="h-3.5 w-3.5" />
                                    {sendingTest ? 'Sending...' : 'Send test code'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        changeEmailForm.setData({
                                            otp_email: otp.otp_email,
                                            password: '',
                                            otp_enabled: true,
                                        });
                                        changeEmailForm.clearErrors();
                                        setChangeEmailModalOpen(true);
                                    }}
                                >
                                    Change
                                </Button>
                                <span className="inline-flex items-center gap-1.5 rounded-md border border-emerald-500/25 bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                    <ShieldCheck className="h-3.5 w-3.5" />
                                    Mandatory Policy
                                </span>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-col justify-between gap-4 rounded-lg border border-border bg-muted/10 p-4 sm:flex-row sm:items-center">
                            <div className="space-y-1">
                                <div className="flex items-center gap-2">
                                    <ShieldOff className="h-4 w-4 text-muted-foreground" />
                                    <span className="text-sm font-semibold text-foreground">
                                        Two-Factor Authentication Setup Required
                                    </span>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {otp?.otp_email ? (
                                        <>
                                            Configured address:{' '}
                                            <span className="font-mono font-medium text-foreground">
                                                {otp.otp_email}
                                            </span>
                                            . Multi-Factor Authentication is required for all
                                            accounts. Please enable 2FA below.
                                        </>
                                    ) : (
                                        'Connect your personal Gmail to complete mandatory two-factor security setup.'
                                    )}
                                </p>
                            </div>

                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    type="button"
                                    variant="primary"
                                    size="sm"
                                    onClick={() => {
                                        enableForm.setData({
                                            otp_email: otp?.otp_email ?? '',
                                            password: '',
                                            otp_enabled: true,
                                        });
                                        enableForm.clearErrors();
                                        setEnableModalOpen(true);
                                    }}
                                >
                                    <ShieldCheck className="h-3.5 w-3.5" />
                                    Enable 2FA
                                </Button>
                            </div>
                        </div>
                    )}
                </CardBody>
            </Card>

            {/* Staff Account Credential Change Requests */}
            <Card>
                <CardHeader
                    title="Account Credential Change Requests"
                    description="Request changes to your company username or personal MFA email. Every request requires an explanation note and must be approved by the Super Administrator."
                    action={
                        <Button
                            variant="primary"
                            size="sm"
                            disabled={hasPendingRequest}
                            onClick={() => {
                                requestForm.setData({
                                    requested_username: '',
                                    requested_email: '',
                                    staff_notes: '',
                                });
                                requestForm.clearErrors();
                                setRequestModalOpen(true);
                            }}
                        >
                            <Plus className="h-4 w-4" />
                            Request Change
                        </Button>
                    }
                />
                <CardBody className="space-y-4">
                    {hasPendingRequest && (
                        <div className="flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning/10 p-3.5 text-sm text-foreground">
                            <Clock className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
                            <div>
                                <p className="font-medium text-warning-foreground">
                                    You have an active request pending review.
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Your request has been forwarded to the Super Administrator.
                                    Once reviewed and decided, you will see their response
                                    below.
                                </p>
                            </div>
                        </div>
                    )}

                    {changeRequests.length === 0 ? (
                        <div className="rounded-lg border border-dashed border-border py-8 text-center">
                            <FileText className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                            <p className="text-sm font-medium text-foreground">
                                No change requests
                            </p>
                            <p className="mx-auto mt-1 max-w-sm text-xs text-muted-foreground">
                                If you need to change your sign-in username or your MFA email
                                address, submit a request above.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {changeRequests.map((req) => (
                                <div
                                    key={req.id}
                                    className="space-y-3 rounded-lg border border-border p-4"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border/60 pb-2.5">
                                        <div className="flex items-center gap-2">
                                            {req.status === 'pending' && (
                                                <Badge variant="warning">
                                                    <Clock className="mr-1 h-3 w-3" />
                                                    Pending Super Admin Review
                                                </Badge>
                                            )}
                                            {req.status === 'approved' && (
                                                <Badge variant="success">
                                                    <CheckCircle2 className="mr-1 h-3 w-3" />
                                                    Approved
                                                </Badge>
                                            )}
                                            {req.status === 'rejected' && (
                                                <Badge variant="destructive">
                                                    <XCircle className="mr-1 h-3 w-3" />
                                                    Rejected
                                                </Badge>
                                            )}
                                            <span className="text-xs text-muted-foreground">
                                                Submitted {formatDate(req.created_at)}
                                            </span>
                                        </div>

                                        {req.decided_at && (
                                            <span className="text-xs text-muted-foreground">
                                                Decided {formatDate(req.decided_at)}
                                                {req.decided_by && ` by ${req.decided_by}`}
                                            </span>
                                        )}
                                    </div>

                                    <div className="grid gap-2 text-xs sm:grid-cols-2">
                                        {req.requested_username && (
                                            <div className="rounded bg-muted/40 p-2">
                                                <div className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                                                    Username Change
                                                </div>
                                                <div className="flex items-center gap-1.5 font-mono">
                                                    <span className="text-muted-foreground line-through">
                                                        {req.current_username}
                                                    </span>
                                                    <ArrowRight className="h-3 w-3 shrink-0 text-primary" />
                                                    <span className="font-semibold text-foreground">
                                                        {req.requested_username}
                                                    </span>
                                                </div>
                                            </div>
                                        )}

                                        {req.requested_email && (
                                            <div className="rounded bg-muted/40 p-2">
                                                <div className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                                                    Personal MFA Email Change
                                                </div>
                                                <div className="flex items-center gap-1.5 font-mono">
                                                    <span className="text-muted-foreground line-through">
                                                        {req.current_email || 'None'}
                                                    </span>
                                                    <ArrowRight className="h-3 w-3 shrink-0 text-emerald-600" />
                                                    <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                                                        {req.requested_email}
                                                    </span>
                                                </div>
                                            </div>
                                        )}
                                    </div>

                                    <div className="rounded-lg bg-muted/20 p-2.5 text-xs text-foreground">
                                        <span className="font-medium text-muted-foreground">
                                            Your explanation:{' '}
                                        </span>
                                        <span className="italic">"{req.staff_notes}"</span>
                                    </div>

                                    {req.admin_notes && (
                                        <div
                                            className={`rounded-lg border p-2.5 text-xs ${
                                                req.status === 'approved'
                                                    ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-950 dark:text-emerald-200'
                                                    : 'border-destructive/20 bg-destructive/10 text-destructive-foreground'
                                            }`}
                                        >
                                            <span className="font-semibold">
                                                Super Admin Feedback:{' '}
                                            </span>
                                            <span>{req.admin_notes}</span>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </CardBody>
            </Card>

            {privacy && (
                <Card>
                    <CardHeader
                        title="Privacy Notice"
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

            {/* The log itself moved to Administration → Audit Logs, where it
                has a date range and pages. This screen keeps the door: 50 rows
                under somebody's password form answered "what happened in the
                last hour" and nothing else. */}
            {canViewAudit && (
                <Card>
                    <CardHeader
                        title="Audit Log"
                        action={
                            <Button variant="outline" href="/settings/audit-logs">
                                <History className="h-4 w-4" />
                                Open Audit Logs
                            </Button>
                        }
                    />
                </Card>
            )}
            <Card className="border-destructive/30">
                <CardHeader title="Delete Account" />
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
                                type="text"
                                style={{ WebkitTextSecurity: 'disc' }}
                                autoComplete="off"
                                data-lpignore="true"
                                data-1p-ignore="true"
                                data-form-type="other"
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

            {/* Modal: Enable 2FA */}
            <Modal
                show={enableModalOpen}
                onClose={() => {
                    setEnableModalOpen(false);
                    enableForm.reset('password');
                    enableForm.clearErrors();
                }}
                title="Enable Two-Factor Authentication"
                maxWidth="md"
            >
                <form onSubmit={handleEnableOtp} className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        Signing in will require a 6-digit one-time code sent directly to your
                        Gmail inbox ({otp?.ttl_minutes ?? 2}-minute validity).
                    </p>

                    <Field
                        label="Personal or Company Gmail"
                        required
                        error={enableForm.errors.otp_email}
                        hint="The email address where your 6-digit verification codes will be sent."
                    >
                        {({ id }) => (
                            <Input
                                id={id}
                                type="text"
                                name="auth_otp_destination"
                                autoComplete="off"
                                autoCapitalize="none"
                                spellCheck={false}
                                data-form-type="other"
                                data-lpignore="true"
                                data-1p-ignore="true"
                                placeholder="name@gmail.com"
                                value={enableForm.data.otp_email}
                                onChange={(e) =>
                                    enableForm.setData('otp_email', e.target.value)
                                }
                                error={enableForm.errors.otp_email}
                                required
                            />
                        )}
                    </Field>

                    <Field
                        label="Current Password"
                        required
                        error={enableForm.errors.password}
                        hint="Enter your current password to authorize this security change."
                    >
                        {({ id }) => (
                            <Input
                                id={id}
                                type="text"
                                style={{ WebkitTextSecurity: 'disc' }}
                                autoComplete="off"
                                data-lpignore="true"
                                data-1p-ignore="true"
                                data-form-type="other"
                                value={enableForm.data.password}
                                onChange={(e) => enableForm.setData('password', e.target.value)}
                                error={enableForm.errors.password}
                                required
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setEnableModalOpen(false);
                                enableForm.reset('password');
                                enableForm.clearErrors();
                            }}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" variant="primary" loading={enableForm.processing}>
                            Confirm & Enable 2FA
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Modal: Change Email */}
            <Modal
                show={changeEmailModalOpen}
                onClose={() => {
                    setChangeEmailModalOpen(false);
                    changeEmailForm.reset('password');
                    changeEmailForm.clearErrors();
                }}
                title="Change 2FA Gmail Address"
                maxWidth="md"
            >
                <form onSubmit={handleChangeEmail} className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        Enter your new Gmail address for receiving login verification codes.
                    </p>

                    <Field
                        label="New Gmail Address"
                        required
                        error={changeEmailForm.errors.otp_email}
                    >
                        {({ id }) => (
                            <Input
                                id={id}
                                type="text"
                                name="auth_otp_destination"
                                autoComplete="off"
                                autoCapitalize="none"
                                spellCheck={false}
                                data-form-type="other"
                                data-lpignore="true"
                                data-1p-ignore="true"
                                placeholder="newemail@gmail.com"
                                value={changeEmailForm.data.otp_email}
                                onChange={(e) =>
                                    changeEmailForm.setData('otp_email', e.target.value)
                                }
                                error={changeEmailForm.errors.otp_email}
                                required
                            />
                        )}
                    </Field>

                    <Field
                        label="Current Password"
                        required
                        error={changeEmailForm.errors.password}
                        hint="Enter your password to verify this update."
                    >
                        {({ id }) => (
                            <Input
                                id={id}
                                type="text"
                                style={{ WebkitTextSecurity: 'disc' }}
                                autoComplete="off"
                                data-lpignore="true"
                                data-1p-ignore="true"
                                data-form-type="other"
                                value={changeEmailForm.data.password}
                                onChange={(e) =>
                                    changeEmailForm.setData('password', e.target.value)
                                }
                                error={changeEmailForm.errors.password}
                                required
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setChangeEmailModalOpen(false);
                                changeEmailForm.reset('password');
                                changeEmailForm.clearErrors();
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            loading={changeEmailForm.processing}
                        >
                            Update Email
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Modal: Request Account Change */}
            <Modal
                show={requestModalOpen}
                onClose={() => {
                    setRequestModalOpen(false);
                    requestForm.reset();
                    requestForm.clearErrors();
                }}
                title="Request Account Credential Change"
                description="Submit a request to change your company username or personal MFA email. The Super Administrator will review your notes before approving."
                maxWidth="md"
            >
                <form onSubmit={handleRequestChange} className="space-y-4">
                    <div className="space-y-2 rounded-lg border border-border bg-muted/20 p-3 text-xs">
                        <div className="flex items-center justify-between">
                            <span className="text-muted-foreground">
                                Current Company Username:
                            </span>
                            <span className="font-mono font-semibold text-foreground">
                                {account.username}
                            </span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-muted-foreground">
                                Current Personal MFA Email:
                            </span>
                            <span className="font-mono font-semibold text-foreground">
                                {otp.otp_email || 'None configured'}
                            </span>
                        </div>
                    </div>

                    <Field
                        label="New Company Username (Optional)"
                        hint="Leave blank if you do not wish to change your username."
                        error={requestForm.errors.requested_username}
                    >
                        {({ id }) => (
                            <Input
                                id={id}
                                value={requestForm.data.requested_username}
                                placeholder="e.g. new.username (without domain)"
                                autoCapitalize="none"
                                spellCheck={false}
                                onChange={(e) =>
                                    requestForm.setData('requested_username', e.target.value)
                                }
                            />
                        )}
                    </Field>

                    <Field
                        label="New Personal MFA Email (Optional)"
                        hint="Leave blank if you do not wish to change your MFA email address."
                        error={requestForm.errors.requested_email}
                    >
                        {({ id }) => (
                            <Input
                                id={id}
                                type="email"
                                value={requestForm.data.requested_email}
                                placeholder="e.g. personal.email@gmail.com"
                                autoCapitalize="none"
                                spellCheck={false}
                                onChange={(e) =>
                                    requestForm.setData('requested_email', e.target.value)
                                }
                            />
                        )}
                    </Field>

                    <Field
                        label="Notes / Reason for Request"
                        required
                        hint="Explain why you are requesting this change (e.g. updated personal Gmail address, legal surname change)."
                        error={requestForm.errors.staff_notes}
                    >
                        {({ id }) => (
                            <textarea
                                id={id}
                                rows={3}
                                required
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm placeholder:text-muted-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                placeholder="Please enter your reason or explanation here..."
                                value={requestForm.data.staff_notes}
                                onChange={(e) =>
                                    requestForm.setData('staff_notes', e.target.value)
                                }
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setRequestModalOpen(false);
                                requestForm.reset();
                                requestForm.clearErrors();
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            loading={requestForm.processing}
                        >
                            Submit Request
                        </Button>
                    </div>
                </form>
            </Modal>
        </SettingsLayout>
    );
}
