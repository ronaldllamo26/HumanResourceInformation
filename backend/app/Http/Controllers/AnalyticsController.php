<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Department;
use App\Models\Employee;
use App\Services\EmployeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI & Analytics — Workforce demographics and trends.
 */
class AnalyticsController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
    ) {}

    /**
     * Workforce Demographics & Attrition Analytics.
     */
    public function workforce(Request $request): Response
    {
        abort_unless($request->user()->isHrAdmin(), 403);

        $today = Carbon::today();
        $yearStart = $today->copy()->startOfYear();

        $activeEmployees = Employee::where('status', 'active')->get();
        $activeCount = $activeEmployees->count();

        $internalCount = $activeEmployees->where('employment_category', Employee::CATEGORY_INTERNAL)->count();
        $externalCount = $activeEmployees->where('employment_category', Employee::CATEGORY_EXTERNAL)->count();

        $joinedYtd = Employee::where('date_hired', '>=', $yearStart)->count();
        $separatedYtd = Employee::where('date_separated', '>=', $yearStart)->count();

        $turnoverRate = $activeCount > 0
            ? round(($separatedYtd / max($activeCount + $separatedYtd, 1)) * 100, 1)
            : 0;

        // Average tenure in months for active staff
        $totalTenureDays = $activeEmployees->reduce(function ($carry, Employee $emp) use ($today) {
            return $carry + ($emp->date_hired ? $emp->date_hired->diffInDays($today) : 0);
        }, 0);
        $avgTenureMonths = $activeCount > 0 ? round(($totalTenureDays / $activeCount) / 30.4, 1) : 0;

        return Inertia::render('HR/Analytics/Workforce', [
            'summary' => [
                'active_count' => $activeCount,
                'internal_count' => $internalCount,
                'external_count' => $externalCount,
                'joined_ytd' => $joinedYtd,
                'separated_ytd' => $separatedYtd,
                'turnover_rate' => $turnoverRate,
                'avg_tenure_months' => $avgTenureMonths,
            ],
            'monthlyMovement' => $this->monthlyMovement($today),
            'tenureBrackets' => $this->tenureBrackets($activeEmployees, $today),
            'clientDeployments' => $this->clientDeployments($activeEmployees, $internalCount),
            'departmentHeadcounts' => $this->departmentHeadcounts(),
            'genderMix' => $this->genderMix($activeEmployees),
            'statusMix' => $this->statusMix(),
        ]);
    }

    /** @return array<int, array{month: string, joined: int, separated: int, net: int}> */
    private function monthlyMovement(Carbon $today): array
    {
        $movement = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $today->copy()->subMonths($i);
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            $joined = Employee::whereBetween('date_hired', [$start, $end])->count();
            $separated = Employee::whereBetween('date_separated', [$start, $end])->count();

            $movement[] = [
                'month' => $month->format('M Y'),
                'joined' => $joined,
                'separated' => $separated,
                'net' => $joined - $separated,
            ];
        }

        return $movement;
    }

    /** @return array<int, array{label: string, count: int, percentage: int}> */
    private function tenureBrackets($employees, Carbon $today): array
    {
        $brackets = [
            '< 6 mos' => 0,
            '6–12 mos' => 0,
            '1–2 yrs' => 0,
            '2–3 yrs' => 0,
            '3+ yrs' => 0,
        ];

        foreach ($employees as $emp) {
            if (! $emp->date_hired) {
                continue;
            }

            $months = $emp->date_hired->diffInMonths($today);

            if ($months < 6) {
                $brackets['< 6 mos']++;
            } elseif ($months <= 12) {
                $brackets['6–12 mos']++;
            } elseif ($months <= 24) {
                $brackets['1–2 yrs']++;
            } elseif ($months <= 36) {
                $brackets['2–3 yrs']++;
            } else {
                $brackets['3+ yrs']++;
            }
        }

        $total = max($employees->count(), 1);

        return collect($brackets)->map(fn ($count, $label) => [
            'label' => $label,
            'count' => $count,
            'percentage' => (int) round(($count / $total) * 100),
        ])->values()->all();
    }

    /** @return array<int, array{name: string, count: int, percentage: int}> */
    private function clientDeployments($employees, int $internalCount): array
    {
        $clients = Client::withCount(['employees' => fn ($q) => $q->where('status', 'active')])
            ->orderByDesc('employees_count')
            ->take(5)
            ->get();

        $total = max($employees->count(), 1);

        $result = [];

        if ($internalCount > 0) {
            $result[] = [
                'name' => 'Agency In-House (Internal)',
                'count' => $internalCount,
                'percentage' => (int) round(($internalCount / $total) * 100),
            ];
        }

        foreach ($clients as $client) {
            if ($client->employees_count > 0) {
                $result[] = [
                    'name' => $client->name,
                    'count' => $client->employees_count,
                    'percentage' => (int) round(($client->employees_count / $total) * 100),
                ];
            }
        }

        return $result;
    }

    /** @return array<int, array{name: string, count: int}> */
    private function departmentHeadcounts(): array
    {
        return Department::withCount(['employees' => fn ($q) => $q->where('status', 'active')])
            ->orderByDesc('employees_count')
            ->get(['id', 'name'])
            ->map(fn ($d) => [
                'name' => $d->name,
                'count' => $d->employees_count,
            ])
            ->all();
    }

    /** @return array<int, array{label: string, count: int, percentage: int}> */
    private function genderMix($employees): array
    {
        $male = $employees->where('gender', 'male')->count();
        $female = $employees->where('gender', 'female')->count();
        $other = $employees->whereNotIn('gender', ['male', 'female'])->count();
        $total = max($employees->count(), 1);

        return [
            ['label' => 'Male', 'count' => $male, 'percentage' => (int) round(($male / $total) * 100)],
            ['label' => 'Female', 'count' => $female, 'percentage' => (int) round(($female / $total) * 100)],
            ['label' => 'Other / Unspecified', 'count' => $other, 'percentage' => (int) round(($other / $total) * 100)],
        ];
    }

    /** @return array<int, array{label: string, count: int}> */
    private function statusMix(): array
    {
        $counts = Employee::where('status', 'active')
            ->selectRaw('employment_status, count(*) as total')
            ->groupBy('employment_status')
            ->pluck('total', 'employment_status');

        return [
            ['label' => 'Regular', 'count' => (int) ($counts['regular'] ?? 0)],
            ['label' => 'Probationary', 'count' => (int) ($counts['probationary'] ?? 0)],
            ['label' => 'Contractual / Project', 'count' => (int) (($counts['contractual'] ?? 0) + ($counts['project-based'] ?? 0))],
        ];
    }
}
