<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcomes a newly provisioned user with their initial credentials.
 *
 * Sent directly to the person's personal inbox (e.g. Gmail) registered
 * by the Administrator upon account creation.
 *
 * Deliberately synchronous (not queued) so the email is dispatched
 * immediately without relying on a background queue worker.
 */
class AccountProvisioned extends Notification
{
    public function __construct(
        public readonly string $temporaryPassword,
        public readonly string $role,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $roleLabel = match ($this->role) {
            User::ROLE_ADMIN => 'Administrator',
            User::ROLE_HR_STAFF => 'HR Staff',
            User::ROLE_SUPERVISOR => 'Supervisor',
            User::ROLE_EMPLOYEE => 'Employee',
            default => ucfirst($this->role),
        };

        return (new MailMessage)
            ->subject('Your PrimePower HRIS Account Credentials')
            ->greeting("Hello {$notifiable->name},")
            ->line('An account has been created for you on the PrimePower Human Resource Information System.')
            ->line("**Role:** {$roleLabel}")
            ->line("**Company Username:** `{$notifiable->username}`")
            ->line("**Registered Gmail:** `{$notifiable->otp_email}`")
            ->line("**Temporary Password:** `{$this->temporaryPassword}`")
            ->line('You can sign in using either your Company Username or your registered Gmail address along with the temporary password above.')
            ->line('**Security Reminders:**')
            ->line('1. You will be required to change this temporary password to your own permanent password upon your first login.')
            ->line('2. Two-Factor Authentication (OTP) is active on your account. Sign-in verification codes will be delivered to this Gmail address.')
            ->line('If you were not expecting this account, please contact your PrimePower HR administrator.');
    }
}
