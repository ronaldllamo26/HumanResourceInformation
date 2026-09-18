import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    Archive as ArchiveIcon,
    Briefcase,
    Handshake,
    KeyRound,
    RotateCcw,
    Shield,
    Users,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    Modal,
    SearchInput,
    StatCard,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
} from '@/Components/ui';
import { cn, formatDate } from '@/lib/utils';

export default function Archive({ rows = [], filters, summary, window: restoreWindow }) {
    const [pending, setPending] = useState(null);
    const [activeCategory, setActiveCategory] = useState(filters?.category || 'all');

    const confirmRestore = () => {
        if (!pending) return;

        let path = `/hr/archive/employees/${pending.id}/restore`;
        if (pending.kind === 'user') {
            path = `/hr/archive/users/${pending.id}/restore`;
        } else if (pending.kind === 'client') {
            path = `/hr/archive/clients/${pending.id}/restore`;
        }

        router.post(path, {}, { preserveScroll: true, onFinish: () => setPending(null) });
    };

    const search = (value) =>
        router.get(
            '/hr/archive',
            {
                search: value || undefined,
                category: activeCategory !== 'all' ? activeCategory : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const selectCategory = (categoryKey) => {
        setActiveCategory(categoryKey);
    };

    const filteredRows = useMemo(() => {
        if (activeCategory === 'all') return rows;
        return rows.filter((row) => row.kind === activeCategory);
    }, [rows, activeCategory]);

    const categories = [
        {
            key: 'all',
            label: 'All Records',
            count: rows.length,
            icon: ArchiveIcon,
        },
        {
            key: 'employee',
            label: 'Employees',
            count: summary.employees ?? 0,
            icon: Users,
        },
        {
            key: 'user',
            label: 'User Accounts',
            count: summary.users ?? 0,
            icon: KeyRound,
        },
        {
            key: 'client',
            label: 'Clients',
            count: summary.clients ?? 0,
            icon: Handshake,
        },
    ];

    return (
        <AppLayout
            title="Archive Module"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Archive' },
            ]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Archived Employees"
                    value={summary.employees}
                    icon={Users}
                    tone={summary.employees > 0 ? 'info' : 'muted'}
                />
                <StatCard
                    label="Archived User Accounts"
                    value={summary.users}
                    icon={KeyRound}
                    tone={summary.users > 0 ? 'warning' : 'muted'}
                />
                <StatCard
                    label="Archived Clients"
                    value={summary.clients}
                    icon={Handshake}
                    tone={summary.clients > 0 ? 'info' : 'muted'}
                />
                <StatCard
                    label="Deleted Recently"
                    value={summary.within_window}
                    icon={ArchiveIcon}
                    tone={summary.within_window > 0 ? 'warning' : 'muted'}
                />
            </div>

            <Card>
                <div className="border-b border-border p-4">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="text-base font-semibold text-foreground">
                                Archive & Recycle Bin
                            </h2>
                        </div>
                        <div className="w-full sm:w-64">
                            <SearchInput
                                defaultValue={filters.search ?? ''}
                                onChange={(event) => search(event.target.value)}
                                placeholder="Search name, number, or code"
                                aria-label="Search the archive"
                            />
                        </div>
                    </div>

                    {/* Category Filter Tabs */}
                    <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-border/50 pt-3">
                        <span className="mr-1 text-xs font-medium text-muted-foreground">
                            Category:
                        </span>
                        {categories.map((cat) => {
                            const IconComponent = cat.icon;
                            const isSelected = activeCategory === cat.key;
                            return (
                                <button
                                    key={cat.key}
                                    type="button"
                                    onClick={() => selectCategory(cat.key)}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors',
                                        isSelected
                                            ? 'bg-primary text-primary-foreground shadow-sm'
                                            : 'bg-muted/70 text-muted-foreground hover:bg-muted hover:text-foreground',
                                    )}
                                >
                                    <IconComponent className="h-3.5 w-3.5" />
                                    <span>{cat.label}</span>
                                    <span
                                        className={cn(
                                            'py-0.2 ml-1 rounded-full px-1.5 text-[10px] tabular-nums',
                                            isSelected
                                                ? 'bg-primary-foreground/20 text-primary-foreground'
                                                : 'bg-background/80 text-muted-foreground',
                                        )}
                                    >
                                        {cat.count}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Record</TH>
                            <TH>Type / Category</TH>
                            <TH>Details / Context</TH>
                            <TH>Date Deleted</TH>
                            <TH className="text-right">Action</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {filteredRows.length === 0 ? (
                            <TableEmpty
                                colSpan={5}
                                icon={ArchiveIcon}
                                title={
                                    filters.search
                                        ? 'Nothing archived matches that search'
                                        : `No ${activeCategory === 'all' ? 'records' : activeCategory + 's'} currently in the archive`
                                }
                                description="Deleted records are kept here safely so they can be restored whenever needed."
                            />
                        ) : (
                            filteredRows.map((row) => (
                                <TR key={`${row.kind}-${row.id}`}>
                                    <TD>
                                        <p className="font-medium text-foreground">
                                            {row.name}
                                        </p>
                                        <p className="font-mono text-xs text-muted-foreground">
                                            {row.reference}
                                        </p>
                                    </TD>

                                    <TD>
                                        {row.kind === 'employee' && (
                                            <Badge variant="info" className="gap-1">
                                                <Users className="h-3 w-3" />
                                                Employee
                                            </Badge>
                                        )}
                                        {row.kind === 'user' && (
                                            <Badge variant="warning" className="gap-1">
                                                <KeyRound className="h-3 w-3" />
                                                User Account
                                            </Badge>
                                        )}
                                        {row.kind === 'client' && (
                                            <Badge variant="muted" className="gap-1">
                                                <Handshake className="h-3 w-3" />
                                                Client
                                            </Badge>
                                        )}
                                    </TD>

                                    <TD className="text-sm">
                                        <p className="text-foreground">{row.detail}</p>
                                        {row.sub_detail && (
                                            <p className="text-xs text-muted-foreground">
                                                {row.sub_detail}
                                            </p>
                                        )}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm">
                                        <p className="text-foreground">
                                            {formatDate(row.deleted_at)}
                                        </p>
                                        <p
                                            className={
                                                row.within_window
                                                    ? 'text-xs font-medium text-warning'
                                                    : 'text-xs text-muted-foreground'
                                            }
                                        >
                                            {row.days_ago === 0
                                                ? 'Today'
                                                : `${row.days_ago} day(s) ago`}
                                        </p>
                                    </TD>

                                    <TD className="text-right">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setPending(row)}
                                            className="hover:border-primary/40 hover:bg-primary/10 hover:text-primary"
                                        >
                                            <RotateCcw className="mr-1 h-3.5 w-3.5" />
                                            Restore
                                        </Button>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            {/* Confirmation Modal */}
            <Modal
                show={pending !== null}
                onClose={() => setPending(null)}
                title="Restore this archived record?"
                description={
                    pending?.kind === 'employee'
                        ? `${pending?.name} (${pending?.reference}) will be restored into the Employee Directory as Active (Regular). If they have a linked user account, it will also be reactivated.`
                        : pending?.kind === 'user'
                          ? `The login account for ${pending?.name} (${pending?.reference}) will be restored and reactivated. If an associated employee profile was archived, it will also be restored to the Employee Directory.`
                          : `${pending?.name} goes back onto the active client list and can be assigned deployments again.`
                }
            >
                <div className="mt-4 flex justify-end gap-2">
                    <Button variant="outline" onClick={() => setPending(null)}>
                        Cancel
                    </Button>
                    <Button onClick={confirmRestore}>
                        <RotateCcw className="mr-1 h-4 w-4" />
                        Confirm & Restore
                    </Button>
                </div>
            </Modal>
        </AppLayout>
    );
}
