import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle2, Circle, Lock, RefreshCw, ShieldCheck } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardFooter,
    CardHeader,
    Field,
    Input,
    Modal,
} from '@/Components/ui';
import { formatCurrency, formatDate } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

const formatDateTime = (value) => formatDate(value, { hour: '2-digit', minute: '2-digit' });

const STATUS_VARIANT = {
    draft: 'warning',
    cleared: 'primary',
    released: 'success',
};

export default function Separation({ separation, can }) {
    const [releasing, setReleasing] = useState(false);

    const form = useForm({
        days_unpaid: '',
        other_deductions: String(separation.other_deductions || ''),
    });

    const recompute = (event) => {
        event.preventDefault();
        form.post(`/hr/payroll/separations/${separation.id}/recompute`, {
            preserveScroll: true,
        });
    };

    const toggle = (key, cleared) =>
        router.post(
            `/hr/payroll/separations/${separation.id}/clearance`,
            { key, cleared },
            { preserveScroll: true },
        );

    const release = () =>
        router.post(
            `/hr/payroll/separations/${separation.id}/release`,
            {},
            { preserveScroll: true, onSuccess: () => setReleasing(false) },
        );

    const blockers = separation.clearance.filter((item) => item.blocking && !item.cleared_at);

    return (
        <AppLayout
            title={`Final Pay — ${separation.employee.full_name}`}
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Payroll', href: '/hr/payroll' },
                { label: 'Separations', href: '/hr/payroll/separations' },
                { label: separation.employee.full_name },
            ]}
            actions={
                can.release && (
                    <Button size="sm" onClick={() => setReleasing(true)}>
                        <ShieldCheck className="h-4 w-4" />
                        <span className="hidden sm:inline">Release Final Pay</span>
                    </Button>
                )
            }
        >
            <div className="grid gap-5 lg:grid-cols-3">
                <div className="space-y-5 lg:col-span-2">
                    <Card>
                        <CardHeader
                            title="Final pay computation"
                            action={
                                <Badge variant={STATUS_VARIANT[separation.status] ?? 'default'}>
                                    {titleCase(separation.status)}
                                </Badge>
                            }
                        />

                        <CardBody className="space-y-3">
                            {separation.breakdown.map((line, index) => (
                                <div
                                    key={index}
                                    className="flex items-start justify-between gap-4 border-b border-border pb-3 last:border-0 last:pb-0"
                                >
                                    <div>
                                        <p className="text-sm font-medium text-foreground">
                                            {line.label}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {line.note}
                                        </p>
                                    </div>
                                    <p
                                        className={`shrink-0 text-sm font-medium tabular-nums ${
                                            line.amount < 0
                                                ? 'text-destructive'
                                                : 'text-foreground'
                                        }`}
                                    >
                                        {formatCurrency(line.amount)}
                                    </p>
                                </div>
                            ))}
                        </CardBody>

                        <CardFooter className="flex items-center justify-between">
                            <span className="text-sm font-medium text-foreground">
                                Net final pay
                            </span>
                            <span className="text-lg font-semibold tabular-nums text-foreground">
                                {formatCurrency(separation.net_final_pay)}
                            </span>
                        </CardFooter>
                    </Card>

                    {can.update && (
                        <Card>
                            <CardHeader title="Adjust and recompute" />
                            <CardBody>
                                <form
                                    onSubmit={recompute}
                                    className="flex flex-col gap-4 sm:flex-row sm:items-end"
                                >
                                    <Field
                                        label="Unpaid days worked"
                                        error={form.errors.days_unpaid}
                                        className="flex-1"
                                    >
                                        <Input
                                            type="number"
                                            step="0.5"
                                            min="0"
                                            value={form.data.days_unpaid}
                                            onChange={(event) =>
                                                form.setData('days_unpaid', event.target.value)
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Other deductions"
                                        error={form.errors.other_deductions}
                                        className="flex-1"
                                    >
                                        <Input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            value={form.data.other_deductions}
                                            onChange={(event) =>
                                                form.setData(
                                                    'other_deductions',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Button type="submit" disabled={form.processing}>
                                        <RefreshCw className="h-4 w-4" />
                                        Recompute
                                    </Button>
                                </form>
                            </CardBody>
                        </Card>
                    )}
                </div>

                <div className="space-y-5">
                    <Card>
                        <CardHeader title="Separation" />
                        <CardBody className="space-y-3 text-sm">
                            <Detail label="Employee" value={separation.employee.full_name} />
                            <Detail
                                label="Employee number"
                                value={separation.employee.employee_number}
                            />
                            <Detail label="Last day" value={formatDate(separation.last_day)} />
                            <Detail label="Reason" value={titleCase(separation.reason)} />
                            <Detail
                                label="Processed by"
                                value={separation.processed_by ?? '—'}
                            />
                            {separation.released_at && (
                                <Detail
                                    label="Released"
                                    value={formatDateTime(separation.released_at)}
                                />
                            )}
                            {separation.remarks && (
                                <div>
                                    <p className="text-xs text-muted-foreground">Remarks</p>
                                    <p className="mt-0.5 text-foreground">
                                        {separation.remarks}
                                    </p>
                                </div>
                            )}
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title="Clearance" />
                        <CardBody className="space-y-1">
                            {separation.clearance.map((item) => (
                                <button
                                    key={item.key}
                                    type="button"
                                    disabled={!can.update}
                                    onClick={() => toggle(item.key, !item.cleared_at)}
                                    className="flex w-full items-start gap-2.5 rounded-md px-2 py-2 text-left transition-colors hover:bg-secondary disabled:cursor-default disabled:hover:bg-transparent"
                                >
                                    {item.cleared_at ? (
                                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-success" />
                                    ) : (
                                        <Circle className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                    )}
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-sm text-foreground">
                                            {item.label}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {item.cleared_at
                                                ? formatDateTime(item.cleared_at)
                                                : item.blocking
                                                  ? 'Required before release'
                                                  : 'Optional'}
                                        </span>
                                    </span>
                                </button>
                            ))}
                        </CardBody>

                        {!separation.released_at && blockers.length > 0 && (
                            <CardFooter>
                                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                                    <Lock className="h-3.5 w-3.5 shrink-0" />
                                    {blockers.length} blocking item(s) still open.
                                </p>
                            </CardFooter>
                        )}
                    </Card>
                </div>
            </div>

            <Modal
                show={releasing}
                onClose={() => setReleasing(false)}
                title="Release this final pay?"
                description="The figures are frozen, the outstanding loans are settled against the deduction, and the employee is marked separated and inactive. This cannot be undone."
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setReleasing(false)}>
                            Cancel
                        </Button>
                        <Button onClick={release}>
                            Release {formatCurrency(separation.net_final_pay)}
                        </Button>
                    </>
                }
            />
        </AppLayout>
    );
}

function Detail({ label, value }) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <span className="text-xs text-muted-foreground">{label}</span>
            <span className="text-right font-medium text-foreground">{value}</span>
        </div>
    );
}
