import { Head, Link, router, useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { LogoMark } from '@/Components/layout/PrimePowerLogo';
import { Button, InputError } from '@/Components/ui';
import { formatDate } from '@/lib/utils';

/**
 * The privacy notice (RA 10173).
 *
 * Shown before first use and whenever the notice's version changes, and
 * readable afterwards from Settings > Security. It deliberately has no sidebar:
 * while it is pending every other screen redirects back here, so a menu would
 * be a row of links that go nowhere.
 */
function Section({ title, children }) {
    return (
        <section className="space-y-2">
            <h2 className="text-sm font-semibold text-foreground">{title}</h2>
            <div className="space-y-2 text-sm leading-relaxed text-muted-foreground">
                {children}
            </div>
        </section>
    );
}

export default function PrivacyNotice({
    version,
    company,
    contact,
    retention,
    scannerProcessor,
    acknowledgedAt,
}) {
    const form = useForm({ understood: false });
    const name = company || 'PrimePower';

    const submit = (event) => {
        event.preventDefault();
        form.post(route('privacy.acknowledge'));
    };

    return (
        <div className="min-h-screen bg-background px-4 py-8 sm:py-12">
            <Head title="Privacy Notice" />

            <div className="mx-auto w-full max-w-3xl">
                <div className="mb-6 flex items-center justify-center gap-2.5">
                    <LogoMark className="h-14 w-14" />
                    <span className="text-lg font-bold tracking-tight text-logo-primary">
                        {name.toUpperCase()}
                    </span>
                </div>

                <div className="rounded-xl border border-border bg-card px-5 py-7 text-card-foreground shadow-lg sm:px-8">
                    <div className="mb-6 flex items-start gap-3">
                        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                            <ShieldCheck className="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-xl font-bold tracking-tight text-foreground">
                                Privacy Notice
                            </h1>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                How {name} handles your personal information under the Data
                                Privacy Act of 2012 (RA 10173). Version {version}.
                            </p>
                        </div>
                    </div>

                    <div className="space-y-6">
                        <Section title="What we collect">
                            <p>
                                Your name, birth details, contact details, address and emergency
                                contact; your employment details (position, department, client,
                                dates, status); your government numbers (SSS, PhilHealth,
                                Pag-IBIG, TIN); your salary and bank account; your driver&apos;s
                                license; your education, trainings and skills; your attendance,
                                leave, payslips and performance reviews; and the documents in
                                your 201 file. Religion and blood type are optional.
                            </p>
                        </Section>

                        <Section title="Why we collect it">
                            <p>
                                To employ you and pay you correctly: payroll, statutory
                                contributions and tax (SSS, PhilHealth, Pag-IBIG, BIR), leave
                                and attendance, performance reviews, deployment to client
                                companies, and the records the Labor Code requires employers to
                                keep. We do not use it for anything else and we do not sell it.
                            </p>
                        </Section>

                        <Section title="Who can see it">
                            <ul className="list-disc space-y-1 pl-5">
                                <li>
                                    <span className="text-foreground">You</span> — your own
                                    record, payslips and filings.
                                </li>
                                <li>
                                    <span className="text-foreground">Your supervisor</span> —
                                    your work record, but not your salary, bank account or
                                    government numbers.
                                </li>
                                <li>
                                    <span className="text-foreground">
                                        HR staff and administrators
                                    </span>{' '}
                                    — the full record, to do their jobs.
                                </li>
                                <li>
                                    <span className="text-foreground">Government agencies</span>{' '}
                                    (SSS, PhilHealth, Pag-IBIG, BIR) — what the law requires us
                                    to report.
                                </li>
                            </ul>
                        </Section>

                        <Section title="How we protect it">
                            <ul className="list-disc space-y-1 pl-5">
                                <li>
                                    Government numbers, bank account and license number are
                                    encrypted in the database with AES-256, and hidden on screen
                                    until somebody allowed to see them presses Show.
                                </li>
                                <li>All connections to the system are encrypted (HTTPS).</li>
                                <li>
                                    Every change to your record, every time somebody opens it or
                                    its documents, and every time a hidden number is shown is
                                    recorded with who did it and when.
                                </li>
                                <li>
                                    Access is by role, and your login is switched off when you
                                    leave the company.
                                </li>
                            </ul>
                        </Section>

                        {scannerProcessor && (
                            <Section title="Document scanning">
                                <p>
                                    When HR uses the document scanner on an ID or certificate in
                                    your 201 file, the image is sent to{' '}
                                    <span className="text-foreground">{scannerProcessor}</span>{' '}
                                    to read it. This service is outside the Philippines. The
                                    reading only fills in a form that HR checks; it is not used
                                    for anything else.
                                </p>
                            </Section>
                        )}

                        <Section title="How long we keep it">
                            <p>
                                Employment records for at least {retention.employment_years}{' '}
                                years and payroll records for at least {retention.payroll_years}{' '}
                                years after you leave, as the Labor Code and the Tax Code
                                require. Records are archived, not deleted, when you leave.
                            </p>
                        </Section>

                        <Section title="Your rights">
                            <p>
                                You may ask to see the personal information we hold about you,
                                have mistakes corrected, object to processing that the law does
                                not require, and be told if your information is ever
                                compromised. You may also file a complaint with the National
                                Privacy Commission.
                            </p>
                        </Section>

                        <Section title="Who to contact">
                            <p>
                                <span className="text-foreground">{contact.name}</span>
                                {contact.email && <> · {contact.email}</>}
                                {contact.phone && <> · {contact.phone}</>}
                                {!contact.email && !contact.phone && (
                                    <> — ask HR, who will direct your request.</>
                                )}
                            </p>
                        </Section>
                    </div>

                    <div className="mt-8 border-t border-border pt-6">
                        {acknowledgedAt ? (
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <p className="text-sm text-muted-foreground">
                                    You acknowledged this notice on{' '}
                                    <span className="font-medium text-foreground">
                                        {formatDate(acknowledgedAt)}
                                    </span>
                                    .
                                </p>
                                <Link href={route('settings.security')}>
                                    <Button variant="outline">Back to Security</Button>
                                </Link>
                            </div>
                        ) : (
                            <form onSubmit={submit} className="space-y-4">
                                <label className="flex items-start gap-2.5">
                                    <input
                                        type="checkbox"
                                        checked={form.data.understood}
                                        onChange={(event) =>
                                            form.setData('understood', event.target.checked)
                                        }
                                        className="mt-0.5 h-4 w-4 rounded border-input text-primary focus:ring-ring/30"
                                    />
                                    <span className="text-sm text-foreground">
                                        I have read and understood this privacy notice.
                                    </span>
                                </label>
                                <InputError message={form.errors.understood} />

                                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-between">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => router.post(route('logout'))}
                                    >
                                        Sign out
                                    </Button>
                                    <Button
                                        type="submit"
                                        loading={form.processing}
                                        disabled={!form.data.understood || form.processing}
                                    >
                                        I understand — continue
                                    </Button>
                                </div>
                            </form>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
