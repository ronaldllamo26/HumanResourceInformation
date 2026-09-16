<?php

namespace App\Http\Controllers\Timekeeping;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Holiday;
use App\Services\TimekeepingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Holiday Calendar.
 *
 * Read by three modules at once: attendance names the day, leave does not
 * charge a credit for it, and payroll pays the premium for working it. A year
 * with nothing recorded is therefore not an empty screen — it silently charges
 * leave for holidays — so the screen warns when next year is still empty.
 */
class HolidayController extends Controller
{
    public function __construct(private readonly TimekeepingService $timekeeping) {}

    public function index(Request $request): Response
    {
        $year = (int) ($request->integer('year') ?: now()->year);
        $holidays = Holiday::whereYear('date', $year)->orderBy('date')->get();

        return Inertia::render('HR/Timekeeping/Holidays', [
            'holidays' => $holidays->map(fn (Holiday $holiday) => [
                'id' => $holiday->id,
                'date' => $holiday->date->toDateString(),
                'day' => $holiday->date->format('l'),
                'name' => $holiday->name,
                'type' => $holiday->type,
                'multiplier' => config('payroll.premiums.'.$holiday->type.'_holiday'),
            ]),
            'filters' => ['year' => $year],
            'years' => range(now()->year - 1, now()->year + 2),
            'summary' => [
                'total' => $holidays->count(),
                'regular' => $holidays->where('type', Holiday::TYPE_REGULAR)->count(),
                'special' => $holidays->where('type', Holiday::TYPE_SPECIAL)->count(),
            ],
            'nextYear' => ['year' => now()->year + 1, 'count' => Holiday::whereYear('date', now()->year + 1)->count()],
            'can' => ['manage' => $request->user()->can('manage', AttendanceLog::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $data = $this->validated($request);
        Holiday::create($data);

        return back()->with('success', "{$data['name']} added. Days already recorded on that date keep their status until recorded again.");
    }

    public function update(Request $request, Holiday $holiday): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $this->timekeeping->assertOpen($holiday->date, 'date');
        $holiday->update($this->validated($request, $holiday));

        return back()->with('success', 'Holiday updated.');
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $this->timekeeping->assertOpen($holiday->date, 'date');
        $holiday->delete();

        return back()->with('success', "{$holiday->name} removed.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Holiday $holiday = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'date' => ['required', 'date'],
            'type' => ['required', Rule::in(Holiday::TYPES)],
        ]);

        $date = Carbon::parse($data['date'])->toDateString();
        $this->timekeeping->assertOpen(Carbon::parse($date), 'date');

        // whereDate, not Rule::unique: a date-cast column may be stored with a time.
        $duplicate = Holiday::query()
            ->whereDate('date', $date)
            ->where('name', $data['name'])
            ->when($holiday, fn ($query) => $query->whereKeyNot($holiday->id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'That holiday is already on this date.']);
        }

        return [...$data, 'date' => $date];
    }
}
