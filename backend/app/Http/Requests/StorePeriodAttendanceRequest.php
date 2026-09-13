<?php

namespace App\Http\Requests;

use App\Models\AttendanceLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The cutoff DTR sheet, submitted as the cells that changed.
 *
 * The employee ids here are checked for shape only. Which of them this user
 * may actually write time for is re-derived at the write, in
 * TimekeepingService::saveSheet() — the browser sends ids, and scope is not
 * the browser's answer to give.
 */
class StorePeriodAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AttendanceLog::class);
    }

    public function rules(): array
    {
        return [
            // The cutoff on screen. saveSheet() refuses any cell outside it,
            // so a crafted payload cannot reach into a period nobody opened.
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],

            // 'present' rather than 'required': saving a sheet nobody changed
            // is a no-op, not an error.
            'cells' => ['present', 'array', 'max:5000'],
            'cells.*.employee_id' => ['required', 'integer'],
            'cells.*.log_date' => ['required', 'date'],
            // A blank status clears the day; anything else must be a status
            // the rest of the module recognises.
            'cells.*.status' => ['nullable', Rule::in(AttendanceLog::STATUSES)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // The same rule the single-record form applies, said once against
            // the whole sheet: an error on `cells.37.log_date` names a cell
            // nobody can find on a grid.
            $future = collect($this->input('cells', []))->contains(
                fn ($cell) => filled($cell['log_date'] ?? null)
                    && Carbon::parse($cell['log_date'])->startOfDay()->gt(today()),
            );

            if ($future) {
                $validator->errors()->add(
                    'cells',
                    'A time record cannot be dated in the future. Days after today are not open for encoding.',
                );
            }
        });
    }
}
