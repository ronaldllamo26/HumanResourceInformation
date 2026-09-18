import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    ArrowRight,
    Check,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Copy,
    Eye,
    EyeOff,
    FileText,
    Inbox,
    KeyRound,
    Mail,
    MessageSquare,
    Pencil,
    Plus,
    ShieldAlert,
    ShieldCheck,
    Trash2,
    TriangleAlert,
    User,
    UserCheck,
    Users as UsersIcon,
    UserX,
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
    users = [],
    roles = [],
    unlinkedEmployees = [],
    accessReview = null,
    staleAfterDays = 90,
    otp = { enabled: true, ttl_minutes: 2 },
    is_super_admin = false,
    can_manage_requests = false,
    can_view_passwords = false,
    change_requests = [],
}) {
    const [createOpen, setCreateOpen] = useState(false);
    const [pending, setPending] = useState(null); // { user, action }
    const [activeTab, setActiveTab] = useState('accounts'); // 'accounts' | 'requests'
    const [requestFilter, setRequestFilter] = useState('pending'); // 'all', 'pending', 'approved', 'rejected'
    const [approvingRequest, setApprovingRequest] = useState(null);
    const [rejectingRequest, setRejectingRequest] = useState(null);
    const [revealedPasswords, setRevealedPasswords] = useState({});
    const [copiedId, setCopiedId] = useState(null);
    const [editingUser, setEditingUser] = useState(null);
    const [deletingUser, setDeletingUser] = useState(null);

    const showTabs = is_super_admin || can_manage_requests;

    const togglePasswordVisibility = (userId) => {
        setRevealedPasswords((prev) => ({
            ...prev,
            [userId]: !prev[userId],
        }));
    };

    const copyPassword = (userId, password) => {
        if (!password) return;
        navigator.clipboard.writeText(password);
        setCopiedId(userId);
        setTimeout(() => setCopiedId(null), 2000);
    };

    const form = useForm({
        employee_id: '',
        name: '',
        username: '',
        otp_email: '',
        role: 'employee',
    });

    const editForm = useForm({
        name: '',
        username: '',
        otp_email: '',
    });

    const deleteForm = useForm({});

    const approveForm = useForm({ admin_notes: '' });
    const rejectForm = useForm({ admin_notes: '' });

    const openEditModal = (user) => {
        setEditingUser(user);
        editForm.setData({
            name: user.name,
            username: user.username,
            otp_email: user.otp_email || '',
        });
        editForm.clearErrors();
    };

    const submitEditProfile = (e) => {
        e.preventDefault();
        if (!editingUser) return;

        editForm.put(`/settings/users/${editingUser.id}/profile`, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingUser(null);
                editForm.reset();
            },
        });
    };

    const submitDeleteUser = (e) => {
        e.preventDefault();
        if (!deletingUser) return;

        deleteForm.delete(`/settings/users/${deletingUser.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setDeletingUser(null);
            },
        });
    };

    // Picking an employee fills the name from their 201 file, suggests a username, and copies their email.
    const pickEmployee = (employeeId) => {
        if (!employeeId) {
            form.setData((prev) => ({
                ...prev,
                employee_id: '',
            }));
            return;
        }

        const employee = unlinkedEmployees.find(
            (candidate) => String(candidate.id) === String(employeeId),
        );

        if (!employee) return;

        form.setData((prev) => ({
            ...prev,
            employee_id: String(employee.id),
            name: employee.full_name || prev.name,
            username: employee.username || prev.username,
            otp_email: employee.email || prev.otp_email,
        }));
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

    const sendTestCode = (user) => {
        setPending(null);
        router.post(`/settings/users/${user.id}/otp-test`, {}, { preserveScroll: true });
    };

    const confirm = () => {
        const url =
            pending.action === 'reset'
                ? `/settings/users/${pending.user.id}/reset-password`
                : pending.action === 'impersonate'
                  ? `/settings/users/${pending.user.id}/impersonate`
                  : `/settings/users/${pending.user.id}/toggle`;

        /*
         * Impersonation is the one action here that does not come back to this
         * screen — it redirects to the dashboard as somebody else — so it must
         * not preserve scroll on a page it is leaving, and clearing `pending`
         * on finish would run against an unmounted component.
         */
        if (pending.action === 'impersonate') {
            router.post(url);

            return;
        }

        router.post(url, {}, { preserveScroll: true, onFinish: () => setPending(null) });
    };

    const submitApprove = (e) => {
        e.preventDefault();
        if (!approvingRequest) return;

        approveForm.post(`/settings/users/requests/${approvingRequest.id}/approve`, {
            preserveScroll: true,
            onSuccess: () => {
                setApprovingRequest(null);
                approveForm.reset();
            },
        });
    };

    const submitReject = (e) => {
        e.preventDefault();
        if (!rejectingRequest) return;

        rejectForm.post(`/settings/users/requests/${rejectingRequest.id}/reject`, {
            preserveScroll: true,
            onSuccess: () => {
                setRejectingRequest(null);
                rejectForm.reset();
            },
        });
    };

    const pendingRequestsCount = change_requests.filter((r) => r.status === 'pending').length;

    const filteredRequests = change_requests.filter((r) => {
        if (requestFilter === 'pending') return r.status === 'pending';
        if (requestFilter === 'approved') return r.status === 'approved';
        if (requestFilter === 'rejected') return r.status === 'rejected';
        return true;
    });

    return (
        <SettingsLayout title="Users & Access">
            {/* Tab Switcher (Super Admin & Admin) */}
            {showTabs && (
                <div className="mb-6 flex border-b border-border">
                    <button
                        type="button"
                        onClick={() => setActiveTab('accounts')}
                        className={`flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-medium transition-colors ${
                            activeTab === 'accounts'
                                ? 'border-primary text-primary'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        <UsersIcon className="h-4 w-4" />
                        <span>User Accounts</span>
                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                            {users.length}
                        </span>
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('requests')}
                        className={`flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-medium transition-colors ${
                            activeTab === 'requests'
                                ? 'border-primary text-primary'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        <Inbox className="h-4 w-4" />
                        <span>Staff Change Requests</span>
                        {pendingRequestsCount > 0 ? (
                            <span className="flex animate-pulse items-center justify-center rounded-full bg-warning/90 px-2 py-0.5 text-xs font-semibold text-warning-foreground">
                                {pendingRequestsCount} pending
                            </span>
                        ) : (
                            <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                {change_requests.length}
                            </span>
                        )}
                    </button>
                </div>
            )}

            {/* TAB 1: ACCOUNTS VIEW */}
            {(!showTabs || activeTab === 'accounts') && (
                <>
                    <Card>
                        <CardHeader title="Roles" />
                        <CardBody className="grid gap-3 sm:grid-cols-2">
                            {roles.map((role) => (
                                <div
                                    key={role.value}
                                    className="rounded-lg border border-border p-3"
                                >
                                    <div className="mb-1 flex items-center gap-2">
                                        <ShieldCheck
                                            className="h-4 w-4 text-primary"
                                            aria-hidden="true"
                                        />
                                        <p className="text-sm font-medium text-foreground">
                                            {role.label}
                                        </p>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {role.description}
                                    </p>
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
                                        Record review today
                                    </Button>
                                }
                            />
                            <CardBody className="space-y-3 text-sm">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant={accessReview.due ? 'warning' : 'success'}>
                                        {accessReview.due
                                            ? 'Review due'
                                            : 'Quarterly review current'}
                                    </Badge>
                                    <span className="text-muted-foreground">
                                        {accessReview.at
                                            ? `Last signed off by ${accessReview.by ?? 'an administrator'} on ${formatDate(accessReview.at)}.`
                                            : 'Access has not been reviewed yet.'}
                                    </span>
                                </div>
                            </CardBody>
                        </Card>
                    )}

                    <Card>
                        <CardHeader
                            title="Accounts"
                            badge={<Badge variant="muted">{users.length}</Badge>}
                            action={
                                <Button variant="primary" onClick={() => setCreateOpen(true)}>
                                    <Plus className="h-4 w-4" />
                                    Create account
                                </Button>
                            }
                        />

                        <Table>
                            <THead>
                                <TR>
                                    {/*
                                        Six columns, down from nine, and the
                                        three that went were merged rather than
                                        dropped — nothing here stopped being
                                        shown.

                                        The table needed a horizontal scrollbar
                                        to be read at all, which is the worst
                                        shape a list of accounts can take: the
                                        name scrolls out of sight exactly when
                                        you reach the buttons that act on it,
                                        so you are choosing Reset or Delete for
                                        a row you can no longer see the name
                                        of.

                                        · Employee # joined Account — it
                                          identifies the same person as the
                                          name above it.
                                        · Status joined Account for the same
                                          reason: whether the login works is a
                                          fact about the account, not a
                                          separate subject.
                                        · API tokens joined Last sign-in.
                                          Both answer "what has this account
                                          been doing", it is almost always a
                                          dash, and a whole column to print one
                                          character is the clearest possible
                                          case for merging.
                                    */}
                                    <TH>Account</TH>
                                    <TH>Role</TH>
                                    <TH>Sign-in</TH>
                                    {/*
                                        Only drawn for somebody who can read it.
                                        It said "•••••••• Confidential" to
                                        everybody else — a column spending real
                                        width to tell you that you may not use
                                        it, which is the same argument the org
                                        directory makes when it draws a plain
                                        row instead of a link nobody may follow.
                                    */}
                                    {can_view_passwords && <TH>Password</TH>}
                                    <TH>Last sign-in</TH>
                                    <TH className="text-right">Actions</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {users.length === 0 ? (
                                    <TableEmpty
                                        // Counted rather than written, because
                                        // the Password column comes and goes
                                        // with the reader — a literal would be
                                        // right for one role and wrong for the
                                        // rest, and an empty row that spans the
                                        // wrong width is a broken-looking table
                                        // on the one screen that has no data to
                                        // distract from it.
                                        colSpan={can_view_passwords ? 6 : 5}
                                        title="No accounts found"
                                        description="Create an account to grant someone login access."
                                    />
                                ) : (
                                    users.map((user) => (
                                        <TR key={user.id}>
                                            {/*
                                                Account: who they are, what
                                                they sign in as, which 201 file
                                                they are, and whether the login
                                                works — four facts about one
                                                person, which is why they are
                                                one cell rather than three
                                                columns and a badge.
                                            */}
                                            <TD>
                                                <div className="flex items-start gap-3">
                                                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                                                        {initials(user.name)}
                                                    </div>
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-2">
                                                            <p className="truncate font-medium text-foreground">
                                                                {user.name}
                                                            </p>
                                                            {/*
                                                                Only drawn when
                                                                it is off. An
                                                                always-lit
                                                                "Active" badge
                                                                on every row is
                                                                one nobody
                                                                reads — the
                                                                same reason the
                                                                credential
                                                                indicator hides
                                                                itself at zero —
                                                                and deactivated
                                                                is the state
                                                                somebody needs
                                                                to spot.
                                                            */}
                                                            {!user.is_active && (
                                                                <Badge status="inactive" />
                                                            )}
                                                        </div>
                                                        <p className="truncate font-mono text-xs text-muted-foreground">
                                                            {user.username}
                                                        </p>
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {user.employee_number ?? (
                                                                <span className="text-muted-foreground/60">
                                                                    No 201 file linked
                                                                </span>
                                                            )}
                                                        </p>
                                                    </div>
                                                </div>
                                            </TD>

                                            <TD>
                                                <Select
                                                    value={user.role}
                                                    disabled={user.is_self}
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

                                            <TD className="min-w-0 max-w-[15rem] text-sm">
                                                {user.otp_email ? (
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-1.5">
                                                            <p className="truncate font-medium text-foreground">
                                                                {user.otp_email}
                                                            </p>
                                                            {(is_super_admin ||
                                                                can_manage_requests) && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() =>
                                                                        openEditModal(user)
                                                                    }
                                                                    className="rounded p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-primary"
                                                                    title="Edit personal email"
                                                                >
                                                                    <Pencil className="h-3.5 w-3.5" />
                                                                </button>
                                                            )}
                                                        </div>
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
                                                                onClick={() =>
                                                                    sendTestCode(user)
                                                                }
                                                                className="rounded px-1 py-0.5 text-xs font-medium text-primary hover:bg-primary/10"
                                                            >
                                                                Send test code
                                                            </button>
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <div className="flex items-center gap-1.5">
                                                        <Badge variant="muted">
                                                            Password only
                                                        </Badge>
                                                        {(is_super_admin ||
                                                            can_manage_requests) && (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    openEditModal(user)
                                                                }
                                                                className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium text-primary hover:bg-primary/10"
                                                            >
                                                                <Pencil className="h-3 w-3" />
                                                                Add email
                                                            </button>
                                                        )}
                                                    </div>
                                                )}
                                            </TD>

                                            {/*
                                                The whole column is drawn only
                                                for a super administrator, not
                                                a cell reading "Confidential"
                                                for everybody else. A column
                                                whose every row says "you may
                                                not see this" is width spent
                                                telling most readers something
                                                they cannot act on, and the
                                                header already says what it is.

                                                The header is guarded by the
                                                same flag, so the count of
                                                columns cannot part company
                                                with the count of cells — and
                                                `TableEmpty`'s colSpan reads it
                                                too.
                                            */}
                                            {can_view_passwords && (
                                                <TD className="whitespace-nowrap text-sm">
                                                    <div className="flex items-center gap-1.5 font-mono">
                                                        <span className="min-w-[70px] text-xs">
                                                            {revealedPasswords[user.id]
                                                                ? user.password_plain || '—'
                                                                : '••••••••'}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                togglePasswordVisibility(
                                                                    user.id,
                                                                )
                                                            }
                                                            className="rounded p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                                            title={
                                                                revealedPasswords[user.id]
                                                                    ? 'Hide password'
                                                                    : 'Show password'
                                                            }
                                                            aria-label={
                                                                revealedPasswords[user.id]
                                                                    ? 'Hide password'
                                                                    : 'Show password'
                                                            }
                                                        >
                                                            {revealedPasswords[user.id] ? (
                                                                <EyeOff className="h-3.5 w-3.5" />
                                                            ) : (
                                                                <Eye className="h-3.5 w-3.5" />
                                                            )}
                                                        </button>
                                                        {user.password_plain && (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    copyPassword(
                                                                        user.id,
                                                                        user.password_plain,
                                                                    )
                                                                }
                                                                className="rounded p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                                                title="Copy password"
                                                                aria-label="Copy password"
                                                            >
                                                                {copiedId === user.id ? (
                                                                    <Check className="h-3.5 w-3.5 text-emerald-600" />
                                                                ) : (
                                                                    <Copy className="h-3.5 w-3.5" />
                                                                )}
                                                            </button>
                                                        )}
                                                    </div>
                                                </TD>
                                            )}

                                            {/*
                                                Last sign-in, with the token
                                                count folded underneath it.
                                                Both answer what this account
                                                has been doing, and the count
                                                is a dash on almost every row —
                                                a column of its own was width
                                                spent printing one character.
                                                Drawn only when there are any,
                                                for the same reason the status
                                                badge is drawn only when the
                                                account is off.
                                            */}
                                            <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                                <div className="flex items-center gap-2">
                                                    <span>
                                                        {user.last_sign_in_at
                                                            ? formatDate(user.last_sign_in_at)
                                                            : 'Never'}
                                                    </span>
                                                    {user.is_stale && (
                                                        <Badge variant="warning">Unused</Badge>
                                                    )}
                                                </div>
                                                {user.tokens > 0 && (
                                                    <p className="mt-0.5 text-xs tabular-nums">
                                                        {user.tokens} API{' '}
                                                        {user.tokens === 1 ? 'token' : 'tokens'}
                                                    </p>
                                                )}
                                            </TD>

                                            {/*
                                                Icons rather than five labelled
                                                buttons, and this was the single
                                                biggest reason the table needed
                                                a horizontal scrollbar: Edit,
                                                Reset, View as, Deactivate and
                                                Delete spelled out came to
                                                roughly 450px of one row, more
                                                than the name, username and
                                                employee number put together.

                                                Every one keeps a `title` and an
                                                `aria-label`, so the label is a
                                                hover away and a screen reader
                                                still hears a sentence rather
                                                than an icon name. This is the
                                                one place in the system that
                                                departs from the `hidden
                                                sm:inline` rule for button
                                                labels — that rule is for a
                                                topbar with three actions, and
                                                five in a table cell do not fit
                                                at any width.

                                                Delete keeps its destructive
                                                tint. An icon-only button that
                                                deletes an account needs to
                                                *look* different from the four
                                                beside it, and the confirmation
                                                modal is still the thing that
                                                actually prevents the mistake.
                                            */}
                                            <TD>
                                                <div className="flex items-center justify-end gap-0.5">
                                                    {(is_super_admin ||
                                                        can_manage_requests) && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() => openEditModal(user)}
                                                            title="Edit profile and personal email"
                                                            aria-label={`Edit ${user.name}`}
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                        </Button>
                                                    )}

                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setPending({
                                                                user,
                                                                action: 'reset',
                                                            })
                                                        }
                                                        title="Reset password"
                                                        aria-label={`Reset the password for ${user.name}`}
                                                    >
                                                        <KeyRound className="h-4 w-4" />
                                                    </Button>

                                                    {is_super_admin &&
                                                        !user.is_self &&
                                                        user.is_active &&
                                                        user.role !== 'super_admin' && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    setPending({
                                                                        user,
                                                                        action: 'impersonate',
                                                                    })
                                                                }
                                                                title="View the system as this person"
                                                                aria-label={`View the system as ${user.name}`}
                                                            >
                                                                <UserCheck className="h-4 w-4" />
                                                            </Button>
                                                        )}

                                                    {!user.is_self && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setPending({
                                                                    user,
                                                                    action: 'toggle',
                                                                })
                                                            }
                                                            title={
                                                                user.is_active
                                                                    ? 'Deactivate this account'
                                                                    : 'Reactivate this account'
                                                            }
                                                            aria-label={`${user.is_active ? 'Deactivate' : 'Reactivate'} ${user.name}`}
                                                        >
                                                            <UserX className="h-4 w-4" />
                                                        </Button>
                                                    )}

                                                    {is_super_admin && !user.is_self && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                            onClick={() =>
                                                                setDeletingUser(user)
                                                            }
                                                            title="Terminate / Archive this account"
                                                            aria-label={`Terminate and archive ${user.name}`}
                                                        >
                                                            <Trash2 className="h-4 w-4" />
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
                </>
            )}

            {/* TAB 2: CHANGE REQUESTS DASHBOARD (SUPER ADMIN & ADMIN) */}
            {showTabs && activeTab === 'requests' && (
                <div className="space-y-6">
                    <Card>
                        <CardHeader
                            title="Staff Account Change Requests"
                            description="Review and decide requests from staff to change their company username or personal MFA email. Approving applies changes immediately."
                            badge={
                                <div className="flex items-center gap-1.5">
                                    <Badge
                                        variant={pendingRequestsCount > 0 ? 'warning' : 'muted'}
                                    >
                                        {pendingRequestsCount} Pending
                                    </Badge>
                                </div>
                            }
                        />
                        <CardBody className="border-b border-border pb-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="mr-1 text-xs font-medium text-muted-foreground">
                                    Filter:
                                </span>
                                {[
                                    {
                                        key: 'pending',
                                        label: 'Pending Review',
                                        count: pendingRequestsCount,
                                    },
                                    {
                                        key: 'all',
                                        label: 'All Requests',
                                        count: change_requests.length,
                                    },
                                    {
                                        key: 'approved',
                                        label: 'Approved',
                                        count: change_requests.filter(
                                            (r) => r.status === 'approved',
                                        ).length,
                                    },
                                    {
                                        key: 'rejected',
                                        label: 'Rejected',
                                        count: change_requests.filter(
                                            (r) => r.status === 'rejected',
                                        ).length,
                                    },
                                ].map((tab) => (
                                    <button
                                        key={tab.key}
                                        type="button"
                                        onClick={() => setRequestFilter(tab.key)}
                                        className={`rounded-full px-3 py-1 text-xs font-medium transition-colors ${
                                            requestFilter === tab.key
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-muted text-muted-foreground hover:bg-muted/80'
                                        }`}
                                    >
                                        {tab.label} ({tab.count})
                                    </button>
                                ))}
                            </div>
                        </CardBody>

                        <Table>
                            <THead>
                                <TR>
                                    <TH>Staff Member</TH>
                                    <TH>Requested Changes</TH>
                                    <TH className="w-1/3">Staff Notes / Reason</TH>
                                    <TH>Date Submitted</TH>
                                    <TH>Status</TH>
                                    <TH className="text-right">Decision / Action</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {filteredRequests.length === 0 ? (
                                    <TableEmpty
                                        colSpan={6}
                                        title="No change requests found"
                                        description={
                                            requestFilter === 'pending'
                                                ? 'There are no pending credential change requests awaiting review.'
                                                : 'No requests match the selected filter.'
                                        }
                                    />
                                ) : (
                                    filteredRequests.map((req) => (
                                        <TR key={req.id}>
                                            <TD>
                                                <div className="flex items-center gap-2.5">
                                                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                                                        {initials(req.staff_name)}
                                                    </div>
                                                    <div>
                                                        <p className="font-medium text-foreground">
                                                            {req.staff_name}
                                                        </p>
                                                        <p className="font-mono text-xs text-muted-foreground">
                                                            ID #{req.user_id}
                                                        </p>
                                                    </div>
                                                </div>
                                            </TD>

                                            <TD>
                                                <div className="space-y-1.5 text-xs">
                                                    {req.requested_username && (
                                                        <div className="rounded bg-muted/50 p-1.5">
                                                            <div className="text-[10px] uppercase tracking-wider text-muted-foreground">
                                                                Username
                                                            </div>
                                                            <div className="flex items-center gap-1.5 font-mono">
                                                                <span className="text-muted-foreground line-through">
                                                                    {req.current_username}
                                                                </span>
                                                                <ArrowRight className="h-3 w-3 shrink-0 text-primary" />
                                                                <span className="font-medium text-foreground">
                                                                    {req.requested_username}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    )}

                                                    {req.requested_email && (
                                                        <div className="rounded bg-muted/50 p-1.5">
                                                            <div className="text-[10px] uppercase tracking-wider text-muted-foreground">
                                                                Personal MFA Email
                                                            </div>
                                                            <div className="flex items-center gap-1.5 font-mono">
                                                                <span className="text-muted-foreground line-through">
                                                                    {req.current_email ||
                                                                        'None'}
                                                                </span>
                                                                <ArrowRight className="h-3 w-3 shrink-0 text-emerald-600" />
                                                                <span className="font-medium text-emerald-600 dark:text-emerald-400">
                                                                    {req.requested_email}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    )}
                                                </div>
                                            </TD>

                                            <TD>
                                                <div className="rounded-lg border border-border/60 bg-muted/30 p-2.5 text-xs text-foreground">
                                                    <div className="mb-1 flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground">
                                                        <MessageSquare className="h-3 w-3" />
                                                        Staff Explanation:
                                                    </div>
                                                    <p className="whitespace-pre-wrap italic">
                                                        "{req.staff_notes}"
                                                    </p>
                                                </div>
                                                {req.admin_notes && (
                                                    <div className="mt-2 rounded-lg border border-primary/20 bg-primary/5 p-2 text-xs">
                                                        <span className="font-medium text-foreground">
                                                            Admin Note:
                                                        </span>{' '}
                                                        <span className="text-muted-foreground">
                                                            {req.admin_notes}
                                                        </span>
                                                    </div>
                                                )}
                                            </TD>

                                            <TD className="whitespace-nowrap text-xs text-muted-foreground">
                                                {formatDate(req.created_at)}
                                            </TD>

                                            <TD>
                                                {req.status === 'pending' && (
                                                    <Badge variant="warning">
                                                        Pending Review
                                                    </Badge>
                                                )}
                                                {req.status === 'approved' && (
                                                    <div className="space-y-0.5">
                                                        <Badge variant="success">
                                                            Approved
                                                        </Badge>
                                                        {req.decided_by && (
                                                            <p className="text-[10px] text-muted-foreground">
                                                                by {req.decided_by}
                                                            </p>
                                                        )}
                                                    </div>
                                                )}
                                                {req.status === 'rejected' && (
                                                    <div className="space-y-0.5">
                                                        <Badge variant="destructive">
                                                            Rejected
                                                        </Badge>
                                                        {req.decided_by && (
                                                            <p className="text-[10px] text-muted-foreground">
                                                                by {req.decided_by}
                                                            </p>
                                                        )}
                                                    </div>
                                                )}
                                            </TD>

                                            <TD>
                                                {req.status === 'pending' ? (
                                                    <div className="flex items-center justify-end gap-1.5">
                                                        <Button
                                                            size="sm"
                                                            variant="primary"
                                                            onClick={() => {
                                                                approveForm.setData({
                                                                    admin_notes: '',
                                                                });
                                                                approveForm.clearErrors();
                                                                setApprovingRequest(req);
                                                            }}
                                                        >
                                                            <CheckCircle2 className="h-3.5 w-3.5" />
                                                            Approve
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                            onClick={() => {
                                                                rejectForm.setData({
                                                                    admin_notes: '',
                                                                });
                                                                rejectForm.clearErrors();
                                                                setRejectingRequest(req);
                                                            }}
                                                        >
                                                            <XCircle className="h-3.5 w-3.5" />
                                                            Reject
                                                        </Button>
                                                    </div>
                                                ) : (
                                                    <div className="text-right text-xs text-muted-foreground">
                                                        Processed on{' '}
                                                        {formatDate(req.decided_at)}
                                                    </div>
                                                )}
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </Card>
                </div>
            )}

            {/* Modal: Approve Request */}
            <Modal
                show={approvingRequest !== null}
                onClose={() => setApprovingRequest(null)}
                title="Approve Account Change Request"
                maxWidth="md"
            >
                {approvingRequest && (
                    <form onSubmit={submitApprove} className="space-y-4">
                        <div className="space-y-2 rounded-lg border border-primary/20 bg-primary/5 p-3 text-sm">
                            <p className="font-medium text-foreground">
                                You are approving credential changes for{' '}
                                <strong className="text-primary">
                                    {approvingRequest.staff_name}
                                </strong>
                                :
                            </p>
                            <ul className="list-disc space-y-1 pl-5 text-xs text-muted-foreground">
                                {approvingRequest.requested_username && (
                                    <li>
                                        Username will change from{' '}
                                        <code className="font-semibold text-foreground">
                                            {approvingRequest.current_username}
                                        </code>{' '}
                                        to{' '}
                                        <code className="font-semibold text-primary">
                                            {approvingRequest.requested_username}
                                        </code>
                                    </li>
                                )}
                                {approvingRequest.requested_email && (
                                    <li>
                                        MFA/OTP Email will change from{' '}
                                        <code className="font-semibold text-foreground">
                                            {approvingRequest.current_email || 'None'}
                                        </code>{' '}
                                        to{' '}
                                        <code className="font-semibold text-emerald-600">
                                            {approvingRequest.requested_email}
                                        </code>
                                    </li>
                                )}
                            </ul>
                            <p className="pt-1 text-xs text-muted-foreground">
                                Staff reason: "{approvingRequest.staff_notes}"
                            </p>
                        </div>

                        <Field
                            label="Admin Notes / Feedback (Optional)"
                            hint="These notes will be visible to the staff member in their request history."
                            error={approveForm.errors.admin_notes}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={approveForm.data.admin_notes}
                                    placeholder="e.g. Approved. Please use your new email for MFA verification on your next sign-in."
                                    onChange={(e) =>
                                        approveForm.setData('admin_notes', e.target.value)
                                    }
                                />
                            )}
                        </Field>

                        <div className="flex justify-end gap-2 pt-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setApprovingRequest(null)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" loading={approveForm.processing}>
                                Approve & Apply Changes
                            </Button>
                        </div>
                    </form>
                )}
            </Modal>

            {/* Modal: Reject Request */}
            <Modal
                show={rejectingRequest !== null}
                onClose={() => setRejectingRequest(null)}
                title="Reject Account Change Request"
                maxWidth="md"
            >
                {rejectingRequest && (
                    <form onSubmit={submitReject} className="space-y-4">
                        <div className="space-y-1 rounded-lg border border-destructive/20 bg-destructive/5 p-3 text-sm">
                            <p className="font-medium text-destructive">
                                Reject request from {rejectingRequest.staff_name}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Staff reason was: "{rejectingRequest.staff_notes}"
                            </p>
                        </div>

                        <Field
                            label="Rejection Reason / Notes"
                            required
                            hint="Please explain to the staff member why their request was rejected."
                            error={rejectForm.errors.admin_notes}
                        >
                            {({ id }) => (
                                <textarea
                                    id={id}
                                    rows={3}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm placeholder:text-muted-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    value={rejectForm.data.admin_notes}
                                    placeholder="e.g. Please provide a verified personal Gmail address under your official name."
                                    onChange={(e) =>
                                        rejectForm.setData('admin_notes', e.target.value)
                                    }
                                    required
                                />
                            )}
                        </Field>

                        <div className="flex justify-end gap-2 pt-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setRejectingRequest(null)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                loading={rejectForm.processing}
                            >
                                Confirm Rejection
                            </Button>
                        </div>
                    </form>
                )}
            </Modal>

            {/* Create account modal */}
            <Modal
                show={createOpen}
                onClose={() => {
                    setCreateOpen(false);
                    form.reset();
                    form.clearErrors();
                }}
                title="Create Account"
                description="A temporary password and company login link are generated and emailed to the user's Gmail inbox."
                maxWidth="lg"
            >
                <form onSubmit={submit} className="space-y-4">
                    <Field label="Link to Employee" error={form.errors.employee_id}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={form.data.employee_id}
                                onChange={(event) => pickEmployee(event.target.value)}
                                placeholder="No linked employee"
                                options={unlinkedEmployees.map((employee) => ({
                                    value: String(employee.id),
                                    label: employee.employee_number
                                        ? `${employee.full_name} (${employee.employee_number})`
                                        : employee.full_name,
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
                                        `${
                                            form.data.otp_email
                                                .split('@')[0]
                                                .toLowerCase()
                                                .split(/[.+_\d]/)[0]
                                        }@primepower.com`,
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

            {/* Action Confirmation (Reset Password / Toggle Active) */}
            <Modal
                show={Boolean(pending)}
                onClose={() => setPending(null)}
                title={
                    pending?.action === 'reset'
                        ? 'Reset this password?'
                        : pending?.action === 'impersonate'
                          ? `View the system as ${pending?.user.name}?`
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
                    {pending?.action === 'impersonate' ? (
                        <>
                            You will be signed in as{' '}
                            <span className="font-medium text-foreground">
                                {pending?.user.name}
                            </span>{' '}
                            and see exactly what they see. Every action is recorded against your
                            own account, and you cannot change their password or username while
                            viewing as them. A banner stays on screen until you stop.
                        </>
                    ) : pending?.action === 'reset' ? (
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

            {/* Edit User Profile & Email Modal (Super Admin & Admin) */}
            <Modal
                show={Boolean(editingUser)}
                onClose={() => {
                    setEditingUser(null);
                    editForm.reset();
                    editForm.clearErrors();
                }}
                title={`Edit Profile & Email: ${editingUser?.name || ''}`}
                maxWidth="md"
            >
                <form onSubmit={submitEditProfile} className="space-y-4">
                    <Field label="Full Name" required error={editForm.errors.name}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={editForm.data.name}
                                onChange={(e) => editForm.setData('name', e.target.value)}
                                error={editForm.errors.name}
                            />
                        )}
                    </Field>

                    <Field label="Company Username" required error={editForm.errors.username}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={editForm.data.username}
                                onChange={(e) => editForm.setData('username', e.target.value)}
                                error={editForm.errors.username}
                            />
                        )}
                    </Field>

                    <Field
                        label="Personal MFA Email (OTP)"
                        description="Sign-in OTP codes and provisional credentials will be sent to this email address."
                        error={editForm.errors.otp_email}
                    >
                        {({ id }) => (
                            <Input
                                id={id}
                                type="email"
                                value={editForm.data.otp_email}
                                placeholder="e.g. employee@gmail.com"
                                onChange={(e) => editForm.setData('otp_email', e.target.value)}
                                error={editForm.errors.otp_email}
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setEditingUser(null);
                                editForm.reset();
                                editForm.clearErrors();
                            }}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" loading={editForm.processing}>
                            Save Changes
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Terminate & Archive Account Confirmation Modal (Super Admin Only) */}
            <Modal
                show={Boolean(deletingUser)}
                onClose={() => setDeletingUser(null)}
                title="Terminate & Archive Account"
                maxWidth="md"
                footer={
                    <>
                        <Button variant="outline" onClick={() => setDeletingUser(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            loading={deleteForm.processing}
                            onClick={submitDeleteUser}
                        >
                            Terminate & Archive
                        </Button>
                    </>
                }
            >
                <div className="space-y-3">
                    <div className="flex items-start gap-3 rounded-lg border border-destructive/25 bg-destructive/10 p-3 text-sm text-destructive">
                        <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0 text-destructive" />
                        <div>
                            <strong className="font-semibold">
                                Note: Account & Profile will be Archived
                            </strong>
                            <p className="mt-1 text-xs opacity-90">
                                Terminating this account will revoke sign-in access and move the
                                account to the Archive Module. If linked to an employee profile,
                                their profile will also be archived and removed from the active
                                Employee Directory. Both can be restored from the Archive module
                                at any time.
                            </p>
                        </div>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Are you sure you want to terminate and archive the user account for{' '}
                        <span className="font-semibold text-foreground">
                            {deletingUser?.name}
                        </span>{' '}
                        (
                        <span className="font-mono text-foreground">
                            {deletingUser?.username}
                        </span>
                        )?
                    </p>
                </div>
            </Modal>
        </SettingsLayout>
    );
}
