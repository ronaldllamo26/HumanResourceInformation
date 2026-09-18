import { useForm } from '@inertiajs/react';
import { Save, UserCheck } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import EmployeeForm from '@/Pages/HR/Employees/Partials/EmployeeForm';
import { Button, Card, CardBody } from '@/Components/ui';

const BLANK_EMPLOYEE = {
    first_name: '',
    middle_name: '',
    last_name: '',
    suffix: '',
    birth_date: '',
    birth_place: '',
    gender: '',
    civil_status: '',
    nationality: 'Filipino',
    religion: '',
    blood_type: '',
    photo: null,

    email: '',
    mobile_number: '',
    phone_number: '',
    present_address: '',
    permanent_address: '',

    emergency_contact_name: '',
    emergency_contact_relationship: '',
    emergency_contact_number: '',

    sss_number: '',
    philhealth_number: '',
    pagibig_number: '',
    tin: '',

    employment_category: 'internal',
    client_id: '',
    wage_region: '',

    department_id: '',
    position_id: '',
    supervisor_id: '',
    employment_status: 'probationary',
    employment_type: 'full_time',
    date_hired: '',
    date_regularized: '',
    date_separated: '',
    separation_reason: '',

    basic_salary: '',
    pay_frequency: 'semi_monthly',
    bank_name: '',
    bank_account_number: '',

    drivers_license_number: '',
    license_dl_codes: '',
    license_conditions: '',
    license_expiry: '',

    status: 'active',
    notes: '',

    create_user_account: false,
    user_role: 'employee',
};

export default function Create({ options, can = {}, endorsement, prefill = {} }) {
    const { data, setData, post, processing, errors } = useForm({
        ...BLANK_EMPLOYEE,
        ...prefill,
        endorsement_id: endorsement?.id ?? null,
    });

    const submit = (event) => {
        event.preventDefault();
        post('/hr/employees', { forceFormData: true });
    };

    return (
        <AppLayout
            title={endorsement ? `Approve · ${endorsement.full_name}` : 'Add Employee'}
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: endorsement ? 'Approve endorsement' : 'Add Employee' },
            ]}
        >
            {/* Where this record came from, when approving a hire endorsement */}
            {endorsement && (
                <Card className="mb-5 border-primary/30">
                    <CardBody className="flex flex-wrap items-center gap-3">
                        <UserCheck
                            className="h-5 w-5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium text-foreground">
                                Approving endorsement{' '}
                                <span className="font-mono">{endorsement.reference}</span> from{' '}
                                {endorsement.source.toUpperCase()}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Everything {endorsement.source.toUpperCase()} sent is filled in
                                below. Saving creates the employee record and marks the
                                endorsement approved.
                            </p>
                        </div>

                        <Button
                            variant="outline"
                            href={`/hr/endorsements/${endorsement.id}`}
                            type="button"
                        >
                            Back to review
                        </Button>
                    </CardBody>
                </Card>
            )}

            <form onSubmit={submit}>
                <EmployeeForm data={data} setData={setData} errors={errors} options={options} />

                <div className="mt-5 flex justify-end gap-2">
                    <Button variant="outline" href="/hr/employees">
                        Cancel
                    </Button>
                    <Button type="submit" loading={processing}>
                        <Save className="h-4 w-4" />
                        Save Employee
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
