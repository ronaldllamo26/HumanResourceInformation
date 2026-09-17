import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    ClipboardCheck,
    KeyRound,
    Pencil,
    Plus,
    ShieldCheck,
    TriangleAlert,
    UserX,
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
    Select,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
} from '@/Components/ui';
import { formatDate, initials } from '@/lib/utils';

export default function Users({
    users,
    roles,
    unlinkedEmployees,
    accessReview = null,
    staleAfterDays = 90,
    otp = { enabled: true, ttl_minutes: 2 },
}) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [pending, setPending] = useState(null); // { user, action }

    const form = useForm({
        employee_id: '',
        name: '',
        username: '',
        otp_email: '',
        role: 'employee',
    });
    const profile = useForm({ name: '', username: '', otp_email: '' });

    // Picking an employee fills the name from their 201 file, suggests a username, and copies their email.
    const pickEmployee = (employeeId) => {
        const employee = unlinkedEmployees.find(
            (candidate) => String(candidate.id) === String(employeeId),
        );

        form.setData({
            ...form.data,
            employee_id: employeeId,
            name: employee?.full_name ?? form.data.name,
            username: employee?.username ?? form.data.username,
            otp_email: employee?.email ?? form.data.otp_email,
        });
    };

    const submit = (event) => {
        event.preventDefault();

        form.post('/settings/users', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setCreateOpen(false);
            },
        });
    };

    const openEdit = (user) => {
        profile.clearErrors();
        profile.setData({
            name: user.name,
            username: user.username ?? '',
            otp_email: user.otp_email ?? '',
        });
        setEditing(user);
    };

    const sendTestCode = (user) => {
        setPending(null);
        router.post(`/settings/users/${user.id}/otp-test`, {}, { preserveScroll: true });
    };

    const saveProfile = (event) => {
        event.preventDefault();

        profile.put(`/settings/users/${editing.id}/profile`, {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        });
    };

    const confirm = () => {
        const url =
            pending.action === 'reset'
                ? `/settings/users/${pending.user.id}/reset-password`
                : `/settings/users/${pending.user.id}/toggle`;

        router.post(url, {}, { preserveScroll: true, onFinish: () => setPending(null) });
    };

    return (
        <SettingsLayout title="Users & Access">
            <Card>
                <CardHeader title="Roles" />
                <CardBody className="grid gap-3 sm:grid-cols-2">
                    {roles.map((role) => (
                        <div key={role.value} className="rounded-lg border border-border p-3">
                            <div className="mb-1 flex items-center gap-2">
                                <ShieldCheck
                                    className="h-4 w-4 text-primary"
                                    aria-hidden="true"
                                />
                                <p className="text-sm font-medium text-foreground">
                                    {role.label}
                                </p>
                            </div>
                            <p className="text-xs text-muted-foreground">{role.description}</p>
                        </div>
                    ))}
                </CardBody>
            </Card>

            {accessReview && (
                <Card>
                    <CardHeader
                        title="Access Review"
                        action={
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        route('settings.users.review'),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <ClipboardCheck className="h-4 w-4" />
                                Record review
                            </Button>
                        }
                    />
                    <CardBody className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                        <span className="text-muted-foreground">
                            {accessReview.at ? (
                                <>
                                    Last reviewed{' '}
                                    <span className="font-medium text-foreground">
                                        {formatDate(accessReview.at)}
                                    </span>{' '}
                                    by {accessReview.by ?? 'unknown'}
                                </>
                            ) : (
                                'Never reviewed'
                            )}
                        </span>
                        {accessReview.due && <Badge variant="warning">Review due</Badge>}
                        <span className="text-muted-foreground">
                            {
                                users.filter((user) => user.is_privileged && user.is_active)
                                    .length
                            }{' '}
                            with full access · {users.filter((user) => user.is_stale).length}{' '}
                            unused for {staleAfterDays}+ days
                        </span>
                    </CardBody>
                </Card>
            )}

            <Card>
                <CardHeader
                    title="Accounts"
                    action={
                        <Button
                            onClick={() => {
                                form.reset();
                                form.clearErrors();
                                setCreateOpen(true);
                            }}
                        >
                            <Plus className="h-4 w-4" />
                            New Account
                        </Button>
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>User</TH>
                            <TH>Role</TH>
                            <TH>201 File</TH>
                            <TH>Sign-in code goes to</TH>
                            <TH className="text-right">Tokens</TH>
                            <TH>Last Sign-in</TH>
                            <TH>Status</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {users.length === 0 ? (
                            <TableEmpty colSpan={8} title="No accounts" />
                        ) : (
                            users.map((user) => (
                                <TR key={user.id}>
                                    <TD>
                                        <div className="flex items-center gap-2.5">
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                {initials(user.name)}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {user.name}
                                                    {user.is_self && (
                                                        <span className="ml-1 text-xs text-primary">
                                                            (you)
                                                        </span>
                                                    )}
                                                </p>
                                                {/* The username first: it is what the person
                                                    signs in with, and the thing an admin gets
                                                    asked for. */}
                                                <p className="truncate font-mono text-xs text-muted-foreground">
                                                    {user.username}
                                                </p>
                                            </div>
                                        </div>
                                    </TD>

                                    <TD>
                                        <Select
                                            value={user.role}
                                            aria-label={`Role for ${user.name}`}
                                            className="w-40"
                                            onChange={(event) =>
                                                router.put(
                                                    `/settings/users/${user.id}/role`,
                                                    { role: event.target.value },
                                                    { preserveScroll: true },
                                                )
                                            }
                                            options={roles.map((role) => ({
                                                value: role.value,
                                                label: role.label,
                                            }))}
                                        />
                                    </TD>

                                    <TD className="text-sm text-muted-foreground">
                                        {user.employee_number ?? (
                                            <span className="text-muted-foreground/60">
                                                Not linked
                                            </span>
                                        )}
                                    </TD>

                                    {/* The personal inbox sign-in codes go to.
                                        In full, not masked: the administrator is
                                        the one who has to spot a typo in it, and
                                        a masked address is one nobody can check. */}
                                    <TD className="min-w-0 text-sm">
                                        {user.otp_email ? (
                                            <div className="min-w-0">
                                                <p className="truncate text-foreground">
                                                    {user.otp_email}
                                                </p>
                                                <div className="mt-0.5 flex flex-wrap items-center gap-1.5">
                                                    <Badge
                                                        variant={
                                                            user.otp_verified
                                                                ? 'success'
                                                                : 'warning'
                                                        }
                                                    >
                                                        {user.otp_verified
                                                            ? 'Code verified'
                                                            : 'Not proved yet'}
                                                    </Badge>
                                                    <button
                                                        type="button"
                                                        onClick={() => sendTestCode(user)}
                                                        className="rounded px-1 py-0.5 text-xs font-medium text-primary hover:bg-primary/10"
                                                    >
                                                        Send test code
                                                    </button>
                                                </div>
                                            </div>
                                        ) : (
                                            <Badge variant="muted">Password only</Badge>
                                        )}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {user.tokens || '—'}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {user.last_sign_in_at
                                            ? formatDate(user.last_sign_in_at)
                                            : 'Never'}
                                        {user.is_stale && (
                                            <Badge variant="warning" className="ml-2">
                                                Unused
                                            </Badge>
                                        )}
                                    </TD>

                                    <TD>
                                        <Badge
                                            status={user.is_active ? 'active' : 'inactive'}
                                        />
                                    </TD>

                                    <TD>
                                        <div className="flex items-center justify-end gap-1">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => openEdit(user)}
                                            >
                                                <Pencil className="h-4 w-4" />
                                                Edit
                                            </Button>

                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setPending({ user, action: 'reset' })
                                                }
                                            >
                                                <KeyRound className="h-4 w-4" />
                                                Reset
                                            </Button>

                                            {!user.is_self && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setPending({ user, action: 'toggle' })
                                                    }
                                                >
                                                    <UserX className="h-4 w-4" />
                                                    {user.is_active ? 'Deactivate' : 'Activate'}
                                                </Button>
                                            )}
                                        </div>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            {/* Editing an account's profile.

                Renaming is allowed here and refused on the person's own
                Security screen, and that is deliberate: `renameSelf` holds
                every non-admin to the name on their employee record because
                nothing reconciles `users.name` with the 201 file, and this
                screen belongs to the administrator who *does* hold that
                ability. Where the account is linked, the employee's name is
                offered as a button rather than silently enforced. */}
            <Modal
                show={editing !== null}
                onClose={() => setEditing(null)}
                title="Edit profile"
            >
                {editing && (
                    <form onSubmit={saveProfile} className="space-y-4">
                        <Field label="Name" required error={profile.errors.name}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={profile.data.name}
                                    onChange={(event) =>
                                        profile.setData('name', event.target.value)
                                    }
                                    required
                                />
                            )}
                        </Field>

                        {editing.employee_name &&
                            editing.employee_name !== profile.data.name && (
                                <div className="flex flex-wrap items-center gap-2 rounded-md border border-warning/20 bg-warning/10 px-3 py-2 text-xs text-foreground">
                                    <TriangleAlert
                                        className="h-4 w-4 shrink-0 text-warning"
                                        aria-hidden="true"
                                    />
                                    <span className="min-w-0 flex-1">
                                        Their 201 file reads{' '}
                                        <strong className="font-medium">
                                            {editing.employee_name}
                                        </strong>
                                        . Nothing reconciles the two.
                                    </span>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            profile.setData('name', editing.employee_name)
                                        }
                                    >
                                        Use it
                                    </Button>
                                </div>
                            )}

                        <Field
                            label="Username"
                            required
                            error={profile.errors.username}
                            hint="Typed without the domain, it is stored with it — nina becomes nina@primepower.com."
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={profile.data.username}
                                    autoCapitalize="none"
                                    spellCheck={false}
                                    onChange={(event) =>
                                        profile.setData('username', event.target.value)
                                    }
                                    required
                                />
                            )}
                        </Field>

                        <Field
                            label="Personal email for sign-in codes"
                            error={profile.errors.otp_email}
                            hint={
                                otp.enabled
                                    ? `Connecting an address switches the code on for this account: after the password, a ${otp.ttl_minutes}-minute code is sent here. Leave it blank and the account signs in with a password alone.`
                                    : 'Sign-in codes are switched off system-wide (OTP_ENABLED=false), so an address here is stored and not used yet.'
                            }
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="email"
                                    value={profile.data.otp_email}
                                    autoCapitalize="none"
                                    spellCheck={false}
                                    placeholder="name@gmail.com"
                                    onChange={(event) =>
                                        profile.setData('otp_email', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        {/* The company username is *suggested* from the inbox,
                            never derived: johnpogs.b@gmail.com could reasonably
                            be johnpogs, john, or jbenavidez at work, and which
                            one a person is called is not in their email. */}
                        {profile.data.otp_email.includes('@') &&
                            profile.data.otp_email.split('@')[0] !== '' && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        profile.setData(
                                            'username',
                                            profile.data.otp_email
                                                .split('@')[0]
                                                .toLowerCase()
                                                .split(/[.+_\d]/)[0],
                                        )
                                    }
                                    className="text-xs font-medium text-primary hover:underline"
                                >
                                    Use “
                                    {
                                        profile.data.otp_email
                                            .split('@')[0]
                                            .toLowerCase()
                                            .split(/[.+_\d]/)[0]
                                    }
                                    ” as the username
                                </button>
                            )}

                        <p className="text-xs text-muted-foreground">
                            Changing the username changes what this person signs in with — tell
                            them, because nothing here is emailed. The role, the password and
                            whether the account is active are set from the row itself.
                        </p>

                        <div className="flex justify-end gap-2 pt-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditing(null)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" loading={profile.processing}>
                                Save profile
                            </Button>
                        </div>
                    </form>
                )}
            </Modal>

            {/* New account */}
            <Modal
                show={createOpen}
                onClose={() => {
                    setCreateOpen(false);
                    form.reset();
                    form.clearErrors();
                }}
                title="New Account"
                description="A temporary password and company login link are generated and emailed to the user's Gmail inbox."
                maxWidth="lg"
            >
                <form onSubmit={submit} className="space-y-4">
                    <Field
                        label="Link to Employee"
                        hint="Optional — fills the name and email from their 201 file."
                        error={form.errors.employee_id}
                    >
                        {({ id }) => (
                            <Select
                                id={id}
                                value={form.data.employee_id}
                                onChange={(event) => pickEmployee(event.target.value)}
                                placeholder="No linked employee"
                                options={unlinkedEmployees.map((employee) => ({
                                    value: employee.id,
                                    label: employee.full_name,
                                }))}
                            />
                        )}
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Name" required error={form.errors.name}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    error={form.errors.name}
                                />
                            )}
                        </Field>

                        <Field
                            label="Username"
                            hint="e.g. nina@primepower.com — leave blank to make one from the name."
                            error={form.errors.username}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.username}
                                    autoCapitalize="none"
                                    spellCheck={false}
                                    onChange={(event) =>
                                        form.setData(
                                            'username',
                                            event.target.value.toLowerCase(),
                                        )
                                    }
                                    error={form.errors.username}
                                />
                            )}
                        </Field>
                    </div>

                    <Field
                        label="Gmail / Personal Email"
                        hint="The user's Gmail where their company login email, temporary password, login link, and OTP sign-in codes will be sent."
                        error={form.errors.otp_email}
                    >
                        {({ id }) => (
                            <Input
                                id={id}
                                type="email"
                                value={form.data.otp_email}
                                placeholder="e.g. employee@gmail.com"
                                autoCapitalize="none"
                                spellCheck={false}
                                onChange={(event) =>
                                    form.setData('otp_email', event.target.value)
                                }
                                error={form.errors.otp_email}
                            />
                        )}
                    </Field>

                    {form.data.otp_email &&
                        form.data.otp_email.includes('@') &&
                        !form.data.username && (
                            <button
                                type="button"
                                onClick={() =>
                                    form.setData(
                                        'username',
                                        `${form.data.otp_email.split('@')[0].toLowerCase().split(/[.+_\d]/)[0]}@primepower.com`,
                                    )
                                }
                                className="text-xs font-medium text-primary hover:underline"
                            >
                                Use “
                                {
                                    form.data.otp_email
                                        .split('@')[0]
                                        .toLowerCase()
                                        .split(/[.+_\d]/)[0]
                                }
                                @primepower.com” as company username
                            </button>
                        )}

                    <Field label="Role" required error={form.errors.role}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={form.data.role}
                                onChange={(event) => form.setData('role', event.target.value)}
                                options={roles.map((role) => ({
                                    value: role.value,
                                    label: role.label,
                                }))}
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            variant="outline"
                            onClick={() => {
                                setCreateOpen(false);
                                form.reset();
                                form.clearErrors();
                            }}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            Create Account
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Confirm */}
            <Modal
                show={Boolean(pending)}
                onClose={() => setPending(null)}
                title={
                    pending?.action === 'reset'
                        ? 'Reset this password?'
                        : pending?.user.is_active
                          ? 'Deactivate this account?'
                          : 'Reactivate this account?'
                }
                maxWidth="md"
                footer={
                    <>
                        <Button variant="outline" onClick={() => setPending(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant={
                                pending?.action === 'toggle' && pending?.user.is_active
                                    ? 'destructive'
                                    : 'primary'
                            }
                            onClick={confirm}
                        >
                            Confirm
                        </Button>
                    </>
                }
            >
                <p className="text-sm text-muted-foreground">
                    {pending?.action === 'reset' ? (
                        <>
                            A new password is generated for{' '}
                            <span className="font-mono font-medium text-foreground">
                                {pending?.user.username}
                            </span>{' '}
                            and shown once. Their existing API tokens are revoked.
                        </>
                    ) : pending?.user.is_active ? (
                        <>
                            <span className="font-medium text-foreground">
                                {pending?.user.name}
                            </span>{' '}
                            will no longer be able to sign in, and their API tokens are revoked.
                            Their records stay untouched.
                        </>
                    ) : (
                        <>
                            <span className="font-medium text-foreground">
                                {pending?.user.name}
                            </span>{' '}
                            will be able to sign in again.
                        </>
                    )}
                </p>
            </Modal>
        </SettingsLayout>
    );
}
