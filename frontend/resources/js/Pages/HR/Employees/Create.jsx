import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import axios from 'axios';
import { CheckCircle2, Loader2, Save, ScanLine, TriangleAlert, UserCheck } from 'lucide-react';
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

    // Every employee is one or the other; internal is the safe default

    // because it bills nobody.

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
    /*
     * The form opens on what Core 1 sent, over this system's own defaults.
     *
     * `prefill` carries only keys Core 1 actually filled — a key it omitted is
     * absent rather than empty, so spreading it cannot overwrite a default
     * with a blank. That is the same rule the 201-form scanner follows below:
     * filling a field with nothing is not filling it, it is erasing it.
     *
     * `endorsement_id` travels with the form and is the only thing about the
     * endorsement the server trusts from it — the values are re-read from the
     * row on the way in, exactly as `scan_id` works on a document upload.
     */
    const { data, setData, post, processing, errors } = useForm({
        ...BLANK_EMPLOYEE,
        ...prefill,
        endorsement_id: endorsement?.id ?? null,
    });

    const picker = useRef(null);
    const [scanning, setScanning] = useState(false);
    const [filled, setFilled] = useState(null);
    const [note, setNote] = useState(null);

    /**
     * Reads a photographed 201 form and fills what it could read.
     *
     * Only non-empty values are applied, and only over fields that are still
     * blank — a second scan, or a scan run after somebody has started typing,
     * must not overwrite a correction with a worse reading. Nothing is saved;
     * the Save button below still does that, and StoreEmployeeRequest
     * validates the result exactly as it does a hand-typed one.
     */
    const scanForm = async (file) => {
        if (!file) return;

        setScanning(true);
        setFilled(null);
        setNote(null);

        try {
            const body = new FormData();
            body.append('file', file);

            const { data: result } = await axios.post('/hr/employees/scan-form', body);

            if (!result.scanned) {
                setNote('Nothing could be read from that image.');

                return;
            }

            const applied = [];

            Object.entries(result.fields).forEach(([field, value]) => {
                if (field === 'note' || value === null || value === '') return;
                // Blank means untouched. `nationality` ships with a default,
                // so it counts as blank when it still holds that default.
                const current = data[field];
                const untouched =
                    current === '' ||
                    current === null ||
                    (field === 'nationality' && current === 'Filipino');

                if (!untouched) return;

                setData(field, value);
                applied.push(field);
            });

            setFilled(applied);
            setNote(result.fields.note);
        } catch {
            setNote('The form could not be read. The record can still be typed below.');
        } finally {
            setScanning(false);
            // Clearing lets the same file be picked again after a correction.
            if (picker.current) picker.current.value = '';
        }
    };

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
            {/* Where this record came from, and what is still being decided.
                Stated on the form rather than left behind on the review screen:
                saving this is the approval, and somebody two screens deep in a
                twenty-field form should not have to remember that. */}
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

            {/* Above the form, because it is a way of *starting* the form
                rather than a field in it. Not drawn at all when no scanner is
                configured — an offered button that 404s is worse than none. */}
            {can.scanForm && (
                <Card className="mb-5">
                    <CardBody className="flex flex-wrap items-center gap-3">
                        <ScanLine
                            className="h-5 w-5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium text-foreground">
                                Start from a paper 201 form
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Photograph or scan the sheet and the fields below are filled in.
                                Every value is yours to correct before saving.
                            </p>
                        </div>

                        <input
                            ref={picker}
                            type="file"
                            accept="image/*"
                            className="hidden"
                            onChange={(event) => scanForm(event.target.files?.[0] ?? null)}
                        />

                        <Button
                            type="button"
                            variant="outline"
                            loading={scanning}
                            onClick={() => picker.current?.click()}
                        >
                            {!scanning && <ScanLine className="h-4 w-4" aria-hidden="true" />}
                            {scanning ? 'Reading…' : 'Scan form'}
                        </Button>
                    </CardBody>

                    {(filled || note) && (
                        <CardBody className="border-t border-border pt-3">
                            {filled && filled.length > 0 && (
                                <p className="flex items-start gap-1.5 text-xs text-success">
                                    <CheckCircle2
                                        className="mt-0.5 h-3.5 w-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    {/* Naming the count rather than the fields: a
                                        list of twenty snake_case keys is not
                                        something anybody reads. */}
                                    Filled in {filled.length} field
                                    {filled.length === 1 ? '' : 's'}. Check each one against the
                                    sheet before saving.
                                </p>
                            )}

                            {filled && filled.length === 0 && (
                                <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
                                    <TriangleAlert
                                        className="mt-0.5 h-3.5 w-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    Nothing was filled in — the fields already had values, or
                                    none could be read.
                                </p>
                            )}

                            {note && (
                                <p className="mt-1.5 flex items-start gap-1.5 text-xs text-warning">
                                    <TriangleAlert
                                        className="mt-0.5 h-3.5 w-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    {note}
                                </p>
                            )}
                        </CardBody>
                    )}
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
