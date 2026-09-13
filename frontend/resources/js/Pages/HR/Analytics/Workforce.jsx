import { Link } from '@inertiajs/react';
import {
    Briefcase,
    Building2,
    Clock,
    Handshake,
    TrendingDown,
    TrendingUp,
    UserCheck,
    UserMinus,
    UserPlus,
    Users,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Badge, Card, CardBody, CardHeader } from '@/Components/ui';
import { cn } from '@/lib/utils';

function StatTile({ label, value, hint, tone = 'text-foreground', icon: Icon }) {
    return (
        <div className="rounded-xl border border-border bg-card p-4 transition-all hover:border-border/80">
            <div className="flex items-center justify-between">
                <p className="text-xs font-medium text-muted-foreground">{label}</p>
                {Icon && (
                    <span className="grid h-8 w-8 place-items-center rounded-lg bg-secondary text-foreground">
                        <Icon className="h-4 w-4" aria-hidden="true" />
                    </span>
                )}
            </div>
            <p className={cn('mt-2 text-2xl font-bold tabular-nums', tone)}>{value}</p>
            {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

export default function Workforce({
    summary,
    monthlyMovement = [],
    tenureBrackets = [],
    clientDeployments = [],
    departmentHeadcounts = [],
    genderMix = [],
    statusMix = [],
}) {
    const maxMovement = Math.max(
        ...monthlyMovement.map((m) => Math.max(m.joined, m.separated, 1)),
        1,
    );

    return (
        <AppLayout
            title="Workforce Analytics"
            breadcrumbs={[{ label: 'AI & Analytics' }, { label: 'Workforce Analytics' }]}
        >
            {/* Header description */}
            <div className="mb-6">
                <h1 className="text-xl font-bold tracking-tight text-foreground sm:text-2xl">
                    Workforce & Demographics Intelligence
                </h1>
                <p className="mt-1 text-sm text-muted-foreground">
                    Real-time monitoring of manpower deployment, tenure distribution, and
                    attrition rates.
                </p>
            </div>

            {/* Top KPIs */}
            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Active Headcount"
                    value={summary.active_count}
                    hint={`${summary.external_count} deployed · ${summary.internal_count} in-house`}
                    icon={Users}
                    tone="text-primary"
                />
                <StatTile
                    label="Turnover Rate (YTD)"
                    value={`${summary.turnover_rate}%`}
                    hint={`${summary.separated_ytd} exits against total active workforce`}
                    icon={summary.turnover_rate > 15 ? TrendingDown : TrendingUp}
                    tone={summary.turnover_rate > 15 ? 'text-warning' : 'text-success'}
                />
                <StatTile
                    label="New Hires (YTD)"
                    value={summary.joined_ytd}
                    hint={`${summary.joined_ytd - summary.separated_ytd >= 0 ? '+' : ''}${summary.joined_ytd - summary.separated_ytd} net workforce change`}
                    icon={UserPlus}
                    tone="text-info"
                />
                <StatTile
                    label="Average Tenure"
                    value={`${summary.avg_tenure_months} mos`}
                    hint="Average retention duration of active staff"
                    icon={Clock}
                />
            </div>

            {/* Middle Section: Movement Trend & Tenure */}
            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                {/* Monthly Movement Trend */}
                <Card>
                    <CardHeader
                        title="Workforce Movement (Last 6 Months)"
                        description="Monthly joiners vs separations."
                    />
                    <CardBody>
                        <div className="space-y-4">
                            {monthlyMovement.map((row) => (
                                <div key={row.month} className="space-y-1.5">
                                    <div className="flex items-center justify-between text-xs">
                                        <span className="font-semibold text-foreground">
                                            {row.month}
                                        </span>
                                        <span className="tabular-nums text-muted-foreground">
                                            <span className="text-success">
                                                +{row.joined} hired
                                            </span>{' '}
                                            ·{' '}
                                            <span className="text-destructive">
                                                -{row.separated} left
                                            </span>{' '}
                                            ·{' '}
                                            <span className="font-medium text-foreground">
                                                Net {row.net >= 0 ? `+${row.net}` : row.net}
                                            </span>
                                        </span>
                                    </div>
                                    <div className="grid grid-cols-2 gap-2">
                                        <div className="h-2 overflow-hidden rounded-full bg-secondary">
                                            <div
                                                className="h-full bg-success transition-all duration-300"
                                                style={{
                                                    width: `${(row.joined / maxMovement) * 100}%`,
                                                }}
                                            />
                                        </div>
                                        <div className="h-2 overflow-hidden rounded-full bg-secondary">
                                            <div
                                                className="h-full bg-destructive transition-all duration-300"
                                                style={{
                                                    width: `${(row.separated / maxMovement) * 100}%`,
                                                }}
                                            />
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardBody>
                </Card>

                {/* Tenure Distribution */}
                <Card>
                    <CardHeader
                        title="Tenure Distribution"
                        description="Length of service across current active personnel."
                    />
                    <CardBody>
                        <div className="space-y-3.5">
                            {tenureBrackets.map((bracket) => (
                                <div key={bracket.label} className="space-y-1.5">
                                    <div className="flex items-center justify-between text-xs">
                                        <span className="font-medium text-foreground">
                                            {bracket.label}
                                        </span>
                                        <span className="tabular-nums text-muted-foreground">
                                            {bracket.count} staff ({bracket.percentage}%)
                                        </span>
                                    </div>
                                    <div className="h-2.5 overflow-hidden rounded-full bg-secondary">
                                        <div
                                            className="h-full rounded-full bg-primary transition-all duration-300"
                                            style={{ width: `${bracket.percentage}%` }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardBody>
                </Card>
            </div>

            {/* Lower Section: Deployments, Departments, and Demographics */}
            <div className="grid gap-6 lg:grid-cols-3">
                {/* Client Deployment Distribution */}
                <Card>
                    <CardHeader
                        title="Deployment Allocation"
                        description="Where manpower is deployed."
                        action={
                            <Link
                                href="/hr/clients"
                                className="text-xs text-primary hover:underline"
                            >
                                View Clients
                            </Link>
                        }
                    />
                    <CardBody>
                        <div className="space-y-3">
                            {clientDeployments.map((client) => (
                                <div
                                    key={client.name}
                                    className="flex items-center justify-between text-sm"
                                >
                                    <div className="flex min-w-0 items-center gap-2">
                                        <Handshake className="h-4 w-4 shrink-0 text-muted-foreground" />
                                        <span className="truncate text-xs font-medium text-foreground">
                                            {client.name}
                                        </span>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        <Badge variant="outline">{client.count}</Badge>
                                        <span className="w-8 text-right text-xs tabular-nums text-muted-foreground">
                                            {client.percentage}%
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardBody>
                </Card>

                {/* Department Distribution */}
                <Card>
                    <CardHeader
                        title="Department Headcount"
                        description="Staff distribution by department."
                    />
                    <CardBody>
                        <div className="space-y-3">
                            {departmentHeadcounts.map((dept) => (
                                <div
                                    key={dept.name}
                                    className="flex items-center justify-between text-sm"
                                >
                                    <div className="flex min-w-0 items-center gap-2">
                                        <Building2 className="h-4 w-4 shrink-0 text-muted-foreground" />
                                        <span className="truncate text-xs font-medium text-foreground">
                                            {dept.name}
                                        </span>
                                    </div>
                                    <Badge variant="primary">{dept.count}</Badge>
                                </div>
                            ))}
                        </div>
                    </CardBody>
                </Card>

                {/* Demographics & Status */}
                <Card>
                    <CardHeader
                        title="Workforce Demographics"
                        description="Gender and contract status breakdown."
                    />
                    <CardBody className="space-y-4">
                        <div>
                            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                Gender Ratio
                            </p>
                            <div className="space-y-2">
                                {genderMix.map((g) => (
                                    <div
                                        key={g.label}
                                        className="flex items-center justify-between text-xs"
                                    >
                                        <span className="text-muted-foreground">{g.label}</span>
                                        <span className="font-medium tabular-nums text-foreground">
                                            {g.count} ({g.percentage}%)
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <hr className="border-border" />

                        <div>
                            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                Employment Status
                            </p>
                            <div className="space-y-2">
                                {statusMix.map((s) => (
                                    <div
                                        key={s.label}
                                        className="flex items-center justify-between text-xs"
                                    >
                                        <span className="text-muted-foreground">{s.label}</span>
                                        <Badge variant="outline">{s.count}</Badge>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </CardBody>
                </Card>
            </div>
        </AppLayout>
    );
}
