import { Link } from '@inertiajs/react';
import { forwardRef } from 'react';
import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';

const VARIANTS = {
    primary:
        'bg-primary text-primary-foreground hover:bg-primary/90 active:bg-primary/95 shadow-sm',
    secondary:
        'bg-secondary text-secondary-foreground hover:bg-secondary/70 border border-border',
    outline: 'border border-border bg-transparent text-foreground hover:bg-secondary/60',
    ghost: 'bg-transparent text-muted-foreground hover:bg-secondary/60 hover:text-foreground',
    destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90 shadow-sm',
    link: 'bg-transparent text-primary underline-offset-4 hover:underline p-0 h-auto',
};

const SIZES = {
    sm: 'h-8 px-3 text-xs gap-1.5',
    md: 'h-9 px-4 text-sm gap-2',
    lg: 'h-11 px-6 text-sm gap-2',
    icon: 'h-9 w-9 p-0',
};

const Button = forwardRef(function Button(
    {
        variant = 'primary',
        size = 'md',
        className,
        children,
        loading = false,
        disabled,
        href,
        // Renders a plain <a> instead of an Inertia Link — required for file
        // downloads, which an XHR visit would swallow.
        external = false,
        type = 'button',
        ...props
    },
    ref,
) {
    const classes = cn(
        'inline-flex items-center justify-center whitespace-nowrap rounded-md font-medium',
        'transition-colors duration-150',
        'disabled:pointer-events-none disabled:opacity-50',
        VARIANTS[variant],
        SIZES[size],
        className,
    );

    const content = (
        <>
            {loading && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
            {children}
        </>
    );

    if (href && external) {
        return (
            <a ref={ref} href={href} className={classes} {...props}>
                {content}
            </a>
        );
    }

    if (href) {
        return (
            <Link ref={ref} href={href} className={classes} {...props}>
                {content}
            </Link>
        );
    }

    return (
        <button
            ref={ref}
            type={type}
            className={classes}
            disabled={disabled || loading}
            {...props}
        >
            {content}
        </button>
    );
});

export default Button;
