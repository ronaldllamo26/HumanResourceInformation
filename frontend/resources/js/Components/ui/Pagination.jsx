import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/**
 * Renders a Laravel paginator's `links` array.
 * `meta` supplies the "Showing x–y of z" summary.
 */
export default function Pagination({ links = [], meta, className }) {
    // Guard against being handed the paginator's {first,last,prev,next} object.
    const pages = Array.isArray(links) ? links : [];

    if (pages.length <= 3) return null;

    return (
        <div
            className={cn(
                'flex flex-col items-center justify-between gap-3 border-t border-border px-4 py-3 sm:flex-row',
                className,
            )}
        >
            {meta && (
                <p className="text-xs text-muted-foreground">
                    Showing{' '}
                    <span className="font-medium text-foreground">{meta.from ?? 0}</span>–
                    <span className="font-medium text-foreground">{meta.to ?? 0}</span> of{' '}
                    <span className="font-medium text-foreground">{meta.total ?? 0}</span>{' '}
                    records
                </p>
            )}

            <nav className="flex flex-wrap items-center gap-1" aria-label="Pagination">
                {pages.map((link, index) => {
                    const label = link.label
                        .replace('&laquo; Previous', 'Previous')
                        .replace('Next &raquo;', 'Next');

                    if (!link.url) {
                        return (
                            <span
                                key={index}
                                className="cursor-not-allowed rounded-md px-3 py-1.5 text-xs text-muted-foreground/50"
                            >
                                {label}
                            </span>
                        );
                    }

                    return (
                        <Link
                            key={index}
                            href={link.url}
                            preserveScroll
                            preserveState
                            aria-current={link.active ? 'page' : undefined}
                            className={cn(
                                'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                                link.active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-secondary hover:text-foreground',
                            )}
                        >
                            {label}
                        </Link>
                    );
                })}
            </nav>
        </div>
    );
}
