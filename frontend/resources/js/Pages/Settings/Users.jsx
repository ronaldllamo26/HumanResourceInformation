import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { KeyRound, Plus, ShieldCheck, UserX } from 'lucide-react';
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
import { initials } from '@/lib/utils';

export default function Users({ users, roles, unlinkedEmployees }) {
    const [createOpen, setCreateOpen] = useState(false);
    const [pending, setPending] = useState(null); // { user, action }

    const form = useForm({ employee_id: '', name: '', email: '', role: 'employee' });

    // Picking an employee fills the name and email from their 201 file.
    const pickEmployee = (employeeId) => {
        const employee = unlinkedEmployees.find(
            (candidate) => String(candidate.id) === String(employeeId),
        );

        form.setData({
            ...form.data,
            employee_id: employeeId,
            name: employee?.full_name ?? form.data.name,
            email: employee?.email ?? form.data.email,
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

    const confirm = () => {
        const url =
            pending.action === 'reset'
                ? `/settings/users/${pending.user.id}/reset-password`
                : `/settings/users/${pending.user.id}/toggle`;

        router.post(url, {}, { preserveScroll: true, onFinish: () => setPending(null) });
    };

    return (
        <SettingsLayout
            title="Users & Access"
            description="Login accounts and what each one may do. Self-registration is disabled, so accounts are only created here or from the employee form."
        >
            <Card>
                <CardHeader title="Roles" description="What each role can reach." />
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

            <Card>
                <CardHeader
                    title="Accounts"
                    description="Changing a role takes effect the next time the person loads a page."
                    action={
                        <Button onClick={() => setCreateOpen(true)}>
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
                            <TH className="text-right">Tokens</TH>
                            <TH>Status</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {users.length === 0 ? (
                            <TableEmpty colSpan={6} title="No accounts" />
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
                                                <p className="truncate text-xs text-muted-foreground">
                                                    <span className="font-mono text-foreground">
                                                        {user.username}
                                                    </span>
                                                    {user.email && <> · {user.email}</>}
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

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {user.tokens || '—'}
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

            {/* New account */}
            <Modal
                show={createOpen}
                onClose={() => setCreateOpen(false)}
                title="New Account"
                description="A temporary password is generated and shown once after saving."
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
                                    label: `${employee.full_name} — ${employee.email}`,
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

                        <Field label="Email" required error={form.errors.email}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="email"
                                    value={form.data.email}
                                    onChange={(event) =>
                                        form.setData('email', event.target.value)
                                    }
                                    error={form.errors.email}
                                />
                            )}
                        </Field>
                    </div>

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
                        <Button variant="outline" onClick={() => setCreateOpen(false)}>
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
                            <span className="font-medium text-foreground">
                                {pending?.user.email}
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
