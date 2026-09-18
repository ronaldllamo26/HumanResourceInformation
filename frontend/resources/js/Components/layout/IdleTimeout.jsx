import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Clock } from 'lucide-react';
import { Button, Modal } from '@/Components/ui';

/**
 * Signs somebody out after `session.lifetime` minutes of doing nothing.
 *
 * The enforcement is not here — it is `config/session.php`, which expires the
 * session server-side whether or not this ever runs. What this adds is the
 * part a server cannot do: noticing that nobody is there, warning before it
 * happens, and landing on the login screen with a sentence explaining it
 * rather than on a 419 the next time somebody clicks.
 *
 * Three things about it are load-bearing and easy to get wrong:
 *
 * 1. **It compares timestamps; it never counts down.** A laptop closed at
 *    4pm and opened at 9am has an interval timer that simply did not fire —
 *    a decrementing counter would wake believing no time had passed, which
 *    is exactly the machine somebody else has since sat down at.
 *
 * 2. **Activity is shared across tabs through `localStorage`.** Typing in one
 *    tab is being present at the computer; without sharing it, a second tab
 *    would sign the person out while they were mid-sentence in the first.
 *
 * 3. **Being active is not the same as talking to the server.** Reading a
 *    long payslip is activity to the person and silence to Laravel, whose
 *    session would expire underneath a countdown that still looked healthy.
 *    So genuine activity pings `session.keepalive` when the last request is
 *    getting old, which keeps the two clocks in step.
 */

// Shared with every other tab on this origin.
const ACTIVITY_KEY = 'primepower-last-activity';

/*
 * Deliberately not `mousemove`. A trackpad brushed by a sleeve is not
 * somebody at the desk, and treating it as such is how an idle timeout
 * quietly stops timing out at all. These all take a deliberate hand.
 */
const ACTIVITY_EVENTS = ['mousedown', 'keydown', 'scroll', 'touchstart', 'wheel'];

/** How often activity is written down. Once a second is plenty for a minute-scale rule. */
const WRITE_INTERVAL_MS = 1000;

/** How often the countdown is checked. */
const TICK_MS = 1000;

const now = () => Date.now();

/** Reads the shared timestamp, falling back to a local one where storage is blocked. */
function readActivity(fallback) {
    try {
        const stored = Number(window.localStorage.getItem(ACTIVITY_KEY));

        return Number.isFinite(stored) && stored > 0 ? stored : fallback;
    } catch {
        // Private windows and blocked site data throw rather than return null.
        return fallback;
    }
}

function writeActivity(at) {
    try {
        window.localStorage.setItem(ACTIVITY_KEY, String(at));
    } catch {
        // Nothing to do: the in-memory fallback below still runs this tab.
    }
}

export default function IdleTimeout() {
    const { idle } = usePage().props;

    const timeout = Number(idle?.timeout) || 0;
    const warnAfter = Number(idle?.warnAfter) || 0;

    const [secondsLeft, setSecondsLeft] = useState(null);

    // Refs, not state: these change every second and must not re-render.
    const lastActivity = useRef(now());
    const lastWrite = useRef(0);
    const lastServerContact = useRef(now());
    const signingOut = useRef(false);

    const markActive = useCallback((at = now()) => {
        lastActivity.current = at;

        // Throttled — a scroll fires this dozens of times a second, and the
        // rule is measured in minutes.
        if (at - lastWrite.current >= WRITE_INTERVAL_MS) {
            lastWrite.current = at;
            writeActivity(at);
        }
    }, []);

    /**
     * Tells the server somebody is still here.
     *
     * Laravel writes `last_activity` on every request, so an empty 204 is the
     * whole job. Separate from `markActive` because the two clocks are
     * separate: resetting the countdown on the screen while the session behind
     * it keeps ageing is the failure this component exists to avoid.
     */
    const keepAlive = useCallback(() => {
        lastServerContact.current = now();

        window
            .fetch('/session/keepalive', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            })
            .catch(() => {
                // Offline, or the session has already gone. The countdown
                // carries on and signing out is still the right ending.
            });
    }, []);

    /** Being answered *is* the answer — but the server has to hear it too. */
    const stayActive = useCallback(() => {
        markActive();
        keepAlive();
    }, [markActive, keepAlive]);

    /** Signs out through the route that leaves a message behind. */
    const signOut = useCallback(() => {
        if (signingOut.current) return;

        signingOut.current = true;

        router.post(
            '/logout/idle',
            {},
            {
                // A full navigation, because the session this page was built
                // against is the thing being destroyed.
                onError: () => {
                    window.location.href = '/login';
                },
            },
        );
    }, []);

    /*
     * Every Inertia visit is a conversation with the server, so it refreshes
     * the session — which means it also resets what this component thinks the
     * session's age is. Registered once, alongside the activity listeners.
     */
    useEffect(() => {
        if (timeout <= 0) return undefined;

        const onActivity = () => markActive();

        ACTIVITY_EVENTS.forEach((event) =>
            window.addEventListener(event, onActivity, { passive: true }),
        );

        // Coming back to a tab is activity, and it is the moment a machine
        // woken from sleep gets its first honest reading.
        const onVisible = () => {
            if (document.visibilityState === 'visible') markActive();
        };

        document.addEventListener('visibilitychange', onVisible);

        const stopNavigation = typeof router?.on === 'function'
            ? router.on('finish', () => {
                lastServerContact.current = now();
                markActive();
            })
            : () => {};

        markActive();

        return () => {
            ACTIVITY_EVENTS.forEach((event) => window.removeEventListener(event, onActivity));
            document.removeEventListener('visibilitychange', onVisible);
            if (typeof stopNavigation === 'function') stopNavigation();
        };
    }, [timeout, markActive]);

    /*
     * The clock. One interval, comparing timestamps — see the note at the top
     * about why it must never decrement a counter of its own.
     */
    useEffect(() => {
        if (timeout <= 0) return undefined;

        const tick = setInterval(() => {
            if (signingOut.current) return;

            const at = now();

            // The newest activity from any tab, this one included.
            const seen = Math.max(lastActivity.current, readActivity(0));
            lastActivity.current = seen;

            const idleFor = Math.max(0, (at - seen) / 1000);
            const remaining = Math.ceil(timeout - idleFor);

            if (remaining <= 0) {
                signOut();

                return;
            }

            /*
             * Somebody is here but has not spoken to the server in a while.
             * Refreshing halfway through keeps Laravel's own expiry in step
             * with this countdown — without it, an hour of reading would end
             * in a sign-out from a screen that said everything was fine.
             */
            if (
                idleFor < 5 &&
                at - lastServerContact.current > (timeout * 1000) / 2 &&
                document.visibilityState === 'visible'
            ) {
                keepAlive();
            }

            setSecondsLeft(warnAfter > 0 && remaining <= warnAfter ? remaining : null);
        }, TICK_MS);

        return () => clearInterval(tick);
    }, [timeout, warnAfter, signOut, keepAlive]);

    if (secondsLeft === null) return null;

    return (
        <Modal
            show
            onClose={stayActive}
            title="Session Timeout Warning"
            maxWidth="sm"
            closeable
        >
            <div className="flex items-start gap-3">
                <span className="grid h-10 w-10 shrink-0 animate-pulse place-items-center rounded-full bg-warning/15 text-warning ring-2 ring-warning/30">
                    <Clock className="h-5 w-5" aria-hidden="true" />
                </span>

                <div className="min-w-0">
                    <p className="text-sm font-semibold text-foreground">
                        Are you still there?
                    </p>
                    <p className="mt-1 text-sm text-foreground">
                        You will be signed out in{' '}
                        <span className="font-bold tabular-nums text-destructive">
                            {secondsLeft}
                        </span>{' '}
                        second
                        {secondsLeft === 1 ? '' : 's'} due to inactivity.
                    </p>
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        Any unsaved work will be lost. Click &ldquo;Stay signed in&rdquo; to
                        continue working.
                    </p>
                </div>
            </div>

            <div className="mt-5 flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={signOut}>
                    Sign out now
                </Button>
                <Button type="button" onClick={stayActive}>
                    Stay signed in
                </Button>
            </div>
        </Modal>
    );
}
