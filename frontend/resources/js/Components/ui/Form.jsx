import { forwardRef, useId, useRef, useState } from 'react';
import { CalendarDays, ChevronDown, Search } from 'lucide-react';
import DatePicker, { parseISO } from './DatePicker';
import { cn } from '@/lib/utils';

const FIELD_BASE =
    'w-full rounded-md border border-input bg-background text-foreground placeholder:text-muted-foreground/70 ' +
    'shadow-sm transition-colors focus:border-primary focus:ring-2 focus:ring-ring/30 ' +
    'disabled:cursor-not-allowed disabled:opacity-60';

export function Label({ className, required, children, ...props }) {
    return (
        <label
            className={cn('mb-1.5 block text-xs font-medium text-foreground', className)}
            {...props}
        >
            {children}
            {required && <span className="ml-0.5 text-destructive">*</span>}
        </label>
    );
}

export function InputError({ message, className }) {
    if (!message) return null;

    return <p className={cn('mt-1 text-xs text-destructive', className)}>{message}</p>;
}

export const Input = forwardRef(function Input({ className, error, ...props }, ref) {
    return (
        <input
            ref={ref}
            /*
             * Off by default, because this is an HRIS.
             *
             * The browser remembers what was typed into a field and offers it
             * back on every later form, so filing one employee leaves their
             * nationality, address, and government numbers suggested while
             * filing the next — someone else's data, on someone else's record,
             * one careless Enter from being saved there.
             *
             * Declared before `{...props}` so a caller can still opt in: the
             * auth screens pass `username` and `current-password` on purpose,
             * and password managers depend on those.
             */
            autoComplete="off"
            className={cn(
                FIELD_BASE,
                'h-9 px-3 text-sm',
                error &&
                    'border-destructive focus:border-destructive focus:ring-destructive/30',
                className,
            )}
            aria-invalid={error ? 'true' : undefined}
            {...props}
        />
    );
});

/**
 * A date field with a calendar that matches the rest of the system.
 *
 * The browser's own picker was the thing being replaced, not the thing being
 * styled: its look is fixed by the browser, and more importantly it has no
 * year control — a date of birth is hundreds of clicks on the month arrow, and
 * every 201 file needs one. {@link DatePicker} puts the month and the year in
 * dropdowns, so any date is two clicks.
 *
 * The trigger shows a readable date rather than the browser's `dd/mm/yyyy`
 * segments, so there is never a question whether 30/01 is the 30th of January
 * or a mangled 1st of March.
 */
export const DateInput = forwardRef(function DateInput(
    { className, error, value, onChange, min, max, id, disabled, ...props },
    ref,
) {
    const [open, setOpen] = useState(false);
    const parsed = parseISO(value);

    // The calendar is portalled into <body> to escape the modal's
    // `overflow-hidden`, so it needs the trigger's own node to position
    // against — and to know that a click on the trigger is not "outside".
    const triggerRef = useRef(null);

    // Shaped like a real change event, so callers written for a plain <input>
    // need no special case.
    const emit = (next) => onChange?.({ target: { value: next } });

    return (
        <div className="relative">
            <button
                ref={(node) => {
                    triggerRef.current = node;
                    if (typeof ref === 'function') ref(node);
                    else if (ref) ref.current = node;
                }}
                id={id}
                type="button"
                disabled={disabled}
                onClick={() => setOpen((was) => !was)}
                aria-haspopup="dialog"
                aria-expanded={open}
                className={cn(
                    FIELD_BASE,
                    'flex h-9 items-center justify-between gap-2 px-3 text-left text-sm',
                    !parsed && 'text-muted-foreground/70',
                    error &&
                        'border-destructive focus:border-destructive focus:ring-destructive/30',
                    className,
                )}
                aria-invalid={error ? 'true' : undefined}
                {...props}
            >
                <span>
                    {parsed
                        ? parsed.toLocaleDateString('en-PH', {
                              year: 'numeric',
                              month: 'short',
                              day: 'numeric',
                          })
                        : 'Select a date'}
                </span>
                <CalendarDays
                    className="h-4 w-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
            </button>

            {open && (
                <DatePicker
                    anchorRef={triggerRef}
                    value={value}
                    min={min}
                    max={max}
                    onSelect={emit}
                    onClose={() => setOpen(false)}
                />
            )}
        </div>
    );
});

export const Textarea = forwardRef(function Textarea(
    { className, error, rows = 3, ...props },
    ref,
) {
    return (
        <textarea
            ref={ref}
            rows={rows}
            autoComplete="off"
            className={cn(
                FIELD_BASE,
                'px-3 py-2 text-sm',
                error &&
                    'border-destructive focus:border-destructive focus:ring-destructive/30',
                className,
            )}
            aria-invalid={error ? 'true' : undefined}
            {...props}
        />
    );
});

export const Select = forwardRef(function Select(
    { className, error, options = [], placeholder, children, ...props },
    ref,
) {
    return (
        <div className="relative">
            <select
                ref={ref}
                className={cn(
                    FIELD_BASE,
                    'h-9 appearance-none py-0 pl-3 pr-9 text-sm',
                    error &&
                        'border-destructive focus:border-destructive focus:ring-destructive/30',
                    className,
                )}
                aria-invalid={error ? 'true' : undefined}
                {...props}
            >
                {placeholder && <option value="">{placeholder}</option>}
                {children ??
                    options.map((option) => {
                        const value = typeof option === 'string' ? option : option.value;
                        const label = typeof option === 'string' ? option : option.label;

                        return (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        );
                    })}
            </select>
            <ChevronDown
                className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
        </div>
    );
});

export const SearchInput = forwardRef(function SearchInput({ className, ...props }, ref) {
    return (
        <div className="relative">
            <Search
                className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input ref={ref} type="search" className={cn('pl-9', className)} {...props} />
        </div>
    );
});

/** Label + control + error, wired together with a generated id. */
export function Field({ label, required, error, hint, className, children }) {
    const id = useId();

    return (
        <div className={cn('min-w-0', className)}>
            {label && (
                <Label htmlFor={id} required={required}>
                    {label}
                </Label>
            )}
            {typeof children === 'function' ? children({ id, error }) : children}
            {hint && !error && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}
