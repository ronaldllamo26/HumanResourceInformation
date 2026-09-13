import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import axios from 'axios';
import {
    ArrowLeft,
    CheckCircle2,
    CircleAlert,
    Loader2,
    TriangleAlert,
    Upload,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Badge, Button, Card, CardBody, CardHeader, Field, Input } from '@/Components/ui';
import { cn } from '@/lib/utils';

/**
 * The three outcomes a row can have, and what each one means for the import.
 *
 * `warning` still creates the employee — it is the same line the rest of the
 * system draws: a missing government number does not stop someone working, it
 * stops the company filing for them, and refusing the row would keep them out
 * of the system entirely over something Compliance chases anyway.
 */
const ROW_STATES = {
    ready: { icon: CheckCircle2, tone: 'text-success', label: 'Ready' },
    warning: { icon: TriangleAlert, tone: 'text-warning', label: 'Warning' },
    error: { icon: CircleAlert, tone: 'text-destructive', label: 'Skipped' },
};

function SummaryTile({ label, value, tone }) {
    return (
        <div className="rounded-lg border border-border bg-card px-4 py-3">
            <p className="text-xs text-muted-foreground">{label}</p>
            {/* Zero drops to grey by itself — "0 errors" in red reads as a
                problem when it is the opposite. */}
            <p
                className={cn(
                    'mt-0.5 text-2xl font-semibold tabular-nums',
                    value > 0 ? tone : 'text-muted-foreground',
                )}
            >
                {value}
            </p>
        </div>
    );
}

export default function Import({ columns = [], clients = [] }) {
    const form = useForm({ file: null, commit: false });

    const [preview, setPreview] = useState(null);
    const [checking, setChecking] = useState(false);
    const [failed, setFailed] = useState(null);

    /**
     * Reads the file and reports what would happen, writing nothing.
     *
     * Over XHR rather than an Inertia visit so the chosen file survives: the
     * browser cannot re-attach a file to a re-rendered form, and a preview
     * above an empty file input is a dead end.
     */
    const check = async (file) => {
        if (!file) return;

        setChecking(true);
        setPreview(null);
        setFailed(null);

        try {
            const body = new FormData();
            body.append('file', file);

            const { data } = await axios.post('/hr/employees/import', body);
            setPreview(data);
        } catch (error) {
            setFailed(
                error.response?.data?.message ??
                    'The file could not be read. Check it is a CSV.',
            );
        } finally {
            setChecking(false);
        }
    };

    const pick = (file) => {
        form.setData('file', file);
        setPreview(null);
        check(file);
    };

    const commit = () => {
        /*
         * Set, then posted — not chained. Inertia's useForm transform() returns
         * undefined, so `.transform(...).post(...)` throws on the post rather
         * than submitting. Nothing catches it: the button simply stops working.
         */
        form.transform((data) => ({ ...data, commit: true }));

        form.post('/hr/employees/import', {
            forceFormData: true,
        });
    };

    const summary = preview?.summary;
    const canImport = (summary?.ready ?? 0) > 0;

    return (
        <AppLayout
            title="Import Employees"
            breadcrumbs={[
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Import' },
            ]}
        >
            <div className="mb-4">
                <Link
                    href="/hr/employees"
                    className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="h-4 w-4" aria-hidden="true" />
                    Back to Employees
                </Link>
            </div>

            <Card>
                <CardHeader
                    title="Upload a spreadsheet"
                    description="Nothing is created until you have seen what the file contains."
                />
                <CardBody className="space-y-4">
                    <Field
                        label="CSV file"
                        required
                        hint="In Excel: File → Save As → CSV. Up to 2,000 rows."
                        error={form.errors.file}
                    >
                        {({ id }) => (
                            <Input
                                id={id}
                                type="file"
                                accept=".csv,text/csv"
                                onChange={(event) => pick(event.target.files?.[0] ?? null)}
                                error={form.errors.file}
                            />
                        )}
                    </Field>

                    {checking && (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                            Reading the file…
                        </p>
                    )}

                    {failed && <p className="text-sm text-destructive">{failed}</p>}

                    {/* Problems with the file itself, as opposed to with a row
                        in it — a missing column stops everything, so it is said
                        once at the top rather than repeated per row. */}
                    {preview?.errors?.length > 0 && (
                        <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-3">
                            {preview.errors.map((message, index) => (
                                <p key={index} className="text-sm text-destructive">
                                    {message}
                                </p>
                            ))}
                        </div>
                    )}
                </CardBody>
            </Card>

            {summary?.total > 0 && (
                <Card className="mt-6">
                    <CardHeader
                        title="What this file would do"
                        description="Rows with an error are skipped; the rest are created."
                        action={
                            <Button
                                onClick={commit}
                                loading={form.processing}
                                disabled={!canImport}
                            >
                                <Upload className="mr-1.5 h-4 w-4" aria-hidden="true" />
                                Import {summary.ready} employee{summary.ready === 1 ? '' : 's'}
                            </Button>
                        }
                    />
                    <CardBody className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-4">
                            <SummaryTile
                                label="Rows read"
                                value={summary.total}
                                tone="text-foreground"
                            />
                            <SummaryTile
                                label="Will be created"
                                value={summary.ready}
                                tone="text-success"
                            />
                            <SummaryTile
                                label="With warnings"
                                value={summary.warnings}
                                tone="text-warning"
                            />
                            <SummaryTile
                                label="Skipped"
                                value={summary.errors}
                                tone="text-destructive"
                            />
                        </div>

                        <div className="overflow-hidden rounded-lg border border-border">
                            <ul className="divide-y divide-border">
                                {preview.rows.map((row) => {
                                    const state = ROW_STATES[row.status] ?? ROW_STATES.ready;
                                    const Icon = state.icon;

                                    return (
                                        <li
                                            key={row.line}
                                            className="flex items-start gap-3 px-3 py-2"
                                        >
                                            <Icon
                                                className={cn(
                                                    'mt-0.5 h-4 w-4 shrink-0',
                                                    state.tone,
                                                )}
                                                aria-hidden="true"
                                            />
                                            <span className="w-12 shrink-0 text-xs tabular-nums text-muted-foreground">
                                                Row {row.line}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm text-foreground">
                                                    {row.name}
                                                </span>
                                                {row.messages.length > 0 && (
                                                    <span
                                                        className={cn(
                                                            'mt-0.5 block text-xs',
                                                            row.status === 'error'
                                                                ? 'text-destructive'
                                                                : 'text-muted-foreground',
                                                        )}
                                                    >
                                                        {row.messages.join('; ')}
                                                    </span>
                                                )}
                                            </span>
                                            {row.employee_number && (
                                                <span className="shrink-0 font-mono text-xs text-muted-foreground">
                                                    {row.employee_number}
                                                </span>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    </CardBody>
                </Card>
            )}

            <Card className="mt-6">
                <CardHeader
                    title="Columns"
                    description="Header names are matched case-insensitively, and spaces work as well as underscores."
                />
                <CardBody>
                    <dl className="divide-y divide-border">
                        {columns.map((column) => (
                            <div
                                key={column.name}
                                className="flex flex-col gap-1 py-2 sm:flex-row sm:gap-4"
                            >
                                <dt className="flex shrink-0 items-center gap-2 sm:w-56">
                                    <code className="font-mono text-xs text-foreground">
                                        {column.name}
                                    </code>
                                    {column.required && (
                                        <Badge variant="destructive">Required</Badge>
                                    )}
                                </dt>
                                <dd className="min-w-0 text-xs text-muted-foreground">
                                    {column.note ?? '—'}
                                </dd>
                            </div>
                        ))}
                    </dl>

                    {clients.length > 0 && (
                        <div className="mt-4 border-t border-border pt-4">
                            <p className="text-xs text-muted-foreground">
                                Clients this file can name, by either column:
                            </p>
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {clients.map((client) => (
                                    <Badge key={client.code ?? client.name} variant="muted">
                                        {client.code ? `${client.code} · ` : ''}
                                        {client.name}
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    )}
                </CardBody>
            </Card>
        </AppLayout>
    );
}
