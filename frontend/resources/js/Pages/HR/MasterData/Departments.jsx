import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Building2, Plus, UserX, Users } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    Field,
    Input,
    Modal,
    MeterCard,
    StatCard,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
    Textarea,
} from '@/Components/ui';

const BLANK = { code: '', name: '', description: '', is_active: true };

export default function Departments({ departments, filters, summary }) {
    const [creating, setCreating] = useState(false);

    const form = useForm(BLANK);

    const open = () => {
        form.clearErrors();
        form.setData(BLANK);
        setCreating(true);
    };

    const submit = (event) => {
        event.preventDefault();

        form.post('/hr/departments', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setCreating(false);
            },
        });
    };

    const activeRate = summary.total > 0 ? (summary.active / summary.total) * 100 : 0;

    return (
        <AppLayout
            title="Departments"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Departments' },
            ]}
        >
            {/* No drill-down here, deliberately: the whole table is on the
                screen below, so a tile that filtered it would hide rows the
                reader can already see. These are a summary, not a way in. */}
            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Departments"
                    value={summary.total}
                    icon={Building2}
                    tone={summary.total > 0 ? 'primary' : 'muted'}
                    hint="in the org chart"
                />

                <MeterCard
                    label="Active"
                    value={summary.active}
                    percent={activeRate}
                    badge={summary.total > 0 ? `${Math.round(activeRate)}%` : undefined}
                    icon={Users}
                    tone="success"
                    iconTone="success"
                    hint={`of ${summary.total} — the rest are deactivated, not deleted`}
                />

                {/* Not an error: a department with nobody in it is usually
                    newly added. Worth seeing, not worth alarming about. */}
                <StatCard
                    label="Without Employees"
                    value={summary.empty}
                    icon={UserX}
                    tone={summary.empty > 0 ? 'info' : 'muted'}
                    hint="newly added, or left behind"
                />
            </div>

            <Card>
                <CardHeader
                    title="Departments"
                    description="Employee records, KPI scoping, and payroll reporting all group by these."
                    action={
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Button onClick={open}>
                                <Plus className="h-4 w-4" />
                                New Department
                            </Button>
                        </div>
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Code</TH>
                            <TH>Department</TH>
                            <TH className="text-right">Employees</TH>
                            <TH className="text-right">Positions</TH>
                            <TH>Status</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {departments.length === 0 ? (
                            <TableEmpty
                                colSpan={5}
                                icon={Building2}
                                title={
                                    filters.search
                                        ? 'No department matches that search'
                                        : 'No departments yet'
                                }
                                description="Departments are the org units every employee record is filed against."
                            />
                        ) : (
                            departments.map((department) => (
                                <TR key={department.id}>
                                    <TD>
                                        <span className="font-mono text-xs font-medium text-muted-foreground">
                                            {department.code}
                                        </span>
                                    </TD>
                                    <TD>
                                        <p className="font-medium text-foreground">
                                            {department.name}
                                        </p>
                                        {department.description && (
                                            <p className="max-w-md truncate text-xs text-muted-foreground">
                                                {department.description}
                                            </p>
                                        )}
                                    </TD>
                                    <TD className="text-right tabular-nums">
                                        {department.employees_count}
                                    </TD>
                                    <TD className="text-right tabular-nums">
                                        {department.positions_count}
                                    </TD>
                                    <TD>
                                        <Badge
                                            variant={department.is_active ? 'success' : 'muted'}
                                        >
                                            {department.is_active ? 'Active' : 'Inactive'}
                                        </Badge>
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
                title="New department"
                description="The code is normalised to upper case and must be unique."
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setCreating(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={form.processing}>
                            Create
                        </Button>
                    </>
                }
            >
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-3">
                    <Field label="Code" required error={form.errors.code}>
                        <Input
                            value={form.data.code}
                            onChange={(event) => form.setData('code', event.target.value)}
                            placeholder="OPS"
                        />
                    </Field>

                    <Field
                        label="Name"
                        required
                        error={form.errors.name}
                        className="sm:col-span-2"
                    >
                        <Input
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            placeholder="Fleet Operations"
                        />
                    </Field>

                    <Field
                        label="Description"
                        error={form.errors.description}
                        className="sm:col-span-3"
                    >
                        <Textarea
                            rows={2}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </Field>

                    <label className="flex w-fit cursor-pointer items-center gap-2 sm:col-span-3">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(event) =>
                                form.setData('is_active', event.target.checked)
                            }
                            className="h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-ring focus:ring-offset-0"
                        />
                        <span className="text-sm text-muted-foreground">
                            Active — available when filing an employee
                        </span>
                    </label>
                </form>
            </Modal>
        </AppLayout>
    );
}
