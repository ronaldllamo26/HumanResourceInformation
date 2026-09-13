/*
 * The public face of the HRIS, and deliberately the only thing in this
 * project.
 *
 * It is a separate deployment from the system it describes: this is static
 * HTML on Vercel, the HRIS is Laravel + Inertia on its own host. The two are
 * joined by exactly one thing — the "Sign in" link — because a landing page
 * that knew anything about employees would need the database, and then it
 * would not be a landing page any more.
 *
 * Everything claimed below is a feature that exists. A marketing page for a
 * system somebody is about to be graded on is the wrong place to describe
 * work that has not been done.
 */

/*
 * Where the real system lives. Set VITE_HRIS_URL in Vercel's environment
 * settings; the fallback is the subdomain the project was issued, so a deploy
 * that forgot the variable still lands somewhere real rather than on "#".
 */
const HRIS_URL = import.meta.env.VITE_HRIS_URL ?? 'https://core2man.primepowersystem.com';

const MODULES = [
    {
        title: 'Employee Information',
        body: 'The 201 file: personal records, employment history, qualifications, and every document filed against a person — with government identifiers encrypted at rest.',
    },
    {
        title: 'Timekeeping & Attendance',
        body: 'Daily time records, a whole-cutoff DTR grid, shift schedules, overtime approvals, and a correction workflow where the employee files and a supervisor decides.',
    },
    {
        title: 'Leave & Absence',
        body: 'Credits that accrue per month of service rather than being handed out in January, cross-checked against attendance so an authorised absence is never docked twice.',
    },
    {
        title: 'Payroll & Compensation',
        body: 'SSS, PhilHealth, Pag-IBIG and BIR withholding from published tables, 13th-month pay under PD 851, loan amortisation, and final pay against DOLE’s 30-day deadline.',
    },
    {
        title: 'Performance Management',
        body: 'KPI scorecards, review cycles, and a weighted score that re-normalises when a perspective is missing instead of counting it as zero.',
    },
];

const CAPABILITIES = [
    {
        label: 'Built for a manpower agency',
        body: 'Staff are either internal or deployed to a client company. One payroll run, split by who is billed for it — not five runs and five SSS remittances.',
    },
    {
        label: 'Deployment readiness',
        body: 'Answers whether a driver can lawfully be sent out tomorrow: credentials, 201-file completeness, and licence standing read together rather than on three screens.',
    },
    {
        label: 'Document scanning',
        body: 'A scanned licence or clearance is read and the upload form filled in for HR to confirm — so an expiry date keyed a year late stops being a driver the system believes is legal.',
    },
    {
        label: 'Integrated with ISMERS',
        body: 'A documented REST API carries hires in from recruitment, loans from benefits, and payroll totals out to finance. Five write doors, each safe to retry.',
    },
];

const SECURITY = [
    'Role-based sign-in: every login sees only what its role allows',
    'Government identifiers and bank details encrypted in the database',
    'Every read of a 201 file recorded, not only every change',
    'Salary and personal data gated per role, in the query and the policy',
    'Sessions expire after ten minutes of nobody being there',
];

function Logo() {
    return (
        <a href="#top" className="flex items-center gap-3">
            <img src="/logo.png" alt="" className="h-9 w-9 shrink-0 object-contain" />
            <span className="text-sm font-semibold uppercase tracking-wide">
                PrimePower
                <span className="block text-[0.65rem] font-normal tracking-normal text-muted-foreground">
                    Manpower Services
                </span>
            </span>
        </a>
    );
}

function SignIn({ className = '' }) {
    return (
        <a
            href={HRIS_URL}
            className={`inline-flex items-center justify-center rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${className}`}
        >
            Sign in to the HRIS
        </a>
    );
}

export default function App() {
    return (
        <div id="top" className="min-h-screen">
            {/* Header. The nav labels hide below `sm` the way the HRIS topbar
                does — the icon or the button stays, the word goes. */}
            <header className="sticky top-0 z-10 border-b border-border/50 bg-background/85 backdrop-blur">
                <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                    <Logo />
                    <nav className="flex items-center gap-6">
                        <a
                            href="#modules"
                            className="hidden text-sm text-muted-foreground transition hover:text-foreground sm:inline"
                        >
                            Modules
                        </a>
                        <a
                            href="#security"
                            className="hidden text-sm text-muted-foreground transition hover:text-foreground sm:inline"
                        >
                            Security
                        </a>
                        <SignIn />
                    </nav>
                </div>
            </header>

            <section className="mx-auto max-w-5xl px-4 pb-16 pt-16 sm:px-6 sm:pb-24 sm:pt-24">
                <p className="text-sm font-semibold uppercase tracking-widest text-primary">
                    Core Transaction 2 · ISMERS
                </p>
                <h1 className="mt-4 max-w-3xl text-4xl font-bold leading-tight tracking-tight sm:text-5xl">
                    The employee record, from endorsement to final pay.
                </h1>
                <p className="mt-6 max-w-2xl text-lg leading-relaxed text-muted-foreground">
                    A Human Resource Information System for a fleet and transportation manpower
                    company — where the workforce is PrimePower&rsquo;s to pay and the client
                    company&rsquo;s to direct, and the payroll has to satisfy the Labor Code
                    either way.
                </p>
                <div className="mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
                    <SignIn />
                    <a
                        href="#modules"
                        className="inline-flex items-center justify-center rounded-lg border border-border px-5 py-2.5 text-sm font-semibold transition hover:bg-muted"
                    >
                        What it covers
                    </a>
                </div>
            </section>

            <section id="modules" className="border-t border-border/50 bg-card/60">
                <div className="mx-auto max-w-5xl px-4 py-16 sm:px-6 sm:py-20">
                    <h2 className="text-2xl font-bold tracking-tight sm:text-3xl">
                        Five modules
                    </h2>
                    <p className="mt-3 max-w-2xl text-muted-foreground">
                        Each one owns its records; none of them keeps a private copy of another
                        module&rsquo;s rules.
                    </p>
                    <div className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {MODULES.map((module, index) => (
                            <article
                                key={module.title}
                                className="rounded-lg border border-border/60 bg-background p-6"
                            >
                                <span className="text-xs font-semibold text-primary">
                                    {String(index + 1).padStart(2, '0')}
                                </span>
                                <h3 className="mt-2 font-semibold">{module.title}</h3>
                                <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                    {module.body}
                                </p>
                            </article>
                        ))}
                    </div>
                </div>
            </section>

            <section className="mx-auto max-w-5xl px-4 py-16 sm:px-6 sm:py-20">
                <h2 className="text-2xl font-bold tracking-tight sm:text-3xl">
                    What makes it an agency&rsquo;s system
                </h2>
                <div className="mt-10 grid gap-8 sm:grid-cols-2">
                    {CAPABILITIES.map((item) => (
                        <div key={item.label} className="border-l-2 border-primary pl-5">
                            <h3 className="font-semibold">{item.label}</h3>
                            <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                {item.body}
                            </p>
                        </div>
                    ))}
                </div>
            </section>

            <section id="security" className="border-y border-border/50 bg-card/60">
                <div className="mx-auto max-w-5xl px-4 py-16 sm:px-6 sm:py-20">
                    <h2 className="text-2xl font-bold tracking-tight sm:text-3xl">
                        Personal data, treated as such
                    </h2>
                    <p className="mt-3 max-w-2xl text-muted-foreground">
                        A 201 file holds salary, government identifiers, and bank details. Under
                        RA&nbsp;10173 that is regulated data, and the controls are in the system
                        rather than in a policy document.
                    </p>
                    <ul className="mt-9 grid gap-x-10 gap-y-4 sm:grid-cols-2">
                        {SECURITY.map((line) => (
                            <li key={line} className="flex gap-3 text-sm leading-relaxed">
                                <svg
                                    viewBox="0 0 20 20"
                                    aria-hidden="true"
                                    className="mt-0.5 h-4 w-4 shrink-0 fill-primary"
                                >
                                    <path d="M8.1 14.5 4 10.4l1.4-1.4 2.7 2.6L14.6 5l1.4 1.5z" />
                                </svg>
                                <span className="text-muted-foreground">{line}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            </section>

            <section className="mx-auto max-w-5xl px-4 py-20 text-center sm:px-6">
                <h2 className="text-2xl font-bold tracking-tight sm:text-3xl">
                    For PrimePower staff
                </h2>
                <p className="mx-auto mt-3 max-w-xl text-muted-foreground">
                    Accounts are provisioned by HR — there is no public sign-up, by design.
                </p>
                <div className="mt-8 flex justify-center">
                    <SignIn />
                </div>
            </section>

            <footer className="border-t border-border/50">
                <div className="mx-auto flex max-w-5xl flex-col gap-4 px-4 py-8 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <Logo />
                    <p className="max-w-md">
                        PrimePower Manpower Services · Core Transaction 2 of the Integrated
                        Service Management &amp; Enterprise Resource System
                    </p>
                </div>
            </footer>
        </div>
    );
}
