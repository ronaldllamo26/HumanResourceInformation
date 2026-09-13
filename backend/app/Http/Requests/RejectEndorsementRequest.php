<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Declining an endorsement.
 *
 * The reason is required, not optional. Core 1 reads the outcome back from the
 * row, and a recruiter told only "rejected" sends the same candidate again —
 * so the queue fills with the same unstated argument, twice, and then a third
 * time. Making it required costs one sentence and is the only thing that turns
 * a refusal into something the other side can act on.
 */
class RejectEndorsementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('decide', $this->route('endorsement'));
    }

    public function rules(): array
    {
        return [
            'decision_note' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision_note.required' => 'Say why this is being declined — Core 1 reads this back.',
            'decision_note.min' => 'Give Core 1 something it can act on.',
        ];
    }
}
