import { useForm } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import {
    Button,
    Card,
    CardBody,
    CardHeader,
    Field,
    Input,
    Select,
    Textarea,
} from '@/Components/ui';

export default function General({ settings, timezones, dateFormats }) {
    const form = useForm({
        company: {
            name: settings['company.name'] ?? '',
            tagline: settings['company.tagline'] ?? '',
            address: settings['company.address'] ?? '',
            email: settings['company.email'] ?? '',
            phone: settings['company.phone'] ?? '',
            tin: settings['company.tin'] ?? '',
            sss_employer_number: settings['company.sss_employer_number'] ?? '',
            philhealth_employer_number: settings['company.philhealth_employer_number'] ?? '',
            pagibig_employer_number: settings['company.pagibig_employer_number'] ?? '',
        },
        regional: {
            timezone: settings['regional.timezone'] ?? 'Asia/Manila',
            date_format: settings['regional.date_format'] ?? 'M j, Y',
            currency: settings['regional.currency'] ?? 'PHP',
            week_starts_on: settings['regional.week_starts_on'] ?? 1,
        },
    });

    const set = (group, key) => (event) =>
        form.setData(group, { ...form.data[group], [key]: event.target.value });

    const error = (group, key) => form.errors[`${group}.${key}`];

    const submit = (event) => {
        event.preventDefault();
        form.put('/settings/general', { preserveScroll: true });
    };

    return (
        <SettingsLayout title="General">
            <form onSubmit={submit} className="space-y-5">
                <Card>
                    <CardHeader title="Company Profile" />
                    <CardBody className="grid gap-4 sm:grid-cols-2">
                        <Field label="Company Name" required error={error('company', 'name')}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.company.name}
                                    onChange={set('company', 'name')}
                                    error={error('company', 'name')}
                                />
                            )}
                        </Field>

                        <Field label="Tagline" error={error('company', 'tagline')}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.company.tagline}
                                    onChange={set('company', 'tagline')}
                                />
                            )}
                        </Field>

                        <Field
                            label="Address"
                            className="sm:col-span-2"
                            error={error('company', 'address')}
                        >
                            {({ id }) => (
                                <Textarea
                                    id={id}
                                    rows={2}
                                    value={form.data.company.address}
                                    onChange={set('company', 'address')}
                                />
                            )}
                        </Field>

                        <Field label="Email" error={error('company', 'email')}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="email"
                                    value={form.data.company.email}
                                    onChange={set('company', 'email')}
                                    error={error('company', 'email')}
                                />
                            )}
                        </Field>

                        <Field label="Phone" error={error('company', 'phone')}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.company.phone}
                                    onChange={set('company', 'phone')}
                                />
                            )}
                        </Field>
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title="Employer Registration Numbers" />
                    <CardBody className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Field label="TIN" error={error('company', 'tin')}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.company.tin}
                                    onChange={set('company', 'tin')}
                                />
                            )}
                        </Field>

                        <Field
                            label="SSS Employer No."
                            error={error('company', 'sss_employer_number')}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.company.sss_employer_number}
                                    onChange={set('company', 'sss_employer_number')}
                                />
                            )}
                        </Field>

                        <Field
                            label="PhilHealth Employer No."
                            error={error('company', 'philhealth_employer_number')}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.company.philhealth_employer_number}
                                    onChange={set('company', 'philhealth_employer_number')}
                                />
                            )}
                        </Field>

                        <Field
                            label="Pag-IBIG Employer No."
                            error={error('company', 'pagibig_employer_number')}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.company.pagibig_employer_number}
                                    onChange={set('company', 'pagibig_employer_number')}
                                />
                            )}
                        </Field>
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title="Regional" />
                    <CardBody className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Field label="Timezone" required error={error('regional', 'timezone')}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={form.data.regional.timezone}
                                    onChange={set('regional', 'timezone')}
                                    options={timezones.map((zone) => ({
                                        value: zone,
                                        label: zone,
                                    }))}
                                />
                            )}
                        </Field>

                        <Field
                            label="Date Format"
                            required
                            error={error('regional', 'date_format')}
                        >
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={form.data.regional.date_format}
                                    onChange={set('regional', 'date_format')}
                                    options={dateFormats}
                                />
                            )}
                        </Field>

                        <Field label="Currency" required error={error('regional', 'currency')}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={form.data.regional.currency}
                                    onChange={set('regional', 'currency')}
                                    options={[
                                        { value: 'PHP', label: 'PHP — Philippine Peso' },
                                        { value: 'USD', label: 'USD — US Dollar' },
                                    ]}
                                />
                            )}
                        </Field>

                        <Field
                            label="Week Starts On"
                            required
                            error={error('regional', 'week_starts_on')}
                        >
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={form.data.regional.week_starts_on}
                                    onChange={set('regional', 'week_starts_on')}
                                    options={[
                                        { value: 1, label: 'Monday' },
                                        { value: 7, label: 'Sunday' },
                                    ]}
                                />
                            )}
                        </Field>
                    </CardBody>
                </Card>

                <div className="flex justify-end">
                    <Button type="submit" loading={form.processing}>
                        Save Changes
                    </Button>
                </div>
            </form>
        </SettingsLayout>
    );
}
