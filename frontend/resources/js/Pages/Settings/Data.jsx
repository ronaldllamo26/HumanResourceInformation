import { useForm } from '@inertiajs/react';
import { Database, Download, Info } from 'lucide-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Badge, Button, Card, CardBody, CardHeader, Field, Input } from '@/Components/ui';

export default function Data({ database, counts, settings, exports }) {
    const form = useForm({
        data: { audit_retention_days: settings['data.audit_retention_days'] ?? 365 },
    });

    const submit = (event) => {
        event.preventDefault();
        form.put('/settings/data', { preserveScroll: true });
    };

    return (
        <SettingsLayout title="Data & Backup">
            <Card>
                <CardHeader title="Database" />
                <CardBody>
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                            <Database className="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium text-foreground">
                                {database.driver}
                                <Badge variant="muted" className="ml-2">
                                    {database.name}
                                </Badge>
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {database.is_sqlite
                                    ? 'A single file — back it up by copying database/database.sqlite.'
                                    : 'Back up with your database server’s own dump tooling.'}
                            </p>
                        </div>
                    </div>

                    {database.is_sqlite && (
                        <div className="mt-4 flex items-start gap-3 rounded-lg border border-warning/30 bg-warning/10 px-4 py-3">
                            <Info
                                className="mt-0.5 h-4.5 w-4.5 shrink-0 text-warning"
                                aria-hidden="true"
                            />
                            <p className="text-sm text-foreground">
                                This project targets PostgreSQL. Swap the{' '}
                                <code className="text-xs">DB_*</code> values in{' '}
                                <code className="text-xs">.env</code> and run{' '}
                                <code className="text-xs">
                                    php artisan migrate:fresh --seed
                                </code>{' '}
                                to move over.
                            </p>
                        </div>
                    )}
                </CardBody>
            </Card>

            <Card>
                <CardHeader title="Records" />
                <CardBody>
                    <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {counts.map((row) => (
                            <div
                                key={row.label}
                                className="rounded-lg border border-border px-3 py-2.5"
                            >
                                <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">
                                    {row.label}
                                </dt>
                                <dd className="mt-0.5 text-lg font-semibold tabular-nums text-foreground">
                                    {row.count.toLocaleString()}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </CardBody>
            </Card>

            <Card>
                <CardHeader title="Exports" />
                <CardBody>
                    <ul className="divide-y divide-border">
                        {exports.map((item) => (
                            <li
                                key={item.href}
                                className="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <span className="text-sm text-foreground">{item.label}</span>
                                <Button size="sm" variant="outline" external href={item.href}>
                                    <Download className="h-4 w-4" />
                                    Download CSV
                                </Button>
                            </li>
                        ))}
                    </ul>
                </CardBody>
            </Card>

            <Card>
                <CardHeader title="Retention" />
                <CardBody>
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-4">
                        <Field
                            label="Audit log retention (days)"
                            required
                            className="w-48"
                            error={form.errors['data.audit_retention_days']}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    min="30"
                                    max="3650"
                                    value={form.data.data.audit_retention_days}
                                    onChange={(event) =>
                                        form.setData('data', {
                                            audit_retention_days: event.target.value,
                                        })
                                    }
                                    error={form.errors['data.audit_retention_days']}
                                />
                            )}
                        </Field>

                        <Button type="submit" loading={form.processing}>
                            Save
                        </Button>
                    </form>

                    <p className="mt-3 text-xs text-muted-foreground">
                        Setting the value records the policy. Automatic pruning runs on a
                        schedule, which is not wired up yet.
                    </p>
                </CardBody>
            </Card>
        </SettingsLayout>
    );
}
