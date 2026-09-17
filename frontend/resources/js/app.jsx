import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { ThemeProvider } from '@/context/ThemeContext';

const appName = import.meta.env.VITE_APP_NAME || 'PrimePower HRIS';

/**
 * An expired session lands on the login screen, not on a modal.
 *
 * The idle timeout signs people out from the page they are on, but it cannot
 * cover the case it exists for: a tab left open on a machine that slept, whose
 * session Laravel expired without anybody's browser running. The first click
 * after that sends a CSRF token the server has forgotten, and Inertia's default
 * for the 419 is a black overlay containing Laravel's "Page Expired" page —
 * which is a dead end. There is no way forward from it and nothing on it says
 * to sign in again.
 *
 * `invalid` fires for exactly this: a response Inertia cannot render.
 */
router.on('invalid', (event) => {
    if (event.detail.response?.status === 419) {
        event.preventDefault();

        window.location.href = '/login';
    }
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <ThemeProvider>
                <App {...props} />
            </ThemeProvider>,
        );
    },
    // Top loading bar on page transitions with spinner for immediate visual feedback
    progress: {
        color: '#2563eb',
        showSpinner: true,
        delay: 0,
        includeCSS: true,
    },
});
