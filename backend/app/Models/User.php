<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_HR_STAFF = 'hr_staff';

    public const ROLE_SUPERVISOR = 'supervisor';

    public const ROLE_EMPLOYEE = 'employee';

    /** Every username ends in this. It looks like an address; nothing is mailed to it. */
    public const USERNAME_DOMAIN = 'primepower.com';

    public const ROLES = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_ADMIN,
        self::ROLE_HR_STAFF,
        self::ROLE_SUPERVISOR,
        self::ROLE_EMPLOYEE,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'is_active',
        'must_change_password',
        'otp_email',
        'otp_enabled',
        'visible_password',
        'privacy_notice_version',
        'privacy_acknowledged_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'visible_password',
        'remember_token',
    ];

    /**
     * A free username for a person: Juan Dela Cruz becomes
     * `jdelacruz@primepower.com`, and if that is taken,
     * `jdelacruz2@primepower.com`.
     */
    public static function usernameFor(string $firstName, string $lastName): string
    {
        return static::availableUsername(mb_substr(trim($firstName), 0, 1).$lastName);
    }

    /**
     * A free username from any starting text — a name, or the part of an
     * address before the `@`. Lowercased, stripped to the characters a
     * username may hold, given the company domain, and numbered when taken.
     */
    public static function availableUsername(string $seed): string
    {
        $base = preg_replace('/[^a-z0-9._-]/', '', strtolower(strstr($seed, '@', true) ?: $seed));
        $base = $base !== '' ? substr($base, 0, 30) : 'user';

        $candidate = static::withDomain($base);

        for ($n = 2; static::where('username', $candidate)->exists(); $n++) {
            $candidate = static::withDomain($base.$n);
        }

        return $candidate;
    }

    /**
     * Where a notification to this account goes.
     *
     * The username is a credential, not an address — `name@primepower.com` is
     * shaped like one and nothing is ever mailed to it — so mail is routed to
     * the personal inbox an administrator connected for sign-in codes. An
     * account with none is not mailable at all, which is exactly why having
     * one is what enrols it in the second factor.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->otp_email;
    }

    /**
     * A company username suggested from a personal address.
     *
     * A *suggestion*, and the distinction is the point: `johnpogs.b@gmail.com`
     * could reasonably become `johnpogs`, `john`, or `jbenavidez`, and which
     * one a person is called at work is not derivable from their inbox. The
     * form offers this and the administrator types over it.
     *
     * The local part up to the first dot, plus-tag or digit, because that is
     * the part of a Gmail address that is usually the person's name —
     * `johnpogs.b` gives `johnpogs`, `nina+work` gives `nina`.
     */
    public static function suggestUsernameFromEmail(string $email): string
    {
        $local = strstr(strtolower(trim($email)), '@', true) ?: strtolower(trim($email));
        $head = preg_split('/[.+_\d]/', $local)[0] ?? '';

        return static::availableUsername($head !== '' ? $head : $local);
    }

    /** `nina` becomes `nina@primepower.com`; a name already carrying a domain is kept. */
    public static function withDomain(string $username): string
    {
        $username = strtolower(trim($username));

        return str_contains($username, '@') ? $username : $username.'@'.self::USERNAME_DOMAIN;
    }

    /**
     * A temporary password for a login somebody else is provisioning — HR
     * creating a self-service account, an admin adding a user.
     *
     * `Str::password()` draws from a pool that contains every character class
     * but guarantees none of them: a 12-character draw comes out all-lowercase
     * often enough to matter, and that password then fails the very policy
     * AppServiceProvider enforces when the user tries to change it. This seeds
     * one character from each required class, fills the rest at random, and
     * shuffles, so what HR reads out is always a password the user can keep.
     */
    public static function generatePassword(int $length = 16): string
    {
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $symbols = '!@#$';

        $pool = $lower.$upper.$digits.$symbols;

        // One guaranteed character per class the policy requires.
        $characters = [
            $lower[random_int(0, strlen($lower) - 1)],
            $upper[random_int(0, strlen($upper) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];

        for ($i = count($characters); $i < $length; $i++) {
            $characters[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        // Without the shuffle the classes would always appear in a fixed
        // order, which is a pattern worth not handing out.
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    public function setVisiblePassword(string $plain): void
    {
        $this->visible_password = Crypt::encryptString($plain);
    }

    public function getDecryptedPassword(): ?string
    {
        if (blank($this->visible_password)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->visible_password);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The 201 file belonging to this login, when the user is also an employee. */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /** The 201 file belonging to this login, including when archived. */
    public function employeeWithTrashed(): HasOne
    {
        return $this->hasOne(Employee::class)->withTrashed();
    }

    public function accountChangeRequests(): HasMany
    {
        return $this->hasMany(AccountChangeRequest::class);
    }

    /** Whether this person has read the privacy notice as it currently reads. */
    public function hasAcknowledgedPrivacyNotice(): bool
    {
        return $this->privacy_acknowledged_at !== null
            && $this->privacy_notice_version === config('privacy.notice_version');
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_ADMIN);
    }

    /** Admin and HR staff both administer HR records. */
    public function isHrAdmin(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN, self::ROLE_HR_STAFF);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN);
    }

    public function isSupervisor(): bool
    {
        return $this->hasRole(self::ROLE_SUPERVISOR);
    }

    /*
     * Every account gets a username, however it was created.
     *
     * Logins are made in four places: the seeder, the employee form, Users &
     * Access, and the factory the tests use. Deriving the name here rather
     * than at each of them means none can create an account that cannot sign
     * in. A username that was passed in explicitly is kept as given. Accounts
     * carry no email any more, so the name is the fallback.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (blank($user->username)) {
                $user->username = static::availableUsername((string) ($user->email ?: $user->name));
            }
        });

        /*
         * Switching an account off ends every way it is signed in, not only
         * the next sign-in: API tokens are deleted and, with database
         * sessions, every open browser session is dropped. Without this a
         * deactivated account kept working until it chose to sign out.
         */
        static::updated(function (User $user) {
            if (! $user->wasChanged('is_active') || $user->is_active) {
                return;
            }

            $user->tokens()->delete();

            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'otp_enabled' => 'boolean',
            'otp_email_verified_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'otp_sent_at' => 'datetime',
            'privacy_acknowledged_at' => 'datetime',
        ];
    }
}
