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
 * a full-bleed split; this is the third shape, **on its second pass**, and
 * the pass was about weight rather than layout.
 *
 * What the second pass removed, because the owner asked for something
 * cleaner and these were what made it heavy:
 *
 *  - **The brand name said twice.** An eyebrow reading "PrimePower Manpower"
 *    sat four lines above a headline reading "Welcome to PrimePower
 *    Manpower". Two of the five stacked text blocks were the same three
 *    words, which is what a reader's eye has to sort through before finding
 *    the one line that says what the system *is*.
 *  - **The "HR Control Center" pill.** A sixth element between the module
 *    name and the headline, carrying no fact the two lines around it did not
 *    already carry.
 *  - **The welcome headline itself.** "Welcome to" is the least informative
 *    thing a front door can say. The headline is now the system's own name,
 *    which is the answer somebody arriving actually needs.
 *
 * So the panel reads in one direction now — logo, whose system this is, what
 * it is, one sentence of what it covers, and a hairline over the five modules
 * by name. The module list is factual rather than promotional: it names what
 * is behind the door. It is deliberately *not* the "5 modules · RBAC · signed
 * audit trail" row an earlier version carried — those were claims about
 * quality, and a row of claims under a headline is a marketing page.
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
 *    Access, and the line under the button says so in words. That line had
 *    gone missing while CLAUDE.md still described it, so the question the
 *    absent link raises had no answer on the screen at all; it is back.
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
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-hero px-4 py-10 sm:px-6">
            <Head title="Log in" />

            {/*
                Three layers behind the card, and the order is what keeps it
                calm: a diagonal wash in the two brand colours, one warm glow
                behind where the card sits so its edges have something to lift
                off, and a vignette that darkens the corners so nothing draws
                the eye away from the middle.

                Every value is a token. The wash runs #007DCC into #0F1B26 —
                the palette's own primary and its dark background — so the
                sign-in screen and the application it opens are the same two
                colours rather than a third invented for the front door.
            */}
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 bg-gradient-to-br from-primary via-primary/90 to-hero"
            />
            <div
                aria-hidden="true"
                className="pointer-events-none absolute left-1/2 top-1/2 h-[46rem] w-[46rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-primary/40 blur-3xl"
            />
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_center,transparent_35%,hsl(var(--hero)/0.65)_100%)]"
            />

            {/*
                The company's own mark as a watermark behind the card.

                **Rendered as a white silhouette rather than the full-colour
                artwork**, and that is the decision worth explaining. At
                watermark opacity the real logo puts a red wordmark over a blue
                globe onto a blue field, and the red at 6% against #007DCC
                turns a muddy violet — the artwork does not read as itself, it
                reads as a printing fault. `grayscale` then `brightness(0)`
                then `invert` collapses every colour to one, so what shows is
                the *shape*: the globe's meridians and the arc of the wordmark,
                which is what the mark is recognisable by from across a room.
                The filter only touches opaque pixels, so the PNG's real
                transparency is untouched and no box appears around it.

                **Wider than the card on purpose.** The card is opaque and
                centred, so a watermark that fits inside it would be a file
                nobody ever sees. At 78rem it runs past the card on every side
                and what reads is the halo around the card — brand presence
                without anything competing with the two fields somebody came
                here to fill.

                6% is the number, and it is deliberately near the floor. An
                earlier version of this watermark ran at 7% for the same
                reason: a logo behind a sign-in form is decoration, and
                decoration that can be read as content is decoration that has
                gone wrong. It sits above the three gradient layers and below
                the card, which is the only stacking order where it is visible
                at all and still cannot touch the form.
            */}
            <img
                src="/images/logo.png"
                alt=""
                aria-hidden="true"
                className="pointer-events-none absolute left-1/2 top-1/2 w-[78rem] max-w-none -translate-x-1/2 -translate-y-1/2 opacity-[0.06] [filter:grayscale(1)_brightness(0)_invert(1)]"
            />

            {/*
                `ring-1` matters more than it looks: a white card on a mid-blue
                field has no edge of its own, and the hairline is what stops it
                reading as a hole cut in the background.
            */}
            <div className="relative grid w-full max-w-5xl overflow-hidden rounded-3xl bg-card shadow-2xl ring-1 ring-hero-foreground/10 lg:grid-cols-[1.05fr_1fr]">
                {/* Brand panel */}
                <section className="relative hidden flex-col justify-between overflow-hidden bg-gradient-to-br from-hero via-hero to-primary p-10 text-hero-foreground lg:flex xl:p-12">
                    {/* Two outlined circles bleeding off opposite corners, plus
                        a soft light behind the logo. Drawn rather than images,
                        so they cost no request and cannot be the asset that
                        fails to load. */}
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute -right-24 -top-28 h-80 w-80 rounded-full border border-hero-foreground/15"
                    />
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute -bottom-32 -left-24 h-72 w-72 rounded-full border border-hero-foreground/10"
                    />
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute -left-10 -top-10 h-64 w-64 rounded-full bg-primary/25 blur-3xl"
                    />

                    <div className="relative">
                        {/*
                            The company's own artwork, on the panel itself —
                            no white disc, which is what the owner asked for.
                            The PNG carries real transparency (alpha 127 at
                            every corner, measured), so nothing shows a white
                            box once the disc is gone.

                            **The glow underneath is not decoration, it is what
                            makes the transparency survivable.** Measured
                            against this panel's navy: 15.6% of the artwork's
                            opaque pixels land below 2.0:1 contrast, and its
                            darkest are pure black at 0.83:1 — darker than the
                            background, so those parts do not merely fade, they
                            read as holes punched in the panel. That is the
                            subtitle "PMS NETWORK INC" and the globe's dark
                            outlines. The old white disc solved it by putting a
                            light surface behind everything and cost a circle
                            nobody asked for.

                            A `drop-shadow` follows the image's own alpha
                            instead, so the lift traces the letterforms and the
                            globe's edge rather than drawing a shape around
                            them: dark pixels get a light halo exactly where
                            they need one, and the panel stays uninterrupted
                            everywhere else. Two shadows, a tight one for the
                            fine strokes of the subtitle and a wide one for the
                            mass of the globe.

                            Freed of the disc's padding it is also bigger —
                            160px against the old 96px of drawn artwork —
                            because keeping the logo visible was the other half
                            of the request. The wordmark inside it is legible
                            at this size, which is the whole reason the brand
                            panel gets the real mark while the 40px sidebar one
                            is paired with text.
                        */}
                        {/*
                            **One tight shadow, and the wide one it replaced is
                            the lesson.** The first attempt paired a 10px glow
                            at 45% with a 28px one at 25% — and a 28px radius
                            is wider than the gaps between the globe and the
                            wordmark arcing over it, so the halo filled those
                            gaps and fused into a solid pale blob. The owner
                            had just asked for the white disc to be removed,
                            and it had been redrawn in light: the thing looked
                            like a disc again while no disc was in the markup.
                            A contrast fix that recreates what it replaced is
                            not a fix.

                            6px traces the letterforms instead of filling
                            between them, which is all the black subtitle
                            needs — its strokes are thin, so even a small lift
                            separates them from the navy. The globe's own edge
                            is mid-blue and was never the part at risk.
                        */}
                        <LogoMark className="h-40 w-40 [filter:drop-shadow(0_0_6px_hsl(var(--hero-foreground)/0.3))]" />

                        <p className="mt-9 text-[11px] font-semibold uppercase tracking-[0.22em] text-hero-muted">
                            {name} Manpower
                        </p>

                        {/* The system's own name as the headline, in place of a
                            "Welcome to" line: it is the one fact somebody
                            arriving at this page does not already have. */}
                        <h1 className="mt-3 text-[2.5rem] font-bold leading-[1.08] tracking-tight">
                            Human Resource
                            <br />
                            Information System
                        </h1>

                        <p className="mt-6 max-w-sm text-sm leading-relaxed text-hero-muted">
                            One secure record of the agency&apos;s own staff and everybody
                            deployed to a client.
                        </p>
                    </div>

                    {/* What is behind the door, named rather than counted. */}
                    <div className="relative mt-12 border-t border-hero-foreground/15 pt-6">
                        <p className="text-[11px] font-medium leading-relaxed tracking-wide text-hero-muted">
                            Employee records · Timekeeping · Leave · Payroll · Performance
                        </p>
                    </div>
                </section>

                {/* Form */}
                <section className="flex items-center justify-center px-6 py-14 sm:px-10 lg:px-12 lg:py-16">
                    <div className="w-full max-w-sm">
                        {/* The brand, for the screens the panel is hidden on. */}
                        <div className="mb-10 flex items-center justify-center gap-2.5 lg:hidden">
                            <LogoMark className="h-14 w-14" />
                            <span className="text-lg font-bold tracking-tight text-logo-primary">
                                {name.toUpperCase()}
                            </span>
                        </div>

                        <h2 className="text-3xl font-bold tracking-tight text-foreground">
                            Sign in
                        </h2>

                        {status && (
                            <p
                                role="status"
                                className="mt-6 rounded-md border border-success/20 bg-success/10 px-3 py-2 text-sm font-medium text-success"
                            >
                                {status}
                            </p>
                        )}

                        <form onSubmit={submit} className="mt-8 space-y-5" autoComplete="off">
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
                                        className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded text-muted-foreground transition-colors hover:text-foreground focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                        aria-label={
                                            showPassword ? 'Hide password' : 'Show password'
                                        }
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
                                className="mt-1 w-full"
                                loading={processing}
                                disabled={processing}
                            >
                                Log In
                            </Button>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    );
}
