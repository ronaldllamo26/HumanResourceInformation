import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { CheckCircle2, Clock, MailCheck, RotateCw, TriangleAlert } from 'lucide-react';
import { LogoMark } from '@/Components/layout/PrimePowerLogo';
import { Button, Input, InputError, Label } from '@/Components/ui';
import { cn } from '@/lib/utils';

/**
 * The second step of signing in: the code sent to the account's own inbox.
 *
 * `RequireOtp` holds the session here, so this screen has exactly three ways
 * off it — the right code, a new code, or signing out.
 */
export default function OtpChallenge({
    sent_to: sentTo,
    expires_in: expiresIn = 120,
    resend_in: resendIn = 0,
    attempts_left: attemptsLeft = 0,
    sent_at: sentAt = 0,
    mail_failed: mailFailed = false,
}) {
    const { brand = {}, flash = {}, errors = {} } = usePage().props;
    const name = brand.name ?? 'PrimePower';

    const form = useForm({ code: '' });
    const [isResending, setIsResending] = useState(false);

    const [deadlines, setDeadlines] = useState(() => ({
        expires: Date.now() + expiresIn * 1000,
        resend: Date.now() + resendIn * 1000,
    }));
    const [now, setNow] = useState(Date.now());

    // Reset countdown deadlines when sentAt, expiresIn, or resendIn change (e.g. on resend)
    useEffect(() => {
        setDeadlines({
            expires: Date.now() + expiresIn * 1000,
            resend: Date.now() + resendIn * 1000,
        });
    }, [sentAt, expiresIn, resendIn]);

    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 1000);

        return () => clearInterval(timer);
    }, []);

    const secondsTo = (at) => Math.max(0, Math.ceil((at - now) / 1000));
    const expiresSeconds = secondsTo(deadlines.expires);
    const resendSeconds = secondsTo(deadlines.resend);
    const isExpired = expiresSeconds === 0;

    const submit = (event) => {
        event.preventDefault();
        form.post('/otp', { onFinish: () => form.reset('code') });
    };

    const resend = () => {
        if (resendSeconds > 0 || isResending) return;
        setIsResending(true);
        router.post(
            '/otp/resend',
            {},
            {
                preserveScroll: true,
                onFinish: () => setIsResending(false),
            },
        );
    };

    const codeError = form.errors.code || errors?.code;

    return (
        <div className="flex min-h-screen items-center justify-center bg-background px-6 py-12">
            <Head title="Sign-in code" />

            <div className="w-full max-w-sm">
                <div className="mb-8 flex items-center justify-center gap-2.5">
                    <LogoMark className="h-12 w-12" />
                    <span className="text-lg font-bold tracking-tight text-logo-primary">
                        {name.toUpperCase()}
                    </span>
                </div>

                <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                    Step 2 of 2
                </p>
                <h1 className="mt-2 text-2xl font-bold tracking-tight text-foreground">
                    Enter your sign-in code
                </h1>

                {/* Flash notices */}
                {flash?.success && (
                    <div className="mt-3 flex items-start gap-2 rounded-md border border-emerald-500/20 bg-emerald-500/10 px-3 py-2 text-xs text-emerald-700 dark:text-emerald-300">
                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                        <span>{flash.success}</span>
                    </div>
                )}

                {flash?.error && (
                    <div className="mt-3 flex items-start gap-2 rounded-md border border-destructive/20 bg-destructive/10 px-3 py-2 text-xs text-destructive">
                        <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{flash.error}</span>
                    </div>
                )}

                {/* Status Message: Mail Failure vs Expired vs Active Countdown */}
                {mailFailed ? (
                    <div className="mt-3 flex items-start gap-2 rounded-md border border-destructive/20 bg-destructive/10 px-3 py-2">
                        <TriangleAlert
                            className="mt-0.5 h-4 w-4 shrink-0 text-destructive"
                            aria-hidden="true"
                        />
                        <p className="text-xs text-foreground">
                            The code could not be sent to {sentTo}. The mail settings may need
                            attention — tell your administrator, or click &ldquo;Send a new
                            code&rdquo; below to retry.
                        </p>
                    </div>
                ) : (
                    <div
                        className={cn(
                            'mt-3 rounded-lg border p-3.5 transition-colors',
                            isExpired
                                ? 'border-amber-500/30 bg-amber-500/10'
                                : 'border-primary/20 bg-primary/5',
                        )}
                    >
                        <div className="flex items-center justify-between text-xs">
                            <span className="text-muted-foreground">
                                Sent to: <strong className="text-foreground">{sentTo}</strong>
                            </span>
                            <span
                                className={cn(
                                    'inline-flex items-center gap-1 font-mono font-bold',
                                    isExpired
                                        ? 'text-amber-700 dark:text-amber-400'
                                        : 'text-primary',
                                )}
                            >
                                <Clock className="h-3.5 w-3.5" />
                                {isExpired
                                    ? '0s (Expired)'
                                    : expiresSeconds >= 60
                                      ? `${Math.floor(expiresSeconds / 60)}m ${String(expiresSeconds % 60).padStart(2, '0')}s left`
                                      : `${expiresSeconds}s left`}
                            </span>
                        </div>
                        <div className="mt-2.5 h-1.5 w-full overflow-hidden rounded-full bg-secondary/60">
                            <div
                                className={cn(
                                    'h-full transition-all duration-1000',
                                    isExpired
                                        ? 'w-0 bg-amber-500'
                                        : expiresSeconds <= 20
                                          ? 'bg-amber-500'
                                          : 'bg-primary',
                                )}
                                style={{
                                    width: isExpired
                                        ? '0%'
                                        : `${Math.min(100, Math.max(0, (expiresSeconds / Math.max(expiresIn, 120)) * 100))}%`,
                                }}
                            />
                        </div>
                        {isExpired && (
                            <p className="mt-2 text-[11px] text-amber-800 dark:text-amber-300">
                                This code has expired. Click{' '}
                                <strong>&ldquo;Send a new code&rdquo;</strong> below for a fresh
                                one.
                            </p>
                        )}
                    </div>
                )}

                <form onSubmit={submit} className="mt-6 space-y-4">
                    <div>
                        <Label htmlFor="code">6-digit code</Label>
                        <Input
                            id="code"
                            name="code"
                            value={form.data.code}
                            onChange={(event) => form.setData('code', event.target.value)}
                            type="text"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            placeholder="123456"
                            maxLength={16}
                            autoFocus
                            error={codeError}
                            className="text-center font-mono text-lg tracking-[0.4em]"
                        />
                        <InputError message={codeError} />
                        {attemptsLeft > 0 && !codeError && (
                            <p className="mt-1 text-xs text-muted-foreground">
                                {attemptsLeft} attempt(s) before this code is cancelled.
                            </p>
                        )}
                    </div>

                    <Button
                        type="submit"
                        size="lg"
                        className="w-full"
                        loading={form.processing}
                        disabled={form.processing || form.data.code.trim().length === 0}
                    >
                        <MailCheck className="h-4 w-4" />
                        Verify and sign in
                    </Button>
                </form>

                <div className="mt-6 flex flex-wrap items-center justify-between gap-2">
                    <Button
                        type="button"
                        variant={isExpired ? 'default' : 'secondary'}
                        size="sm"
                        onClick={resend}
                        disabled={resendSeconds > 0 || isResending}
                        className={cn('transition-all', isExpired && 'font-semibold shadow-sm')}
                    >
                        <RotateCw
                            className={cn(
                                'mr-1.5 h-3.5 w-3.5',
                                (resendSeconds > 0 || isResending) && 'animate-spin',
                            )}
                        />
                        {isResending
                            ? 'Sending code...'
                            : resendSeconds > 0
                              ? `Send a new code in ${resendSeconds}s`
                              : 'Send a new code'}
                    </Button>

                    <Button variant="ghost" size="sm" onClick={() => router.post('/logout')}>
                        Sign out
                    </Button>
                </div>
            </div>
        </div>
    );
}
