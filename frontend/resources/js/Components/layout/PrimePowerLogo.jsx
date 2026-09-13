import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

/** Drop the company logo here and it is picked up automatically. */
const LOGO_SRC = '/images/logo.png';

/*
 * The default size, in one place because the drawn fallback has to match the
 * artwork it stands in for — two literals would drift the moment one is
 * changed.
 *
 * 40px is the ceiling, and it is the *collapsed* sidebar that sets it: 64px
 * wide less its 8px of padding each side leaves 48px, so anything larger is
 * clipped on a screen nobody looks at while resizing. Call sites with more
 * room pass their own size; `cn()` is tailwind-merge, so theirs wins.
 *
 * The artwork is 988x728 — landscape, not square — so `object-contain` fits
 * it to the width and leaves the rest of a square box empty. What is actually
 * drawn is 40x29, not 40x40. That is why the mark reads smaller than its box
 * suggests, and why the box has to grow further than the apparent size does.
 *
 * The file was cropped to its own alpha bounds (it carried 31% empty padding,
 * so a 40px box was spending 8px on nothing). The original is kept under
 * storage/app/backups/. Do not re-export it with padding.
 *
 * The wordmark inside the artwork does not survive this size, and no amount
 * of sharpening changes that — "PMS NETWORK INC" is illegible below roughly
 * 200px. The company name is carried by the text beside the mark, from
 * Settings > General, which is why that text exists.
 */
const LOGO_SIZE = 'h-10 w-10';

/**
 * The logo mark.
 *
 * Uses the real artwork when it is present and falls back to a drawn mark
 * otherwise, so a missing file degrades to something sensible rather than a
 * broken-image icon.
 */
export function LogoMark({ className }) {
    const [failed, setFailed] = useState(false);

    if (!failed) {
        return (
            <img
                src={LOGO_SRC}
                alt=""
                onError={() => setFailed(true)}
                className={cn(LOGO_SIZE, 'shrink-0 object-contain', className)}
            />
        );
    }

    return (
        <svg
            viewBox="0 0 32 32"
            className={cn(LOGO_SIZE, 'shrink-0', className)}
            role="img"
            aria-label="PrimePower"
        >
            <rect width="32" height="32" rx="8" className="fill-logo-primary" />
            <path d="M10 23 L16 9 L22 23 L16 19.5 Z" className="fill-primary-foreground" />
        </svg>
    );
}

export default function PrimePowerLogo({ collapsed = false, className }) {
    // Brand text is shared from Settings > General, so changing it there
    // changes it everywhere at once.
    const brand = usePage().props.brand ?? {};

    return (
        <div className={cn('flex items-center gap-2.5 overflow-hidden', className)}>
            <LogoMark />

            <div
                className={cn(
                    'min-w-0 transition-[opacity,width] duration-300',
                    collapsed ? 'w-0 opacity-0' : 'w-auto opacity-100',
                )}
                aria-hidden={collapsed}
            >
                {/*
                 * Wrapped, not truncated.
                 *
                 * "Human Resource Information System" is about 214px at 10px
                 * and the expanded sidebar leaves 178px beside the mark, so on
                 * one line it was always going to end in an ellipsis — this is
                 * a fixed 260px rail, not a column that can be widened. Over
                 * two lines it fits with room to spare.
                 *
                 * `line-clamp-2` rather than free wrapping because the height
                 * is not ours to spend: the header is h-16 to align with the
                 * topbar's own h-16, and a border that does not meet across
                 * that seam is obvious. Both lines at their two-line maximum
                 * come to 57.5px inside 64px.
                 *
                 * The name is clamped too, for the company whose registered
                 * name is longer than its trading one — "PRIMEPOWER PMS
                 * NETWORK INC" needs 268px at 13px bold, which no single line
                 * here can hold.
                 */}
                <p className="line-clamp-2 text-[13px] font-bold leading-tight tracking-tight text-logo-primary">
                    {(brand.name ?? 'PrimePower').toUpperCase()}
                </p>
                <p className="line-clamp-2 text-[10px] font-medium leading-tight text-logo-subtitle">
                    {brand.tagline ?? 'Human Resource Information System'}
                </p>
            </div>
        </div>
    );
}
