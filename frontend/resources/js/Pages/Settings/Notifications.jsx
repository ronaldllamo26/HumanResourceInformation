import { useForm } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Button, Card, CardBody, CardHeader, Field, Input } from '@/Components/ui';

const EVENTS = [
    {
        key: 'leave_filed',
        label: 'Leave filed',
        description: "Notifies the employee's supervisor that a request is waiting.",
    },
    {
        key: 'leave_endorsed',
        label: 'Leave endorsed',
        description:
            'Notifies HR that a supervisor has signed off and credits are ready to move.',
    },
    {
        key: 'overtime_filed',
        label: 'Overtime filed',
        description: 'Notifies the supervisor and HR that overtime needs approving.',
    },
    {
        key: 'payroll_for_approval',
        label: 'Payroll submitted',
        description: 'Notifies administrators that a run is waiting on approval.',
    },
    {
        key: 'review_assigned',
        label: 'Evaluation assigned',
        description: 'Notifies a reviewer when a cycle rollout gives them an evaluation.',
    },
    {
        key: 'document_expiring',
        label: 'Document expiring',
        description: "Flags 201-file documents and driver's licences nearing expiry.",
    },
];

export default function Notifications({ settings }) {
    const form = useForm({
        notifications: EVENTS.reduce(
            (accumulator, event) => ({
                ...accumulator,
                [event.key]: Boolean(settings[`notifications.${event.key}`]),
            }),
            { expiry_lead_days: settings['notifications.expiry_lead_days'] ?? 30 },
        ),
    });

    const toggle = (key) => (event) =>
        form.setData('notifications', {
            ...form.data.notifications,
            [key]: event.target.checked,
        });

    const submit = (event) => {
        event.preventDefault();
        form.put('/settings/notifications', { preserveScroll: true });
    };

    return (
        <SettingsLayout title="Notifications">
            <form onSubmit={submit} className="space-y-5">
                <Card>
                    <CardHeader title="Events" />
                    <CardBody className="divide-y divide-border">
                        {EVENTS.map((event) => (
                            <label
                                key={event.key}
                                className="flex items-start gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <input
                                    type="checkbox"
                                    checked={Boolean(form.data.notifications[event.key])}
                                    onChange={toggle(event.key)}
                                    className="mt-0.5 h-4 w-4 rounded border-input text-primary focus:ring-ring/30"
                                />
                                <span className="min-w-0">
                                    <span className="block text-sm font-medium text-foreground">
                                        {event.label}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {event.description}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title="Expiry Warnings" />
                    <CardBody>
                        <Field
                            label="Lead time (days)"
                            required
                            className="max-w-xs"
                            error={form.errors['notifications.expiry_lead_days']}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    min="1"
                                    max="180"
                                    value={form.data.notifications.expiry_lead_days}
                                    onChange={(event) =>
                                        form.setData('notifications', {
                                            ...form.data.notifications,
                                            expiry_lead_days: event.target.value,
                                        })
                                    }
                                    error={form.errors['notifications.expiry_lead_days']}
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
