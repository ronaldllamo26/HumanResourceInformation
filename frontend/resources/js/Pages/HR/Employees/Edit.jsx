import { useForm } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import EmployeeForm from '@/Pages/HR/Employees/Partials/EmployeeForm';
import { Button } from '@/Components/ui';

/** Inertia posts `null` badly through FormData — normalise to empty strings. */
const text = (value) => value ?? '';

export default function Edit({ employee, options }) {
    const record = employee.data ?? employee;

    const { data, setData, post, processing, errors } = useForm({
        // Spoofs PUT — browsers cannot send multipart bodies over a real PUT.
        _method: 'put',
        first_name: text(record.first_name),
        middle_name: text(record.middle_name),
        last_name: text(record.last_name),
        suffix: text(record.suffix),
        birth_date: text(record.birth_date),
        birth_place: text(record.birth_place),
        gender: text(record.gender),
        civil_status: text(record.civil_status),
        nationality: text(record.nationality),
        religion: text(record.religion),
        blood_type: text(record.blood_type),
        photo: null,

        email: text(record.email),
        mobile_number: text(record.mobile_number),
        phone_number: text(record.phone_number),
        present_address: text(record.present_address),
        permanent_address: text(record.permanent_address),

        emergency_contact_name: text(record.emergency_contact_name),
        emergency_contact_relationship: text(record.emergency_contact_relationship),
        emergency_contact_number: text(record.emergency_contact_number),

        sss_number: text(record.sss_number),
        philhealth_number: text(record.philhealth_number),
        pagibig_number: text(record.pagibig_number),
        tin: text(record.tin),

        employment_category: record.employment_category ?? 'internal',

        client_id: text(record.client_id),

        wage_region: text(record.wage_region),

        department_id: text(record.department_id),
        position_id: text(record.position_id),
        supervisor_id: text(record.supervisor_id),
        employment_status: text(record.employment_status),
        employment_type: text(record.employment_type),
        date_hired: text(record.date_hired),
        date_regularized: text(record.date_regularized),
        date_separated: text(record.date_separated),
        separation_reason: text(record.separation_reason),

        basic_salary: text(record.basic_salary),
        pay_frequency: text(record.pay_frequency),
        bank_name: text(record.bank_name),
        bank_account_number: text(record.bank_account_number),

        drivers_license_number: text(record.drivers_license_number),
        license_dl_codes: text(record.license_dl_codes),
        license_conditions: text(record.license_conditions),
        license_expiry: text(record.license_expiry),

        status: text(record.status),
        notes: text(record.notes),
    });

    const submit = (event) => {
        event.preventDefault();

        post(`/hr/employees/${record.id}`, { forceFormData: true });
    };

    return (
        <AppLayout
            title={`Edit — ${record.full_name}`}
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: record.full_name, href: `/hr/employees/${record.id}` },
                { label: 'Edit' },
            ]}
        >
            <form onSubmit={submit}>
                <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-muted-foreground">
                        Editing{' '}
                        <span className="font-medium text-foreground">
                            {record.employee_number}
                        </span>
                    </p>

                    <div className="flex items-center gap-2">
                        <Button variant="outline" href={`/hr/employees/${record.id}`}>
                            <ArrowLeft className="h-4 w-4" />
                            Cancel
                        </Button>
                        <Button type="submit" loading={processing}>
                            <Save className="h-4 w-4" />
                            Save Changes
                        </Button>
                    </div>
                </div>

                <EmployeeForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    options={options}
                    isEdit
                    currentPhotoUrl={record.photo_url}
                />

                <div className="mt-5 flex justify-end gap-2">
                    <Button variant="outline" href={`/hr/employees/${record.id}`}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={processing}>
                        <Save className="h-4 w-4" />
                        Save Changes
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
