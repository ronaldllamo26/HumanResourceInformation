import { router, usePage } from '@inertiajs/react';
import { Loader2, UserCheck, X } from 'lucide-react';
import { useState } from 'react';

/**
 * Says whose screen this is, on every page, while an impersonation runs.
 *
 * The one failure this feature cannot afford is an administrator who has
 * forgotten they are impersonating and takes an action believing it is their
 * own — so this is deliberately the loudest thing on the page and cannot be
 * dismissed. It is `sticky top-0` with a high stack order for the same
 * reason: scrolled past, it would stop doing the one job it has.
 *
 * Drawn from `impersonation` shared by `HandleInertiaRequests`, which is null
 * for every ordinary session, so this renders nothing at all the rest of the
 * time.
 *
 * It uses `--destructive` rather than a warning amber: amber means "somebody
 * should look at this", and this is closer to "you are holding somebody
 * else's identity". Tone is valence, and this is the strongest valence the
 * palette has.
 */
export default function ImpersonationBanner() {
    const { impersonation } = usePage().props;
    const [stopping, setStopping] = useState(false);

    if (!impersonation) return null;

    const stop = () => {
        if (stopping) return;
        setStopping(true);
        router.post(
            '/settings/impersonate/stop',
            {},
            {
                preserveScroll: false,
                onError: () => setStopping(false),
                onFinish: () => setStopping(false),
            },
        );
    };

    return (
        <div
            className="sticky top-0 z-50 flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-destructive/30 bg-destructive px-4 py-2 text-destructive-foreground sm:px-6"
            role="status"
        >
            <UserCheck className="h-4 w-4 shrink-0" aria-hidden="true" />

            <p className="text-sm font-medium">
                Viewing as <span className="font-semibold">{impersonation.as}</span>
                {impersonation.administrator && (
                    <span className="font-normal opacity-90">
                        {' '}
                        · signed in as {impersonation.administrator}
                    </span>
                )}
            </p>

            {/*
             * Stated on the banner rather than left to the audit log, because
             * the moment it matters is before an action is taken, not after
             * somebody goes looking for who took it.
             */}
            <p className="hidden text-xs opacity-90 sm:block">
                Everything you do here is recorded against your own account.
            </p>

            <button
                type="button"
                onClick={stop}
                disabled={stopping}
                className="ml-auto inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-destructive-foreground/15 px-2.5 py-1 text-xs font-semibold transition hover:bg-destructive-foreground/25 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-destructive-foreground disabled:cursor-not-allowed disabled:opacity-60"
            >
                {stopping ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                ) : (
                    <X className="h-3.5 w-3.5" aria-hidden="true" />
                )}
                {stopping ? 'Stopping...' : 'Stop impersonating'}
            </button>
        </div>
    );
}
