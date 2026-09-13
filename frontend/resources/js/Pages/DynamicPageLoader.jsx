import React, { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import api from '@/lib/api';
import { PageProvider, registerNavigator } from '@/lib/inertia-adapter';

// Dynamically import all page components in Pages directory
const pageModules = import.meta.glob('./**/*.jsx', { eager: true });

export default function DynamicPageLoader() {
    const location = useLocation();
    const navigate = useNavigate();

    useEffect(() => {
        registerNavigator(navigate);
    }, [navigate]);

    const [pageState, setPageState] = useState({
        componentName: '',
        props: null,
        url: '',
        loading: true,
        error: null,
    });

    const currentUrl = location.pathname + location.search;

    const fetchPage = async (url) => {
        setPageState((prev) => ({ ...prev, loading: true, error: null }));
        try {
            const res = await api.get(url, {
                timeout: 10000,
                headers: {
                    'X-Inertia': 'true',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            const data = res.data;

            // If backend returned a different URL (e.g. redirected internally)
            if (data?.url && !data.url.startsWith('http')) {
                const targetClean = data.url.split('?')[0];
                const currentClean = location.pathname;
                if (targetClean !== currentClean) {
                    navigate(data.url, { replace: true });
                }
            }

            let componentName = data?.component;
            let pageProps = data?.props;

            // Fallback for direct JSON data without component key
            if (!componentName) {
                const clean = url.split('?')[0];
                if (clean === '/dashboard' || clean === '/') {
                    componentName = 'Dashboard';
                    pageProps = data;
                } else if (clean.startsWith('/hr/employees')) {
                    componentName = 'HR/Employees/Index';
                    pageProps = data;
                } else if (clean.startsWith('/settings/security')) {
                    componentName = 'Settings/Security';
                    pageProps = data;
                } else if (clean.startsWith('/settings')) {
                    componentName = 'Settings/Index';
                    pageProps = data;
                }
            }

            if (!componentName) {
                throw new Error(`Could not determine page component for URL: ${url}`);
            }

            const componentKey = `./${componentName}.jsx`;
            const PageComponent = pageModules[componentKey]?.default;

            if (!PageComponent) {
                console.error(`Component '${componentKey}' not found in available modules:`, Object.keys(pageModules));
                setPageState({
                    componentName: '',
                    props: null,
                    url: data?.url || url,
                    loading: false,
                    error: `Page component '${componentName}' not found.`,
                });
                return;
            }

            setPageState({
                componentName,
                props: pageProps || {},
                url: data?.url || url,
                loading: false,
                error: null,
            });
        } catch (err) {
            console.error('Failed to load page data:', err);

            // 1. Handle Inertia 409 redirect location
            const inertiaLoc =
                err.response?.headers?.['x-inertia-location'] ||
                err.response?.headers?.['X-Inertia-Location'];
            if (err.response?.status === 409 && inertiaLoc) {
                try {
                    const parsed = new URL(inertiaLoc, window.location.origin);
                    navigate(parsed.pathname + parsed.search, { replace: true });
                } catch {
                    navigate(inertiaLoc, { replace: true });
                }
                return;
            }

            // 2. Handle 401 Unauthorized: clear user state to prevent infinite redirect loops
            if (err.response?.status === 401) {
                window.dispatchEvent(new CustomEvent('auth:unauthorized'));
                setPageState((prev) => ({ ...prev, loading: false }));
                navigate('/login', { replace: true });
                return;
            }

            setPageState((prev) => ({
                ...prev,
                loading: false,
                error: err.response?.data?.message || err.message || 'Failed to load page data.',
            }));
        }
    };

    useEffect(() => {
        fetchPage(currentUrl);
    }, [currentUrl]);

    useEffect(() => {
        const handleRefresh = () => fetchPage(currentUrl);
        window.addEventListener('inertia:refresh', handleRefresh);
        return () => window.removeEventListener('inertia:refresh', handleRefresh);
    }, [currentUrl]);

    if (pageState.loading && !pageState.componentName) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-background">
                <div className="flex flex-col items-center gap-3">
                    <div className="h-10 w-10 animate-spin rounded-full border-4 border-primary border-t-transparent" />
                    <p className="text-sm font-medium text-muted-foreground">Loading module...</p>
                </div>
            </div>
        );
    }

    if (pageState.error && !pageState.componentName) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-background p-4">
                <div className="rounded-lg border border-destructive/20 bg-card p-6 text-center shadow max-w-md">
                    <p className="text-sm font-semibold text-destructive">Failed to load module.</p>
                    <p className="mt-2 text-xs text-muted-foreground">{pageState.error}</p>
                    <button
                        type="button"
                        onClick={() => fetchPage(currentUrl)}
                        className="mt-4 rounded bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                    >
                        Retry
                    </button>
                </div>
            </div>
        );
    }

    const componentKey = `./${pageState.componentName}.jsx`;
    const PageComponent = pageModules[componentKey]?.default;

    if (!PageComponent) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-background p-6">
                <div className="rounded-lg border border-destructive/30 bg-card p-6 text-left max-w-2xl shadow">
                    <h2 className="text-base font-bold text-destructive">Component Not Found</h2>
                    <p className="mt-2 text-sm text-foreground">
                        Requested: <code className="bg-muted px-1.5 py-0.5 rounded">{pageState.componentName}</code>
                    </p>
                    <p className="mt-1 text-sm text-foreground">
                        Key looked up: <code className="bg-muted px-1.5 py-0.5 rounded">{componentKey}</code>
                    </p>
                    <details className="mt-3">
                        <summary className="text-xs text-muted-foreground cursor-pointer">Available Modules ({Object.keys(pageModules).length})</summary>
                        <pre className="mt-2 text-xs bg-muted p-3 rounded max-h-60 overflow-auto">
                            {JSON.stringify(Object.keys(pageModules), null, 2)}
                        </pre>
                    </details>
                    <button
                        type="button"
                        onClick={() => fetchPage(currentUrl)}
                        className="mt-4 rounded bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                    >
                        Retry
                    </button>
                </div>
            </div>
        );
    }

    return (
        <PageProvider
            value={{
                ...pageState.props,
                url: pageState.url || currentUrl,
                component: pageState.componentName,
            }}
        >
            <PageComponent {...pageState.props} />
        </PageProvider>
    );
}
