<?php

namespace App\Http\Requests;

use App\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Doubles as the update request — the route model binding decides which, and
 * the unique rule ignores the record being edited.
 */
class StoreHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isHrAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $holiday = $this->route('holiday');

        return [
            'name' => ['required', 'string', 'max:255'],
            'date' => [
                'required',
                'date',
                // The table's unique key is (date, name): the same day can carry
                // two proclamations, but not the same one twice.
                //
                // Written out rather than Rule::unique because `date` is a
                // date-cast column stored as "Y-m-d 00:00:00" — the exact
                // column equality Rule::unique generates never matches a
                // "Y-m-d" input, so the rule would pass and the insert would
                // then hit the database constraint instead. whereDate compares
                // the calendar day, which is what the key actually means.
                function (string $attribute, mixed $value, callable $fail) use ($holiday) {
                    $clash = Holiday::whereDate('date', $value)
                        ->where('name', $this->input('name'))
                        ->when($holiday, fn ($query) => $query->whereKeyNot($holiday->id))
                        ->exists();

                    if ($clash) {
                        $fail('That holiday is already recorded on this date.');
                    }
                },
            ],
            'type' => ['required', Rule::in([Holiday::TYPE_REGULAR, Holiday::TYPE_SPECIAL])],
            'is_nationwide' => ['boolean'],
        ];
    }
}
