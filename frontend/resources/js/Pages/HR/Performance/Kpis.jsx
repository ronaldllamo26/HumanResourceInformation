import { useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Plus, Target } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
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
    Textarea,
} from '@/Components/ui';

const BLANK = {
    title: '',
    description: '',
    category: '',
    measurement_unit: '',
    default_weight: 0,
    department_id: '',
    position_id: '',
    is_active: true,
};

export default function Kpis({ kpis, departments, positions, can }) {
    const [creating, setCreating] = useState(false);

    const form = useForm(BLANK);

    // A position already implies its department, so narrow the list.
    const scopedPositions = useMemo(() => {
        if (!form.data.department_id) return positions;

        return positions.filter(
            (position) => String(position.department_id) === String(form.data.department_id),
        );
    }, [positions, form.data.department_id]);

    const open = () => {
        form.clearErrors();
        form.setData(BLANK);
        setCreating(true);
    };

    const submit = (event) => {
        event.preventDefault();

        form.post('/hr/performance/kpis', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setCreating(false);
            },
        });
    };

    return (
        <AppLayout
            title="Performance Management"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Performance', href: '/hr/performance' },
                { label: 'KPI Library' },
            ]}
        >
            <Card>
                <CardHeader
                    title="KPI Library"
                    description="Scorecards are built from these. A KPI applies company-wide, to a department, or to a single position."
                    action={
                        can.manage && (
                            <Button size="sm" onClick={open}>
                                <Plus className="h-4 w-4" />
                                New KPI
                            </Button>
                        )
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>KPI</TH>
                            <TH>Category</TH>
                            <TH>Applies To</TH>
                            <TH className="text-right">Default Weight</TH>
                            <TH className="text-right">In Use</TH>
                            <TH>Status</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {kpis.length === 0 ? (
                            <TableEmpty
                                colSpan={6}
                                icon={Target}
                                title="No KPIs defined"
                                description="Add KPIs before rolling out a review cycle — scorecards are built from this library."
                            />
                        ) : (
                            kpis.map((kpi) => (
                                <TR key={kpi.id}>
                                    <TD>
                                        <p className="text-sm font-medium text-foreground">
                                            {kpi.title}
                                        </p>
                                        {kpi.description && (
                                            <p className="max-w-md truncate text-xs text-muted-foreground">
                                                {kpi.description}
                                            </p>
                                        )}
                                    </TD>

                                    <TD>
                                        {kpi.category ? (
                                            <Badge variant="muted">{kpi.category}</Badge>
                                        ) : (
                                            <span className="text-sm text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </TD>

                                    <TD>
                                        <p className="text-sm text-foreground">
                                            {kpi.scope_name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {kpi.scope}
                                        </p>
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-foreground">
                                        {kpi.default_weight || '—'}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {kpi.assigned_count}
                                    </TD>

                                    <TD>
                                        <Badge status={kpi.is_active ? 'active' : 'inactive'} />
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            <Modal
                show={creating}
                onClose={() => setCreating(false)}
                title="New KPI"
                description="Leave both department and position blank for a company-wide KPI."
                maxWidth="2xl"
            >
                <form onSubmit={submit} className="space-y-4">
                    <Field label="Title" required error={form.errors.title}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={form.data.title}
                                onChange={(event) => form.setData('title', event.target.value)}
                                error={form.errors.title}
                                placeholder="On-time delivery rate"
                            />
                        )}
                    </Field>

                    <Field label="Description" error={form.errors.description}>
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={2}
                                value={form.data.description ?? ''}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                                placeholder="What good looks like, so every reviewer scores it the same way."
                            />
                        )}
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Category" error={form.errors.category}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.category ?? ''}
                                    onChange={(event) =>
                                        form.setData('category', event.target.value)
                                    }
                                    placeholder="Safety"
                                />
                            )}
                        </Field>

                        <Field label="Measurement Unit" error={form.errors.measurement_unit}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.measurement_unit ?? ''}
                                    onChange={(event) =>
                                        form.setData('measurement_unit', event.target.value)
                                    }
                                    placeholder="%"
                                />
                            )}
                        </Field>

                        <Field
                            label="Default Weight"
                            required
                            hint="Out of 100"
                            error={form.errors.default_weight}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    step="0.5"
                                    min="0"
                                    max="100"
                                    value={form.data.default_weight}
                                    onChange={(event) =>
                                        form.setData('default_weight', event.target.value)
                                    }
                                    error={form.errors.default_weight}
                                />
                            )}
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Department" error={form.errors.department_id}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={form.data.department_id}
                                    onChange={(event) =>
                                        form.setData((current) => ({
                                            ...current,
                                            department_id: event.target.value,
                                            position_id: '',
                                        }))
                                    }
                                    placeholder="All departments"
                                    options={departments.map((department) => ({
                                        value: department.id,
                                        label: department.name,
                                    }))}
                                />
                            )}
                        </Field>

                        <Field
                            label="Position"
                            hint="Overrides the department scope"
                            error={form.errors.position_id}
                        >
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={form.data.position_id}
                                    onChange={(event) =>
                                        form.setData('position_id', event.target.value)
                                    }
                                    placeholder="Any position"
                                    options={scopedPositions.map((position) => ({
                                        value: position.id,
                                        label: position.title,
                                    }))}
                                />
                            )}
                        </Field>
                    </div>

                    <label className="flex items-center gap-2.5">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(event) =>
                                form.setData('is_active', event.target.checked)
                            }
                            className="h-4 w-4 rounded border-input text-primary focus:ring-ring/30"
                        />
                        <span className="text-sm text-foreground">
                            Active — included when a cycle is rolled out
                        </span>
                    </label>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setCreating(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            Save KPI
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
