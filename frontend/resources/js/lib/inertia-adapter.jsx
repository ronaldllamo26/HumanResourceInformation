import React, { createContext, useContext, useState, useEffect } from 'react';
import { Link as RouterLink, useNavigate } from 'react-router-dom';
import { useAuth } from '@/contexts/AuthContext';
import api from '@/lib/api';

const PageContext = createContext({});

export function PageProvider({ value = {}, children }) {
    return <PageContext.Provider value={value}>{children}</PageContext.Provider>;
}

let globalNavigator = null;

export function registerNavigator(nav) {
    globalNavigator = nav;
}

export function usePage() {
    const auth = useAuth();
    const pageContext = useContext(PageContext);

    const user = pageContext?.auth?.user || auth?.user || null;
    const brand = pageContext?.brand || auth?.brand || { name: 'PrimePower' };

    return {
        props: {
            auth: { user },
            brand,
            flash: pageContext?.flash || {},
            errors: pageContext?.errors || {},
            ...pageContext,
        },
        url: pageContext?.url || (typeof window !== 'undefined' ? window.location.pathname + window.location.search : '/'),
        component: pageContext?.component || '',
        version: null,
    };
}

export function Head({ title, children }) {
    useEffect(() => {
        if (title) {
            document.title = `${title} - PrimePower HRIS`;
        }
    }, [title]);

    return children || null;
}

export function Link({ href, to, children, className, method = 'get', as = 'a', onClick, ...props }) {
    const target = href || to || '#';
    const navigate = useNavigate();

    if (method && method.toLowerCase() !== 'get') {
        const handleClick = async (e) => {
            e.preventDefault();
            if (onClick) onClick(e);
            try {
                await api[method.toLowerCase()](target);
                navigate(0);
            } catch (err) {
                console.error(err);
            }
        };

        if (as === 'button') {
            return (
                <button type="button" className={className} onClick={handleClick} {...props}>
                    {children}
                </button>
            );
        }

        return (
            <a href={target} className={className} onClick={handleClick} {...props}>
                {children}
            </a>
        );
    }

    if (target.startsWith('http') || target.startsWith('#')) {
        return (
            <a href={target} className={className} onClick={onClick} {...props}>
                {children}
            </a>
        );
    }

    return (
        <RouterLink to={target} className={className} onClick={onClick} {...props}>
            {children}
        </RouterLink>
    );
}

export function useForm(initialData = {}) {
    const [data, setDataState] = useState(initialData);
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const [recentlySuccessful, setRecentlySuccessful] = useState(false);

    const setData = (keyOrUpdater, value) => {
        if (typeof keyOrUpdater === 'function') {
            setDataState(keyOrUpdater);
        } else if (typeof keyOrUpdater === 'object' && keyOrUpdater !== null) {
            setDataState(keyOrUpdater);
        } else {
            setDataState((prev) => ({ ...prev, [keyOrUpdater]: value }));
        }
    };

    const submit = async (method, url, options = {}) => {
        setProcessing(true);
        setErrors({});
        try {
            const res = await api[method.toLowerCase()](url, data, {
                headers: {
                    'X-Inertia': 'true',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            setRecentlySuccessful(true);
            setTimeout(() => setRecentlySuccessful(false), 2000);
            if (options.onSuccess) options.onSuccess(res);
            window.dispatchEvent(new CustomEvent('inertia:refresh'));
            return res;
        } catch (err) {
            const apiErrors = err.response?.data?.errors || {};
            setErrors(apiErrors);
            if (options.onError) options.onError(apiErrors);
            throw err;
        } finally {
            setProcessing(false);
            if (options.onFinish) options.onFinish();
        }
    };

    const reset = (...fields) => {
        if (fields.length === 0) {
            setDataState(initialData);
        } else {
            setDataState((prev) => {
                const next = { ...prev };
                fields.forEach((f) => {
                    next[f] = initialData[f];
                });
                return next;
            });
        }
    };

    return {
        data,
        setData,
        errors,
        setErrors,
        processing,
        recentlySuccessful,
        reset,
        clearErrors: (...fields) => {
            if (fields.length === 0) setErrors({});
            else {
                setErrors((prev) => {
                    const next = { ...prev };
                    fields.forEach((f) => delete next[f]);
                    return next;
                });
            }
        },
        setError: (key, val) => setErrors((prev) => ({ ...prev, [key]: val })),
        post: (url, opts) => submit('post', url, opts),
        put: (url, opts) => submit('put', url, opts),
        patch: (url, opts) => submit('patch', url, opts),
        delete: (url, opts) => submit('delete', url, opts),
    };
}

export const router = {
    on: (event, callback) => {
        const handler = (e) => (typeof callback === 'function' ? callback(e?.detail || e) : null);
        window.addEventListener(`inertia:${event}`, handler);
        return () => window.removeEventListener(`inertia:${event}`, handler);
    },
    visit: (url, options = {}) => {
        if (options?.method && options.method.toLowerCase() !== 'get') {
            return router[options.method.toLowerCase()](url, options.data, options);
        }
        if (globalNavigator) {
            globalNavigator(url);
        } else {
            window.location.href = url;
        }
    },
    get: (url, data = {}, options = {}) => {
        let fullUrl = url;
        if (data && typeof data === 'object' && Object.keys(data).length > 0) {
            const params = new URLSearchParams();
            for (const [k, v] of Object.entries(data)) {
                if (v !== undefined && v !== null && v !== '') {
                    params.append(k, v);
                }
            }
            const qs = params.toString();
            if (qs) {
                fullUrl += (url.includes('?') ? '&' : '?') + qs;
            }
        }
        if (globalNavigator) {
            globalNavigator(fullUrl);
        } else {
            window.location.href = fullUrl;
        }
    },
    post: async (url, data, opts) => {
        try {
            const res = await api.post(url, data, {
                headers: {
                    'X-Inertia': 'true',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (opts?.onSuccess) opts.onSuccess(res);
            window.dispatchEvent(new CustomEvent('inertia:refresh'));
            return res;
        } catch (err) {
            if (opts?.onError) opts.onError(err.response?.data?.errors || err);
            throw err;
        }
    },
    put: async (url, data, opts) => {
        try {
            const res = await api.put(url, data, {
                headers: {
                    'X-Inertia': 'true',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (opts?.onSuccess) opts.onSuccess(res);
            window.dispatchEvent(new CustomEvent('inertia:refresh'));
            return res;
        } catch (err) {
            if (opts?.onError) opts.onError(err.response?.data?.errors || err);
            throw err;
        }
    },
    patch: async (url, data, opts) => {
        try {
            const res = await api.patch(url, data, {
                headers: {
                    'X-Inertia': 'true',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (opts?.onSuccess) opts.onSuccess(res);
            window.dispatchEvent(new CustomEvent('inertia:refresh'));
            return res;
        } catch (err) {
            if (opts?.onError) opts.onError(err.response?.data?.errors || err);
            throw err;
        }
    },
    delete: async (url, opts) => {
        try {
            const res = await api.delete(url, {
                data: opts?.data,
                headers: {
                    'X-Inertia': 'true',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (opts?.onSuccess) opts.onSuccess(res);
            window.dispatchEvent(new CustomEvent('inertia:refresh'));
            return res;
        } catch (err) {
            if (opts?.onError) opts.onError(err.response?.data?.errors || err);
            throw err;
        }
    },
    reload: () => {
        window.dispatchEvent(new CustomEvent('inertia:refresh'));
    },
};

export function WhenVisible({ children }) {
    return children;
}

export default {
    Head,
    Link,
    usePage,
    useForm,
    router,
    WhenVisible,
};
