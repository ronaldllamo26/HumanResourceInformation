import { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import { LogoMark } from '@/Components/layout/PrimePowerLogo';
import { Button, Input, InputError, Label } from '@/Components/ui';

/**
 * The one page an unauthenticated visitor sees.
 *
 * A floating two-panel card on a deep brand gradient — the shape the owner
 * asked for, matching the sign-in screens the other ISMERS systems use, so
 * somebody moving between Core 2 and Finance Management is not meeting a
 * different building each time. It has been a centred form-and-logo card and
 * a full-bleed split; this is the third, and it keeps what the second was
 * for: half the screen says what this system *is* rather than showing a
 * picture of the logo.
 *
 * **Below `lg` the brand panel is gone, not stacked.** A phone should not
 * scroll past a page of prose to reach two fields, so the brand collapses to
 * the logo row above the form.
 *
 * Deliberately absent, and each for a reason this system actually has:
 *
 *  - **A demo credential line.** The screen this was modelled on prints one.
 *    This system holds salary, government identifiers and bank details, and a
 *    working login painted on its own front door is not a demo convenience —
 *    it is the credential handed to whoever finds the page. The seeded local
 *    password is in CLAUDE.md, where a reader of the repository finds it and
 *    a visitor to the deployment does not.
 *  - **Social sign-in.** No provider is configured and logins are provisioned
 *    by HR against an employee record. A button that cannot work is worse
 *    than no button.
 *  - **A role picker.** Roles come from the account. A dropdown at sign-in
 *    either does nothing or implies you pick your own permissions.
 *  - **A sign-up link.** Self-registration is disabled by design.
 *  - **A forgot-password link.** Accounts have no inbox to send a reset to —
 *    the personal address on an account receives *sign-in codes* and is not a
 *    password channel. An administrator resets a password from Users &
 *    Access, which the line under the button says in words.
 */
export default function Login({ status }) {
    const brand = usePage().props.brand ?? {};
    const name = brand.name ?? 'PrimePower';

    const [showPassword, setShowPassword] = useState(false);

    // `remember` is not sent at all, rather than sent as false: Fortify reads
    // it off the request, and a key that is never there cannot be flipped on
    // by anything the browser does.
    const { data, setData, post, processing, errors, reset } = useForm({
        username: '',
        password: '',
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-primary px-4 py-10 sm:px-6">
            <Head title="Log in" />

            {/*
                The page behind the card, in the brand blue rather than the
                navy this used to be: every colour on this screen now comes
                out of the palette table — #007DCC lifting into #0F1B26 (the
                palette's own dark background, carried by `--hero`) — so the
                sign-in screen and the application it opens are the same two
                colours rather than a third one invented for the front door.
            */}
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 bg-gradient-to-br from-primary via-primary to-hero"
            />
            <div
                aria-hidden="true"
                className="pointer-events-none absolute -left-32 top-1/4 h-[28rem] w-[28rem] rounded-full bg-hero/30 blur-3xl"
            />

            <div className="relative grid w-full max-w-5xl overflow-hidden rounded-3xl bg-card shadow-2xl lg:grid-cols-2">
                {/* Brand panel */}
                <section className="relative hidden flex-col justify-center overflow-hidden bg-gradient-to-br from-hero via-hero to-primary p-10 text-hero-foreground lg:flex xl:p-12">
                    {/* Two outlined circles bleeding off opposite corners.
                        Drawn rather than an image, so they cost no request and
                        cannot be the asset that fails to load. */}
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute -right-24 -top-28 h-80 w-80 rounded-full border border-hero-foreground/15"
                    />
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute -bottom-32 -left-24 h-72 w-72 rounded-full border border-hero-foreground/10"
                    />

                    <div className="relative">
                        {/*
                            The company's own artwork, at a size where it can
                            actually be read — its wordmark is illegible below
                            roughly 200px, which is why the sidebar's 40px mark
                            is paired with text and this one is not. On a white
                            disc because the globe is full-colour with a red
                            wordmark and it disappears into the panel without
                            one.
                        */}
                        <span className="grid h-28 w-28 place-items-center rounded-full bg-card p-3 shadow-lg">
                            <LogoMark className="h-20 w-20" />
                        </span>

                        <p className="mt-7 text-[11px] font-semibold uppercase tracking-[0.2em] text-hero-muted">
                            {name} Manpower
                        </p>
                        <p className="mt-1 text-2xl font-bold leading-tight tracking-tight">
                            Human Resource Information System
                        </p>

                        <p className="mt-8 inline-flex rounded-full border border-hero-foreground/20 px-3.5 py-1.5 text-[10px] font-semibold uppercase tracking-[0.18em] text-hero-foreground/90">
                            HR Control Center
                        </p>

                        <h1 className="mt-6 text-4xl font-bold leading-[1.1] tracking-tight">
                            Welcome to
                            <br />
                            {name} Manpower
                        </h1>

                        <p className="mt-5 max-w-sm text-sm leading-relaxed text-hero-muted">
                            Manage employee records, timekeeping, leave, payroll, and
                            performance from one secure system — for the agency&apos;s own staff
                            and for everybody deployed to a client.
                        </p>
                    </div>
                </section>

                {/* Form */}
                <section className="flex items-center justify-center px-6 py-12 sm:px-10 lg:px-12">
                    <div className="w-full max-w-sm">
                        {/* The brand, for the screens the panel is hidden on. */}
                        <div className="mb-8 flex items-center justify-center gap-2.5 lg:hidden">
                            <LogoMark className="h-14 w-14" />
                            <span className="text-lg font-bold tracking-tight text-logo-primary">
                                {name.toUpperCase()}
                            </span>
                        </div>

                        <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-primary">
                            Account access
                        </p>
                        <h2 className="mt-2 text-3xl font-bold tracking-tight text-foreground">
                            Sign In
                        </h2>

                        {status && (
                            <p
                                role="status"
                                className="mt-5 rounded-md border border-success/20 bg-success/10 px-3 py-2 text-sm font-medium text-success"
                            >
                                {status}
                            </p>
                        )}

                        <form onSubmit={submit} className="mt-8 space-y-4" autoComplete="off">
                            <div>
                                {/* "Username", not "Email Address": this is a
                                    company login with no inbox behind it, and
                                    labelling it as an email is what had people
                                    typing their Gmail into it. */}
                                <Label htmlFor="username">Username or Gmail</Label>
                                <Input
                                    id="username"
                                    type="text"
                                    name="auth_account_id"
                                    value={data.username}
                                    autoComplete="one-time-code"
                                    data-lpignore="true"
                                    data-1p-ignore="true"
                                    data-form-type="other"
                                    placeholder="Username, company email, or Gmail"
                                    autoCapitalize="none"
                                    spellCheck={false}
                                    error={errors.username}
                                    onChange={(event) =>
                                        setData('username', event.target.value)
                                    }
                                />
                                <InputError message={errors.username} />
                            </div>

                            <div>
                                <Label htmlFor="password">Password</Label>
                                <div className="relative">
                                    <Input
                                        id="password"
                                        type="text"
                                        style={{
                                            WebkitTextSecurity: showPassword ? 'none' : 'disc',
                                        }}
                                        name="auth_credential_key"
                                        value={data.password}
                                        autoComplete="off"
                                        data-lpignore="true"
                                        data-1p-ignore="true"
                                        data-form-type="other"
                                        placeholder="Enter your password"
                                        className="pr-10"
                                        error={errors.password}
                                        onChange={(event) =>
                                            setData('password', event.target.value)
                                        }
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(!showPassword)}
                                        className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground focus:outline-none"
                                        aria-label={showPassword ? 'Hide password' : 'Show password'}
                                    >
                                        {showPassword ? (
                                            <EyeOff className="h-4 w-4" />
                                        ) : (
                                            <Eye className="h-4 w-4" />
                                        )}
                                    </button>
                                </div>
                                <InputError message={errors.password} />
                            </div>

                            {/* No "Remember me", and its absence is load-bearing.
                                The box issued a long-lived cookie that signs the
                                holder back in after the session cookie has gone —
                                which is exactly what the five-minute idle timeout
                                exists to prevent. Left in, an unattended machine
                                would sign itself back in on the next click and the
                                timeout would be theatre. */}
                            <Button
                                type="submit"
                                size="lg"
                                className="w-full"
                                loading={processing}
                                disabled={processing}
                            >
                                Login to Dashboard
                            </Button>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    );
}
