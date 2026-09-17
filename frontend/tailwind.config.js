import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** Semantic token -> `hsl(var(--token) / <alpha>)` so opacity modifiers keep working. */
const token = (name) => `hsl(var(--${name}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    /*
     * Three of these four globs point across into `backend/`, and that is not
     * an oversight — Tailwind only emits the classes it can *see*, and in an
     * Inertia app the markup is split across both folders: the JSX here, and
     * the blade shell plus Laravel's own paginator views over there. Drop the
     * `../backend/` ones and those classes vanish from the stylesheet with no
     * error, which shows up as an unstyled page rather than a failed build.
     */
    content: [
        './index.html',
        './resources/js/**/*.{js,jsx}',
        '../backend/resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                background: token('background'),
                foreground: token('foreground'),

                card: {
                    DEFAULT: token('card'),
                    foreground: token('card-foreground'),
                },
                popover: {
                    DEFAULT: token('popover'),
                    foreground: token('popover-foreground'),
                },
                primary: {
                    DEFAULT: token('primary'),
                    foreground: token('primary-foreground'),
                },
                secondary: {
                    DEFAULT: token('secondary'),
                    foreground: token('secondary-foreground'),
                },
                muted: {
                    DEFAULT: token('muted'),
                    foreground: token('muted-foreground'),
                },
                accent: {
                    DEFAULT: token('accent'),
                    foreground: token('accent-foreground'),
                },
                destructive: {
                    DEFAULT: token('destructive'),
                    foreground: token('destructive-foreground'),
                },

                success: token('success'),
                warning: {
                    DEFAULT: token('warning'),
                    foreground: token('warning-foreground'),
                },
                info: token('info'),

                // Fixed-alpha per the spec's rgba() border values.
                border: 'hsl(var(--border) / var(--border-opacity))',
                input: 'hsl(var(--input) / var(--input-opacity))',
                ring: token('ring'),
                switch: token('switch'),

                sidebar: {
                    DEFAULT: token('sidebar'),
                    foreground: token('sidebar-foreground'),
                    primary: token('sidebar-primary'),
                    'primary-foreground': token('sidebar-primary-foreground'),
                    accent: token('sidebar-accent'),
                    'accent-foreground': token('sidebar-accent-foreground'),
                    muted: token('sidebar-muted'),
                    border: 'hsl(var(--sidebar-border) / var(--border-opacity))',
                },

                /* The sign-in hero. One look in both modes — see app.css. */
                hero: {
                    DEFAULT: token('hero'),
                    foreground: token('hero-foreground'),
                    muted: token('hero-muted'),
                    accent: token('hero-accent'),
                },

                logo: {
                    primary: token('logo-primary'),
                    subtitle: token('logo-subtitle'),
                },

                chart: {
                    1: token('chart-1'),
                    2: token('chart-2'),
                    3: token('chart-3'),
                    4: token('chart-4'),
                },

                /* Good -> bad ramp. Meaningful only in order; see app.css. */
                grade: {
                    1: token('grade-1'),
                    2: token('grade-2'),
                    3: token('grade-3'),
                    4: token('grade-4'),
                    5: token('grade-5'),
                    6: token('grade-6'),
                },
            },

            borderRadius: {
                lg: 'var(--radius)',
                md: 'calc(var(--radius) - 2px)',
                sm: 'calc(var(--radius) - 4px)',
            },

            spacing: {
                // Sidebar rails: 64px collapsed / 260px expanded.
                sidebar: '260px',
                'sidebar-collapsed': '64px',
                0.75: '3px',
                4.5: '1.125rem',
            },

            keyframes: {
                'accordion-down': {
                    from: { height: '0', opacity: '0' },
                    to: { height: 'var(--radix-accordion-content-height)', opacity: '1' },
                },
                'fade-in': {
                    from: { opacity: '0' },
                    to: { opacity: '1' },
                },
                'slide-up': {
                    from: { opacity: '0', transform: 'translateY(6px)' },
                    to: { opacity: '1', transform: 'translateY(0)' },
                },
            },

            animation: {
                'fade-in': 'fade-in 150ms ease-out',
                'slide-up': 'slide-up 200ms ease-out',
            },
        },
    },

    plugins: [forms],
};
