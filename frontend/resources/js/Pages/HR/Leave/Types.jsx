import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FileText, Paperclip, Plus } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
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
    Textarea,
} from '@/Components/ui';

const BLANK_TYPE = {
    code: '',
    name: '',
    description: '',
    default_credits: 0,
    is_paid: true,
    requires_attachment: false,
    is_convertible_to_cash: false,
    max_consecutive_days: '',
    min_days_notice: 0,
    is_active: true,
};

export default function Types({ types, can }) {
    const [creating, setCreating] = useState(false);

    const form = useForm(BLANK_TYPE);

    const open = () => {
        form.clearErrors();
        form.setData(BLANK_TYPE);
        setCreating(true);
    };

    const submit = (event) => {
        event.preventDefault();

        form.post('/hr/leave/types', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setCreating(false);
            },
        });
    };

    const toggles = [
        ['is_paid', 'Paid leave', 'Unpaid types never touch the credit ledger.'],
        ['requires_attachment', 'Requires attachment', 'e.g. a medical certificate.'],
        ['is_convertible_to_cash', 'Convertible to cash', 'Unused credits can be paid out.'],
        ['is_active', 'Active', 'Inactive types cannot be filed against.'],
    ];

    return (
        <AppLayout
            title="Leave & Absence"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Leave', href: '/hr/leave' },
                { label: 'Leave Types' },
            ]}
        >
            <Card>
                <CardHeader
                    title="Leave Types"
                    description="The catalogue employees file against, and the entitlement each one carries."
                    action={
                        can.manage && (
                            <Button size="sm" onClick={open}>
                                <Plus className="h-4 w-4" />
                                New Type
                            </Button>
                        )
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Code</TH>
                            <TH>Name</TH>
                            <TH className="text-right">Default Credits</TH>
                            <TH className="text-right">Notice</TH>
                            <TH className="text-right">Filed</TH>
                            <TH>Flags</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {types.length === 0 ? (
                            <TableEmpty
                                colSpan={6}
                                icon={FileText}
                                title="No leave types"
                                description="Add at least one type before employees can file leave."
                            />
                        ) : (
                            types.map((type) => (
                                <TR key={type.id}>
                                    <TD>
                                        <Badge variant={type.is_paid ? 'primary' : 'muted'}>
                                            {type.code}
                                        </Badge>
                                    </TD>

                                    <TD>
                                        <p className="text-sm font-medium text-foreground">
                                            {type.name}
                                        </p>
                                        {type.description && (
                                            <p className="max-w-sm truncate text-xs text-muted-foreground">
                                                {type.description}
                                            </p>
                                        )}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-foreground">
                                        {type.default_credits || '—'}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {type.min_days_notice
                                            ? `${type.min_days_notice}d`
                                            : '—'}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {type.requests_count}
                                    </TD>

                                    <TD>
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            {!type.is_paid && (
                                                <Badge variant="muted">Unpaid</Badge>
                                            )}
                                            {type.requires_attachment && (
                                                <span
                                                    title="Requires an attachment"
                                                    className="text-muted-foreground"
                                                >
                                                    <Paperclip className="h-3.5 w-3.5" />
                                                </span>
                                            )}
                                            {!type.is_active && (
                                                <Badge status="inactive">Inactive</Badge>
                                            )}
                                        </div>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            {/* Type form */}
            <Modal
                show={creating}
                onClose={() => setCreating(false)}
                title="New Leave Type"
                maxWidth="2xl"
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Code" required error={form.errors.code}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.code}
                                    onChange={(event) =>
                                        form.setData('code', event.target.value)
                                    }
                                    error={form.errors.code}
                                    placeholder="SL"
                                />
                            )}
                        </Field>

                        <Field
                            label="Name"
                            required
                            className="sm:col-span-2"
                            error={form.errors.name}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    error={form.errors.name}
                                    placeholder="Sick Leave"
                                />
                            )}
                        </Field>
                    </div>

                    <Field label="Description" error={form.errors.description}>
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={2}
                                value={form.data.description ?? ''}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field
                            label="Default Credits"
                            required
                            hint="Days granted per year"
                            error={form.errors.default_credits}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    step="0.5"
                                    min="0"
                                    value={form.data.default_credits}
                                    onChange={(event) =>
                                        form.setData('default_credits', event.target.value)
                                    }
                                    error={form.errors.default_credits}
                                />
                            )}
                        </Field>

                        <Field
                            label="Max Consecutive Days"
                            hint="Blank for no limit"
                            error={form.errors.max_consecutive_days}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    min="1"
                                    value={form.data.max_consecutive_days}
                                    onChange={(event) =>
                                        form.setData('max_consecutive_days', event.target.value)
                                    }
                                    error={form.errors.max_consecutive_days}
                                />
                            )}
                        </Field>

                        <Field
                            label="Days Notice"
                            required
                            hint="Advance filing required"
                            error={form.errors.min_days_notice}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    min="0"
                                    value={form.data.min_days_notice}
                                    onChange={(event) =>
                                        form.setData('min_days_notice', event.target.value)
                                    }
                                    error={form.errors.min_days_notice}
                                />
                            )}
                        </Field>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        {toggles.map(([field, label, hint]) => (
                            <label key={field} className="flex items-start gap-2.5">
                                <input
                                    type="checkbox"
                                    checked={Boolean(form.data[field])}
                                    onChange={(event) =>
                                        form.setData(field, event.target.checked)
                                    }
                                    className="mt-0.5 h-4 w-4 rounded border-input text-primary focus:ring-ring/30"
                                />
                                <span>
                                    <span className="block text-sm text-foreground">
                                        {label}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {hint}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setCreating(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            Save Type
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
