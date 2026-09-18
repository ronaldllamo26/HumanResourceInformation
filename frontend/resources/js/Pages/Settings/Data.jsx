import { Link, useForm } from '@inertiajs/react';
import { Database, Download, Info } from 'lucide-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Badge, Button, Card, CardBody, CardHeader, Field, Input } from '@/Components/ui';

export default function Data({ database, counts, settings, exports, can }) {
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
                                {/* Local or cloud — the thing somebody opening
                                    this screen on a deployment most needs to be
                                    sure of before they press anything, and the
                                    one fact the card used to leave out. `info`
                                    rather than `success` for a remote one:
                                    where the database lives is a state, not a
                                    pass. */}
                                <Badge
                                    variant={database.placement === 'local' ? 'muted' : 'info'}
                                    className="ml-2"
                                >
                                    {database.placement === 'local'
                                        ? 'This machine'
                                        : 'Remote server'}
                                </Badge>
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {database.host
                                    ? `${database.host}${database.port ? `:${database.port}` : ''}`
                                    : 'No host configured'}
                            </p>
                        </div>

                        {/* Drawn only for somebody who may press it. A dump
                            carries every record in the company, so it is super
                            administrator only while the rest of this screen is
                            the administrator's. */}
                        {can?.backupDatabase && database.backup_available && (
                            /* `href` + `external` rather than an Inertia visit,
                               the same shape the audit log's CSV button uses:
                               the response is a file, and an Inertia POST hands
                               the binary to the page renderer instead of the
                               browser's download manager. */
                            <Button
                                variant="secondary"
                                href="/settings/data/backup"
                                external
                                title="Download a full backup of this database"
                            >
                                <Download className="h-4 w-4" />
                                Download backup
                            </Button>
                        )}
                    </div>

                    {/* Why the button cannot work, in words that say who fixes
                        it: an unsupported driver is a decision in `.env`, a
                        missing binary is something to install on the server.
                        "Backup unavailable" would send both of those people
                        looking in the wrong place. */}
                    {can?.backupDatabase && database.unavailable_reason && (
                        <div className="mt-4 flex items-start gap-3 rounded-lg border border-border bg-muted/50 px-4 py-3">
                            <Info
                                className="mt-0.5 h-4.5 w-4.5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <p className="text-sm text-muted-foreground">
                                {database.unavailable_reason}
                            </p>
                        </div>
                    )}

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
                    {/* Every figure opens the rows it counted, which is the
                        rule the dashboard tiles already follow and this card
                        was quietly breaking: a count of 1,247 payslips that
                        cannot be opened has raised a question and then refused
                        to answer it.

                        A real `<a>` rather than an onClick, for the same
                        reasons `StatTile` uses one — middle-click opens a tab,
                        the status bar says where it goes, and a keyboard
                        reaches it. */}
                    <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {counts.map((row) => {
                            const figure = (
                                <>
                                    <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">
                                        {row.label}
                                    </dt>
                                    <dd className="mt-0.5 text-lg font-semibold tabular-nums text-foreground">
                                        {row.count.toLocaleString()}
                                    </dd>
                                </>
                            );

                            return row.href ? (
                                <Link
                                    key={row.label}
                                    href={row.href}
                                    className="group rounded-lg border border-border px-3 py-2.5 transition-colors hover:border-primary/40 hover:bg-secondary/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                >
                                    {figure}
                                </Link>
                            ) : (
                                <div
                                    key={row.label}
                                    className="rounded-lg border border-border px-3 py-2.5"
                                >
                                    {figure}
                                </div>
                            );
                        })}
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
                </CardBody>
            </Card>
        </SettingsLayout>
    );
}
