<?php

namespace App\Http\Requests;

use App\Models\EmployeeEndorsement;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What Core 1 may send when it endorses a hire.
 *
 * The rules are looser than `StoreEmployeeRequest` on purpose, and the reason
 * is the whole design of this queue: **refusing to receive somebody is the one
 * outcome it exists to avoid.** A recruitment system that cannot hand a
 * candidate over because it does not know this system's pay frequency has not
 * been integrated with — it has been locked out, and the workaround is
 * somebody re-typing the record by hand, which is what the handover was for.
 *
 * So nothing here is required except identity: a reference and a name. Missing
 * detail arrives as a gap on the review screen, where a person can see it and
 * fill it. Everything a payroll record actually needs is still required — by
 * `StoreEmployeeRequest`, at approval, where it has always been.
 *
 * The fields Core 1 is *not* allowed to send are as deliberate as the ones it
 * is: `basic_salary`, `department_id`, `position_id`, `client_id`,
 * `employment_category`, `employment_status`, and `pay_frequency` are absent
 * from these rules and dropped from the payload. Accepting them would let
 * another system decide what PrimePower pays and who it bills, over an API
 * token, with nobody in this system having agreed to either.
 */
class StoreEndorsementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', EmployeeEndorsement::class);
    }

    public function rules(): array
    {
        return [
            /*
             * Core 1's own identifier. Required, and the reason a retry is
             * safe: without it a network timeout on their side becomes two
             * rows for one person, and two rows is two approvals and two
             * employee numbers.
             */
            'reference' => ['required', 'string', 'max:64'],

            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:16'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:16'],
            'civil_status' => ['nullable', 'string', 'max:16'],
            'nationality' => ['nullable', 'string', 'max:255'],
            'religion' => ['nullable', 'string', 'max:255'],
            'blood_type' => ['nullable', 'string', 'max:8'],

            'email' => ['nullable', 'email', 'max:255'],
            'mobile_number' => ['nullable', 'string', 'max:32'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'present_address' => ['nullable', 'string', 'max:255'],
            'permanent_address' => ['nullable', 'string', 'max:255'],

            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:64'],
            'emergency_contact_number' => ['nullable', 'string', 'max:32'],

            'sss_number' => ['nullable', 'string', 'max:32'],
            'philhealth_number' => ['nullable', 'string', 'max:32'],
            'pagibig_number' => ['nullable', 'string', 'max:32'],
            'tin' => ['nullable', 'string', 'max:32'],

            'drivers_license_number' => ['nullable', 'string', 'max:32'],
            'license_dl_codes' => ['nullable', 'string', 'max:32'],
            'license_conditions' => ['nullable', 'string', 'max:32'],
            'license_expiry' => ['nullable', 'date'],

            /*
             * What Core 1 hired them as, in words. Free text rather than an
             * id: Core 1 does not have this system's positions table, and
             * refusing an endorsement over a job title it spells differently
             * would be refusing to receive a person over a string.
             */
            'position_title' => ['nullable', 'string', 'max:255'],
            'client_name' => ['nullable', 'string', 'max:255'],

            // The agreed start date, if recruitment settled one. Not
            // `after_or_equal:today` — an endorsement sent late for somebody
            // who has already started is a record to catch up on, not an error
            // to reject.
            'date_hired' => ['nullable', 'date'],

            // Anything recruitment wants to pass along to whoever reviews it.
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference.required' => 'A reference is required so a resend cannot create a second record for the same person.',
        ];
    }
}
