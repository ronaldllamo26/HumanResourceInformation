import { useEffect, useState } from 'react';
import { Check, Monitor, Moon, Sun } from 'lucide-react';
import { LogoMark } from '@/Components/layout/PrimePowerLogo';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { Card, CardBody, CardHeader } from '@/Components/ui';
import { useTheme } from '@/context/ThemeContext';
import { cn } from '@/lib/utils';

const THEME_KEY = 'primepower-theme';
const COLLAPSE_KEY = 'primepower-sidebar-collapsed';

const THEMES = [
    { value: 'light', label: 'Light', icon: Sun, description: 'Always the light palette.' },
    { value: 'dark', label: 'Dark', icon: Moon, description: 'Always the dark palette.' },
    {
        value: 'system',
        label: 'System',
        icon: Monitor,
        description: 'Follows your operating system.',
    },
];

/**
 * These are per-device preferences, so they live in this browser's storage
 * rather than the database — the same account on a different machine keeps its
 * own choice.
 */
export default function Appearance({ brand }) {
    const { theme, setTheme } = useTheme();

    const [choice, setChoice] = useState('system');
    const [collapsed, setCollapsed] = useState(false);

    useEffect(() => {
        setChoice(window.localStorage.getItem(THEME_KEY) ?? 'system');
        setCollapsed(window.localStorage.getItem(COLLAPSE_KEY) === '1');
    }, []);

    const pickTheme = (value) => {
        setChoice(value);

        if (value === 'system') {
            // Clearing the key is what puts the app back under OS control.
            window.localStorage.removeItem(THEME_KEY);
            setTheme(
                window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light',
            );

            return;
        }

        setTheme(value);
    };

    const toggleCollapsed = (value) => {
        setCollapsed(value);
        window.localStorage.setItem(COLLAPSE_KEY, value ? '1' : '0');
        // The sidebar reads this on mount, so the change lands on next load.
    };

    return (
        <SettingsLayout title="Appearance">
            <Card>
                <CardHeader title="Theme" />
                <CardBody className="grid gap-3 sm:grid-cols-3">
                    {THEMES.map((option) => {
                        const Icon = option.icon;
                        const selected = choice === option.value;

                        return (
                            <button
                                key={option.value}
                                type="button"
                                onClick={() => pickTheme(option.value)}
                                aria-pressed={selected}
                                className={cn(
                                    'rounded-lg border p-4 text-left transition-colors',
                                    selected
                                        ? 'border-primary bg-primary/5'
                                        : 'border-border hover:bg-secondary/60',
                                )}
                            >
                                <div className="mb-2 flex items-center justify-between">
                                    <Icon
                                        className={cn(
                                            'h-5 w-5',
                                            selected ? 'text-primary' : 'text-muted-foreground',
                                        )}
                                        aria-hidden="true"
                                    />
                                    {selected && (
                                        <Check
                                            className="h-4 w-4 text-primary"
                                            aria-hidden="true"
                                        />
                                    )}
                                </div>
                                <p
                                    className={cn(
                                        'text-sm font-medium',
                                        selected ? 'text-primary' : 'text-foreground',
                                    )}
                                >
                                    {option.label}
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {option.description}
                                </p>
                            </button>
                        );
                    })}
                </CardBody>
            </Card>

            <Card>
                <CardHeader title="Navigation" />
                <CardBody>
                    <label className="flex items-start gap-3">
                        <input
                            type="checkbox"
                            checked={collapsed}
                            onChange={(event) => toggleCollapsed(event.target.checked)}
                            className="mt-0.5 h-4 w-4 rounded border-input text-primary focus:ring-ring/30"
                        />
                        <span>
                            <span className="block text-sm text-foreground">
                                Start with the sidebar collapsed
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                Shows icons only until you expand it. Takes effect on the next
                                page load.
                            </span>
                        </span>
                    </label>
                </CardBody>
            </Card>

            <Card>
                <CardHeader title="Preview" />
                <CardBody>
                    <div className="flex items-center gap-3 rounded-lg border border-border bg-sidebar p-4">
                        <LogoMark />
                        <div className="min-w-0">
                            <p className="truncate text-[13px] font-bold tracking-tight text-logo-primary">
                                {brand.name?.toUpperCase()}
                            </p>
                            <p className="truncate text-[11px] text-logo-subtitle">
                                {brand.tagline}
                            </p>
                        </div>
                    </div>
                </CardBody>
            </Card>
        </SettingsLayout>
    );
}
