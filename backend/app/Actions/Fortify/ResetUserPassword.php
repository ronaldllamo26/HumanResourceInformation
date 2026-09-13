<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        /*
         * The hold has to lift here too, and it did not.
         *
         * `must_change_password` marks a login still on the password somebody
         * else chose, and `RequirePasswordChange` pins the account to
         * `/settings/security` until that stops being true. Only
         * `SecurityController::updatePassword()` cleared it — so a person who
         * took the *other* route to the same act, the emailed reset link, chose
         * a password nobody else had ever seen and was still held afterwards,
         * with the screen telling them to replace a password they had just
         * replaced. There is no way out of that loop from the reset flow.
         *
         * A reset is the user choosing their own password, which is the whole
         * condition the flag describes. Both paths clear it now, for the same
         * reason and with the same consequence below.
         */
        $wasForced = (bool) $user->must_change_password;

        $user->forceFill([
            'password' => Hash::make($input['password']),
            'must_change_password' => false,
        ])->save();

        // A token issued while the shared password was live was issued to
        // whoever held that password. Rotating one and leaving the other is
        // half a rotation — the same rule the Settings path applies.
        if ($wasForced) {
            $user->tokens()->delete();
        }
    }
}
