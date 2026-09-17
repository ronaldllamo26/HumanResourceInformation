import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { History, KeyRound, Mail, Send, ShieldCheck, ShieldOff, TriangleAlert } from 'lucide-react';
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
}) {
    const isOtpActive = Boolean(otp?.otp_enabled && otp?.otp_email);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [disableModalOpen, setDisableModalOpen] = useState(false);
    const [enableModalOpen, setEnableModalOpen] = useState(false);
    const [changeEmailModalOpen, setChangeEmailModalOpen] = useState(false);
    const [sendingTest, setSendingTest] = useState(false);

    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const deleteForm = useForm({ password: '' });

    const disableForm = useForm({
        password: '',
        otp_enabled: false,
    });

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

    const handleDisableOtp = (e) => {
        e.preventDefault();
        disableForm.put('/settings/security/otp', {
            preserveScroll: true,
            onSuccess: () => {
                setDisableModalOpen(false);
                disableForm.reset();
            },
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
        router.post('/settings/security/otp/test', {}, {
            preserveScroll: true,
            onFinish: () => setSendingTest(false),
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
                                {otp?.otp_verified ? 'Enabled & Verified' : 'Enabled (Pending Verification)'}
                            </Badge>
                        ) : (
                            <Badge variant="muted">Disabled (Password Only)</Badge>
                        )
                    }
                />
                <CardBody className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        Protect your account with a two-factor sign-in code. When enabled, signing in requires a 6-digit one-time code sent directly to your Gmail inbox, valid for {otp?.ttl_minutes ?? 2} minutes.
                    </p>

                    {isOtpActive ? (
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-lg border border-border bg-muted/20 p-4">
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
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="border-destructive/30 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    onClick={() => {
                                        disableForm.setData({
                                            password: '',
                                            otp_enabled: false,
                                        });
                                        disableForm.clearErrors();
                                        setDisableModalOpen(true);
                                    }}
                                >
                                    <ShieldOff className="h-3.5 w-3.5" />
                                    Disable
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-lg border border-border bg-muted/10 p-4">
                            <div className="space-y-1">
                                <div className="flex items-center gap-2">
                                    <ShieldOff className="h-4 w-4 text-muted-foreground" />
                                    <span className="text-sm font-semibold text-foreground">
                                        Two-Factor Authentication is currently Disabled
                                    </span>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {otp?.otp_email ? (
                                        <>
                                            Configured address:{' '}
                                            <span className="font-mono font-medium text-foreground">
                                                {otp.otp_email}
                                            </span>
                                            . You can re-enable 2FA anytime using your password.
                                        </>
                                    ) : (
                                        'Connect your personal Gmail to add an extra layer of protection to your account.'
                                    )}
                                </p>
                            </div>

                            <div className="flex flex-wrap items-center gap-2">
                                {otp?.otp_email && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            changeEmailForm.setData({
                                                otp_email: otp.otp_email,
                                                password: '',
                                                otp_enabled: false,
                                            });
                                            changeEmailForm.clearErrors();
                                            setChangeEmailModalOpen(true);
                                        }}
                                    >
                                        Change Email
                                    </Button>
                                )}
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

            {/* Modal: Disable 2FA */}
            <Modal
                show={disableModalOpen}
                onClose={() => {
                    setDisableModalOpen(false);
                    disableForm.reset();
                    disableForm.clearErrors();
                }}
                title="Disable Two-Factor Authentication?"
                maxWidth="md"
            >
                <form onSubmit={handleDisableOtp} className="space-y-4">
                    <div className="flex items-start gap-3 rounded-lg border border-destructive/20 bg-destructive/10 p-3.5 text-xs text-destructive">
                        <ShieldOff className="mt-0.5 h-4 w-4 shrink-0" />
                        <div>
                            <p className="font-semibold">Security Warning</p>
                            <p className="mt-0.5 text-muted-foreground">
                                Disabling two-factor authentication makes your account less secure. Once disabled, signing in will only require your password.
                            </p>
                        </div>
                    </div>

                    <p className="text-sm text-muted-foreground">
                        For security verification, please enter your current password to confirm disabling two-factor authentication.
                    </p>

                    <Field label="Current Password" required error={disableForm.errors.password}>
                        {({ id }) => (
                            <Input
                                id={id}
                                type="text"
                                style={{ WebkitTextSecurity: 'disc' }}
                                autoComplete="off"
                                data-lpignore="true"
                                data-1p-ignore="true"
                                data-form-type="other"
                                value={disableForm.data.password}
                                onChange={(e) => disableForm.setData('password', e.target.value)}
                                error={disableForm.errors.password}
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setDisableModalOpen(false);
                                disableForm.reset();
                                disableForm.clearErrors();
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            loading={disableForm.processing}
                        >
                            Confirm & Disable 2FA
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
                        Signing in will require a 6-digit one-time code sent directly to your Gmail inbox ({otp?.ttl_minutes ?? 2}-minute validity).
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
                                onChange={(e) => enableForm.setData('otp_email', e.target.value)}
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
                        <Button
                            type="submit"
                            variant="primary"
                            loading={enableForm.processing}
                        >
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
                                onChange={(e) => changeEmailForm.setData('otp_email', e.target.value)}
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
                                onChange={(e) => changeEmailForm.setData('password', e.target.value)}
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
        </SettingsLayout>
    );
}
