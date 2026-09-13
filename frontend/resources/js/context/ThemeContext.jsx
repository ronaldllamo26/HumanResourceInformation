import { createContext, useCallback, useContext, useEffect, useState } from 'react';

const STORAGE_KEY = 'primepower-theme';

const ThemeContext = createContext({
    theme: 'light',
    setTheme: () => {},
    toggleTheme: () => {},
});

function resolveInitialTheme() {
    if (typeof window === 'undefined') return 'light';

    const stored = window.localStorage.getItem(STORAGE_KEY);
    if (stored === 'light' || stored === 'dark') return stored;

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function ThemeProvider({ children }) {
    // The blade head script already applied the class; this keeps React in sync with it.
    const [theme, setThemeState] = useState(resolveInitialTheme);

    useEffect(() => {
        document.documentElement.classList.toggle('dark', theme === 'dark');
        window.localStorage.setItem(STORAGE_KEY, theme);
    }, [theme]);

    // Follow the OS only while the user has not made an explicit choice.
    useEffect(() => {
        const media = window.matchMedia('(prefers-color-scheme: dark)');

        const onChange = (event) => {
            if (window.localStorage.getItem(STORAGE_KEY)) return;
            setThemeState(event.matches ? 'dark' : 'light');
        };

        media.addEventListener('change', onChange);
        return () => media.removeEventListener('change', onChange);
    }, []);

    const setTheme = useCallback((next) => setThemeState(next), []);
    const toggleTheme = useCallback(
        () => setThemeState((current) => (current === 'dark' ? 'light' : 'dark')),
        [],
    );

    return (
        <ThemeContext.Provider value={{ theme, setTheme, toggleTheme }}>
            {children}
        </ThemeContext.Provider>
    );
}

export function useTheme() {
    return useContext(ThemeContext);
}
