import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, CheckCircle2, CircleAlert, UserCheck, XCircle } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    Field,
    Modal,
    Textarea,
} from '@/Components/ui';
import { formatDate } from '@/lib/utils';

/**
 * Groups of the payload worth showing, in the order somebody reads them.
 *
 * Driven by a list rather than by iterating the payload's own keys: Core 1
 * decides what it sends, and rendering whatever arrives would put an unlabelled
 * `applicant_tracking_uuid` on screen beside somebody's birthday. A key this
 * system has no label for is not shown — it is still kept on the row, whole,
 * for anyone who needs it.
 */
const SECTIONS = [
    {
        title: 'Personal',
        fields: [
            ['birth_date', 'Date of birth', 'date'],
            ['birth_place', 'Place of birth'],
            ['gender', 'Sex'],
            ['civil_status', 'Civil status'],
            ['nationality', 'Nationality'],
            ['religion', 'Religion'],
            ['blood_type', 'Blood type'],
        ],
    },
    {
        title: 'Contact',
        fields: [
            ['email', 'Email'],
            ['mobile_number', 'Mobile'],
            ['phone_number', 'Landline'],
            ['present_address', 'Present address'],
            ['permanent_address', 'Permanent address'],
        ],
    },
    {
        title: 'Emergency contact',
        fields: [
            ['emergency_contact_name', 'Name'],
            ['emergency_contact_relationship', 'Relationship'],
            ['emergency_contact_number', 'Contact number'],
        ],
    },
    {
        title: 'Government numbers',
        fields: [
            ['sss_number', 'SSS'],
            ['philhealth_number', 'PhilHealth'],
            ['pagibig_number', 'Pag-IBIG'],
            ['tin', 'TIN'],
        ],
    },
    {
        title: 'Licence',
        fields: [
            ['drivers_license_number', "Driver's licence"],
            ['license_dl_codes', 'DL codes'],
            ['license_conditions', 'Conditions'],
            ['license_expiry', 'Expires', 'date'],
        ],
    },
];

export default function Show({ endorsement, fullName, missing, can }) {
    const [rejecting, setRejecting] = useState(false);

    const form = useForm({ decision_note: '' });

    const payload = endorsement.payload ?? {};

    const submitRejection = (event) => {
        event.preventDefault();

        form.post(`/hr/endorsements/${endorsement.id}/reject`, {
            preserveScroll: true,
            onSuccess: () => setRejecting(false),
        });
    };

    // A section with nothing in it is not drawn — an empty "Licence" heading
    // reads as a failure to load rather than as a candidate who does not drive.
    const populated = SECTIONS.map((section) => ({
        ...section,
        rows: section.fields
            .map(([key, label, type]) => [label, payload[key], type])
            .filter(([, value]) => value !== null && value !== undefined && value !== ''),
    })).filter((section) => section.rows.length > 0);

    return (
        <AppLayout title={`Endorsement · ${fullName}`}>
            <div className="mb-5">
                <Link
                    href="/hr/endorsements"
                    className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="h-4 w-4" aria-hidden="true" />
                    Back to endorsements
                </Link>
            </div>

            <Card floating className="mb-5">
                <CardBody>
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="text-lg font-semibold text-foreground">
                                    {fullName}
                                </h2>
                                <Badge status={endorsement.status}>{endorsement.status}</Badge>
                            </div>

                            <p className="mt-1 text-sm text-muted-foreground">
                                {endorsement.position_title ?? 'No position stated'}
                                {endorsement.client_name && ` · ${endorsement.client_name}`}
                            </p>

                            <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-muted-foreground">
                                <div className="flex gap-1.5">
                                    <dt>Reference</dt>
                                    <dd className="font-mono text-foreground">
                                        {endorsement.reference}
                                    </dd>
                                </div>
                                <div className="flex gap-1.5">
                                    <dt>Source</dt>
                                    <dd className="uppercase text-foreground">
                                        {endorsement.source}
                                    </dd>
                                </div>
                                {endorsement.date_hired && (
                                    <div className="flex gap-1.5">
                                        <dt>Start date</dt>
                                        <dd className="text-foreground">
                                            {formatDate(endorsement.date_hired)}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </div>

                        {/* Wraps: "Approve & complete record" beside "Decline"
                            is about 340px of button, and a 375px phone has
                            335px to put it in. */}
                        {can.decide && (
                            <div className="flex flex-wrap gap-2 sm:shrink-0">
                                <Button
                                    variant="outline"
                                    onClick={() => setRejecting(true)}
                                    type="button"
                                >
                                    <XCircle className="h-4 w-4" />
                                    Decline
                                </Button>

                                {/* Approving opens the employee form rather
                                    than creating the record outright. Core 1
                                    cannot know the salary, the category, or
                                    which client is billed — and those are not
                                    fields to default silently on somebody
                                    about to be put on a payroll. */}
                                <Button
                                    href={`/hr/employees/create?endorsement=${endorsement.id}`}
                                >
                                    <UserCheck className="h-4 w-4" />
                                    Approve &amp; complete record
                                </Button>
                            </div>
                        )}
                    </div>
                </CardBody>
            </Card>

            {/* --- The decision already taken, if there is one --- */}
            {endorsement.status === 'approved' && endorsement.employee && (
                <Card floating className="mb-5 border-success/30">
                    <CardBody className="flex flex-wrap items-center gap-3">
                        <CheckCircle2
                            className="h-5 w-5 shrink-0 text-success"
                            aria-hidden="true"
                        />
                        <p className="text-sm text-foreground">
                            Approved
                            {endorsement.decided_by_name &&
                                ` by ${endorsement.decided_by_name}`}
                            {endorsement.decided_at &&
                                ` on ${formatDate(endorsement.decided_at)}`}
                            .
                        </p>
                        <Link
                            href={`/hr/employees/${endorsement.employee.id}`}
                            className="text-sm font-medium text-primary hover:underline"
                        >
                            {endorsement.employee.employee_number}
                        </Link>
                    </CardBody>
                </Card>
            )}

            {endorsement.status === 'rejected' && (
                <Card floating className="mb-5 border-destructive/30">
                    <CardBody>
                        <div className="flex items-start gap-3">
                            <XCircle
                                className="mt-0.5 h-5 w-5 shrink-0 text-destructive"
                                aria-hidden="true"
                            />
                            <div className="min-w-0">
                                <p className="text-sm font-medium text-foreground">
                                    Declined
                                    {endorsement.decided_by_name &&
                                        ` by ${endorsement.decided_by_name}`}
                                    {endorsement.decided_at &&
                                        ` on ${formatDate(endorsement.decided_at)}`}
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {endorsement.decision_note}
                                </p>
                            </div>
                        </div>
                    </CardBody>
                </Card>
            )}

            <div className="grid gap-5 lg:grid-cols-3">
                {/* --- What Core 1 sent --- */}
                <div className="space-y-5 lg:col-span-2">
                    {populated.length === 0 && (
                        <Card floating>
                            <CardBody>
                                <p className="text-sm text-muted-foreground">
                                    Core 1 sent a name and nothing else. Everything below will
                                    need to be filled in on the employee form.
                                </p>
                            </CardBody>
                        </Card>
                    )}

                    {populated.map((section) => (
                        <Card floating key={section.title}>
                            <CardHeader title={section.title} />
                            <CardBody>
                                <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                                    {section.rows.map(([label, value, type]) => (
                                        <div key={label} className="min-w-0">
                                            <dt className="text-xs text-muted-foreground">
                                                {label}
                                            </dt>
                                            <dd className="mt-0.5 break-words text-sm text-foreground">
                                                {type === 'date' ? formatDate(value) : value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </CardBody>
                        </Card>
                    ))}

                    {payload.remarks && (
                        <Card floating>
                            <CardHeader title="Remarks from recruitment" />
                            <CardBody>
                                <p className="whitespace-pre-line text-sm text-foreground">
                                    {payload.remarks}
                                </p>
                            </CardBody>
                        </Card>
                    )}
                </div>

                {/* --- What this system still has to decide --- */}
                {endorsement.status === 'pending' && (
                    <Card floating className="h-fit">
                        <CardHeader title="Still needed here" />
                        <CardBody>
                            <ul className="space-y-2.5">
                                {missing.map((item) => (
                                    <li key={item} className="flex gap-2.5 text-sm">
                                        <CircleAlert
                                            className="mt-0.5 h-4 w-4 shrink-0 text-warning"
                                            aria-hidden="true"
                                        />
                                        <span className="text-muted-foreground">{item}</span>
                                    </li>
                                ))}
                            </ul>
                        </CardBody>
                    </Card>
                )}
            </div>

            <Modal
                show={rejecting}
                onClose={() => setRejecting(false)}
                title={`Decline ${fullName}?`}
                description="Core 1 reads this reason back, so say what would have to change."
            >
                <form onSubmit={submitRejection} className="space-y-4">
                    <Field label="Reason" required error={form.errors.decision_note}>
                        <Textarea
                            value={form.data.decision_note}
                            onChange={(event) =>
                                form.setData('decision_note', event.target.value)
                            }
                            error={form.errors.decision_note}
                            rows={4}
                            placeholder="e.g. No LTO licence on file — cannot be deployed as a driver."
                        />
                    </Field>

                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setRejecting(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" variant="destructive" disabled={form.processing}>
                            Decline endorsement
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
