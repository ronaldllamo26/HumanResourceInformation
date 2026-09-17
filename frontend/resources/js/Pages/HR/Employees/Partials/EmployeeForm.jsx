import { useEffect, useMemo, useState } from 'react';
import {
    Card,
    CardBody,
    CardHeader,
    DateInput,
    Field,
    Input,
    Select,
    Textarea,
} from '@/Components/ui';
import { initials } from '@/lib/utils';

const GENDERS = [
    { value: 'male', label: 'Male' },
    { value: 'female', label: 'Female' },
];

const CIVIL_STATUSES = [
    { value: 'single', label: 'Single' },
    { value: 'married', label: 'Married' },
    { value: 'widowed', label: 'Widowed' },
    { value: 'separated', label: 'Separated' },
];

const EMPLOYMENT_TYPES = [
    { value: 'full_time', label: 'Full-time' },
    { value: 'part_time', label: 'Part-time' },
];

const PAY_FREQUENCIES = [
    { value: 'semi_monthly', label: 'Semi-monthly' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'daily', label: 'Daily' },
];

const titleCase = (value) =>
    String(value)
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

function Section({ title, children }) {
    return (
        <Card>
            <CardHeader title={title} />
            <CardBody className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{children}</CardBody>
        </Card>
    );
}

/**
 * Shared by Create and Edit. `data`/`setData`/`errors` come from Inertia's useForm.
 */
export default function EmployeeForm({
    data,
    setData,
    errors,
    options,
    isEdit = false,
    currentPhotoUrl = null,
}) {
    // A live preview of whatever is picked — the newly-chosen file if there is
    // one, else the photo already on file when editing. Without this, HR has
    // no way to confirm they grabbed the right 2x2 before saving, and no way
    // to see that a photo already exists when opening someone's record to edit.
    const [previewUrl, setPreviewUrl] = useState(currentPhotoUrl);

    useEffect(() => {
        if (!data.photo) {
            setPreviewUrl(currentPhotoUrl);
            return;
        }

        const objectUrl = URL.createObjectURL(data.photo);
        setPreviewUrl(objectUrl);

        // Object URLs are never freed automatically — revoke the old one
        // whenever a different file is chosen, or the form unmounts.
        return () => URL.revokeObjectURL(objectUrl);
    }, [data.photo, currentPhotoUrl]);

    // Positions belong to a department — narrow the list once one is chosen.
    const positions = useMemo(() => {
        if (!data.department_id) return options.positions;

        return options.positions.filter(
            (position) => String(position.department_id) === String(data.department_id),
        );
    }, [options.positions, data.department_id]);

    // Drives the wage-region hint: what the employee inherits if left blank.
    const selectedClient = useMemo(
        () =>
            (options.clients ?? []).find(
                (client) => String(client.id) === String(data.client_id),
            ) ?? null,
        [options.clients, data.client_id],
    );

    const set = (field) => (event) => setData(field, event.target.value);

    return (
        <div className="space-y-5">
            <Section title="Personal Information">
                <Field label="First Name" required error={errors.first_name}>
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.first_name}
                            onChange={set('first_name')}
                            error={errors.first_name}
                        />
                    )}
                </Field>

                <Field label="Middle Name" error={errors.middle_name}>
                    {({ id }) => (
                        <Input id={id} value={data.middle_name} onChange={set('middle_name')} />
                    )}
                </Field>

                <Field label="Last Name" required error={errors.last_name}>
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.last_name}
                            onChange={set('last_name')}
                            error={errors.last_name}
                        />
                    )}
                </Field>

                <Field label="Suffix" hint="Jr., Sr., III" error={errors.suffix}>
                    {({ id }) => <Input id={id} value={data.suffix} onChange={set('suffix')} />}
                </Field>

                <Field label="Date of Birth" error={errors.birth_date}>
                    {({ id }) => (
                        <DateInput
                            id={id}
                            value={data.birth_date}
                            onChange={set('birth_date')}
                            error={errors.birth_date}
                        />
                    )}
                </Field>

                <Field label="Place of Birth" error={errors.birth_place}>
                    {({ id }) => (
                        <Input id={id} value={data.birth_place} onChange={set('birth_place')} />
                    )}
                </Field>

                <Field label="Gender" error={errors.gender}>
                    {({ id }) => (
                        <Select
                            id={id}
                            value={data.gender}
                            onChange={set('gender')}
                            options={GENDERS}
                            placeholder="Select gender"
                        />
                    )}
                </Field>

                <Field label="Civil Status" error={errors.civil_status}>
                    {({ id }) => (
                        <Select
                            id={id}
                            value={data.civil_status}
                            onChange={set('civil_status')}
                            options={CIVIL_STATUSES}
                            placeholder="Select civil status"
                        />
                    )}
                </Field>

                <Field label="Nationality" error={errors.nationality}>
                    {({ id }) => (
                        <Input id={id} value={data.nationality} onChange={set('nationality')} />
                    )}
                </Field>

                <Field label="Religion" error={errors.religion}>
                    {({ id }) => (
                        <Input id={id} value={data.religion} onChange={set('religion')} />
                    )}
                </Field>

                <Field label="Blood Type" error={errors.blood_type}>
                    {({ id }) => (
                        <Input id={id} value={data.blood_type} onChange={set('blood_type')} />
                    )}
                </Field>

                <Field
                    label="Photo"
                    hint="2x2, JPG or PNG, max 2 MB"
                    error={errors.photo}
                    className="sm:col-span-2 lg:col-span-3"
                >
                    {({ id }) => (
                        <div className="flex items-center gap-4">
                            {previewUrl ? (
                                <img
                                    src={previewUrl}
                                    alt=""
                                    className="h-20 w-20 shrink-0 rounded-full object-cover ring-2 ring-border"
                                />
                            ) : (
                                <span className="grid h-20 w-20 shrink-0 place-items-center rounded-full bg-primary/10 text-lg font-semibold text-primary">
                                    {initials(`${data.first_name} ${data.last_name}`) || '—'}
                                </span>
                            )}

                            <div className="min-w-0 flex-1">
                                <input
                                    id={id}
                                    type="file"
                                    accept="image/*"
                                    onChange={(event) =>
                                        setData('photo', event.target.files[0] ?? null)
                                    }
                                    className="block w-full text-xs text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-xs file:font-medium file:text-secondary-foreground hover:file:bg-secondary/70"
                                />
                                {isEdit && currentPhotoUrl && !data.photo && (
                                    <p className="mt-1.5 text-xs text-muted-foreground">
                                        Choosing a new file replaces the photo on file.
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                </Field>
            </Section>

            <Section title="Contact Information">
                <Field label="Email Address" error={errors.email}>
                    {({ id }) => (
                        <Input
                            id={id}
                            type="email"
                            value={data.email}
                            onChange={set('email')}
                            error={errors.email}
                        />
                    )}
                </Field>

                <Field label="Mobile Number" error={errors.mobile_number}>
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.mobile_number}
                            onChange={set('mobile_number')}
                            placeholder="09XX XXX XXXX"
                        />
                    )}
                </Field>

                <Field label="Phone Number" error={errors.phone_number}>
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.phone_number}
                            onChange={set('phone_number')}
                        />
                    )}
                </Field>

                <Field
                    label="Present Address"
                    className="sm:col-span-2"
                    error={errors.present_address}
                >
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.present_address}
                            onChange={set('present_address')}
                        />
                    )}
                </Field>

                <Field
                    label="Permanent Address"
                    className="sm:col-span-2"
                    error={errors.permanent_address}
                >
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.permanent_address}
                            onChange={set('permanent_address')}
                        />
                    )}
                </Field>
            </Section>

            <Section title="Emergency Contact">
                <Field label="Contact Name" error={errors.emergency_contact_name}>
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.emergency_contact_name}
                            onChange={set('emergency_contact_name')}
                        />
                    )}
                </Field>

                <Field label="Relationship" error={errors.emergency_contact_relationship}>
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.emergency_contact_relationship}
                            onChange={set('emergency_contact_relationship')}
                        />
                    )}
                </Field>

                <Field label="Contact Number" error={errors.emergency_contact_number}>
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.emergency_contact_number}
                            onChange={set('emergency_contact_number')}
                        />
                    )}
                </Field>
            </Section>

            <Section title="Government IDs">
                <Field label="SSS Number" error={errors.sss_number}>
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.sss_number}
                            onChange={set('sss_number')}
                            placeholder="XX-XXXXXXX-X"
                        />
                    )}
                </Field>

                <Field label="PhilHealth Number" error={errors.philhealth_number}>
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.philhealth_number}
                            onChange={set('philhealth_number')}
                        />
                    )}
                </Field>

                <Field label="Pag-IBIG Number" error={errors.pagibig_number}>
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.pagibig_number}
                            onChange={set('pagibig_number')}
                        />
                    )}
                </Field>

                <Field label="TIN" error={errors.tin}>
                    {({ id }) => <Input id={id} value={data.tin} onChange={set('tin')} />}
                </Field>
            </Section>

            {/* Ahead of Employment Details on purpose: whether someone is
                agency staff or deployed decides what the rest of that section
                even means, and it is the first thing HR knows about a hire. */}
            <Section title="Assignment">
                <Field label="Staff Category" required error={errors.employment_category}>
                    {({ id }) => (
                        <Select
                            id={id}
                            value={data.employment_category}
                            onChange={(event) => {
                                const category = event.target.value;

                                setData((current) => ({
                                    ...current,
                                    employment_category: category,
                                    // Cleared rather than left behind: a stale
                                    // client on someone brought in-house keeps
                                    // them in that client's billing.
                                    client_id: category === 'external' ? current.client_id : '',
                                    wage_region:
                                        category === 'external' ? current.wage_region : '',
                                }));
                            }}
                            error={errors.employment_category}
                            options={[
                                { value: 'internal', label: 'Internal Staff (PrimePower)' },
                                { value: 'external', label: 'External (Deployed to a client)' },
                            ]}
                        />
                    )}
                </Field>

                {data.employment_category === 'external' && (
                    <>
                        <Field label="Client" required error={errors.client_id}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={data.client_id}
                                    onChange={set('client_id')}
                                    placeholder="Select client"
                                    error={errors.client_id}
                                    options={(options.clients ?? []).map((client) => ({
                                        value: client.id,
                                        label: `${client.name} (${client.code})`,
                                    }))}
                                />
                            )}
                        </Field>

                        <Field
                            label="Wage Region"
                            error={errors.wage_region}
                            hint={
                                selectedClient?.wage_region
                                    ? `Defaults to ${selectedClient.wage_region} — the client's own site. Set this only if posted elsewhere.`
                                    : 'Only needed if posted away from the client site.'
                            }
                        >
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={data.wage_region}
                                    onChange={set('wage_region')}
                                    placeholder={
                                        selectedClient?.wage_region
                                            ? `Use client's (${selectedClient.wage_region})`
                                            : "Use client's region"
                                    }
                                    options={(options.wageRegions ?? []).map((region) => ({
                                        value: region.value,
                                        label: region.label,
                                    }))}
                                />
                            )}
                        </Field>
                    </>
                )}
            </Section>

            <Section title="Employment Details">
                <Field label="Department" error={errors.department_id}>
                    {({ id }) => (
                        <Select
                            id={id}
                            value={data.department_id}
                            onChange={(event) => {
                                setData((current) => ({
                                    ...current,
                                    department_id: event.target.value,
                                    // The old position may not belong to the new department.
                                    position_id: '',
                                }));
                            }}
                            placeholder="Select department"
                            options={options.departments.map((department) => ({
                                value: department.id,
                                label: department.name,
                            }))}
                        />
                    )}
                </Field>

                <Field
                    label="Position"
                    error={errors.position_id}
                    hint={!data.department_id ? 'Select a department first' : undefined}
                >
                    {({ id }) => (
                        <Select
                            id={id}
                            value={data.position_id}
                            onChange={set('position_id')}
                            placeholder="Select position"
                            options={positions.map((position) => ({
                                value: position.id,
                                label: position.title,
                            }))}
                        />
                    )}
                </Field>

                <Field label="Immediate Supervisor" error={errors.supervisor_id}>
                    {({ id }) => (
                        <Select
                            id={id}
                            value={data.supervisor_id}
                            onChange={set('supervisor_id')}
                            placeholder="None"
                            options={options.supervisors.map((supervisor) => ({
                                value: supervisor.id,
                                label: supervisor.full_name,
                            }))}
                        />
                    )}
                </Field>

                <Field label="Employment Status" required error={errors.employment_status}>
                    {({ id }) => (
                        <Select
                            id={id}
                            value={data.employment_status}
                            onChange={set('employment_status')}
                            options={options.employmentStatuses.map((status) => ({
                                value: status,
                                label: titleCase(status),
                            }))}
                        />
                    )}
                </Field>

                <Field label="Employment Type" required error={errors.employment_type}>
                    {({ id }) => (
                        <Select
                            id={id}
                            value={data.employment_type}
                            onChange={set('employment_type')}
                            options={EMPLOYMENT_TYPES}
                        />
                    )}
                </Field>

                <Field label="Record Status" required error={errors.status}>
                    {({ id }) => (
                        <Select
                            id={id}
                            value={data.status}
                            onChange={set('status')}
                            options={options.statuses.map((status) => ({
                                value: status,
                                label: titleCase(status),
                            }))}
                        />
                    )}
                </Field>

                <Field label="Date Hired" required error={errors.date_hired}>
                    {({ id }) => (
                        <DateInput
                            id={id}
                            value={data.date_hired}
                            onChange={set('date_hired')}
                            error={errors.date_hired}
                        />
                    )}
                </Field>

                <Field label="Date Regularized" error={errors.date_regularized}>
                    {({ id }) => (
                        <DateInput
                            id={id}
                            value={data.date_regularized}
                            onChange={set('date_regularized')}
                            error={errors.date_regularized}
                        />
                    )}
                </Field>

                <Field label="Date Separated" error={errors.date_separated}>
                    {({ id }) => (
                        <DateInput
                            id={id}
                            value={data.date_separated}
                            onChange={set('date_separated')}
                            error={errors.date_separated}
                        />
                    )}
                </Field>

                {data.date_separated && (
                    <Field
                        label="Separation Reason"
                        className="sm:col-span-2 lg:col-span-3"
                        error={errors.separation_reason}
                    >
                        {({ id }) => (
                            <Textarea
                                id={id}
                                value={data.separation_reason}
                                onChange={set('separation_reason')}
                            />
                        )}
                    </Field>
                )}
            </Section>

            <Section title="Compensation & Banking">
                <Field label="Basic Salary (PHP)" required error={errors.basic_salary}>
                    {({ id }) => (
                        <Input
                            id={id}
                            type="number"
                            step="0.01"
                            min="0"
                            value={data.basic_salary}
                            onChange={set('basic_salary')}
                            error={errors.basic_salary}
                        />
                    )}
                </Field>

                <Field label="Pay Frequency" required error={errors.pay_frequency}>
                    {({ id }) => (
                        <Select
                            id={id}
                            value={data.pay_frequency}
                            onChange={set('pay_frequency')}
                            options={PAY_FREQUENCIES}
                        />
                    )}
                </Field>

                <Field label="Bank Name" error={errors.bank_name}>
                    {({ id }) => (
                        <Input id={id} value={data.bank_name} onChange={set('bank_name')} />
                    )}
                </Field>

                <Field label="Bank Account Number" error={errors.bank_account_number}>
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.bank_account_number}
                            onChange={set('bank_account_number')}
                        />
                    )}
                </Field>
            </Section>

            <Section title="Driver's License">
                <Field label="License Number" error={errors.drivers_license_number}>
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.drivers_license_number}
                            onChange={set('drivers_license_number')}
                        />
                    )}
                </Field>

                {/* DL Codes, not "Restriction Codes". The numeric restriction
                    scheme (1–8) is retired; a card issued today prints letter
                    codes in a panel headed "I. DL CODES", and they are the
                    legal ceiling on what the holder may drive — code A alone
                    is a motorcycle licence, and putting that driver on a truck
                    is the same class of problem as dispatching a lapsed one. */}
                <Field
                    label="DL Codes"
                    hint="What they may drive. A, A1, B, B1, B2, C, D, BE, CE."
                    error={errors.license_dl_codes}
                >
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.license_dl_codes}
                            onChange={set('license_dl_codes')}
                            placeholder="e.g. B,C"
                        />
                    )}
                </Field>

                {/* The card's second panel. Condition 4 is the one that
                    reaches scheduling: a driver restricted to daylight cannot
                    lawfully take a night run, which Deployment Readiness says
                    out loud. */}
                <Field
                    label="Conditions"
                    hint="1 lenses · 2 special equipment · 3 customized vehicle · 4 daylight only · 5 hearing aid"
                    error={errors.license_conditions}
                >
                    {({ id }) => (
                        <Input
                            id={id}
                            value={data.license_conditions}
                            onChange={set('license_conditions')}
                            placeholder="Leave blank for NONE"
                        />
                    )}
                </Field>

                <Field label="License Expiry" error={errors.license_expiry}>
                    {({ id }) => (
                        <DateInput
                            id={id}
                            value={data.license_expiry}
                            onChange={set('license_expiry')}
                        />
                    )}
                </Field>
            </Section>

            <Card>
                <CardHeader title="Notes" />
                <CardBody>
                    <Field error={errors.notes}>
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={3}
                                value={data.notes}
                                onChange={set('notes')}
                            />
                        )}
                    </Field>
                </CardBody>
            </Card>

            {!isEdit && (
                <Card>
                    <CardHeader title="Self-Service Account" />
                    <CardBody className="space-y-4">
                        <label className="flex items-center gap-2.5">
                            <input
                                type="checkbox"
                                checked={data.create_user_account}
                                onChange={(event) =>
                                    setData('create_user_account', event.target.checked)
                                }
                                className="h-4 w-4 rounded border-input text-primary focus:ring-ring/30"
                            />
                            <span className="text-sm text-foreground">
                                Create a login account for this employee
                            </span>
                        </label>

                        {data.create_user_account && (
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <Field
                                    label="System Role"
                                    error={errors.user_role}
                                    hint="A temporary password is shown once after saving."
                                >
                                    {({ id }) => (
                                        <Select
                                            id={id}
                                            value={data.user_role}
                                            onChange={set('user_role')}
                                            options={[
                                                { value: 'employee', label: 'Employee' },
                                                { value: 'supervisor', label: 'Supervisor' },
                                                { value: 'hr_staff', label: 'HR Staff' },
                                            ]}
                                        />
                                    )}
                                </Field>
                            </div>
                        )}
                    </CardBody>
                </Card>
            )}
        </div>
    );
}
