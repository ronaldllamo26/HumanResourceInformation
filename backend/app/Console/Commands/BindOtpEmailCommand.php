<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\OtpService;
use Illuminate\Console\Command;

class BindOtpEmailCommand extends Command
{
    protected $signature = 'otp:bind
                            {username : The company username (e.g. admin@primepower.com or admin)}
                            {email : The personal/company Gmail address to bind for OTP}
                            {--send-test : Send an immediate test code to the bound email}';

    protected $description = 'Bind an RBAC user account to a Gmail address for Multi-Factor Authentication (OTP)';

    public function handle(OtpService $otp): int
    {
        $rawUsername = (string) $this->argument('username');
        $username = User::withDomain($rawUsername);
        $email = strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$email}");
            return Command::FAILURE;
        }

        $user = User::where('username', $username)
            ->orWhere('username', $rawUsername)
            ->first();

        if (! $user) {
            $this->error("User with username [{$username}] not found.");
            $this->line("Available accounts in the system:");
            User::orderBy('role')->get(['id', 'username', 'role', 'name', 'otp_email'])->each(function ($u) {
                $this->line(sprintf(" - %-30s (%-12s) [%s] -> OTP: %s", $u->username, $u->role, $u->name, $u->otp_email ?? 'None'));
            });
            return Command::FAILURE;
        }

        $oldEmail = $user->otp_email;
        $user->forceFill([
            'otp_email' => $email,
            'otp_email_verified_at' => null,
            'otp_code_hash' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
        ])->save();

        $this->info("✓ Successfully bound [{$user->username}] ({$user->name}, Role: {$user->role}) to Gmail: {$email}");
        if ($oldEmail && $oldEmail !== $email) {
            $this->line("  Previous OTP address: {$oldEmail}");
        }

        $this->line("  Multi-Factor Authentication (MFA) is now ACTIVE for this account.");
        $this->line("  Sign-in codes (2-minute validity) will be sent to {$email}.");

        if ($this->option('send-test')) {
            $this->line("  Sending a test sign-in code now...");
            if ($otp->send($user)) {
                $this->info("  ✓ Test code sent to {$email}! Check your inbox.");
            } else {
                $this->error("  ✗ Could not send test code. Please check your mail configuration.");
            }
        } else {
            $this->comment("  Tip: Pass --send-test or run 'php artisan mail:test {$email}' to verify delivery.");
        }

        return Command::SUCCESS;
    }
}
