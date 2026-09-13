import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Banknote, HandCoins, Plus, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    DateInput,
    Field,
    Input,
    Modal,
    Select,
    Table,
    TableEmpty,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { formatCurrency, formatDate } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

export default function Compensation({ allowances, loans, employees, frequencies, loanTypes }) {
    const [allowanceOpen, setAllowanceOpen] = useState(false);
    const [loanOpen, setLoanOpen] = useState(false);

    const allowanceForm = useForm({
        employee_id: '',
        name: '',
        amount: '',
        frequency: 'monthly',
        is_taxable: false,
        effective_from: '',
        effective_to: '',
    });

    const loanForm = useForm({
        employee_id: '',
        type: 'sss',
        reference_number: '',
        principal_amount: '',
        monthly_amortization: '',
        start_date: '',
    });

    const submitAllowance = (event) => {
        event.preventDefault();

        allowanceForm.post('/hr/payroll/allowances', {
            preserveScroll: true,
            onSuccess: () => {
                allowanceForm.reset();
                setAllowanceOpen(false);
            },
        });
    };

    const submitLoan = (event) => {
        event.preventDefault();

        loanForm.post('/hr/payroll/loans', {
            preserveScroll: true,
            onSuccess: () => {
                loanForm.reset();
                setLoanOpen(false);
            },
        });
    };

    const employeeOptions = employees.map((employee) => ({
        value: employee.id,
        label: employee.full_name,
    }));

    return (
        <AppLayout
            title="Payroll & Compensation"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Payroll', href: '/hr/payroll' },
                { label: 'Allowances & Loans' },
            ]}
        >
            {/* Allowances */}
            <Card className="mb-5">
                <CardHeader
                    title="Recurring Allowances"
                    description="Added to gross pay every run. Non-taxable allowances are excluded from withholding tax."
                    action={
                        <Button size="sm" onClick={() => setAllowanceOpen(true)}>
                            <Plus className="h-4 w-4" />
                            Add Allowance
                        </Button>
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Allowance</TH>
                            <TH className="text-right">Amount</TH>
                            <TH>Frequency</TH>
                            <TH>Effective</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {allowances.length === 0 ? (
                            <TableEmpty
                                colSpan={6}
                                icon={HandCoins}
                                title="No allowances configured"
                                description="Transportation, meal, and COLA allowances go here."
                            />
                        ) : (
                            allowances.map((allowance) => (
                                <TR key={allowance.id}>
                                    <TD className="text-sm text-foreground">
                                        {allowance.employee}
                                    </TD>

                                    <TD>
                                        <span className="text-sm font-medium text-foreground">
                                            {allowance.name}
                                        </span>
                                        {!allowance.is_taxable && (
                                            <Badge variant="muted" className="ml-2">
                                                Non-taxable
                                            </Badge>
                                        )}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-foreground">
                                        {formatCurrency(allowance.amount)}
                                    </TD>

                                    <TD className="text-sm text-muted-foreground">
                                        {titleCase(allowance.frequency)}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {formatDate(allowance.effective_from)}
                                        {' → '}
                                        {allowance.effective_to
                                            ? formatDate(allowance.effective_to)
                                            : 'ongoing'}
                                    </TD>

                                    <TD>
                                        <div className="flex justify-end">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.delete(
                                                        `/hr/payroll/allowances/${allowance.id}`,
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                aria-label={`Remove ${allowance.name}`}
                                                className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </button>
                                        </div>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            {/* Loans */}
            <Card>
                <CardHeader
                    title="Loans & Advances"
                    description="Amortisations are withheld each run and applied to the balance when the run is approved."
                    action={
                        <Button size="sm" onClick={() => setLoanOpen(true)}>
                            <Plus className="h-4 w-4" />
                            Record Loan
                        </Button>
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Type</TH>
                            <TH className="text-right">Principal</TH>
                            <TH className="text-right">Monthly</TH>
                            <TH className="text-right">Balance</TH>
                            <TH>Status</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {loans.length === 0 ? (
                            <TableEmpty
                                colSpan={7}
                                icon={Banknote}
                                title="No loans on record"
                                description="SSS, Pag-IBIG, and company loans are deducted automatically once recorded."
                            />
                        ) : (
                            loans.map((loan) => (
                                <TR key={loan.id}>
                                    <TD className="text-sm text-foreground">{loan.employee}</TD>

                                    <TD>
                                        <span className="text-sm text-foreground">
                                            {titleCase(loan.type)}
                                        </span>
                                        {loan.reference_number && (
                                            <p className="text-xs text-muted-foreground">
                                                {loan.reference_number}
                                            </p>
                                        )}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {formatCurrency(loan.principal_amount)}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {formatCurrency(loan.monthly_amortization)}
                                    </TD>

                                    <TD className="text-right text-sm font-medium tabular-nums text-foreground">
                                        {formatCurrency(loan.outstanding_balance)}
                                    </TD>

                                    <TD>
                                        <Badge status={loan.status}>
                                            {titleCase(loan.status)}
                                        </Badge>
                                    </TD>

                                    <TD>
                                        <div className="flex justify-end">
                                            {loan.status === 'active' && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        router.post(
                                                            `/hr/payroll/loans/${loan.id}/cancel`,
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    Cancel
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

            {/* Add allowance */}
            <Modal
                show={allowanceOpen}
                onClose={() => setAllowanceOpen(false)}
                title="Add Allowance"
                maxWidth="lg"
            >
                <form onSubmit={submitAllowance} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Employee"
                            required
                            error={allowanceForm.errors.employee_id}
                        >
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={allowanceForm.data.employee_id}
                                    onChange={(event) =>
                                        allowanceForm.setData('employee_id', event.target.value)
                                    }
                                    placeholder="Select employee"
                                    error={allowanceForm.errors.employee_id}
                                    options={employeeOptions}
                                />
                            )}
                        </Field>

                        <Field
                            label="Allowance Name"
                            required
                            error={allowanceForm.errors.name}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={allowanceForm.data.name}
                                    onChange={(event) =>
                                        allowanceForm.setData('name', event.target.value)
                                    }
                                    error={allowanceForm.errors.name}
                                    placeholder="Transportation"
                                />
                            )}
                        </Field>

                        <Field label="Amount" required error={allowanceForm.errors.amount}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={allowanceForm.data.amount}
                                    onChange={(event) =>
                                        allowanceForm.setData('amount', event.target.value)
                                    }
                                    error={allowanceForm.errors.amount}
                                />
                            )}
                        </Field>

                        <Field
                            label="Frequency"
                            required
                            error={allowanceForm.errors.frequency}
                        >
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={allowanceForm.data.frequency}
                                    onChange={(event) =>
                                        allowanceForm.setData('frequency', event.target.value)
                                    }
                                    options={frequencies.map((frequency) => ({
                                        value: frequency,
                                        label: titleCase(frequency),
                                    }))}
                                />
                            )}
                        </Field>

                        <Field
                            label="Effective From"
                            required
                            error={allowanceForm.errors.effective_from}
                        >
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={allowanceForm.data.effective_from}
                                    onChange={(event) =>
                                        allowanceForm.setData(
                                            'effective_from',
                                            event.target.value,
                                        )
                                    }
                                    error={allowanceForm.errors.effective_from}
                                />
                            )}
                        </Field>

                        <Field
                            label="Effective To"
                            hint="Blank for ongoing"
                            error={allowanceForm.errors.effective_to}
                        >
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={allowanceForm.data.effective_to}
                                    onChange={(event) =>
                                        allowanceForm.setData(
                                            'effective_to',
                                            event.target.value,
                                        )
                                    }
                                    error={allowanceForm.errors.effective_to}
                                />
                            )}
                        </Field>
                    </div>

                    <label className="flex items-start gap-2.5">
                        <input
                            type="checkbox"
                            checked={allowanceForm.data.is_taxable}
                            onChange={(event) =>
                                allowanceForm.setData('is_taxable', event.target.checked)
                            }
                            className="mt-0.5 h-4 w-4 rounded border-input text-primary focus:ring-ring/30"
                        />
                        <span>
                            <span className="block text-sm text-foreground">Taxable</span>
                            <span className="block text-xs text-muted-foreground">
                                Leave unticked for de minimis benefits, which are excluded from
                                withholding tax.
                            </span>
                        </span>
                    </label>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setAllowanceOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={allowanceForm.processing}>
                            Add Allowance
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Record loan */}
            <Modal
                show={loanOpen}
                onClose={() => setLoanOpen(false)}
                title="Record Loan"
                description="The full principal starts as the outstanding balance."
                maxWidth="lg"
            >
                <form onSubmit={submitLoan} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Employee" required error={loanForm.errors.employee_id}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={loanForm.data.employee_id}
                                    onChange={(event) =>
                                        loanForm.setData('employee_id', event.target.value)
                                    }
                                    placeholder="Select employee"
                                    error={loanForm.errors.employee_id}
                                    options={employeeOptions}
                                />
                            )}
                        </Field>

                        <Field label="Loan Type" required error={loanForm.errors.type}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={loanForm.data.type}
                                    onChange={(event) =>
                                        loanForm.setData('type', event.target.value)
                                    }
                                    options={loanTypes.map((type) => ({
                                        value: type,
                                        label: titleCase(type),
                                    }))}
                                />
                            )}
                        </Field>

                        <Field
                            label="Reference Number"
                            error={loanForm.errors.reference_number}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={loanForm.data.reference_number}
                                    onChange={(event) =>
                                        loanForm.setData('reference_number', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        <Field label="Start Date" required error={loanForm.errors.start_date}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={loanForm.data.start_date}
                                    onChange={(event) =>
                                        loanForm.setData('start_date', event.target.value)
                                    }
                                    error={loanForm.errors.start_date}
                                />
                            )}
                        </Field>

                        <Field
                            label="Principal Amount"
                            required
                            error={loanForm.errors.principal_amount}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    step="0.01"
                                    min="1"
                                    value={loanForm.data.principal_amount}
                                    onChange={(event) =>
                                        loanForm.setData('principal_amount', event.target.value)
                                    }
                                    error={loanForm.errors.principal_amount}
                                />
                            )}
                        </Field>

                        <Field
                            label="Monthly Amortisation"
                            required
                            hint="Halved on a semi-monthly run"
                            error={loanForm.errors.monthly_amortization}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    step="0.01"
                                    min="1"
                                    value={loanForm.data.monthly_amortization}
                                    onChange={(event) =>
                                        loanForm.setData(
                                            'monthly_amortization',
                                            event.target.value,
                                        )
                                    }
                                    error={loanForm.errors.monthly_amortization}
                                />
                            )}
                        </Field>
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setLoanOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={loanForm.processing}>
                            Record Loan
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
