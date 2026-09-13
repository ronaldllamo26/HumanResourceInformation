import { cn } from '@/lib/utils';
import { useId } from 'react';

/**
 * A single measure over time, drawn as a line with the area beneath it filled.
 *
 * One series only, and deliberately so: the fill under a line reads as "this
 * quantity", and two overlapping fills stop meaning anything. For several
 * categories at once, use the donut or the bar list instead.
 *
 * Colour is inherited — the component paints with `currentColor`, so the
 * caller sets `text-chart-1` and no hex is written here. That is also what
 * lets the gradient stops stay on a token: an SVG gradient needs a real
 * colour value, and `currentColor` is the only way to give it one without
 * hard-coding it.
 *
 * @param {Array<{label: string, value: number}>} data
 */
export function TrendChart({ data = [], className, valueLabel = 'value', ticks = 4 }) {
    // Gradient ids must be unique per instance or two charts on one page share
    // the first one's stops.
    const gradientId = useId();

    if (data.length < 2) {
        return (
            <p className="py-10 text-center text-sm text-muted-foreground">
                Not enough history to chart yet.
            </p>
        );
    }

    const WIDTH = 720;
    const HEIGHT = 260;
    const PAD_LEFT = 42;
    const PAD_RIGHT = 12;
    const PAD_TOP = 12;
    const PAD_BOTTOM = 30;

    const plotWidth = WIDTH - PAD_LEFT - PAD_RIGHT;
    const plotHeight = HEIGHT - PAD_TOP - PAD_BOTTOM;

    const values = data.map((point) => Number(point.value) || 0);
    const rawMax = Math.max(...values);
    const rawMin = Math.min(...values);

    /*
     * The axis is padded away from the data rather than starting at zero.
     * Headcount moving 34 -> 39 against a 0-based axis is a flat line: the
     * change is real and the chart's job is to show it. The padding keeps the
     * line off both edges so it never touches the frame.
     */
    const span = rawMax - rawMin || Math.max(rawMax, 1);

    let max = rawMax + span * 0.15;
    let min = Math.max(0, rawMin - span * 0.35);
    let lines = ticks;

    /*
     * A headcount is a whole number, so the axis has to step in whole numbers
     * too — and the axis was being *rounded* rather than stepped.
     *
     * Twelve months moving 39 -> 41 gave a padded axis of 38.3 to 41.3, split
     * into four: 38.3, 39.05, 39.8, 40.55, 41.3. Each label was rounded for
     * display, which printed 38, 39, 40, 41, **41** — two gridlines carrying
     * the same number, one of them a lie about where it sat. Rounding the
     * label is the bug: it changes what the tick says without moving the tick.
     *
     * So the step is snapped to a whole unit first and the axis is grown to
     * fit it. Fractional data (a rate, an average) keeps the even split, where
     * a fractional tick is the honest answer.
     */
    if (values.every(Number.isInteger)) {
        const step = Math.max(1, Math.ceil((max - min) / ticks));

        min = Math.floor(min);
        lines = Math.max(1, Math.ceil((max - min) / step));
        max = min + step * lines;
    }

    const range = max - min || 1;

    const x = (index) => PAD_LEFT + (index / (data.length - 1)) * plotWidth;
    const y = (value) => PAD_TOP + plotHeight - ((value - min) / range) * plotHeight;

    const points = data.map((point, index) => ({
        ...point,
        cx: x(index),
        cy: y(Number(point.value) || 0),
    }));

    const line = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.cx},${p.cy}`).join(' ');
    const area = `${line} L${points.at(-1).cx},${PAD_TOP + plotHeight} L${points[0].cx},${PAD_TOP + plotHeight} Z`;

    /*
     * The label is whatever the tick actually is — never a rounded stand-in
     * for it. A fractional axis is shown to one decimal rather than snapped to
     * an integer it does not sit on.
     */
    const gridlines = Array.from({ length: lines + 1 }, (_, i) => {
        const value = min + (range / lines) * i;

        return {
            value: Number.isInteger(value) ? value : Number(value.toFixed(1)),
            y: y(value),
        };
    });

    const first = values[0];
    const last = values.at(-1);

    return (
        <figure
            className={cn('text-chart-1', className)}
            role="img"
            aria-label={`${valueLabel} from ${data[0].label} to ${data.at(-1).label}: ${first} to ${last}.`}
        >
            <svg viewBox={`0 0 ${WIDTH} ${HEIGHT}`} className="w-full" aria-hidden="true">
                <defs>
                    <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="currentColor" stopOpacity="0.22" />
                        <stop offset="100%" stopColor="currentColor" stopOpacity="0.01" />
                    </linearGradient>
                </defs>

                {/* Gridlines and the value axis. Drawn first so the line sits
                    over them rather than being cut by them. */}
                {gridlines.map((tick) => (
                    <g key={tick.y}>
                        <line
                            x1={PAD_LEFT}
                            x2={WIDTH - PAD_RIGHT}
                            y1={tick.y}
                            y2={tick.y}
                            className="stroke-border"
                            strokeWidth="1"
                        />
                        <text
                            x={PAD_LEFT - 10}
                            y={tick.y + 4}
                            textAnchor="end"
                            className="fill-muted-foreground text-[11px]"
                        >
                            {tick.value}
                        </text>
                    </g>
                ))}

                <path d={area} fill={`url(#${gradientId})`} />

                <path
                    d={line}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2.5"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />

                {points.map((point) => (
                    <circle
                        key={point.label + point.cx}
                        cx={point.cx}
                        cy={point.cy}
                        r="3.5"
                        stroke="currentColor"
                        strokeWidth="2"
                        // Punches the card's own background through the dot, so
                        // it reads as a marker rather than a blob on the line.
                        className="fill-card"
                    />
                ))}

                {points.map((point) => (
                    <text
                        key={`label-${point.label}-${point.cx}`}
                        x={point.cx}
                        y={HEIGHT - 8}
                        textAnchor="middle"
                        className="fill-muted-foreground text-[11px]"
                    >
                        {point.label}
                    </text>
                ))}
            </svg>

            {/* The figures themselves, for anyone the chart does not reach. */}
            <figcaption className="sr-only">
                <ul>
                    {data.map((point) => (
                        <li key={`sr-${point.label}`}>
                            {point.label}: {point.value}
                        </li>
                    ))}
                </ul>
            </figcaption>
        </figure>
    );
}
