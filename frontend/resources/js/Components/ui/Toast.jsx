import { useEffect, useState } from 'react';
import { AlertCircle, CheckCircle2, Info, X } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Surfaces Laravel session flash messages shared through
 * HandleInertiaRequests: `success`, `error`, or `info`.
 *
 * `info` exists for the messages that are neither — a redirect explaining
 * where it sent you and why. Those used to have to borrow the success
 * variant, which put a green tick beside a sentence saying nothing had
 * happened.
 */
const TONES = {
    success: { Icon: CheckCircle2, border: 'border-success/30', text: 'text-success' },
    error: { Icon: AlertCircle, border: 'border-destructive/30', text: 'text-destructive' },
    info: { Icon: Info, border: 'border-primary/30', text: 'text-primary' },
};

export default function Toast({ flash, duration = 4500 }) {
    const tone = flash?.error ? 'error' : flash?.info ? 'info' : 'success';
    const message = flash?.success ?? flash?.error ?? flash?.info ?? null;

    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (!message) {
            setVisible(false);
            return;
        }

        setVisible(true);
        const timer = setTimeout(() => setVisible(false), duration);

        return () => clearTimeout(timer);
    }, [message, duration]);

    if (!message || !visible) return null;

    const { Icon, border, text } = TONES[tone];

    return (
        <div
            role="status"
            aria-live="polite"
            className="fixed bottom-5 right-5 z-[60] animate-slide-up"
        >
            <div
                className={cn(
                    'flex max-w-sm items-start gap-2.5 rounded-lg border bg-popover px-4 py-3 shadow-lg',
                    border,
                )}
            >
                <Icon className={cn('mt-0.5 h-4.5 w-4.5 shrink-0', text)} aria-hidden="true" />
                <p className="flex-1 text-sm text-foreground">{message}</p>
                <button
                    type="button"
                    onClick={() => setVisible(false)}
                    aria-label="Dismiss"
                    className="-mr-1 shrink-0 rounded p-0.5 text-muted-foreground transition-colors hover:text-foreground"
                >
                    <X className="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            </div>
        </div>
    );
}
