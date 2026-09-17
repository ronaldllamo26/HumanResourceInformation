import { Link } from '@inertiajs/react';
import { Briefcase, Building2, Network } from 'lucide-react';
import { cn } from '@/lib/utils';

export default function OrgTabs({ currentTab, viewMode, onViewModeChange }) {
    const tabs = [
        {
            id: 'tree',
            label: 'Hierarchy Tree',
            href: '/hr/departments?view=tree',
            icon: Network,
            desc: 'Visual org chart',
        },
        {
            id: 'departments',
            label: 'Departments & Staff',
            href: '/hr/departments?view=departments',
            icon: Building2,
            desc: 'Department units & teams',
        },
        {
            id: 'positions',
            label: 'Positions & Bands',
            href: '/hr/departments?view=positions',
            icon: Briefcase,
            desc: 'Job titles & salary bands',
        },
    ];

    const effectiveTab = viewMode || currentTab || 'tree';

    return (
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-border pb-px">
            <div className="flex flex-wrap gap-1 sm:gap-2">
                {tabs.map((t) => {
                    const Icon = t.icon;
                    const isActive =
                        effectiveTab === t.id ||
                        (effectiveTab === 'directory' && t.id === 'tree') ||
                        (currentTab === t.id);

                    if (onViewModeChange) {
                        return (
                            <button
                                key={t.id}
                                type="button"
                                onClick={() => onViewModeChange(t.id)}
                                className={cn(
                                    'flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors',
                                    isActive
                                        ? 'border-primary text-primary font-semibold'
                                        : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                                )}
                            >
                                <Icon className="h-4 w-4" />
                                <span>{t.label}</span>
                            </button>
                        );
                    }

                    return (
                        <Link
                            key={t.id}
                            href={t.href}
                            preserveScroll
                            className={cn(
                                'flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors',
                                isActive
                                    ? 'border-primary text-primary font-semibold'
                                    : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                            )}
                        >
                            <Icon className="h-4 w-4" />
                            <span>{t.label}</span>
                        </Link>
                    );
                })}
            </div>
        </div>
    );
}
