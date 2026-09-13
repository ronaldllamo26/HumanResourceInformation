import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * With an `href` the card becomes a link to the screen its figure came from.
 *
 * Done here rather than in each tile so every card built on this one — StatCard,
 * MeterCard, SplitStatCard — gains it from a single place, and so a clickable
 * card is a real `<a>`: middle-click opens a tab, the status bar shows where it
 * goes, and a keyboard reaches it. Wrapping the tile in a `<div onClick>` would
 * have looked identical and been none of those things.
 *
 * `floating` is the dashboard's own look — a deeper resting shadow and a
 * bigger radius, and on a clickable card a lift on hover rather than the
 * flatter tint every other list screen's card uses. Opt-in and kept out of the
 * base classes on purpose: every table screen in the system (Departments,
 * Positions, Payroll…) builds on this same component, and a global shadow
 * change would restyle all of them for a request that was about one page.
 */
export function Card({ className, href, floating = false, children, ...props }) {
    const classes = cn(
        'rounded-lg border border-border bg-card text-card-foreground shadow-sm transition-shadow duration-200',
        /*
         * On the dashboard the border steps back and the shadow does the
         * separating. It could not before: the card was the same colour as the
         * page, so the outline was the only thing saying where it ended, and a
         * saturated blue hairline around every card is what the screen read as.
         * With the card surface now lighter than the ground, a soft wide
         * shadow is enough, and the border is left as a whisper rather than an
         * edge.
         */
        floating &&
            'rounded-xl border-border/70 shadow-[0_1px_2px_-1px_hsl(var(--foreground)/0.06),0_8px_20px_-8px_hsl(var(--foreground)/0.10)]',
        href &&
            (floating
                ? 'block transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-[0_18px_30px_-12px_hsl(var(--foreground)/0.18)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40'
                : 'block transition-colors hover:border-primary/40 hover:bg-secondary/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40'),
        className,
    );

    if (href) {
        return (
            <Link href={href} className={classes} {...props}>
                {children}
            </Link>
        );
    }

    return (
        <div className={classes} {...props}>
            {children}
        </div>
    );
}

/**
 * A card's title row, and — on most list screens — the row its create button
 * and filters sit in.
 *
 * **It stacks below `sm`, and that is not decoration.** The action slot holds
 * real controls: Departments puts a 224px search box and a "New Department"
 * button in it, which is about 382px of content. A 375px phone leaves 301px
 * here once the page and card padding are taken off — so side by side, with
 * the action refusing to shrink, the row overflowed the card and pushed the
 * whole page sideways while truncating the title to nothing. Stacking is what
 * gives the action its own full-width line.
 *
 * The action's own contents still have to cope with that line being narrow;
 * the ones that hold two controls stack themselves the same way.
 */
export function CardHeader({ className, title, description, action, children, ...props }) {
    return (
        <div
            className={cn(
                // The rule under a title separates two parts of one card, not
                // two cards — so it is lighter than the card's own edge.
                'flex flex-col gap-3 border-b border-border/60 px-5 py-4',
                'sm:flex-row sm:items-start sm:justify-between sm:gap-4',
                className,
            )}
            {...props}
        >
            {children ?? (
                <div className="min-w-0">
                    {title && (
                        <h3 className="truncate text-sm font-semibold text-foreground">
                            {title}
                        </h3>
                    )}
                    {description && (
                        <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>
                    )}
                </div>
            )}
            {action && <div className="shrink-0">{action}</div>}
        </div>
    );
}

export function CardBody({ className, children, ...props }) {
    return (
        <div className={cn('px-5 py-4', className)} {...props}>
            {children}
        </div>
    );
}

export function CardFooter({ className, children, ...props }) {
    return (
        <div
            className={cn(
                'flex items-center justify-end gap-2 border-t border-border px-5 py-3',
                className,
            )}
            {...props}
        >
            {children}
        </div>
    );
}

/**
 * Tones a tile may carry.
 *
 * Colour here encodes the figure's *valence* — it is not decoration. A number
 * that is neither good nor bad keeps the default, because once every tile is
 * coloured none of them reads as a signal any more. The `grade-*` entries are
 * stops on the shared good -> bad ramp and are for figures that sit on a
 * scale; the named tones are for figures that mean one thing.
 *
 * Spelled out rather than built from a template string: Tailwind scans for
 * literal class names.
 */
const ICON_TONES = {
    primary: 'bg-primary/10 text-primary',
    info: 'bg-info/10 text-info',
    success: 'bg-success/10 text-success',
    warning: 'bg-warning/10 text-warning',
    destructive: 'bg-destructive/10 text-destructive',
    muted: 'bg-muted text-muted-foreground',
};

const TEXT_TONES = {
    default: 'text-foreground',
    primary: 'text-primary',
    info: 'text-info',
    success: 'text-success',
    warning: 'text-warning',
    destructive: 'text-destructive',
    muted: 'text-muted-foreground',
    'grade-1': 'text-grade-1',
    'grade-2': 'text-grade-2',
    'grade-3': 'text-grade-3',
    'grade-4': 'text-grade-4',
    'grade-5': 'text-grade-5',
    'grade-6': 'text-grade-6',
};

const BAR_TONES = {
    primary: 'bg-chart-1',
    success: 'bg-success',
    warning: 'bg-warning',
    destructive: 'bg-destructive',
    'grade-1': 'bg-grade-1',
    'grade-2': 'bg-grade-2',
    'grade-3': 'bg-grade-3',
    'grade-4': 'bg-grade-4',
    'grade-5': 'bg-grade-5',
    'grade-6': 'bg-grade-6',
};

/**
 * Dashboard KPI tile.
 *
 * `trend` takes `{ direction: 'up' | 'down', label }`. Direction only sets the
 * arrow and colour — whether "up" is good is the caller's business, so the
 * label carries the meaning.
 *
 * `tone` colours the icon tile. The headline number itself stays in the
 * foreground colour: it is the thing being read, and tinting it costs
 * contrast for no added meaning.
 */
export function StatCard({
    label,
    value,
    icon: Icon,
    hint,
    trend,
    tone = 'primary',
    href,
    floating = false,
    className,
}) {
    const TrendIcon = trend?.direction === 'down' ? ArrowDown : ArrowUp;

    return (
        <Card href={href} floating={floating} className={cn('p-4', className)}>
            <div className="flex items-start justify-between gap-3">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {label}
                </p>
                {Icon && (
                    <span
                        className={cn(
                            'grid h-9 w-9 shrink-0 place-items-center rounded-lg',
                            ICON_TONES[tone] ?? ICON_TONES.primary,
                        )}
                    >
                        <Icon className="h-[18px] w-[18px]" aria-hidden="true" />
                    </span>
                )}
            </div>

            <p className="mt-2 truncate text-[26px] font-semibold tabular-nums leading-tight text-foreground">
                {value}
            </p>

            {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}

            {trend && (
                <p
                    className={cn(
                        'mt-1 flex items-center gap-1 text-xs',
                        trend.direction === 'down' ? 'text-destructive' : 'text-success',
                    )}
                >
                    <TrendIcon className="h-3 w-3" aria-hidden="true" />
                    {trend.label}
                </p>
            )}
        </Card>
    );
}

/**
 * A tile whose headline is a share of something — the bar makes the proportion
 * readable without doing arithmetic.
 */
export function MeterCard({
    label,
    value,
    percent,
    icon: Icon,
    hint,
    badge,
    tone,
    iconTone = 'primary',
    href,
    floating = false,
    className,
}) {
    const clamped = Math.max(0, Math.min(100, percent ?? 0));

    return (
        <Card href={href} floating={floating} className={cn('p-4', className)}>
            <div className="flex items-start justify-between gap-3">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {label}
                </p>
                {Icon && (
                    <span
                        className={cn(
                            'grid h-9 w-9 shrink-0 place-items-center rounded-lg',
                            ICON_TONES[iconTone] ?? ICON_TONES.primary,
                        )}
                    >
                        <Icon className="h-[18px] w-[18px]" aria-hidden="true" />
                    </span>
                )}
            </div>

            <p className="mt-2 flex items-baseline gap-2">
                <span className="text-[26px] font-semibold tabular-nums leading-tight text-foreground">
                    {value}
                </span>
                {badge && (
                    <span
                        className={cn(
                            'text-xs font-medium',
                            TEXT_TONES[tone] ?? TEXT_TONES.primary,
                        )}
                    >
                        {badge}
                    </span>
                )}
            </p>

            <span
                className="mt-2.5 block h-1.5 w-full overflow-hidden rounded-full bg-muted"
                role="img"
                aria-label={`${clamped}%`}
            >
                <span
                    className={cn(
                        'block h-full rounded-r-[4px] transition-[width] duration-500',
                        BAR_TONES[tone] ?? BAR_TONES.primary,
                    )}
                    style={{ width: `${clamped}%` }}
                />
            </span>

            {hint && <p className="mt-1.5 text-xs text-muted-foreground">{hint}</p>}
        </Card>
    );
}

/**
 * Filled backgrounds for a `StatTile`.
 *
 * A tint, not a block — the figure sits on it and has to stay readable, so
 * these are the same /10 fills `Badge` uses rather than solid colour. `muted`
 * is the resting state and is what a zero falls back to.
 */
const TILE_TONES = {
    default: {
        rest: 'bg-secondary/60 ring-border',
        hover: 'hover:bg-secondary/80 hover:ring-muted-foreground/25',
    },
    primary: {
        rest: 'bg-primary/[0.07] ring-primary/20',
        hover: 'hover:bg-primary/[0.14] hover:ring-primary/40',
    },
    info: {
        rest: 'bg-info/[0.07] ring-info/20',
        hover: 'hover:bg-info/[0.14] hover:ring-info/40',
    },
    success: {
        rest: 'bg-success/[0.07] ring-success/20',
        hover: 'hover:bg-success/[0.14] hover:ring-success/40',
    },
    warning: {
        rest: 'bg-warning/[0.07] ring-warning/20',
        hover: 'hover:bg-warning/[0.14] hover:ring-warning/40',
    },
    destructive: {
        rest: 'bg-destructive/[0.07] ring-destructive/20',
        hover: 'hover:bg-destructive/[0.14] hover:ring-destructive/40',
    },
    muted: {
        rest: 'bg-muted ring-border',
        hover: 'hover:bg-muted hover:ring-muted-foreground/25',
    },
};

/**
 * One figure in a tinted box — the unit the three summary cards are built
 * from.
 *
 * Same rule as every other tile on the dashboard: a zero drops to grey. Three
 * of these side by side is a count broken into its parts, so they are only
 * meaningful together — a lone StatTile should be a StatCard instead.
 *
 * With an `href` it becomes a link to the rows it counted, and it is a real
 * `<a>` for the same reasons `Card` is: middle-click opens a tab, the status
 * bar says where it goes, and a keyboard reaches it.
 *
 * A zero still links. The list is empty either way, and a tile that stops
 * responding at zero teaches the reader that some of them are not clickable —
 * after which they stop trying the ones that are.
 */
export function StatTile({ label, value, tone = 'default', href, className }) {
    const isZero = value === 0 || value === '0';
    const palette = isZero ? TILE_TONES.muted : (TILE_TONES[tone] ?? TILE_TONES.default);

    const classes = cn(
        /*
         * A ring, not a shadow, and that is the whole change in how these
         * read. The tile is a tint with no edge, so on the app's own pale
         * background it dissolved into the card behind it — three soft
         * rectangles rather than three figures. An inset hairline in the
         * tile's own tone gives it a boundary without adding a second colour.
         *
         * A drop shadow would have done it too and would have been wrong:
         * these sit *inside* a card, and a raised tile inside a card reads as
         * a card floating on a card.
         */
        'block min-w-0 flex-1 rounded-lg px-2.5 py-3 text-center ring-1 ring-inset',
        palette.rest,
        /*
         * Hover deepens the tint rather than lifting the tile, for the same
         * reason: a tile that moves inside a card makes the card look loose.
         * The tint doubling is a clear enough answer that something happened.
         */
        href &&
            cn(
                'transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50',
                palette.hover,
            ),
        className,
    );

    const body = (
        <>
            <p
                className={cn(
                    'truncate text-[22px] font-semibold tabular-nums leading-none',
                    isZero ? TEXT_TONES.muted : (TEXT_TONES[tone] ?? TEXT_TONES.default),
                )}
            >
                {value}
            </p>
            {/* Sat 2px under the number before, which read as one block of
                text rather than a figure with a caption. */}
            <p className="mt-1.5 truncate text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                {label}
            </p>
        </>
    );

    return href ? (
        <Link href={href} className={classes}>
            {body}
        </Link>
    ) : (
        <div className={classes}>{body}</div>
    );
}

/**
 * The single most recent record beneath a row of `StatTile`s — what the counts
 * above are counting, made concrete.
 *
 * One row, never a list: the summary cards are a glance, and the screen behind
 * them is where the rest lives.
 */
export function TilePreview({
    icon: Icon,
    tone = 'muted',
    title,
    subtitle,
    badge,
    empty,
    href,
}) {
    if (!title) {
        return (
            <p className="mt-3 border-t border-border pt-3 text-xs text-muted-foreground">
                {empty ?? 'Nothing recorded yet.'}
            </p>
        );
    }

    const body = (
        <>
            {Icon && (
                <span
                    className={cn(
                        'grid h-7 w-7 shrink-0 place-items-center rounded-md',
                        ICON_TONES[tone] ?? ICON_TONES.muted,
                    )}
                >
                    <Icon className="h-3.5 w-3.5" aria-hidden="true" />
                </span>
            )}

            <div className="min-w-0 flex-1">
                <p
                    className={cn(
                        'truncate text-xs font-medium text-foreground',
                        href && 'group-hover:text-primary',
                    )}
                >
                    {title}
                </p>
                {subtitle && (
                    <p className="truncate text-[11px] text-muted-foreground">{subtitle}</p>
                )}
            </div>

            {badge && <div className="shrink-0">{badge}</div>}
        </>
    );

    /*
     * The preview names one record, so with an `href` it opens *that* record
     * rather than the list — the tiles above already lead to the list. A row
     * naming a person and leading somewhere they are one of forty is a link
     * that answers a question nobody asked.
     */
    return href ? (
        <Link
            href={href}
            className="group mt-3 flex items-center gap-2.5 border-t border-border pt-3 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
        >
            {body}
            <ChevronRight
                className="h-3.5 w-3.5 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                aria-hidden="true"
            />
        </Link>
    ) : (
        <div className="mt-3 flex items-center gap-2.5 border-t border-border pt-3">{body}</div>
    );
}

/**
 * A tile holding two or three related counts, for figures that only mean
 * something beside each other.
 */
export function SplitStatCard({
    label,
    icon: Icon,
    stats = [],
    tone = 'primary',
    href,
    floating = false,
    className,
}) {
    return (
        <Card href={href} floating={floating} className={cn('p-4', className)}>
            <div className="flex items-start justify-between gap-3">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {label}
                </p>
                {Icon && (
                    <span
                        className={cn(
                            'grid h-9 w-9 shrink-0 place-items-center rounded-lg',
                            ICON_TONES[tone] ?? ICON_TONES.primary,
                        )}
                    >
                        <Icon className="h-[18px] w-[18px]" aria-hidden="true" />
                    </span>
                )}
            </div>

            <dl className="mt-2 flex items-end justify-between gap-3">
                {stats.map((stat) => (
                    <div
                        key={stat.label}
                        className="min-w-0 flex-1 text-center first:text-left last:text-right"
                    >
                        <dd
                            className={cn(
                                'text-[26px] font-semibold tabular-nums leading-tight',
                                // A zero carries no warning worth colouring —
                                // "0 absent" in red reads as a problem when it
                                // is the opposite.
                                stat.value === 0 || stat.value === '0'
                                    ? TEXT_TONES.muted
                                    : (TEXT_TONES[stat.tone] ?? TEXT_TONES.default),
                            )}
                        >
                            {stat.value}
                        </dd>
                        <dt className="truncate text-[11px] uppercase tracking-wide text-muted-foreground">
                            {stat.label}
                        </dt>
                    </div>
                ))}
            </dl>
        </Card>
    );
}
