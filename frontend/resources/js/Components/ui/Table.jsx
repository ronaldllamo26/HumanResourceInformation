import { ArrowDown, ArrowUp, ChevronsUpDown, Inbox } from 'lucide-react';
import { cn } from '@/lib/utils';

export function Table({ className, children, ...props }) {
    return (
        <div className="scrollbar-thin w-full overflow-x-auto">
            <table className={cn('w-full caption-bottom text-sm', className)} {...props}>
                {children}
            </table>
        </div>
    );
}

export function THead({ className, children, ...props }) {
    return (
        <thead className={cn('bg-secondary/50', className)} {...props}>
            {children}
        </thead>
    );
}

export function TBody({ className, children, ...props }) {
    return (
        <tbody className={cn('divide-y divide-border', className)} {...props}>
            {children}
        </tbody>
    );
}

/** Control totals — a summary row that reads as part of the table, not of the data. */
export function TFoot({ className, children, ...props }) {
    return (
        <tfoot className={cn('border-t-2 border-border bg-secondary/50', className)} {...props}>
            {children}
        </tfoot>
    );
}

export function TR({ className, clickable = false, children, ...props }) {
    return (
        <tr
            className={cn(
                'transition-colors',
                clickable && 'cursor-pointer hover:bg-secondary/50',
                className,
            )}
            {...props}
        >
            {children}
        </tr>
    );
}

/**
 * Header cell. Pass `sortKey` plus the current `sort` state to make it sortable;
 * `onSort` receives the key and should toggle direction upstream.
 */
export function TH({ className, sortKey, sort, onSort, children, ...props }) {
    const isSorted = sortKey && sort?.key === sortKey;
    const Icon = !isSorted ? ChevronsUpDown : sort.direction === 'asc' ? ArrowUp : ArrowDown;

    return (
        <th
            scope="col"
            className={cn(
                'whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground',
                className,
            )}
            aria-sort={
                isSorted ? (sort.direction === 'asc' ? 'ascending' : 'descending') : undefined
            }
            {...props}
        >
            {sortKey ? (
                <button
                    type="button"
                    onClick={() => onSort?.(sortKey)}
                    className="inline-flex items-center gap-1 rounded transition-colors hover:text-foreground"
                >
                    {children}
                    <Icon
                        className={cn('h-3.5 w-3.5', isSorted ? 'text-primary' : 'opacity-50')}
                        aria-hidden="true"
                    />
                </button>
            ) : (
                children
            )}
        </th>
    );
}

export function TD({ className, children, ...props }) {
    return (
        <td className={cn('px-4 py-3 align-middle text-foreground', className)} {...props}>
            {children}
        </td>
    );
}

export function TableEmpty({
    colSpan,
    icon: Icon = Inbox,
    title = 'No records found',
    description,
}) {
    return (
        <tr>
            <td colSpan={colSpan} className="px-4 py-14">
                <div className="flex flex-col items-center gap-2 text-center">
                    <span className="grid h-11 w-11 place-items-center rounded-full bg-secondary text-muted-foreground">
                        <Icon className="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p className="text-sm font-medium text-foreground">{title}</p>
                    {description && (
                        <p className="max-w-sm text-xs text-muted-foreground">{description}</p>
                    )}
                </div>
            </td>
        </tr>
    );
}
