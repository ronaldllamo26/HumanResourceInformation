/*
 * The same semantic tokens the HRIS uses, so the two read as one product.
 *
 * Copied rather than imported, because this is a separate deployment with no
 * access to the Laravel project's files — and that is the cost of separating
 * them. The values come from `resources/css/app.css` in the HRIS; if the
 * brand changes there, it has to change here too. Only the handful of tokens
 * a marketing page actually needs are carried over, so the drift surface is
 * small and visible rather than the whole design system restated.
 */
export default {
    content: ['./index.html', './src/**/*.{js,jsx}'],
    theme: {
        extend: {
            colors: {
                background: 'hsl(var(--background) / <alpha-value>)',
                foreground: 'hsl(var(--foreground) / <alpha-value>)',
                card: 'hsl(var(--card) / <alpha-value>)',
                primary: {
                    DEFAULT: 'hsl(var(--primary) / <alpha-value>)',
                    foreground: 'hsl(var(--primary-foreground) / <alpha-value>)',
                },
                muted: {
                    DEFAULT: 'hsl(var(--muted) / <alpha-value>)',
                    foreground: 'hsl(var(--muted-foreground) / <alpha-value>)',
                },
                border: 'hsl(var(--border) / <alpha-value>)',
            },
            borderRadius: {
                lg: '0.5rem',
            },
            fontFamily: {
                sans: [
                    'ui-sans-serif',
                    'system-ui',
                    '-apple-system',
                    'Segoe UI',
                    'Roboto',
                    'Helvetica Neue',
                    'Arial',
                    'sans-serif',
                ],
            },
        },
    },
    plugins: [],
};
