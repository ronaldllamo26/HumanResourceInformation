import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The calendar behind DateInput.
 *
 * Built rather than pulled in, and used instead of the browser's own picker,
 * for one reason that shows up constantly in this app: **the native picker has
 * no year control.** Chrome gives a month header and two arrows, so a date of
 * birth in 1985 is roughly 480 clicks away, and the only shortcut is typing
 * into segments whose order the browser decides. Every 201 file needs one.
 *
 * So the month and the year are both dropdowns here. Any date in the range is
 * two clicks.
 *
 * The trade-off, stated plainly: the native input hands a phone its own date
 * wheel, and this does not. The grid is sized for touch to compensate, but a
 * native wheel is still nicer on a small screen — that is the cost of a
 * calendar that matches the rest of the system and can jump years.
 */

const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

/** `YYYY-MM-DD` → Date, parsed as local midnight so no zone shifts the day. */
export function parseISO(value) {
    if (!value) return null;

    const date = new Date(`${value}T00:00:00`);

    return Number.isNaN(date.getTime()) ? null : date;
}

/** Date → `YYYY-MM-DD`, built by hand because toISOString() converts to UTC. */
export function toISO(date) {
    const pad = (n) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

const isSameDay = (a, b) =>
    a &&
    b &&
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate();

/**
 * The 42 cells of a month grid — six weeks, so the calendar never changes
 * height as you page through months and the buttons stay under the cursor.
 */
function monthGrid(year, month) {
    const first = new Date(year, month, 1);
    const start = new Date(year, month, 1 - first.getDay());

    return Array.from({ length: 42 }, (_, i) => {
        const date = new Date(start);
        date.setDate(start.getDate() + i);

        return date;
    });
}

/** Roughly what the panel below measures; used to decide which way it opens. */
const PANEL_HEIGHT = 330;
const PANEL_WIDTH = 280;

export default function DatePicker({ anchorRef, value, onSelect, onClose, min, max }) {
    const selected = parseISO(value);
    const today = new Date();

    const [cursor, setCursor] = useState(() => selected ?? today);
    const [position, setPosition] = useState(null);
    const containerRef = useRef(null);

    /*
     * Rendered into <body> rather than beside the field.
     *
     * An absolutely-positioned popover is clipped by any ancestor that scrolls
     * or hides its overflow, and this app has two on the paths that matter:
     * the modal panel is `overflow-hidden` so its rounded corners cut cleanly,
     * and every table scrolls horizontally. A calendar opened inside the
     * document-upload modal lost its bottom half to the first of those. A
     * portal has no ancestors to be clipped by.
     *
     * The cost is that position has to be computed rather than inherited, and
     * recomputed whenever anything moves — hence the listeners below.
     */
    useLayoutEffect(() => {
        const place = () => {
            const anchor = anchorRef?.current;

            if (!anchor) return;

            const rect = anchor.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom;

            // Flip above the field when there is not room under it — otherwise
            // a date field near the bottom of a long form opens off-screen.
            const openUp = spaceBelow < PANEL_HEIGHT && rect.top > spaceBelow;

            setPosition({
                top: openUp ? rect.top - PANEL_HEIGHT - 4 : rect.bottom + 4,
                // Clamped to the viewport so a field at the right edge does not
                // push the panel past it.
                left: Math.min(
                    Math.max(8, rect.left),
                    Math.max(8, window.innerWidth - PANEL_WIDTH - 8),
                ),
            });
        };

        place();

        // `true` for capture: a scroll inside the modal body does not bubble to
        // window, and that is exactly the container the field usually sits in.
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);

        return () => {
            window.removeEventListener('scroll', place, true);
            window.removeEventListener('resize', place);
        };
    }, [anchorRef]);

    // Dismiss on an outside click or Escape. Without both, a picker opened by
    // accident has to be dismissed by choosing a date — which is how a wrong
    // date gets saved.
    useEffect(() => {
        const onPointerDown = (event) => {
            const insidePanel = containerRef.current?.contains(event.target);
            // The trigger has to count as "inside" too, or its own click both
            // closes the panel here and reopens it in the button's handler —
            // which looks like the calendar refusing to open at all.
            const insideAnchor = anchorRef?.current?.contains(event.target);

            if (!insidePanel && !insideAnchor) {
                onClose?.();
            }
        };
        const onKeyDown = (event) => event.key === 'Escape' && onClose?.();

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [onClose, anchorRef]);

    /*
     * A hundred years back and twenty forward.
     *
     * Back covers a birth date for anyone who could still be employed; forward
     * covers the longest contract or licence this system tracks. Anchored on
     * the selected year as well as today's, so opening a record from outside
     * the window still shows its own year in the list rather than silently
     * snapping to the nearest edge.
     */
    const years = useMemo(() => {
        const now = today.getFullYear();
        const lowest = Math.min(now - 100, selected ? selected.getFullYear() : now);
        const highest = Math.max(now + 20, selected ? selected.getFullYear() : now);

        return Array.from({ length: highest - lowest + 1 }, (_, i) => highest - i);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);

    const days = monthGrid(cursor.getFullYear(), cursor.getMonth());
    const minDate = parseISO(min);
    const maxDate = parseISO(max);

    const outOfRange = (date) => (minDate && date < minDate) || (maxDate && date > maxDate);

    const shiftMonth = (by) =>
        setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + by, 1));

    const choose = (date) => {
        if (outOfRange(date)) return;

        onSelect(toISO(date));
        onClose?.();
    };

    // Nothing to draw until the anchor has been measured; one frame of
    // nothing beats one frame in the wrong corner of the screen.
    if (!position) return null;

    return createPortal(
        <div
            ref={containerRef}
            style={{ top: position.top, left: position.left, width: PANEL_WIDTH }}
            // Above the modal's own z-50, since it is portalled out of it.
            className="fixed z-[60] rounded-lg border border-border bg-popover p-3 shadow-lg"
            /*
             * Stops the click reaching the document.
             *
             * The panel is portalled to <body>, which puts it outside the
             * Headless UI Dialog when a date field sits in a modal — and that
             * Dialog closes on any click it considers outside itself. Without
             * this, picking a date would shut the whole upload form and throw
             * away what had been typed into it.
             */
            onMouseDown={(event) => event.stopPropagation()}
            onClick={(event) => event.stopPropagation()}
            role="dialog"
            aria-label="Choose a date"
        >
            {/* Month and year as selects — the whole reason this exists. */}
            <div className="mb-2 flex items-center gap-1.5">
                <select
                    value={cursor.getMonth()}
                    onChange={(event) =>
                        setCursor(new Date(cursor.getFullYear(), Number(event.target.value), 1))
                    }
                    aria-label="Month"
                    className="h-8 flex-1 rounded-md border border-input bg-background px-2 text-xs text-foreground focus:border-primary focus:ring-2 focus:ring-ring/30"
                >
                    {MONTHS.map((label, index) => (
                        <option key={label} value={index}>
                            {label}
                        </option>
                    ))}
                </select>

                <select
                    value={cursor.getFullYear()}
                    onChange={(event) =>
                        setCursor(new Date(Number(event.target.value), cursor.getMonth(), 1))
                    }
                    aria-label="Year"
                    className="h-8 w-[4.75rem] rounded-md border border-input bg-background px-2 text-xs text-foreground focus:border-primary focus:ring-2 focus:ring-ring/30"
                >
                    {years.map((year) => (
                        <option key={year} value={year}>
                            {year}
                        </option>
                    ))}
                </select>

                <button
                    type="button"
                    onClick={() => shiftMonth(-1)}
                    aria-label="Previous month"
                    className="grid h-8 w-7 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                >
                    <ChevronLeft className="h-4 w-4" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    onClick={() => shiftMonth(1)}
                    aria-label="Next month"
                    className="grid h-8 w-7 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                >
                    <ChevronRight className="h-4 w-4" aria-hidden="true" />
                </button>
            </div>

            <div className="grid grid-cols-7 gap-0.5">
                {WEEKDAYS.map((day) => (
                    <div
                        key={day}
                        className="grid h-7 place-items-center text-[10px] font-medium uppercase text-muted-foreground"
                    >
                        {day}
                    </div>
                ))}

                {days.map((date) => {
                    const thisMonth = date.getMonth() === cursor.getMonth();
                    const isSelected = isSameDay(date, selected);
                    const isToday = isSameDay(date, today);
                    const disabled = outOfRange(date);

                    return (
                        <button
                            key={date.toISOString()}
                            type="button"
                            disabled={disabled}
                            onClick={() => choose(date)}
                            aria-current={isSelected ? 'date' : undefined}
                            className={cn(
                                'grid h-8 place-items-center rounded-md text-xs transition-colors',
                                'disabled:cursor-not-allowed disabled:opacity-30',
                                isSelected
                                    ? 'bg-primary font-semibold text-primary-foreground'
                                    : thisMonth
                                      ? 'text-foreground hover:bg-secondary'
                                      : // Days spilling in from the neighbouring
                                        // months stay reachable but recede, so
                                        // the current month reads as one block.
                                        'text-muted-foreground/50 hover:bg-secondary/60',
                                isToday && !isSelected && 'ring-1 ring-inset ring-primary/40',
                            )}
                        >
                            {date.getDate()}
                        </button>
                    );
                })}
            </div>

            <div className="mt-2 flex items-center justify-between border-t border-border pt-2">
                <button
                    type="button"
                    onClick={() => {
                        onSelect('');
                        onClose?.();
                    }}
                    className="rounded px-1.5 py-0.5 text-xs text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                >
                    Clear
                </button>

                <button
                    type="button"
                    onClick={() => choose(today)}
                    className="flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium text-primary transition-colors hover:bg-primary/10"
                >
                    <CalendarDays className="h-3.5 w-3.5" aria-hidden="true" />
                    Today
                </button>
            </div>
        </div>,
        document.body,
    );
}
