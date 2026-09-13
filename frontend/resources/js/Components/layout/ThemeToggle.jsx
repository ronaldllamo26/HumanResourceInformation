import { Moon, Sun } from 'lucide-react';
import { useTheme } from '@/context/ThemeContext';
import { cn } from '@/lib/utils';

export default function ThemeToggle({ className }) {
    const { theme, toggleTheme } = useTheme();
    const isDark = theme === 'dark';

    return (
        <button
            type="button"
            onClick={toggleTheme}
            aria-label={isDark ? 'Switch to light mode' : 'Switch to dark mode'}
            title={isDark ? 'Light mode' : 'Dark mode'}
            className={cn(
                'grid h-9 w-9 place-items-center rounded-md text-muted-foreground',
                'transition-colors hover:bg-secondary hover:text-foreground',
                className,
            )}
        >
            {isDark ? (
                <Sun className="h-4.5 w-4.5" aria-hidden="true" />
            ) : (
                <Moon className="h-4.5 w-4.5" aria-hidden="true" />
            )}
        </button>
    );
}
