import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Fingerprint, KeyRound, Plus } from 'lucide-react';
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
import { cn, formatDate } from '@/lib/utils';

const METHOD_TONE = {
    GET: 'text-success',
    POST: 'text-primary',
    PUT: 'text-warning',
    DELETE: 'text-destructive',
};

export default function Integrations({ tokens, endpoints, biometric }) {
    const [createOpen, setCreateOpen] = useState(false);

    const form = useForm({ name: '' });

    const submit = (event) => {
        event.preventDefault();

        form.post('/settings/integrations/tokens', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setCreateOpen(false);
            },
        });
    };

    return (
        <SettingsLayout title="Integrations">
            <Card>
                <CardHeader
                    title="API Tokens"
                    action={
                        <Button onClick={() => setCreateOpen(true)}>
                            <Plus className="h-4 w-4" />
                            New Token
                        </Button>
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Name</TH>
                            <TH>Abilities</TH>
                            <TH>Last Used</TH>
                            <TH>Created</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {tokens.length === 0 ? (
                            <TableEmpty
                                colSpan={5}
                                icon={KeyRound}
                                title="No tokens yet"
                                description="Create one to let a device or script reach the REST API."
                            />
                        ) : (
                            tokens.map((token) => (
                                <TR key={token.id}>
                                    <TD className="text-sm font-medium text-foreground">
                                        {token.name}
                                    </TD>
                                    <TD>
                                        <div className="flex flex-wrap gap-1">
                                            {(token.abilities ?? []).map((ability) => (
                                                <Badge key={ability} variant="muted">
                                                    {ability}
                                                </Badge>
                                            ))}
                                        </div>
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
                                                        `/settings/integrations/tokens/${token.id}`,
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

            <Card>
                <CardHeader title="Biometric Device" />
                <CardBody className="space-y-4">
                    <div className="flex items-start gap-3">
                        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                            <Fingerprint className="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div className="min-w-0 space-y-2">
                            <div>
                                <p className="text-xs uppercase tracking-wide text-muted-foreground">
                                    Push endpoint
                                </p>
                                <code className="text-xs text-foreground">
                                    POST {biometric.api_url}
                                </code>
                            </div>

                            <div>
                                <p className="text-xs uppercase tracking-wide text-muted-foreground">
                                    CSV upload
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Daily Records → Import. Expected columns:
                                </p>
                                <code className="scrollbar-thin block overflow-x-auto whitespace-pre text-xs text-foreground">
                                    {biometric.csv_columns}
                                </code>
                            </div>
                        </div>
                    </div>
                </CardBody>
            </Card>

            <Card>
                <CardHeader title="Available Endpoints" />
                <CardBody>
                    <ul className="divide-y divide-border">
                        {endpoints.map((endpoint) => (
                            <li
                                key={`${endpoint.method}-${endpoint.path}`}
                                className="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2.5 first:pt-0 last:pb-0"
                            >
                                <span
                                    className={cn(
                                        'w-14 shrink-0 text-xs font-semibold',
                                        METHOD_TONE[endpoint.method] ?? 'text-muted-foreground',
                                    )}
                                >
                                    {endpoint.method}
                                </span>
                                <code className="text-xs text-foreground">{endpoint.path}</code>
                                <span className="text-xs text-muted-foreground">
                                    {endpoint.description}
                                </span>
                            </li>
                        ))}
                    </ul>
                </CardBody>
            </Card>

            <Modal
                show={createOpen}
                onClose={() => setCreateOpen(false)}
                title="New API Token"
                description="Name it after the device or script that will use it."
                maxWidth="md"
            >
                <form onSubmit={submit} className="space-y-4">
                    <Field label="Token Name" required error={form.errors.name}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                error={form.errors.name}
                                placeholder="Biometric device — main gate"
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={() => setCreateOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            Create Token
                        </Button>
                    </div>
                </form>
            </Modal>
        </SettingsLayout>
    );
}
